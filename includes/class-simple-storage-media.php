<?php
/**
 * Media Library integration: automatic offload of new uploads, file names that do not collide
 * with files already moved to the storage, deletion of remote copies with their attachment, and
 * the work that needs real files (image editing, thumbnail regeneration) for files that live
 * only in the storage.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_Storage_Media {
	public const OFFLOAD_HOOK = 'simple_storage_offload_dir';
	public const DELETE_HOOK = 'simple_storage_delete_remote';

	/** New files younger than this are left for the next run: thumbnails may still be in progress. */
	private const MIN_AGE = 60;
	private const DELAY = 90;
	private const BUDGET = 20;

	/** Option holding the time a `wp media regenerate` run last showed signs of life. */
	public const REGENERATING_OPTION = 'simple_storage_regenerating';

	/** Whether `wp media regenerate` is running in this process. */
	private static bool $regenerating = false;

	/** @var array<string, mixed> Arguments of the `wp media regenerate` run, config defaults included. */
	private static array $cli_args = array();

	/**
	 * What each attachment looked like before its files were brought back for regeneration.
	 *
	 * @var array<int, array{attached: string, original: string, files: array<int, string>, sizes: array<int, string>}>
	 */
	private static array $before = array();

	public static function init(): void {
		add_action( self::OFFLOAD_HOOK, array( self::class, 'offload_dir' ) );
		add_action( self::DELETE_HOOK, array( self::class, 'retry_delete' ), 10, 2 );
		add_filter( 'wp_unique_filename', array( self::class, 'unique_filename' ), 10, 3 );
		add_action( 'delete_attachment', array( self::class, 'delete_attachment' ) );
		add_filter( 'wp_delete_file', array( self::class, 'delete_file' ), 99 );
		add_filter( 'load_image_to_edit_path', array( self::class, 'load_image_to_edit_path' ), 10, 3 );
		add_filter( 'rest_request_before_callbacks', array( self::class, 'before_rest_edit' ), 10, 3 );
		add_action( 'wp_ajax_regeneratethumbnail', array( self::class, 'before_regenerate_ajax' ), 1 );
		add_filter( 'wp_update_attachment_metadata', array( self::class, 'claim_regenerated' ), 98, 2 );
		add_filter( 'wp_get_original_image_path', array( self::class, 'regeneration_source' ), 99, 2 );
		add_filter( 'get_attached_file', array( self::class, 'regeneration_attached_file' ), 99, 2 );

		if ( self::auto_enabled() ) {
			add_action( 'add_attachment', array( self::class, 'schedule_for_attachment' ) );
			add_filter( 'wp_update_attachment_metadata', array( self::class, 'metadata_updated' ), 99, 2 );
		}
	}

	/**
	 * New uploads follow the media into the storage once the site serves from it — and only while
	 * serving was verified end to end for the current settings.
	 */
	public static function auto_enabled(): bool {
		return Simple_Storage_Settings::auto_offload()
			&& 'remote' === Simple_Storage_Settings::state()['mode']
			&& Simple_Storage_Settings::is_configured()
			&& Simple_Storage_Delivery::active()
			&& Simple_Storage_Delivery::delivery_verified();
	}

	/**
	 * Packages that core reads again from disk in a later request: plugin and theme uploads
	 * (context "upgrader") and import files (context "import"). They must stay local.
	 */
	private static function is_transient_attachment( int $attachment_id ): bool {
		$context = (string) get_post_meta( $attachment_id, '_wp_attachment_context', true );

		return in_array( $context, array( 'upgrader', 'import' ), true );
	}

	/** @return array<string, bool> Uploads-relative files of such packages in one folder. */
	private static function transient_files( string $dir ): array {
		global $wpdb;

		$paths = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT file.meta_value FROM {$wpdb->postmeta} file
				INNER JOIN {$wpdb->postmeta} context ON context.post_id = file.post_id AND context.meta_key = '_wp_attachment_context' AND context.meta_value IN ('upgrader', 'import')
				WHERE file.meta_key = '_wp_attached_file' AND file.meta_value LIKE %s",
				$wpdb->esc_like( $dir . '/' ) . '%'
			)
		);

		return array_fill_keys( array_map( 'strval', (array) $paths ), true );
	}

	public static function schedule_for_attachment( $attachment_id ): void {
		if ( self::is_transient_attachment( (int) $attachment_id ) ) {
			return;
		}

		$file = (string) get_post_meta( (int) $attachment_id, '_wp_attached_file', true );
		$dir  = dirname( $file );
		if ( Simple_Storage_Paths::is_month_dir( $dir ) ) {
			// The upload goes out at the end of this request; the event stays for anything that
			// is not settled by then.
			self::schedule( $dir );
			Simple_Storage_Runner::queue_attachment( (int) $attachment_id );
		}
	}

	/**
	 * @param mixed $data
	 * @param mixed $attachment_id
	 * @return mixed
	 */
	public static function metadata_updated( $data, $attachment_id ) {
		self::schedule_for_attachment( (int) $attachment_id );

		return $data;
	}

	public static function schedule( string $dir, int $delay = self::DELAY ): void {
		if ( did_action( 'shutdown' ) ) {
			// Late in a long request the cached schedule is stale; writing it back would drop
			// events other requests added meanwhile.
			Simple_Storage_Runner::refresh_cron_cache();
		}
		if ( false === wp_next_scheduled( self::OFFLOAD_HOOK, array( $dir ) ) ) {
			wp_schedule_single_event( time() + $delay, self::OFFLOAD_HOOK, array( $dir ) );
		}
	}

	/** Cron: move settled local files of one YYYY/MM folder to the storage. */
	public static function offload_dir( $dir ): void {
		self::offload( (string) $dir, 0.0, null );
	}

	/**
	 * At the end of a request: move the files this request wrote — exactly these paths, complete
	 * by then. Everything else in the folder (files of requests still running, an original brought
	 * back to cut a size) waits for the folder's scheduled run and its one-minute rule.
	 *
	 * @param array<int, string> $paths
	 */
	public static function offload_paths_now( string $dir, array $paths, float $request_start ): void {
		self::offload( $dir, $request_start, $paths );
	}

	/**
	 * Files of an attachment saved in this request, with the WebP/AVIF copies themes keep next to
	 * an image ("photo-jpg.webp").
	 *
	 * @return array<int, string>
	 */
	public static function upload_files( int $attachment_id ): array {
		$paths = self::attachment_files( $attachment_id );
		foreach ( $paths as $relative ) {
			$extension = pathinfo( $relative, PATHINFO_EXTENSION );
			if ( '' === $extension ) {
				continue;
			}
			$stem = dirname( $relative ) . '/' . pathinfo( $relative, PATHINFO_FILENAME ) . '-' . strtolower( $extension );
			foreach ( array( 'webp', 'avif' ) as $format ) {
				if ( is_file( Simple_Storage_Paths::local( $stem . '.' . $format ) ) ) {
					$paths[] = $stem . '.' . $format;
				}
			}
		}

		return array_values( array_unique( $paths ) );
	}

	/** Keep a file brought back from the storage local for a while: others may be using it. */
	private static function hold( string $relative ): void {
		set_transient( 'simple_storage_hold_' . md5( $relative ), 1, self::DELAY );
	}

	public static function release_hold( string $relative ): void {
		delete_transient( 'simple_storage_hold_' . md5( $relative ) );
	}

	private static function is_held( string $relative ): bool {
		return (bool) get_transient( 'simple_storage_hold_' . md5( $relative ) );
	}

	/** @param array<int, string>|null $only Exact paths written by this request, or null for the folder. */
	private static function offload( string $dir, float $request_start, ?array $only ): void {
		if ( ! self::auto_enabled() || ! Simple_Storage_Paths::is_month_dir( $dir ) ) {
			return;
		}

		// Never alongside a job, even a paused one: its scans and phases assume they are the only
		// ones changing files and the index. The job lock keeps two offloads apart as well.
		if ( Simple_Storage_Jobs::is_active() || self::regeneration_running() ) {
			self::schedule( $dir, 5 * MINUTE_IN_SECONDS );

			return;
		}
		$lock = Simple_Storage_Jobs::lock();
		if ( null === $lock ) {
			self::schedule( $dir, 5 * MINUTE_IN_SECONDS );

			return;
		}

		try {
			self::offload_locked( $dir, $request_start, $only );
		} finally {
			Simple_Storage_Jobs::unlock( $lock );
		}
	}

	/** @param array<int, string>|null $only */
	private static function offload_locked( string $dir, float $request_start, ?array $only ): void {
		$client = Simple_Storage_Client::create();
		if ( is_wp_error( $client ) ) {
			Simple_Storage_Log::error( $client->get_error_message() );
			self::schedule( $dir, 15 * MINUTE_IN_SECONDS );

			return;
		}
		$client->set_heartbeat( array( Simple_Storage_Jobs::class, 'heartbeat' ) );

		if ( null === $only ) {
			$files = Simple_Storage_Jobs::scan_local_dir( $dir );
		} else {
			$files = array();
			foreach ( array_unique( $only ) as $relative ) {
				$local = Simple_Storage_Paths::local( $relative );
				clearstatcache( true, $local );
				if ( dirname( $relative ) === $dir && Simple_Storage_Paths::is_media_path( $relative ) && is_file( $local ) ) {
					$files[] = array(
						'path'  => $relative,
						'size'  => (int) filesize( $local ),
						'mtime' => (int) filemtime( $local ),
					);
				}
			}
		}

		$deadline = microtime( true ) + self::BUDGET;
		$pending  = false;
		$failed   = false;
		$moved    = 0;
		$skip     = self::transient_files( $dir );
		foreach ( $files as $file ) {
			if ( isset( $skip[ $file['path'] ] ) ) {
				continue;
			}
			if ( microtime( true ) >= $deadline ) {
				$pending = true;
				break;
			}
			if ( self::is_held( $file['path'] ) ) {
				$pending = true;
				continue;
			}
			if ( null !== $only ) {
				// Written by this request and complete: anything older or still empty is not.
				if ( $file['mtime'] < (int) floor( $request_start ) || 0 === $file['size'] ) {
					$pending = true;
					continue;
				}
			} elseif ( $file['mtime'] > time() - self::MIN_AGE ) {
				// A file younger than a minute may still be in the making (thumbnails, an optimizer).
				$pending = true;
				continue;
			}

			$result = self::offload_file( $client, $file );
			Simple_Storage_Jobs::heartbeat();
			if ( is_wp_error( $result ) ) {
				$failed = true;
				Simple_Storage_Log::error( $file['path'] . ': ' . $result->get_error_message() );
			} elseif ( $result ) {
				++$moved;
			}
		}

		if ( $moved > 0 ) {
			/* translators: 1: number of files, 2: folder. */
			Simple_Storage_Log::info( sprintf( __( 'Automatically moved %1$d new files from %2$s to the storage.', 'simple-storage' ), $moved, $dir ) );
		}
		// The folder's event is never cleared here: another request may have counted on it.
		if ( $failed ) {
			self::schedule( $dir, 15 * MINUTE_IN_SECONDS );
		} elseif ( $pending ) {
			self::schedule( $dir, self::DELAY );
		}
	}

	/**
	 * @param array{path: string, size: int, mtime: int} $file
	 * @return bool|WP_Error True when the local copy was replaced by a verified remote one.
	 */
	private static function offload_file( Simple_Storage_Client $client, array $file ) {
		$row = Simple_Storage_Index::touch_local( $file['path'], $file['size'], $file['mtime'] );
		if ( null === $row || $row['conflict'] ) {
			return false;
		}

		if ( ! $row['remote'] ) {
			$uploaded = Simple_Storage_Transfer::upload( $client, $row );
			if ( is_wp_error( $uploaded ) ) {
				return $uploaded;
			}
			$row = Simple_Storage_Index::get( $file['path'] );
		}

		if ( null !== $row && $row['remote'] && ! $row['verified'] ) {
			$verified = Simple_Storage_Transfer::verify( $client, $row );
			if ( true !== $verified ) {
				return is_wp_error( $verified ) ? $verified : false;
			}
			$row = Simple_Storage_Index::get( $file['path'] );
		}

		if ( null === $row || ! $row['verified'] ) {
			return false;
		}
		if ( ! Simple_Storage_Delivery::ensure_extension_verified( Simple_Storage_Paths::extension( $file['path'] ) ) ) {
			// Serving this extension from the storage does not work: the file stays local.
			return false;
		}

		$removed = Simple_Storage_Transfer::remove_local( $client, $row );

		return is_wp_error( $removed ) ? $removed : true;
	}

	/**
	 * Bring one media file back from the storage into uploads when it exists only there, so that
	 * code that needs a real file (the image editor) can work with it. The download is verified
	 * like any other; the automatic offload moves the file out again later.
	 */
	public static function ensure_local( string $relative ): bool {
		if ( ! Simple_Storage_Paths::is_media_path( $relative ) ) {
			return false;
		}
		$row = Simple_Storage_Index::has_remote_files() ? Simple_Storage_Index::get( $relative ) : null;
		if ( is_file( Simple_Storage_Paths::local( $relative ) ) ) {
			if ( null !== $row && $row['remote'] ) {
				// Brought back earlier and still in use: it stays a while longer.
				self::hold( $relative );
			}

			return true;
		}

		if ( null === $row || ! $row['remote'] || $row['conflict'] ) {
			return false;
		}

		$client = Simple_Storage_Client::create();
		$result = is_wp_error( $client ) ? $client : Simple_Storage_Transfer::download( $client, $row );
		if ( is_wp_error( $result ) ) {
			Simple_Storage_Log::error( $relative . ': ' . $result->get_error_message() );

			return false;
		}

		/* translators: %s: file path. */
		Simple_Storage_Log::info( sprintf( __( '%s was brought back from the storage to be processed locally.', 'simple-storage' ), $relative ) );
		self::hold( $relative );
		if ( self::auto_enabled() ) {
			// The work may save its result elsewhere (or not at all): the folder goes out again.
			self::schedule( implode( '/', array_slice( explode( '/', $relative ), 0, 2 ) ) );
		}

		return true;
	}

	/**
	 * The image editor (crop, rotate, flip, scale, preview) and the Customizer crop load the file
	 * through _load_image_to_edit_path(); a file that lives only in the storage is downloaded
	 * into place first, so editing works without allow_url_fopen.
	 *
	 * @param mixed $filepath
	 * @param mixed $attachment_id
	 * @param mixed $size
	 * @return mixed
	 */
	public static function load_image_to_edit_path( $filepath, $attachment_id, $size = 'full' ) {
		$attached = (string) get_post_meta( (int) $attachment_id, '_wp_attached_file', true );
		if ( '' === $attached || ! Simple_Storage_Paths::is_media_path( $attached ) ) {
			return $filepath;
		}

		$relative = $attached;
		if ( 'full' !== $size ) {
			$data = image_get_intermediate_size( (int) $attachment_id, $size );
			if ( is_array( $data ) && ! empty( $data['file'] ) ) {
				$relative = dirname( $attached ) . '/' . wp_basename( (string) $data['file'] );
			}
		}

		if ( is_string( $filepath ) && '' !== $filepath && file_exists( $filepath ) ) {
			return $filepath;
		}

		return self::ensure_local( $relative ) ? Simple_Storage_Paths::local( $relative ) : $filepath;
	}

	/**
	 * The block editor crops and rotates through POST /wp/v2/media/{id}/edit, which reads the
	 * original image (before "-scaled") from disk: make it and the attached file local first.
	 * The Regenerate Thumbnails plugin needs the same for its regenerate route.
	 *
	 * @param mixed $response
	 * @param mixed $handler
	 * @param mixed $request
	 * @return mixed
	 */
	public static function before_rest_edit( $response, $handler, $request ) {
		if ( ! $request instanceof WP_REST_Request ) {
			return $response;
		}

		$route = $request->get_route();
		if ( ! ( 'POST' === $request->get_method() && preg_match( '#^/wp/v2/media/(\d+)/edit$#', $route, $match ) )
			&& ! preg_match( '#^/regenerate-thumbnails/v1/regenerate/(\d+)$#', $route, $match ) ) {
			return $response;
		}

		$attachment_id = (int) $match[1];
		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			return $response;
		}

		self::remember_files( $attachment_id );
		foreach ( self::edit_sources( $attachment_id ) as $relative ) {
			self::ensure_local( $relative );
		}

		return $response;
	}

	/** Regenerate Thumbnails 2.x regenerates one image per admin-ajax request. */
	public static function before_regenerate_ajax(): void {
		$attachment_id = (int) ( $_REQUEST['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		if ( $attachment_id <= 0 || ! current_user_can( 'edit_post', $attachment_id ) ) {
			return;
		}

		self::remember_files( $attachment_id );
		foreach ( self::edit_sources( $attachment_id ) as $relative ) {
			self::ensure_local( $relative );
		}
	}

	/** @return array<int, string> The attached file and the original image of an attachment. */
	private static function edit_sources( int $attachment_id ): array {
		$attached = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( '' === $attached ) {
			return array();
		}

		$sources = array( $attached );
		$meta    = get_post_meta( $attachment_id, '_wp_attachment_metadata', true );
		if ( is_array( $meta ) && ! empty( $meta['original_image'] ) ) {
			$sources[] = dirname( $attached ) . '/' . wp_basename( (string) $meta['original_image'] );
		}

		return array_values( array_unique( array_filter( $sources, array( Simple_Storage_Paths::class, 'is_media_path' ) ) ) );
	}

	/**
	 * Remember an attachment's files before its original is brought back to be processed, so that
	 * regenerated files can later be told apart from files of other attachments.
	 */
	public static function remember_files( int $attachment_id ): void {
		if ( isset( self::$before[ $attachment_id ] ) ) {
			return;
		}

		$attached = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$meta     = get_post_meta( $attachment_id, '_wp_attachment_metadata', true );
		$meta     = is_array( $meta ) ? $meta : array();
		$dir      = dirname( $attached );
		$sizes    = array();
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
			if ( is_array( $size ) && ! empty( $size['file'] ) ) {
				$sizes[] = $dir . '/' . wp_basename( (string) $size['file'] );
			}
			foreach ( (array) ( is_array( $size ) ? ( $size['sources'] ?? array() ) : array() ) as $source ) {
				if ( is_array( $source ) && ! empty( $source['file'] ) ) {
					$sizes[] = $dir . '/' . wp_basename( (string) $source['file'] );
				}
			}
		}

		self::$before[ $attachment_id ] = array(
			'attached' => $attached,
			'original' => ! empty( $meta['original_image'] ) ? $dir . '/' . wp_basename( (string) $meta['original_image'] ) : $attached,
			'files'    => self::attachment_files( $attachment_id ),
			'sizes'    => array_values( array_unique( array_filter( $sizes, array( Simple_Storage_Paths::class, 'is_media_path' ) ) ) ),
		);
	}

	/**
	 * Regenerated sub-sizes keep their names, so they land on paths whose remote copies are their
	 * old versions. A path is taken over — the new local file replaces the remote copy with the
	 * next offload, instead of being held back as a name conflict — only when all of this holds:
	 * the attachment's files were remembered before it was brought back for this work; the path
	 * was one of them and is not its original; no other attachment uses the path; and the
	 * original is the very file recorded in the storage. Anything else stays a conflict.
	 *
	 * @param mixed $data
	 * @param mixed $attachment_id
	 * @return mixed
	 */
	public static function claim_regenerated( $data, $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$before        = self::$before[ $attachment_id ] ?? null;
		if ( ! is_array( $data ) || null === $before || ! Simple_Storage_Index::has_remote_files() ) {
			return $data;
		}

		$original = $before['original'];
		$row      = Simple_Storage_Index::get( $original );
		$local    = Simple_Storage_Paths::local( $original );
		clearstatcache( true, $local );
		if ( null === $row || ! $row['remote'] || ! $row['local'] || $row['conflict'] || ! is_file( $local ) || (int) filesize( $local ) !== (int) $row['size'] ) {
			return $data;
		}

		$attached = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$dir      = dirname( $before['attached'] );
		$derived  = $attached !== $original ? array( $attached ) : array();
		foreach ( (array) ( $data['sizes'] ?? array() ) as $size ) {
			if ( is_array( $size ) && ! empty( $size['file'] ) ) {
				$derived[] = $dir . '/' . wp_basename( (string) $size['file'] );
			}
			foreach ( (array) ( is_array( $size ) ? ( $size['sources'] ?? array() ) : array() ) as $source ) {
				if ( is_array( $source ) && ! empty( $source['file'] ) ) {
					$derived[] = $dir . '/' . wp_basename( (string) $source['file'] );
				}
			}
		}
		foreach ( (array) ( $data['sources'] ?? array() ) as $source ) {
			if ( is_array( $source ) && ! empty( $source['file'] ) ) {
				$derived[] = $dir . '/' . wp_basename( (string) $source['file'] );
			}
		}

		$claimed = 0;
		foreach ( array_unique( $derived ) as $relative ) {
			if ( $relative === $original || ! in_array( $relative, $before['files'], true ) || ! Simple_Storage_Paths::is_media_path( $relative ) ) {
				continue;
			}

			$row   = Simple_Storage_Index::get( $relative );
			$local = Simple_Storage_Paths::local( $relative );
			clearstatcache( true, $local );
			if ( null === $row || ! $row['remote'] || ( $row['local'] && ! $row['conflict'] ) || ! is_file( $local ) || self::used_by_other_attachment( $relative, $before['attached'] ) ) {
				continue;
			}

			Simple_Storage_Index::update(
				(int) $row['id'],
				array(
					'local'    => 1,
					'remote'   => 0,
					'verified' => 0,
					'conflict' => 0,
					'error'    => null,
					'sha256'   => null,
					'size'     => (int) filesize( $local ),
					'mtime'    => (int) filemtime( $local ),
				)
			);
			++$claimed;
		}

		if ( $claimed > 0 && self::auto_enabled() && ! self::$regenerating ) {
			self::schedule( $dir );
		}

		return $data;
	}

	/**
	 * Whether another attachment — one with a different main file, so not a translation sharing
	 * this one's files — names this path as its file, a size or a backup.
	 *
	 * @param string $own_attached The main file of the attachment asking ("" for none).
	 */
	public static function used_by_other_attachment( string $relative, string $own_attached ): bool {
		global $wpdb;

		$basename = wp_basename( $relative );

		return (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT f.post_id FROM {$wpdb->postmeta} f
				LEFT JOIN {$wpdb->postmeta} m ON m.post_id = f.post_id AND m.meta_key IN ('_wp_attachment_metadata', '_wp_attachment_backup_sizes')
				WHERE f.meta_key = '_wp_attached_file' AND f.meta_value LIKE %s AND f.meta_value <> %s
				AND ( f.meta_value = %s OR m.meta_value LIKE %s )
				LIMIT 1",
				$wpdb->esc_like( dirname( $relative ) . '/' ) . '%',
				$own_attached,
				$relative,
				'%' . $wpdb->esc_like( '"' . $basename . '"' ) . '%'
			)
		);
	}

	/**
	 * WP-CLI: remember the arguments of `wp media regenerate`, with the defaults of its section in
	 * wp-cli.yml, which the command receives but the before_invoke hook does not.
	 *
	 * @param mixed $args
	 * @param mixed $assoc_args
	 */
	public static function remember_cli_command( $args, $assoc_args = array() ): void {
		if ( ! is_array( $args ) || array( 'media', 'regenerate' ) !== array_slice( array_values( $args ), 0, 2 ) ) {
			return;
		}

		$defaults = array();
		if ( class_exists( 'WP_CLI' ) && is_object( WP_CLI::get_runner() ) ) {
			$config   = (array) WP_CLI::get_runner()->extra_config;
			$defaults = (array) ( $config['media regenerate'] ?? array() );
		}
		self::$cli_args = array_merge( $defaults, (array) $assoc_args );
	}

	/** WP-CLI: `wp media regenerate` starts. */
	public static function start_regeneration(): void {
		if ( self::$regenerating ) {
			return;
		}

		self::$regenerating = true;
		self::$before       = array();
		update_option( self::REGENERATING_OPTION, time(), false );
		// WP-CLI ends a run with failed attachments through WP_CLI::error(), which exits before
		// after_invoke: the clean-up has to run on shutdown as well.
		register_shutdown_function( array( self::class, 'finish_regeneration' ) );
	}

	/** Whether a `wp media regenerate` run (in any process) is bringing originals back right now. */
	private static function regeneration_running(): bool {
		$since = (int) get_option( self::REGENERATING_OPTION, 0 );

		return $since > time() - 15 * MINUTE_IN_SECONDS;
	}

	/**
	 * `wp media regenerate` reads the original through wp_get_original_image_path() and skips an
	 * attachment whose file is missing: bring it back from the storage first.
	 *
	 * @param mixed $path
	 * @param mixed $attachment_id
	 * @return mixed
	 */
	public static function regeneration_source( $path, $attachment_id ) {
		if ( self::$regenerating && is_string( $path ) && '' !== $path ) {
			self::prepare_regeneration( (int) $attachment_id, $path );
		}

		return $path;
	}

	/**
	 * Files that are not images (PDF previews) are read through get_attached_file() instead.
	 *
	 * @param mixed $file
	 * @param mixed $attachment_id
	 * @return mixed
	 */
	public static function regeneration_attached_file( $file, $attachment_id ) {
		if ( self::$regenerating && is_string( $file ) && '' !== $file && ! str_starts_with( (string) get_post_mime_type( (int) $attachment_id ), 'image/' ) ) {
			self::prepare_regeneration( (int) $attachment_id, $file );
		}

		return $file;
	}

	private static function prepare_regeneration( int $attachment_id, string $path ): void {
		self::remember_files( $attachment_id );

		$relative = Simple_Storage_Paths::relative_from_local( $path );
		if ( null !== $relative && ! is_file( $path ) ) {
			self::ensure_local( $relative );
		}

		// Keep the automatic offload away from the originals for the whole run.
		if ( (int) get_option( self::REGENERATING_OPTION, 0 ) < time() - MINUTE_IN_SECONDS ) {
			update_option( self::REGENERATING_OPTION, time(), false );
		}
	}

	/**
	 * WP-CLI: `wp media regenerate` finished (or exited). Unless told to keep them, WP-CLI deletes
	 * the old sub-sizes it finds on disk; old sub-sizes that lived only in the storage and are no
	 * longer part of the attachment go the same way. Only sub-sizes: never the attached file, the
	 * original, backups or companions, and never a file another attachment uses. Then the folders
	 * go out again.
	 */
	public static function finish_regeneration(): void {
		if ( ! self::$regenerating ) {
			return;
		}

		self::$regenerating = false;
		$before             = self::$before;
		self::$before       = array();
		delete_option( self::REGENERATING_OPTION );

		$args = self::$cli_args;
		if ( empty( $args ) && class_exists( 'WP_CLI' ) && is_object( WP_CLI::get_runner() ) ) {
			$args = (array) WP_CLI::get_runner()->assoc_args;
		}
		$flag      = static fn( string $name ): bool => ! empty( $args[ $name ] ) && 'false' !== $args[ $name ];
		$keep_old  = ( $flag( 'skip-delete' ) || $flag( 'only-missing' ) ) && ! $flag( 'delete-unknown' );
		$dirs      = array();

		foreach ( $before as $attachment_id => $files ) {
			$dirs[ dirname( $files['attached'] ) ] = true;
			if ( $keep_old || self::shared_with_other_attachment( $attachment_id ) ) {
				continue;
			}

			$current = self::attachment_files( $attachment_id );
			$gone    = array();
			foreach ( $files['sizes'] as $relative ) {
				if ( ! in_array( $relative, $current, true ) && ! is_file( Simple_Storage_Paths::local( $relative ) ) && ! self::used_by_other_attachment( $relative, $files['attached'] ) ) {
					$gone[] = $relative;
				}
			}
			self::delete_remote_copies( $gone );
		}

		if ( self::auto_enabled() ) {
			foreach ( array_keys( $dirs ) as $dir ) {
				if ( Simple_Storage_Paths::is_month_dir( (string) $dir ) ) {
					self::schedule( (string) $dir );
				}
			}
		}
	}

	/**
	 * WordPress checks only local files when it picks a unique name, so a new upload could take the
	 * name of a file that already lives only in the storage and overwrite it there.
	 *
	 * @param mixed $filename
	 * @param mixed $ext
	 * @param mixed $dir
	 * @return mixed
	 */
	public static function unique_filename( $filename, $ext, $dir ) {
		if ( ! is_string( $filename ) || ! is_string( $dir ) || ! Simple_Storage_Index::has_remote_files() ) {
			return $filename;
		}

		$base = Simple_Storage_Paths::uploads()['basedir'] . '/';
		$dir  = untrailingslashit( wp_normalize_path( $dir ) );
		if ( ! str_starts_with( $dir . '/', $base ) ) {
			return $filename;
		}

		$relative_dir = substr( $dir, strlen( $base ) );
		if ( ! Simple_Storage_Paths::is_month_dir( $relative_dir ) ) {
			return $filename;
		}

		$taken = array();
		foreach ( Simple_Storage_Index::in_dir( $relative_dir ) as $path => $row ) {
			if ( $row['remote'] && ! $row['local'] ) {
				$taken[] = strtolower( basename( (string) $path ) );
			}
		}
		if ( empty( $taken ) ) {
			return $filename;
		}

		$extension = pathinfo( $filename, PATHINFO_EXTENSION );
		$extension = '' !== $extension ? '.' . $extension : '';
		$name      = '' !== $extension ? substr( $filename, 0, -strlen( $extension ) ) : $filename;
		$candidate = $filename;
		$number    = 1;

		while ( self::name_taken( $candidate, $taken ) || ( $candidate !== $filename && file_exists( $dir . '/' . $candidate ) ) ) {
			$candidate = $name . '-' . $number . $extension;
			++$number;
		}

		return $candidate;
	}

	/** @param array<int, string> $taken Lowercase base names of remote-only files. */
	private static function name_taken( string $candidate, array $taken ): bool {
		$candidate = strtolower( $candidate );
		$extension = pathinfo( $candidate, PATHINFO_EXTENSION );
		$stem      = '' !== $extension ? substr( $candidate, 0, -strlen( $extension ) - 1 ) : $candidate;
		// Sub-sizes, edits, and the WebP/AVIF copies themes keep next to an image ("photo-jpg.webp").
		$variants = '/^' . preg_quote( $stem, '/' ) . '(?:-[0-9]+x[0-9]+|-scaled|-rotated|-e[0-9]+|-(?:jpe?g|png|gif|webp|avif|bmp|tiff?|heic)\.(?:webp|avif)$)/';

		foreach ( $taken as $name ) {
			if ( $name === $candidate || str_starts_with( $name, $stem . '.' ) || preg_match( $variants, $name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Delete the remote copies of an attachment that is being deleted permanently — but only
	 * copies of this attachment's own files: not when another attachment uses the same file (a
	 * translation sharing media), not when a wp_delete_file filter keeps the file, and not when
	 * the local file at that path is a different one (a name conflict, or a file the index has not
	 * seen yet), since the remote object is then another attachment's original.
	 */
	public static function delete_attachment( $attachment_id ): void {
		$attachment_id = (int) $attachment_id;
		if ( ! Simple_Storage_Index::has_remote_files() || self::shared_with_other_attachment( $attachment_id ) ) {
			return;
		}

		$files = self::attachment_files( $attachment_id );
		self::delete_remote_copies( array_merge( $files, self::derived_files( $files, false ) ) );
	}

	/**
	 * Timber deletes its resized copies of an image from disk when the attachment metadata is
	 * generated again; the copies that live only in the storage go too, so they are made anew.
	 *
	 * @param mixed $metadata
	 * @param mixed $attachment_id
	 * @return mixed
	 */
	public static function forget_timber_copies( $metadata, $attachment_id ) {
		if ( Simple_Storage_Index::has_remote_files() && ! self::shared_with_other_attachment( (int) $attachment_id ) ) {
			$attached = (string) get_post_meta( (int) $attachment_id, '_wp_attached_file', true );
			if ( Simple_Storage_Paths::is_media_path( $attached ) ) {
				self::delete_remote_copies( self::derived_files( array( $attached ), true ) );
			}
		}

		return $metadata;
	}

	/**
	 * Delete the remote copies of these files, where the remote copy is the file's own.
	 *
	 * @param array<int, string> $paths
	 */
	private static function delete_remote_copies( array $paths ): void {
		$client = null;
		foreach ( array_unique( $paths ) as $relative ) {
			$row = Simple_Storage_Index::get( $relative );
			if ( null === $row || ! $row['remote'] || ! self::remote_is_copy_of_local( $row ) || self::deletion_vetoed( $relative ) ) {
				continue;
			}

			$client  = $client ?? Simple_Storage_Client::create();
			$deleted = is_wp_error( $client ) ? $client : $client->delete_file( Simple_Storage_Paths::remote( (string) $row['path'] ) );
			if ( is_wp_error( $deleted ) ) {
				// The attachment is going away either way; the index forgets the file now and a
				// later retry deletes the remote copy, unless a new file has taken the name by then.
				Simple_Storage_Log::error( $row['path'] . ': ' . $deleted->get_error_message() );
				wp_schedule_single_event( time() + 10 * MINUTE_IN_SECONDS, self::DELETE_HOOK, array( (string) $row['path'], 1 ) );
			}

			if ( $row['local'] && is_file( Simple_Storage_Paths::local( $relative ) ) ) {
				Simple_Storage_Index::update(
					(int) $row['id'],
					array(
						'remote'      => 0,
						'verified'    => 0,
						'remote_size' => null,
					)
				);
			} else {
				Simple_Storage_Index::delete( (int) $row['id'] );
			}
		}
	}

	/**
	 * Files that themes and Timber derive from these files without recording them in the
	 * attachment metadata, as far as the index knows them: Timber's resized and letterboxed
	 * copies ("photo-768x0-c-default.jpg", "photo-lbox-300x200-ffffff.jpg") and, unless only
	 * Timber's are asked for, WebP/AVIF copies kept next to an image ("photo-jpg.webp") and
	 * Timber's resized copies of those. A file that is the main file of an attachment is never one.
	 *
	 * @param array<int, string> $files
	 * @return array<int, string>
	 */
	private static function derived_files( array $files, bool $timber_only ): array {
		$owner   = $files[0] ?? '';
		$derived = array();
		foreach ( $files as $file ) {
			$extension = pathinfo( $file, PATHINFO_EXTENSION );
			if ( '' === $extension ) {
				continue;
			}

			$stem     = dirname( $file ) . '/' . pathinfo( $file, PATHINFO_FILENAME );
			$quoted   = preg_quote( $stem, '#' );
			$ext      = preg_quote( $extension, '#' );
			$patterns = array(
				$quoted . '-[0-9]+x[0-9]+-c-[a-z]+(?:-[a-z]+)?\.' . $ext,
				$quoted . '-lbox-[0-9]+x[0-9]+-[a-zA-Z0-9]+\.' . $ext,
			);
			if ( ! $timber_only ) {
				$patterns[] = $quoted . '-' . $ext . '(?:-[0-9]+x[0-9]+-c-[a-z]+(?:-[a-z]+)?)?\.(?:webp|avif)';
			}
			$regex = '#^(?:' . implode( '|', $patterns ) . ')$#i';

			foreach ( Simple_Storage_Index::with_prefix( $stem . '-' ) as $path => $row ) {
				$path = (string) $path;
				if ( ! $row['remote'] || $row['conflict'] || in_array( $path, $files, true ) || ! preg_match( $regex, $path ) ) {
					continue;
				}
				// An upload that only looks like a derived file belongs to an attachment of its own.
				if ( self::used_by_other_attachment( $path, $owner ) ) {
					continue;
				}
				$derived[] = $path;
			}
		}

		return array_values( array_unique( $derived ) );
	}

	/** Whether the remote object of a row is a copy of what is (or was) the local file. @param array<string, mixed> $row */
	private static function remote_is_copy_of_local( array $row ): bool {
		if ( $row['conflict'] ) {
			return false;
		}
		if ( $row['local'] ) {
			return true;
		}

		$local = Simple_Storage_Paths::local( (string) $row['path'] );
		clearstatcache( true, $local );

		// Remote-only: a local file at the same path is a newcomer the index has not seen.
		return ! file_exists( $local );
	}

	/** Whether another attachment records the same main file (shared media of translations). */
	private static function shared_with_other_attachment( int $attachment_id ): bool {
		global $wpdb;

		$file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( '' === $file ) {
			return false;
		}

		return (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s AND post_id <> %d LIMIT 1", $file, $attachment_id )
		);
	}

	/**
	 * Ask the wp_delete_file filters (without this plugin's own) whether the file may go; core
	 * never asks them for files that exist only in the storage.
	 */
	private static function deletion_vetoed( string $relative ): bool {
		$callback = array( self::class, 'delete_file' );
		$priority = has_filter( 'wp_delete_file', $callback );
		if ( false !== $priority ) {
			remove_filter( 'wp_delete_file', $callback, $priority );
		}

		$result = apply_filters( 'wp_delete_file', Simple_Storage_Paths::local( $relative ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.

		if ( false !== $priority ) {
			add_filter( 'wp_delete_file', $callback, $priority );
		}

		return empty( $result );
	}

	/**
	 * Every file WordPress records for an attachment, as uploads-relative media paths.
	 *
	 * @return array<int, string>
	 */
	public static function attachment_files( int $attachment_id ): array {
		$file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( '' === $file ) {
			return array();
		}

		$dir   = dirname( $file );
		$names = array();
		$meta  = get_post_meta( $attachment_id, '_wp_attachment_metadata', true );
		if ( is_array( $meta ) ) {
			if ( ! empty( $meta['original_image'] ) ) {
				$names[] = (string) $meta['original_image'];
			}
			foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
				if ( is_array( $size ) && ! empty( $size['file'] ) ) {
					$names[] = (string) $size['file'];
				}
				foreach ( (array) ( $size['sources'] ?? array() ) as $source ) {
					if ( is_array( $source ) && ! empty( $source['file'] ) ) {
						$names[] = (string) $source['file'];
					}
				}
			}
			foreach ( (array) ( $meta['sources'] ?? array() ) as $source ) {
				if ( is_array( $source ) && ! empty( $source['file'] ) ) {
					$names[] = (string) $source['file'];
				}
			}
			// Companions core deletes with the attachment: the original of a converted upload
			// (HEIC), the video and poster of an animated GIF, and the legacy thumbnail.
			foreach ( array( 'source_image', 'animated_video', 'animated_video_poster', 'thumb' ) as $key ) {
				if ( ! empty( $meta[ $key ] ) && is_string( $meta[ $key ] ) ) {
					$names[] = $meta[ $key ];
				}
			}
		}

		$backups = get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true );
		foreach ( is_array( $backups ) ? $backups : array() as $backup ) {
			if ( is_array( $backup ) && ! empty( $backup['file'] ) ) {
				$names[] = (string) $backup['file'];
			}
		}

		$paths = array( $file );
		foreach ( $names as $name ) {
			$paths[] = $dir . '/' . wp_basename( $name );
		}

		/**
		 * Files of an attachment, as uploads-relative paths: their remote copies are deleted with
		 * the attachment. Add files a theme or plugin keeps for an attachment outside its metadata.
		 *
		 * @param array<int, string> $paths
		 * @param int                $attachment_id
		 */
		$paths = (array) apply_filters( 'simple_storage_attachment_files', array_values( array_unique( $paths ) ), $attachment_id );

		return array_values( array_unique( array_filter( array_map( 'strval', $paths ), array( Simple_Storage_Paths::class, 'is_media_path' ) ) ) );
	}

	/**
	 * WordPress deletes a media file that still exists locally (an attachment, or old sizes after
	 * editing): the remote copy goes with it.
	 *
	 * @param mixed $file
	 * @return mixed
	 */
	public static function delete_file( $file ) {
		if ( ! is_string( $file ) || '' === $file ) {
			return $file;
		}

		$relative = Simple_Storage_Paths::relative_from_local( $file );
		if ( null === $relative || ! Simple_Storage_Index::has_remote_files() ) {
			return $file;
		}

		$row = Simple_Storage_Index::get( $relative );
		if ( null === $row ) {
			return $file;
		}

		clearstatcache( true, $file );
		if ( $row['conflict'] || ( ! $row['local'] && file_exists( $file ) ) ) {
			// The local file being deleted is not the one recorded in the storage: the remote
			// original stays, and stays reserved, as a file that lives only in the storage.
			if ( $row['conflict'] ) {
				Simple_Storage_Index::update(
					(int) $row['id'],
					array(
						'local'    => 0,
						'conflict' => 0,
						'error'    => null,
					)
				);
			}

			return $file;
		}

		if ( $row['remote'] ) {
			$client  = Simple_Storage_Client::create();
			$deleted = is_wp_error( $client ) ? $client : $client->delete_file( Simple_Storage_Paths::remote( $relative ) );
			if ( is_wp_error( $deleted ) ) {
				Simple_Storage_Log::error( $relative . ': ' . $deleted->get_error_message() );
				wp_schedule_single_event( time() + 10 * MINUTE_IN_SECONDS, self::DELETE_HOOK, array( $relative, 1 ) );
			}
		}

		Simple_Storage_Index::delete( (int) $row['id'] );

		return $file;
	}

	/** Cron: retry a failed remote deletion, unless the file came back locally meanwhile. */
	public static function retry_delete( $relative, $attempt ): void {
		$relative = (string) $relative;
		if ( ! Simple_Storage_Paths::is_media_path( $relative ) || is_file( Simple_Storage_Paths::local( $relative ) ) ) {
			return;
		}
		if ( null !== Simple_Storage_Index::get( $relative ) ) {
			// A new file took this name in the meantime; its remote copy must stay.
			return;
		}

		$client  = Simple_Storage_Client::create();
		$deleted = is_wp_error( $client ) ? $client : $client->delete_file( Simple_Storage_Paths::remote( $relative ) );
		if ( ! is_wp_error( $deleted ) ) {
			Simple_Storage_Index::delete_path( $relative );

			return;
		}

		if ( (int) $attempt < 3 ) {
			wp_schedule_single_event( time() + 30 * MINUTE_IN_SECONDS, self::DELETE_HOOK, array( $relative, (int) $attempt + 1 ) );
		} else {
			/* translators: %s: file path. */
			Simple_Storage_Log::error( sprintf( __( 'Could not delete %s from the storage; delete it manually.', 'simple-storage' ), $relative ) );
		}
	}
}
