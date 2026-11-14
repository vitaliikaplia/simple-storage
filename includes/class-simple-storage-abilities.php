<?php
/**
 * Tools for an AI agent: abilities of the WordPress Abilities API (core 6.9+), marked for MCP, so
 * that Simple MCP (2.7+), the official WordPress MCP Adapter or another client of the API offers
 * them as tools. Without such a client they only sit in the core registry; on an older WordPress
 * nothing is registered. The plugin does not depend on any of them.
 *
 * The abilities are thin: they run what the admin page and WP-CLI run (the jobs, the serving check,
 * the connection test) and report what the overview shows. A job runs in steps, as on the page:
 * the ability that starts it does its first step, and simple-storage/job continues it, because
 * nothing runs a job on its own. Each call is limited to the step budget of the page.
 *
 * The person behind the MCP key or connection needs manage_options, like for the admin page;
 * finding where the files of an attachment are needs upload_files only.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_Storage_Abilities {
	public const CATEGORY = 'simple-storage';
	private const CAPABILITY = 'manage_options';
	private const LOCATE_CAPABILITY = 'upload_files';

	/** Log entries in the status: by default and at most. */
	private const LOG_DEFAULT = 10;
	private const LOG_MAX = 50;

	/** Errors of the index listed in the status. */
	private const ERRORS = 20;

	/** At most this many files of one attachment in a lookup. */
	private const LOCATE_MAX = 200;

	/**
	 * The ability (and its arguments) that does what the overview suggests next, by the action of
	 * Simple_Storage_Admin::next_step().
	 */
	private const NEXT = array(
		'index'         => array( 'simple-storage/index', array() ),
		'verify_remote' => array( 'simple-storage/index', array( 'check_copies' => true ) ),
		'copy_remote'   => array( 'simple-storage/copy', array( 'to' => 'storage' ) ),
		'adopt'         => array( 'simple-storage/switch-serving', array( 'to' => 'storage' ) ),
		'clean_local'   => array( 'simple-storage/delete-copies', array( 'in' => 'wordpress' ) ),
		'copy_local'    => array( 'simple-storage/copy', array( 'to' => 'wordpress' ) ),
		'switch_local'  => array( 'simple-storage/switch-serving', array( 'to' => 'wordpress' ) ),
		'clean_remote'  => array( 'simple-storage/delete-copies', array( 'in' => 'storage' ) ),
		'verify'        => array( 'simple-storage/check-serving', array() ),
	);

	public static function init(): void {
		if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		// Both fire lazily, the first time something reads the registry after init: the order in
		// which plugins load does not matter.
		add_action( 'wp_abilities_api_categories_init', array( self::class, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( self::class, 'register' ) );
	}

	public static function register_category(): void {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Simple Storage', 'simple-storage' ),
				'description' => __( 'The media files of the site in the Hosting Ukraine storage: where they are, serving from the storage, moving them there and back.', 'simple-storage' ),
			)
		);
	}

	public static function register(): void {
		foreach ( self::definitions() as $name => $args ) {
			wp_register_ability( $name, $args );
		}
	}

	/**
	 * Every ability with its arguments for wp_register_ability().
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions(): array {
		$admin  = static fn(): bool => current_user_can( self::CAPABILITY );
		$hints  = static fn( bool $readonly, bool $destructive, bool $idempotent ): array => array(
			'mcp'         => array( 'public' => true ),
			'annotations' => array(
				'readonly'    => $readonly,
				'destructive' => $destructive,
				'idempotent'  => $idempotent,
			),
		);
		$object = static function ( array $properties, array $required = array() ): array {
			$schema = array(
				'type'                 => 'object',
				'properties'           => $properties,
				'additionalProperties' => false,
			);
			if ( $required ) {
				$schema['required'] = $required;
			} else {
				// A call without arguments is valid too (WP_Ability::normalize_input()).
				$schema['default'] = array();
			}

			return $schema;
		};
		$runs_as_job = __( 'Runs as a job: this call does its first part, and simple-storage/job with the action "continue" does the rest.', 'simple-storage' );

		$definitions = array(
			'simple-storage/status'          => array(
				'label'               => __( 'Storage status', 'simple-storage' ),
				'description'         => __( 'Where the media files of this site are and how the site serves them: whether the storage is connected, whether visitors get the files from the storage or from WordPress (and why serving does not work, when it does not), where new uploads go, how many files are only in WordPress, in both places or only in the storage, the files with errors, the current or last job with its progress and report, recent log entries, and the suggested next step with the ability and arguments that do it. Read-only. Call it first, and again after every job.', 'simple-storage' ),
				'input_schema'        => $object(
					array(
						'log' => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'maximum'     => self::LOG_MAX,
							'default'     => self::LOG_DEFAULT,
							/* translators: %d: the largest number of log entries. */
							'description' => sprintf( __( 'How many recent log entries to include, newest first (0 to %d).', 'simple-storage' ), self::LOG_MAX ),
						),
					)
				),
				'execute_callback'    => array( self::class, 'status' ),
				'permission_callback' => $admin,
				'meta'                => $hints( true, false, true ),
			),
			'simple-storage/locate'          => array(
				'label'               => __( 'Where media files are', 'simple-storage' ),
				'description'         => __( 'Finds the files of one attachment (attachment_id: the original, every size, the WebP/AVIF copies) or one media file (path) and tells for each whether it is in WordPress, in the storage and verified there, and where visitors get it from, with its addresses. Use it when an image or a document does not show. Give attachment_id or path. Read-only; the storage side is what the last indexing saw.', 'simple-storage' ),
				'input_schema'        => $object(
					array(
						'attachment_id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'ID of an attachment in the Media Library.', 'simple-storage' ),
						),
						'path'          => array(
							'type'        => 'string',
							'minLength'   => 1,
							'maxLength'   => 2048,
							'description' => __( 'A media file: its path inside uploads ("2024/05/photo.jpg") or its full address on the site or in the storage.', 'simple-storage' ),
						),
					)
				),
				'execute_callback'    => array( self::class, 'locate' ),
				'permission_callback' => static fn(): bool => current_user_can( self::LOCATE_CAPABILITY ),
				'meta'                => $hints( true, false, true ),
			),
			'simple-storage/test-connection' => array(
				'label'               => __( 'Test the storage connection', 'simple-storage' ),
				'description'         => __( 'Tests the connection to the storage with a real round trip: logs in, uploads a small test file into a folder of its own, checks its size and its public address, and deletes it. Real media files are never touched. Returns every step and what failed.', 'simple-storage' ),
				'input_schema'        => $object( array() ),
				'execute_callback'    => array( self::class, 'test_connection' ),
				'permission_callback' => $admin,
				'meta'                => $hints( false, false, true ),
			),
			'simple-storage/check-serving'   => array(
				'label'               => __( 'Check serving from the storage', 'simple-storage' ),
				'description'         => __( 'While the site serves its files from the storage: refreshes the rules in uploads/.htaccess and the proxy settings, then requests a test file that exists only in the storage at its site address, once for every file extension in the storage. Returns the extensions that are served, those that fail and, when nothing is served, what the server does wrong and how to fix it. Files of a failing extension stay in WordPress. Nothing is copied or deleted.', 'simple-storage' ),
				'input_schema'        => $object( array() ),
				'execute_callback'    => array( self::class, 'check_serving' ),
				'permission_callback' => $admin,
				'meta'                => $hints( false, false, true ),
			),
			'simple-storage/index'           => array(
				'label'               => __( 'Index media files', 'simple-storage' ),
				'description'         => __( 'Starts indexing: lists the media files in WordPress and in the storage and records where each one is, so that the status and the other jobs see the current state. Nothing is copied, deleted or switched.', 'simple-storage' ) . ' ' . $runs_as_job,
				'input_schema'        => $object(
					array(
						'check_copies'    => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Also compare the contents of the local files the storage already holds under the same names (uploaded by hand, for example), so that they count as copies. Slower: it downloads them.', 'simple-storage' ),
						),
						'confirm_missing' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Ask the storage about every recorded file its listing did not show, however many there are, and forget those it no longer has.', 'simple-storage' ),
						),
					)
				),
				'execute_callback'    => array( self::class, 'index' ),
				'permission_callback' => $admin,
				'meta'                => $hints( false, false, false ),
			),
			'simple-storage/copy'            => array(
				'label'               => __( 'Copy media files', 'simple-storage' ),
				'description'         => __( 'Starts copying every file that is only on one side to the other: to "storage" from WordPress, or to "wordpress" from the storage. Every copy is verified; nothing is deleted, and the site keeps serving its files as before. After copying to "wordpress", new uploads stay in WordPress.', 'simple-storage' ) . ' ' . $runs_as_job,
				'input_schema'        => $object(
					array(
						'to' => array(
							'type'        => 'string',
							'enum'        => array( 'storage', 'wordpress' ),
							'description' => __( 'Where the files are copied: "storage" or "wordpress".', 'simple-storage' ),
						),
					),
					array( 'to' )
				),
				'execute_callback'    => array( self::class, 'copy' ),
				'permission_callback' => $admin,
				'meta'                => $hints( false, false, false ),
			),
			'simple-storage/switch-serving'  => array(
				'label'               => __( 'Switch where the files are served from', 'simple-storage' ),
				'description'         => __( 'Changes where visitors get the media files from, so ask a person first: "storage" turns serving from the storage on, "wordpress" turns it off. The job first checks that every file of the Media Library is on that side; when one is missing, it changes nothing and lists them. accept_missing switches anyway, and those files stay broken until they are uploaded; files that exist only on the side being left are never accepted. Nothing is copied or deleted.', 'simple-storage' ) . ' ' . $runs_as_job,
				'input_schema'        => $object(
					array(
						'to'             => array(
							'type'        => 'string',
							'enum'        => array( 'storage', 'wordpress' ),
							'description' => __( 'Where the site serves its files from: "storage" or "wordpress".', 'simple-storage' ),
						),
						'accept_missing' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Switch although files of the Media Library are missing. Only after a person agreed.', 'simple-storage' ),
						),
					),
					array( 'to' )
				),
				'execute_callback'    => array( self::class, 'switch_serving' ),
				'permission_callback' => $admin,
				'meta'                => $hints( false, true, false ),
			),
			'simple-storage/delete-copies'   => array(
				'label'               => __( 'Delete the copies on one side', 'simple-storage' ),
				'description'         => __( 'Deletes files for good, so ask a person first: the copies in "wordpress" (frees the disk of the hosting; the site then serves the files from the storage) or in "storage" (the site then serves them from WordPress). It deletes only when every file has a verified copy on the other side; otherwise it deletes nothing and lists the files without one. Before deleting in WordPress, it turns serving from the storage on and checks it.', 'simple-storage' ) . ' ' . $runs_as_job,
				'input_schema'        => $object(
					array(
						'in' => array(
							'type'        => 'string',
							'enum'        => array( 'wordpress', 'storage' ),
							'description' => __( 'Where the copies are deleted: "wordpress" or "storage".', 'simple-storage' ),
						),
					),
					array( 'in' )
				),
				'execute_callback'    => array( self::class, 'delete_copies' ),
				'permission_callback' => $admin,
				'meta'                => $hints( false, true, false ),
			),
			'simple-storage/job'             => array(
				'label'               => __( 'Continue or stop the job', 'simple-storage' ),
				'description'         => __( 'Works with the current job, which another Simple Storage ability started. "continue" runs it for up to about 20 seconds and returns its progress; call it again while the status is "running": nothing runs the job in between, and new uploads wait in WordPress until it ends. "resume" continues a paused job, or one stopped by an error once the cause is fixed. "pause" pauses it, and "cancel" stops it: files already processed stay where they are. Starts no new work.', 'simple-storage' ),
				'input_schema'        => $object(
					array(
						'action' => array(
							'type'        => 'string',
							'enum'        => array( 'continue', 'resume', 'pause', 'cancel' ),
							'default'     => 'continue',
							'description' => __( 'continue (the default), resume, pause or cancel.', 'simple-storage' ),
						),
					)
				),
				'execute_callback'    => array( self::class, 'job' ),
				'permission_callback' => $admin,
				'meta'                => $hints( false, false, false ),
			),
		);

		foreach ( $definitions as $name => $args ) {
			$definitions[ $name ] = array( 'category' => self::CATEGORY ) + $args;
		}

		return $definitions;
	}

	// ── Callbacks ──────────────────────────────────────────────────────────

	/**
	 * @param mixed $input
	 * @return array<string, mixed>
	 */
	public static function status( $input = array() ): array {
		$input    = is_array( $input ) ? $input : array();
		$lines    = max( 0, min( self::LOG_MAX, (int) ( $input['log'] ?? self::LOG_DEFAULT ) ) );
		$settings = Simple_Storage_Settings::state();
		$stats    = Simple_Storage_Index::stats();
		$listed   = Simple_Storage_Index::report( 'listed_unchecked', 0, (int) $settings['last_remote_scan'] );
		$serving  = Simple_Storage_Delivery::active();
		$proxy    = 'proxy' === Simple_Storage_Settings::delivery_mode();

		$uploads = 'wordpress';
		if ( Simple_Storage_Media::auto_enabled() ) {
			$uploads = Simple_Storage_Jobs::is_active() ? 'wordpress_until_the_job_ends' : 'storage';
		}

		$log = array();
		if ( $lines > 0 ) {
			foreach ( array_reverse( array_slice( Simple_Storage_Log::entries(), -$lines ) ) as $entry ) {
				$log[] = array(
					'time'    => self::iso_time( (int) $entry['time'] ),
					'level'   => (string) $entry['level'],
					'message' => (string) $entry['message'],
				);
			}
		}

		return array(
			'configured'  => Simple_Storage_Settings::is_configured(),
			'connection'  => array(
				'works'     => ! empty( $settings['last_test']['ok'] ),
				'tested_at' => self::iso_time( (int) ( $settings['last_test']['time'] ?? 0 ) ),
			),
			'serving'     => array(
				'from'                => $serving ? 'storage' : 'wordpress',
				'mode'                => $proxy ? 'proxy' : 'direct',
				'proxy_engine'        => $serving && $proxy ? Simple_Storage_Delivery::proxy_engine() : '',
				'verified_extensions' => $serving ? Simple_Storage_Delivery::verified_extensions() : array(),
				'htaccess_rules'      => $serving ? Simple_Storage_Delivery::htaccess_status() : '',
				'problem'             => $serving ? (string) $settings['delivery_problem'] : '',
			),
			'new_uploads' => $uploads,
			'files'       => array(
				'indexed_at'       => self::iso_time( (int) $settings['last_index'] ),
				'total'            => self::count( $stats['total'] ),
				'only_wordpress'   => self::count( $stats['local_only'] ),
				'both'             => self::count( $stats['both'] ),
				'only_storage'     => self::count( $stats['remote_only'] ),
				// Local files the storage holds under the same name and size, not compared yet.
				'unchecked_copies' => self::count(
					array(
						'files' => $listed['count'],
						'bytes' => $listed['bytes'],
					)
				),
				'with_errors'      => (int) $stats['errors'],
			),
			'next_step'   => self::next_step( Simple_Storage_Admin::situation( $settings, $stats ) ),
			'job'         => self::job_view( Simple_Storage_Jobs::current() ),
			'errors'      => Simple_Storage_Index::errors( self::ERRORS ),
			'log'         => $log,
			'admin_page'  => Simple_Storage_Admin::url(),
		);
	}

	/**
	 * @param mixed $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function locate( $input = array() ) {
		$input         = is_array( $input ) ? $input : array();
		$attachment_id = (int) ( $input['attachment_id'] ?? 0 );
		$path          = trim( (string) ( $input['path'] ?? '' ) );
		if ( ( $attachment_id > 0 ) === ( '' !== $path ) ) {
			return new WP_Error( 'simple_storage_locate_input', __( 'Give either attachment_id or path.', 'simple-storage' ) );
		}

		$result = array();
		if ( $attachment_id > 0 ) {
			if ( 'attachment' !== get_post_type( $attachment_id ) ) {
				/* translators: %d: attachment ID. */
				return new WP_Error( 'simple_storage_locate_attachment', sprintf( __( 'There is no attachment with ID %d.', 'simple-storage' ), $attachment_id ) );
			}
			$attached = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
			$expected = Simple_Storage_Media::expected_files( $attachment_id );
			$result   = array(
				'attachment_id' => $attachment_id,
				'attached_file' => $attached,
			);
			if ( ! $expected ) {
				$result['files'] = array();
				$result['note']  = __( 'The attachment has no file in the year and month folders of uploads. The plugin moves only those; the rest stays in WordPress.', 'simple-storage' );

				return $result;
			}
			$paths = array_slice( array_map( 'strval', array_keys( $expected ) ), 0, self::LOCATE_MAX );
		} else {
			$relative = self::relative_path( $path );
			if ( null === $relative ) {
				return new WP_Error( 'simple_storage_locate_path', __( 'This is not a file in the year and month folders of uploads ("2024/05/photo.jpg"), on the site or in the storage. The plugin moves only those files; the rest stays in WordPress.', 'simple-storage' ) );
			}
			$expected = array( $relative => null );
			$paths    = array( $relative );
		}

		$rows    = Simple_Storage_Index::by_paths( $paths );
		$serving = Simple_Storage_Delivery::active();
		$base    = untrailingslashit( Simple_Storage_Paths::uploads()['baseurl'] );
		$files   = array();
		foreach ( $paths as $relative ) {
			$row        = $rows[ $relative ] ?? null;
			$on_disk    = file_exists( Simple_Storage_Paths::local( $relative ) );
			$in_storage = null !== $row && ! empty( $row['remote'] );
			// A file still in WordPress is served from there, whatever the mode.
			$served = $on_disk ? 'wordpress' : ( $in_storage && $serving ? 'storage' : 'nowhere' );

			$file = array(
				'path'          => $relative,
				'in_wordpress'  => $on_disk,
				'in_storage'    => $in_storage,
				'verified'      => null !== $row && ! empty( $row['verified'] ),
				'indexed'       => null !== $row,
				'size'          => null !== $row ? (int) $row['size'] : ( $on_disk ? (int) filesize( Simple_Storage_Paths::local( $relative ) ) : null ),
				'expected_size' => $expected[ $relative ] ?? null,
				'served_from'   => $served,
				'url'           => $base . '/' . Simple_Storage_Paths::encode( $relative ),
				'storage_url'   => $in_storage && Simple_Storage_Settings::is_configured() ? Simple_Storage_Paths::public_url( $relative ) : '',
				'excluded'      => Simple_Storage_Paths::is_excluded( $relative ),
				'conflict'      => null !== $row && ! empty( $row['conflict'] ),
				'error'         => null !== $row ? (string) ( $row['error'] ?? '' ) : '',
			);
			$files[] = $file;
		}

		$result['files'] = $files;
		$missing         = count( array_filter( $files, static fn( array $file ): bool => 'nowhere' === $file['served_from'] ) );
		if ( $missing > 0 ) {
			$result['note'] = $serving
				/* translators: %d: number of files. */
				? sprintf( __( '%d files are neither in WordPress nor in the storage, as far as the last indexing saw. Index again if they were uploaded since; otherwise they have to be uploaded.', 'simple-storage' ), $missing )
				/* translators: %d: number of files. */
				: sprintf( __( '%d files are not in WordPress, and the site does not serve files from the storage.', 'simple-storage' ), $missing );
		}

		return $result;
	}

	/** @return array<string, mixed> */
	public static function test_connection(): array {
		$report = Simple_Storage_Tester::run();

		return array(
			'works'     => (bool) $report['ok'],
			'tested_at' => self::iso_time( (int) $report['time'] ),
			'steps'     => array_values( $report['steps'] ),
		);
	}

	/** @return array<string, mixed>|WP_Error */
	public static function check_serving() {
		if ( ! Simple_Storage_Settings::is_configured() ) {
			return new WP_Error( 'simple_storage_not_configured', __( 'The storage connection is not configured.', 'simple-storage' ) );
		}

		$report = Simple_Storage_Delivery::verify_current();
		if ( is_wp_error( $report ) ) {
			return $report;
		}

		$failed = array();
		foreach ( (array) $report['failed'] as $extension => $message ) {
			$failed[] = array(
				'extension' => (string) $extension,
				'message'   => (string) $message,
			);
		}
		$result = array(
			'works'  => ! empty( $report['ok'] ),
			'served' => array_values( array_map( 'strval', (array) $report['ok'] ) ),
			'failed' => $failed,
		);
		if ( empty( $report['ok'] ) ) {
			// Also kept for the overview and the status, until a check passes.
			$result['problem'] = Simple_Storage_Delivery::explain( $report );
		} elseif ( $failed ) {
			/* translators: %s: list of file extensions. */
			$result['note'] = sprintf( __( 'Serving does not work for these extensions, so their files stay local: %s. See the log for details.', 'simple-storage' ), implode( ', ', array_column( $failed, 'extension' ) ) );
		}

		return $result;
	}

	/**
	 * @param mixed $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function index( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		if ( ! empty( $input['check_copies'] ) ) {
			return self::start( 'verify_remote' );
		}

		return self::start( 'index', array( 'confirm_missing' => ! empty( $input['confirm_missing'] ) ) );
	}

	/**
	 * @param mixed $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function copy( $input = array() ) {
		$input = is_array( $input ) ? $input : array();

		return self::start( 'wordpress' === ( $input['to'] ?? '' ) ? 'copy_local' : 'copy_remote' );
	}

	/**
	 * @param mixed $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function switch_serving( $input = array() ) {
		$input = is_array( $input ) ? $input : array();

		return self::start( 'wordpress' === ( $input['to'] ?? '' ) ? 'switch_local' : 'adopt', array( 'accept_missing' => ! empty( $input['accept_missing'] ) ) );
	}

	/**
	 * @param mixed $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function delete_copies( $input = array() ) {
		$input = is_array( $input ) ? $input : array();

		return self::start( 'storage' === ( $input['in'] ?? '' ) ? 'clean_remote' : 'clean_local' );
	}

	/**
	 * @param mixed $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function job( $input = array() ) {
		$input  = is_array( $input ) ? $input : array();
		$action = (string) ( $input['action'] ?? 'continue' );
		$job    = Simple_Storage_Jobs::current();

		if ( 'pause' === $action ) {
			Simple_Storage_Jobs::pause();

			return self::result( Simple_Storage_Jobs::current() );
		}
		if ( 'cancel' === $action ) {
			Simple_Storage_Jobs::cancel();

			return self::result( Simple_Storage_Jobs::current() );
		}
		if ( 'resume' === $action ) {
			$resumed = Simple_Storage_Jobs::resume();
			if ( is_wp_error( $resumed ) ) {
				return $resumed;
			}

			return self::step();
		}

		if ( null !== $job && in_array( $job['status'], array( 'paused', 'failed' ), true ) ) {
			// Nothing to continue until it is resumed: say how.
			return new WP_Error( 'simple_storage_job_paused', self::hint( $job, null ) );
		}

		return self::step();
	}

	// ── Helpers ────────────────────────────────────────────────────────────

	/**
	 * Start a job and do its first step, as the page does after its button.
	 *
	 * @param array<string, mixed> $options
	 * @return array<string, mixed>|WP_Error
	 */
	private static function start( string $type, array $options = array() ) {
		$job = Simple_Storage_Jobs::start( $type, $options );
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		return self::step();
	}

	/** @return array<string, mixed>|WP_Error */
	private static function step() {
		$job = Simple_Storage_Jobs::step( Simple_Storage_Admin::budget() );
		if ( is_wp_error( $job ) ) {
			if ( 'simple_storage_job_locked' !== $job->get_error_code() ) {
				return $job;
			}
			// The admin page, WP-CLI or another call works on it right now: report where it is.
			$result         = self::result( Simple_Storage_Jobs::current() );
			$result['note'] = $job->get_error_message();

			return $result;
		}

		return self::result( $job );
	}

	/**
	 * @param array<string, mixed>|null $job
	 * @return array<string, mixed>
	 */
	private static function result( ?array $job ): array {
		return array(
			'job'       => self::job_view( $job ),
			'next_step' => null !== $job && 'done' === $job['status'] ? self::next_step( Simple_Storage_Admin::situation( Simple_Storage_Settings::state(), Simple_Storage_Index::stats() ) ) : null,
		);
	}

	/**
	 * The job as the page shows it, with what the agent does next.
	 *
	 * @param array<string, mixed>|null $job
	 * @return array<string, mixed>|null
	 */
	private static function job_view( ?array $job ): ?array {
		if ( null === $job ) {
			return null;
		}

		$view         = (array) Simple_Storage_Admin::job_view( $job );
		$view['hint'] = self::hint( $job, $view['report'] ?? null );

		return $view;
	}

	/**
	 * @param array<string, mixed>      $job
	 * @param array<string, mixed>|null $report Simple_Storage_Jobs::report_view().
	 */
	public static function hint( array $job, ?array $report ): string {
		switch ( (string) $job['status'] ) {
			case 'running':
				return __( 'The job is running. Call simple-storage/job with the action "continue" until its status is no longer "running": nothing runs it in between, and new uploads wait in WordPress until it ends.', 'simple-storage' );
			case 'paused':
				return __( 'The job is paused. Call simple-storage/job with the action "resume" to continue it, or "cancel".', 'simple-storage' );
			case 'failed':
				return __( 'The job stopped on an error (see its message). Fix the cause, then call simple-storage/job with the action "resume", or "cancel" it.', 'simple-storage' );
			case 'cancelled':
				return __( 'The job was cancelled. Files that were already processed stay where they are now.', 'simple-storage' );
		}

		if ( ! empty( $report['blocked'] ) ) {
			if ( ! empty( $report['canAccept'] ) ) {
				return __( 'Nothing was switched, because files are missing (the report lists them). Ask a person whether to switch anyway; if they agree, call simple-storage/switch-serving again with accept_missing.', 'simple-storage' );
			}
			if ( in_array( $job['type'], array( 'clean_local', 'clean_remote' ), true ) ) {
				return __( 'Nothing was deleted, because some files have no copy on the other side (the report lists them). Copy them first with simple-storage/copy.', 'simple-storage' );
			}

			return __( 'Nothing was changed; the report says why.', 'simple-storage' );
		}
		if ( ! empty( $job['delivery_failed'] ) ) {
			return __( 'Switched, but the site does not serve the files from the storage yet; the message says why. Once the server is fixed, call simple-storage/check-serving.', 'simple-storage' );
		}

		return __( 'The job is finished. The next step says what comes after it.', 'simple-storage' );
	}

	/**
	 * What the overview suggests next, with the ability that does it.
	 *
	 * @param array<string, mixed> $situation Simple_Storage_Admin::situation().
	 * @return array<string, mixed>|null Null while a job is unfinished.
	 */
	public static function next_step( array $situation ): ?array {
		$next = Simple_Storage_Admin::next_step( $situation );
		if ( null === $next ) {
			return null;
		}

		$step = array(
			'text' => $next['text'],
			'tone' => $next['tone'],
		);
		if ( isset( self::NEXT[ $next['action'] ] ) ) {
			$step['ability']   = self::NEXT[ $next['action'] ][0];
			$step['arguments'] = (object) self::NEXT[ $next['action'] ][1];
		} elseif ( 'settings' === $next['action'] ) {
			// The address, login and password are given by a person, on the settings page.
			$step['settings_page'] = Simple_Storage_Admin::url( 'settings' );
		}

		return $step;
	}

	/**
	 * Uploads-relative media path of a path or an address on the site or in the storage.
	 */
	public static function relative_path( string $value ): ?string {
		$value = trim( $value );
		if ( preg_match( '#^(?:https?:)?//#i', $value ) ) {
			$relative = Simple_Storage_Paths::relative_from_url( $value );
			if ( null === $relative && Simple_Storage_Settings::is_configured() ) {
				$strip  = static fn( string $url ): string => (string) preg_replace( '#^(?:https?:)?//#i', '//', $url );
				$prefix = $strip( Simple_Storage_Paths::public_prefix() );
				$url    = $strip( $value );
				if ( str_starts_with( $url, $prefix ) ) {
					$candidate = rawurldecode( (string) preg_replace( '/[?#].*$/', '', substr( $url, strlen( $prefix ) ) ) );
					$relative  = Simple_Storage_Paths::is_media_path( $candidate ) ? $candidate : null;
				}
			}

			return $relative;
		}

		$value = ltrim( $value, '/' );
		$base  = ltrim( Simple_Storage_Paths::uploads_url_path(), '/' ) . '/';
		if ( '/' !== $base && str_starts_with( $value, $base ) ) {
			$value = substr( $value, strlen( $base ) );
		}

		return Simple_Storage_Paths::is_media_path( $value ) ? $value : null;
	}

	/**
	 * @param array{files: int, bytes: int} $item
	 * @return array{files: int, bytes: int, size: string}
	 */
	private static function count( array $item ): array {
		return array(
			'files' => (int) $item['files'],
			'bytes' => (int) $item['bytes'],
			'size'  => html_entity_decode( size_format( (int) $item['bytes'], 1 ) ?: '0 B', ENT_QUOTES, 'UTF-8' ),
		);
	}

	/** ISO 8601 in the site's time zone, or "" for never. */
	private static function iso_time( int $timestamp ): string {
		return $timestamp > 0 ? (string) wp_date( 'c', $timestamp ) : '';
	}
}
