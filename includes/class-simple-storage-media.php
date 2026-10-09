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

	/**
	 * Image optimizers that rewrite or add files after the upload request (plugin folder names):
	 * with one of them active, files leave the disk only with the folder's later run.
	 */
	private const BACKGROUND_OPTIMIZERS = array( 'wp-smushit', 'wp-smush-pro', 'imagify', 'ewww-image-optimizer', 'ewww-image-optimizer-cloud', 'shortpixel-image-optimiser', 'tiny-compress-images', 'image-optimization', 'robin-image-optimizer', 'kraken-image-optimizer', 'squeeze', 'image-optimizer-wd', 'imagerecycle-pdf-image-compression', 'webp-converter-for-media' );

	/**
	 * Attachment meta of an upload whose sizes the browser makes (WordPress 7.1+ client-side media
	 * processing): "time|YYYY/MM/stem". Its files stay local until the request that finalizes it,
	 * for at most CLIENT_WAIT seconds.
	 */
	private const CLIENT_META = '_simple_storage_client_processing';
	private const CLIENT_WAIT = HOUR_IN_SECONDS;

	/** A folder run cut short this many times in a row is not retried by a lease any more. */
	private const MAX_LEASES = 3;

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

	/**
	 * Remote copies of attachments deleted in this request whose file another attachment still
	 * used at that moment, to be deleted once that is no longer so.
	 *
	 * @var array<int, array{file: string, paths: array<int, string>}>
	 */
	private static array $deferred_deletes = array();

	/** @var array<string, bool> Files this request brought back or worked with, held local. */
	private static array $in_use = array();

	/** Whether this request uploads a file whose sizes the browser makes. */
	private static bool $client_upload = false;

	public static function init(): void {
		add_action( self::OFFLOAD_HOOK, array( self::class, 'offload_dir' ) );
		add_action( self::DELETE_HOOK, array( self::class, 'retry_delete' ), 10, 2 );
		add_filter( 'wp_unique_filename', array( self::class, 'unique_filename' ), 10, 3 );
		add_action( 'delete_attachment', array( self::class, 'delete_attachment' ) );
		add_action( 'deleted_post', array( self::class, 'attachment_deleted' ) );
		add_filter( 'wp_delete_file', array( self::class, 'delete_file' ), 99 );
		add_filter( 'load_image_to_edit_path', array( self::class, 'load_image_to_edit_path' ), 10, 3 );
		add_filter( 'rest_request_before_callbacks', array( self::class, 'note_client_processing' ), 9, 3 );
		add_filter( 'rest_request_after_callbacks', array( self::class, 'client_processing_finalized' ), 10, 3 );
		add_filter( 'rest_request_before_callbacks', array( self::class, 'before_rest_edit' ), 10, 3 );
		add_action( 'add_attachment', array( self::class, 'client_upload_started' ), 5 );
		add_action( 'wp_ajax_regeneratethumbnail', array( self::class, 'before_regenerate_ajax' ), 1 );
		add_action( 'wp_ajax_media-create-image-subsizes', array( self::class, 'before_subsizes_ajax' ), 1 );
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
		Simple_Storage_Runner::schedule_event( time() + $delay, self::OFFLOAD_HOOK, array( $dir ) );
	}

	/**
	 * Cron: move settled local files of one YYYY/MM folder to the storage.
	 *
	 * @param mixed $dir
	 */
	public static function offload_dir( $dir ): void {
		self::offload( (string) $dir, 0.0, null );
	}

	/**
	 * At the end of a request: move the files this request wrote — exactly these paths, complete
	 * by then — to the storage, verified, and remove their local copies right away. Where an image
	 * optimizer works in the background (see remove_at_request_end()) they are only copied, and
	 * their local copies go with the folder's scheduled run once they have settled for a minute.
	 * Everything else in the folder waits for that run as well.
	 *
	 * @param array<int, string> $paths
	 */
	public static function offload_paths_now( string $dir, array $paths, float $request_start, float $wait = 0.0 ): void {
		self::offload( $dir, $request_start, $paths, $wait );
	}

	/**
	 * Whether the files a request wrote leave the disk at its end. Not where an image optimizer
	 * works in the background: it rewrites or adds files in requests of its own after the upload,
	 * and would find them gone, or bring one back under a name the storage already holds. Filter
	 * simple_storage_remove_at_request_end to decide otherwise.
	 */
	public static function remove_at_request_end(): bool {
		return (bool) apply_filters( 'simple_storage_remove_at_request_end', ! self::background_optimizer_active() );
	}

	/** Forget this request's copies of the plugin's state, which another request may have changed. */
	public static function forget_cached_state(): void {
		foreach ( array( 'alloptions', 'notoptions', Simple_Storage_Jobs::OPTION, self::REGENERATING_OPTION ) as $key ) {
			wp_cache_delete( $key, 'options' );
		}
		Simple_Storage_Settings::flush_cache();
	}

	/** Whether an active plugin optimizes or converts uploaded images after the upload request. */
	private static function background_optimizer_active(): bool {
		foreach ( (array) get_option( 'active_plugins', array() ) as $plugin ) {
			if ( in_array( strtok( (string) $plugin, '/' ), self::BACKGROUND_OPTIMIZERS, true ) ) {
				return true;
			}
		}

		return false;
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

	/**
	 * A file brought back from the storage stays local while this request works with it and for
	 * a while after (others may be using it too): the hold lasts as long as a request may run, and
	 * counts the usual delay from the end of this one.
	 */
	public static function keep_local( string $relative ): void {
		$limit = (int) ini_get( 'max_execution_time' );
		set_transient( 'simple_storage_hold_' . md5( $relative ), 1, self::DELAY + max( $limit, 300 ) );
		self::$in_use[ $relative ] = true;
	}

	/** End of a request: the files it kept local are held for the usual delay from now. */
	public static function release_holds_after_request(): void {
		foreach ( array_keys( self::$in_use ) as $relative ) {
			set_transient( 'simple_storage_hold_' . md5( (string) $relative ), 1, self::DELAY );
		}
		self::$in_use = array();
	}

	public static function release_hold( string $relative ): void {
		delete_transient( 'simple_storage_hold_' . md5( $relative ) );
	}

	public static function is_held( string $relative ): bool {
		return (bool) get_transient( 'simple_storage_hold_' . md5( $relative ) );
	}

	/**
	 * @param array<int, string>|null $only Exact paths written by this request, or null for the folder.
	 * @param float                   $wait Seconds to wait for the job lock another request holds.
	 */
	private static function offload( string $dir, float $request_start, ?array $only, float $wait = 0.0 ): void {
		if ( ! self::auto_enabled() || ! Simple_Storage_Paths::is_month_dir( $dir ) ) {
			return;
		}

		// Never alongside a job, even a paused one: its scans and phases assume they are the only
		// ones changing files and the index. The job lock keeps two offloads apart as well.
		if ( Simple_Storage_Jobs::is_active() || self::regeneration_running() ) {
			self::schedule( $dir, 5 * MINUTE_IN_SECONDS );

			return;
		}
		// Uploads of one person overlap (the next file arrives while the last is still moving):
		// after its response, a request waits its turn rather than leaving its files to an event.
		$lock   = Simple_Storage_Jobs::lock();
		$until  = microtime( true ) + $wait;
		$waited = false;
		while ( null === $lock && microtime( true ) < $until ) {
			$waited = true;
			usleep( 500000 );
			self::forget_cached_state();
			if ( Simple_Storage_Jobs::is_active() ) {
				break;
			}
			$lock = Simple_Storage_Jobs::lock();
		}
		if ( null === $lock ) {
			self::schedule( $dir, 5 * MINUTE_IN_SECONDS );

			return;
		}

		try {
			// While this request waited, a job may have started or the site gone back to serving
			// local files: decide again on what the database says now.
			if ( $waited ) {
				self::forget_cached_state();
			}
			if ( $waited && ( ! self::auto_enabled() || Simple_Storage_Jobs::is_active() || self::regeneration_running() ) ) {
				if ( self::auto_enabled() ) {
					self::schedule( $dir, 5 * MINUTE_IN_SECONDS );
				}

				return;
			}
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
				if ( str_starts_with( $relative, $dir . '/' ) && Simple_Storage_Paths::is_media_path( $relative ) && is_file( $local ) ) {
					$files[] = array(
						'path'  => $relative,
						'size'  => (int) filesize( $local ),
						'mtime' => (int) filemtime( $local ),
					);
				}
			}
		}

		$leased     = null === $only && ! empty( $files );
		$own_lease  = $leased && self::take_lease( $dir );
		$deadline   = microtime( true ) + self::BUDGET;
		$remove     = null === $only || self::remove_at_request_end();
		$waiting    = self::client_waiting( $dir );
		$wait_until = 0;
		$pending    = false;
		$failed     = false;
		$moved      = 0;
		$skip       = self::transient_files( $dir );
		foreach ( $files as $file ) {
			if ( isset( $skip[ $file['path'] ] ) ) {
				continue;
			}
			if ( microtime( true ) >= $deadline ) {
				$pending = true;
				break;
			}
			$until = self::waiting_until( $file['path'], $waiting );
			if ( $until > 0 ) {
				// The browser is still making this upload's sizes.
				$wait_until = max( $wait_until, $until );
				continue;
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

			// A file only copied at the end of a request leaves the disk with the folder's run,
			// which keeps the one-minute rule.
			$result = self::offload_file( $client, $file, $remove );
			Simple_Storage_Jobs::heartbeat();
			if ( is_wp_error( $result ) ) {
				$failed = true;
				Simple_Storage_Log::error( $file['path'] . ': ' . $result->get_error_message() );
			} elseif ( ! $remove ) {
				$pending = true;
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
		if ( $wait_until > 0 ) {
			// Should the browser never finish, the files go once the wait runs out.
			Simple_Storage_Runner::schedule_event( $wait_until + MINUTE_IN_SECONDS, self::OFFLOAD_HOOK, array( $dir, 'client' ) );
		}
		if ( $leased ) {
			self::end_lease( $dir, $own_lease );
		}
	}

	/**
	 * A folder run cut short (a time limit, a restart) would leave its work with no event to finish
	 * it: the one that started it is off the schedule by then. A lease event does, unless the run
	 * gets to its end; after MAX_LEASES runs in a row that did not, the folder waits for its next
	 * change instead of being retried every fifteen minutes.
	 *
	 * @return bool Whether this run scheduled the lease it must clear.
	 */
	private static function take_lease( string $dir ): bool {
		$key   = 'simple_storage_lease_' . md5( $dir );
		$count = (int) get_transient( $key );
		if ( $count >= self::MAX_LEASES ) {
			if ( self::MAX_LEASES === $count ) {
				/* translators: %s: folder. */
				Simple_Storage_Log::error( sprintf( __( 'Moving the files of %s to the storage was cut short several times in a row; it is tried again with the next change in that folder.', 'simple-storage' ), $dir ) );
				set_transient( $key, $count + 1, DAY_IN_SECONDS );
			}

			return false;
		}
		set_transient( $key, $count + 1, DAY_IN_SECONDS );

		return Simple_Storage_Runner::schedule_event( time() + 15 * MINUTE_IN_SECONDS, self::OFFLOAD_HOOK, array( $dir, 'lease' ) );
	}

	/** The run got to its end: the folder is healthy, and a lease it scheduled is not needed. */
	private static function end_lease( string $dir, bool $own ): void {
		delete_transient( 'simple_storage_lease_' . md5( $dir ) );
		if ( $own ) {
			Simple_Storage_Runner::clear_event( self::OFFLOAD_HOOK, array( $dir, 'lease' ) );
		}
	}

	/**
	 * @param array{path: string, size: int, mtime: int} $file
	 * @param bool $remove Whether to remove the local copy once the remote one is verified.
	 * @return bool|WP_Error True when the remote copy is verified (and the local one removed).
	 */
	private static function offload_file( Simple_Storage_Client $client, array $file, bool $remove = true ) {
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
		if ( ! $remove ) {
			return true;
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
				// Brought back earlier and in use again: it stays a while longer.
				self::keep_local( $relative );
			}

			return true;
		}

		if ( null === $row || ! $row['remote'] || $row['conflict'] ) {
			return false;
		}

		// Held from before the download lands, so no offload run takes it away in between.
		self::keep_local( $relative );

		$client = Simple_Storage_Client::create();
		$result = is_wp_error( $client ) ? $client : Simple_Storage_Transfer::download( $client, $row );
		if ( is_wp_error( $result ) ) {
			Simple_Storage_Log::error( $relative . ': ' . $result->get_error_message() );

			return false;
		}

		/* translators: %s: file path. */
		Simple_Storage_Log::info( sprintf( __( '%s was brought back from the storage to be processed locally.', 'simple-storage' ), $relative ) );
		if ( self::auto_enabled() ) {
			// The work may save its result elsewhere (or not at all): the folder goes out again.
			self::schedule( Simple_Storage_Paths::month_dir( $relative ) );
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
		// Editing, and WordPress finishing the sizes of an upload whose request died (post-process).
		if ( ! ( 'POST' === $request->get_method() && preg_match( '#^/wp/v2/media/(\d+)/(?:edit|post-process)$#', $route, $match ) )
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

	/** The media modal finishes the sizes of an upload whose request died, from the original. */
	public static function before_subsizes_ajax(): void {
		$attachment_id = (int) ( $_POST['attachment_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		if ( $attachment_id <= 0 || ! current_user_can( 'edit_post', $attachment_id ) ) {
			return;
		}

		self::remember_files( $attachment_id );
		foreach ( self::edit_sources( $attachment_id ) as $relative ) {
			self::ensure_local( $relative );
		}
	}

	/**
	 * WordPress 7.1+ lets the browser make the sizes of an upload: the upload request stores only
	 * the file, sideload requests add the sizes, and a finalize request writes the metadata — where
	 * themes make their WebP/AVIF copies from the files on disk. The files stay local until then.
	 *
	 * @param mixed $response
	 * @param mixed $handler
	 * @param mixed $request
	 * @return mixed
	 */
	public static function note_client_processing( $response, $handler, $request ) {
		if ( ! $request instanceof WP_REST_Request || 'POST' !== $request->get_method() ) {
			return $response;
		}

		if ( '/wp/v2/media' === $request->get_route() && false === $request['generate_sub_sizes'] ) {
			self::$client_upload = true;
		}

		return $response;
	}

	/**
	 * The browser is done once finalize has written the metadata — not before: the hold stays
	 * while its metadata filters (themes making WebP/AVIF copies) read the files, and after a
	 * finalize that failed.
	 *
	 * @param mixed $response
	 * @param mixed $handler
	 * @param mixed $request
	 * @return mixed
	 */
	public static function client_processing_finalized( $response, $handler, $request ) {
		if ( $request instanceof WP_REST_Request && 'POST' === $request->get_method() && preg_match( '#^/wp/v2/media/(\d+)/finalize$#', $request->get_route(), $match )
			&& ! is_wp_error( $response ) && ! ( $response instanceof WP_HTTP_Response && $response->get_status() >= 400 ) && current_user_can( 'edit_post', (int) $match[1] ) ) {
			self::client_processing_done( (int) $match[1] );
		}

		return $response;
	}

	/** @param mixed $attachment_id */
	public static function client_upload_started( $attachment_id ): void {
		if ( ! self::$client_upload ) {
			return;
		}
		self::$client_upload = false;

		$file = (string) get_post_meta( (int) $attachment_id, '_wp_attached_file', true );
		if ( Simple_Storage_Paths::is_media_path( $file ) ) {
			$stem = dirname( $file ) . '/' . pathinfo( $file, PATHINFO_FILENAME );
			update_post_meta( (int) $attachment_id, self::CLIENT_META, time() . '|' . $stem );
		}
	}

	/** The browser is done: the attachment's files go out at the end of this request. */
	public static function client_processing_done( int $attachment_id ): void {
		if ( '' === (string) get_post_meta( $attachment_id, self::CLIENT_META, true ) ) {
			return;
		}

		delete_post_meta( $attachment_id, self::CLIENT_META );
		Simple_Storage_Runner::queue_finished_attachment( $attachment_id );
	}

	/**
	 * Uploads in a folder whose sizes the browser is still making, with until when to wait and the
	 * names of their files: the upload's own variants, the attached file now (core makes a scaled
	 * or rotated sideload the attached file) and every file sideloaded so far.
	 *
	 * @return array<int, array{until: int, stem: string, names: array<string, bool>}>
	 */
	private static function client_waiting( string $dir ): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s", self::CLIENT_META, '%|' . $wpdb->esc_like( $dir . '/' ) . '%' ),
			ARRAY_A
		);

		$waiting = array();
		foreach ( (array) $rows as $row ) {
			list( $since, $stem ) = array_pad( explode( '|', (string) $row['meta_value'], 2 ), 2, '' );
			$until                = (int) $since + self::CLIENT_WAIT;
			if ( '' === $stem || $until <= time() ) {
				continue;
			}

			$names = array( wp_basename( (string) get_post_meta( (int) $row['post_id'], '_wp_attached_file', true ) ) => true );
			foreach ( (array) get_post_meta( (int) $row['post_id'], '_wp_sideloaded_file', false ) as $file ) {
				$names[ wp_basename( (string) $file ) ] = true;
			}
			$waiting[] = array(
				'until' => $until,
				'stem'  => $stem,
				'names' => $names,
			);
		}

		return $waiting;
	}

	/**
	 * Until when a file waits for the browser, 0 when it does not: the upload's own names only
	 * ("photo.jpg", "photo-300x200.jpg", "photo-scaled.jpg", a theme's "photo-jpg.webp"), not
	 * every name that starts the same ("photo-2.jpg" is another upload).
	 *
	 * @param array<int, array{until: int, stem: string, names: array<string, bool>}> $waiting
	 */
	private static function waiting_until( string $relative, array $waiting ): int {
		$name = wp_basename( $relative );
		foreach ( $waiting as $upload ) {
			if ( dirname( $relative ) !== dirname( $upload['stem'] ) ) {
				continue;
			}
			$base = preg_quote( wp_basename( $upload['stem'] ), '/' );
			if ( isset( $upload['names'][ $name ] ) || preg_match( '/^' . $base . '(?:-\d+x\d+|-scaled|-rotated)*(?:\.[A-Za-z0-9]+|-[A-Za-z0-9]+\.(?:webp|avif))$/', $name ) ) {
				return $upload['until'];
			}
		}

		return 0;
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
	public static function regeneration_running(): bool {
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
		if ( ! Simple_Storage_Index::has_remote_files() ) {
			return;
		}

		$files = self::attachment_files( $attachment_id );
		$paths = array_merge( $files, self::derived_files( $files, false ) );
		if ( self::shared_with_other_attachment( $attachment_id ) ) {
			// Another attachment uses the same file — a translation. Multilingual plugins delete
			// all language copies together, each while the others still exist (WP-LOC does it from
			// delete_attachment), so the decision waits until this attachment is gone.
			self::$deferred_deletes[ $attachment_id ] = array(
				'file'  => (string) get_post_meta( $attachment_id, '_wp_attached_file', true ),
				'paths' => $paths,
			);

			return;
		}

		self::delete_remote_copies( $paths );
	}

	/**
	 * An attachment whose file was shared is gone: when no attachment uses the file any more (the
	 * last language copy went with it), its remote copies go too.
	 *
	 * @param mixed $post_id
	 */
	public static function attachment_deleted( $post_id ): void {
		$post_id = (int) $post_id;
		$pending = self::$deferred_deletes[ $post_id ] ?? null;
		unset( self::$deferred_deletes[ $post_id ] );
		if ( null === $pending || '' === $pending['file'] || self::file_in_use( $pending['file'] ) ) {
			return;
		}

		self::delete_remote_copies( $pending['paths'] );
	}

	/** Whether any attachment records this file as its main file. */
	private static function file_in_use( string $file ): bool {
		global $wpdb;

		return (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1", $file )
		);
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
				Simple_Storage_Runner::schedule_event( time() + 10 * MINUTE_IN_SECONDS, self::DELETE_HOOK, array( (string) $row['path'], 1 ) );
			} else {
				Simple_Storage_Prune::schedule( (string) $row['path'] );
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
				Simple_Storage_Runner::schedule_event( time() + 10 * MINUTE_IN_SECONDS, self::DELETE_HOOK, array( $relative, 1 ) );
			} else {
				Simple_Storage_Prune::schedule( $relative );
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
			Simple_Storage_Prune::schedule( $relative );

			return;
		}

		if ( (int) $attempt < 3 ) {
			Simple_Storage_Runner::schedule_event( time() + 30 * MINUTE_IN_SECONDS, self::DELETE_HOOK, array( $relative, (int) $attempt + 1 ) );
		} else {
			/* translators: %s: file path. */
			Simple_Storage_Log::error( sprintf( __( 'Could not delete %s from the storage; delete it manually.', 'simple-storage' ), $relative ) );
		}
	}
}
