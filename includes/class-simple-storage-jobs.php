<?php
/**
 * Resumable jobs driven in short steps by the admin page (AJAX) or WP-CLI.
 *
 * index — scan the uploads folders (and the storage, when configured) into the index;
 * push  — copy everything to the storage, verify it, enable serving from it, delete local copies;
 * pull  — download everything back, verify it, delete the remote copies, disable serving.
 *
 * A step holds a database lock, works until its time budget runs out and saves the job, so a
 * closed tab or a failed request only pauses the job.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_Storage_Jobs {
	public const OPTION = 'simple_storage_job';
	private const LOCK_OPTION = 'simple_storage_lock';
	private const LOCK_TTL = 300;
	private const SCAN_SEQ_OPTION = 'simple_storage_scan_seq';
	private const BATCH = 20;

	private const REMOTE_PHASES = array( 'remote_dirs', 'remote_scan', 'remote_cleanup' );

	private const PHASES = array(
		'index' => array( 'local_dirs', 'local_scan', 'local_cleanup', 'remote_dirs', 'remote_scan', 'remote_cleanup' ),
		'push'  => array( 'check', 'local_dirs', 'local_scan', 'local_cleanup', 'upload', 'verify', 'enable_delivery', 'check_delivery', 'delete_local', 'finish_push' ),
		'pull'  => array( 'check', 'remote_dirs', 'remote_scan', 'remote_cleanup', 'download', 'delete_remote', 'prune_remote', 'finish_pull' ),
	);

	/** Rows that a remote scan did not list are confirmed one by one only up to this many. */
	private const UNSEEN_CONFIRM_LIMIT = 200;

	/** Token of the lock this request holds, for the heartbeat. */
	private static ?string $held = null;
	private static int $beat = 0;

	private ?Simple_Storage_Client $client = null;

	/** @var array<string, mixed> */
	private array $job;

	/** @param array<string, mixed> $job */
	private function __construct( array $job ) {
		$this->job = $job;
	}

	/** @return array<string, mixed>|null */
	public static function current(): ?array {
		$job = get_option( self::OPTION, null );

		return is_array( $job ) && isset( $job['type'] ) ? $job : null;
	}

	public static function is_active(): bool {
		$job = self::current();

		return null !== $job && in_array( $job['status'], array( 'running', 'paused', 'failed' ), true );
	}

	/** @return array<string, mixed>|WP_Error */
	public static function start( string $type ) {
		if ( ! isset( self::PHASES[ $type ] ) ) {
			return new WP_Error( 'simple_storage_job_type', __( 'Unknown job.', 'simple-storage' ) );
		}
		if ( self::is_active() ) {
			return new WP_Error( 'simple_storage_job_active', __( 'Another job is not finished yet. Continue or cancel it first.', 'simple-storage' ) );
		}
		if ( 'index' !== $type && ! Simple_Storage_Settings::is_configured() ) {
			return new WP_Error( 'simple_storage_not_configured', __( 'The storage connection is not configured.', 'simple-storage' ) );
		}

		$phases = self::PHASES[ $type ];
		if ( 'index' === $type && ! Simple_Storage_Settings::is_configured() ) {
			$phases = array_values( array_diff( $phases, self::REMOTE_PHASES ) );
		}

		$job = array(
			'id'          => wp_generate_password( 12, false ),
			'type'        => $type,
			'phases'      => $phases,
			'phase'       => 0,
			'status'      => 'running',
			'cursor'      => 0,
			'dirs'        => array(),
			'dir_pos'     => 0,
			'local_scan'  => 0,
			'remote_scan' => 0,
			'remote_seen' => 0,
			'progress'    => array(),
			'warnings'    => array(),
			'message'     => '',
			'current'     => '',
			'started_at'  => time(),
			'updated_at'  => time(),
			'finished_at' => 0,
		);

		if ( 'pull' === $type ) {
			// New uploads must stay local from now on, or they would race the download.
			Simple_Storage_Settings::update_state( array( 'mode' => 'local' ) );
		}

		update_option( self::OPTION, $job, false );
		Simple_Storage_Log::info( self::type_label( $type ) . ': ' . __( 'started.', 'simple-storage' ) );

		return $job;
	}

	/** @return array<string, mixed>|WP_Error */
	public static function resume() {
		$job = self::current();
		if ( null === $job || ! in_array( $job['status'], array( 'paused', 'failed' ), true ) ) {
			return new WP_Error( 'simple_storage_job_none', __( 'There is no paused job.', 'simple-storage' ) );
		}

		$job['status']  = 'running';
		$job['message'] = '';
		update_option( self::OPTION, $job, false );

		return $job;
	}

	public static function pause(): void {
		$job = self::current();
		if ( null !== $job && 'running' === $job['status'] ) {
			$job['status'] = 'paused';
			update_option( self::OPTION, $job, false );
		}
	}

	public static function cancel(): void {
		$job = self::current();
		if ( null !== $job && in_array( $job['status'], array( 'running', 'paused', 'failed' ), true ) ) {
			$job['status']      = 'cancelled';
			$job['finished_at'] = time();
			update_option( self::OPTION, $job, false );
			Simple_Storage_Log::warning( self::type_label( (string) $job['type'] ) . ': ' . __( 'cancelled.', 'simple-storage' ) );
		}
	}

	/**
	 * Run the current job for up to $budget seconds.
	 *
	 * @return array<string, mixed>|WP_Error The job after the step.
	 */
	public static function step( float $budget ) {
		$job = self::current();
		if ( null === $job || 'running' !== $job['status'] ) {
			return new WP_Error( 'simple_storage_job_none', __( 'There is no running job.', 'simple-storage' ) );
		}

		$lock = self::lock();
		if ( null === $lock ) {
			return new WP_Error( 'simple_storage_job_locked', __( 'The job is already being processed in another request.', 'simple-storage' ) );
		}

		$job = self::current();
		if ( null === $job || 'running' !== $job['status'] ) {
			self::unlock( $lock );

			return new WP_Error( 'simple_storage_job_none', __( 'There is no running job.', 'simple-storage' ) );
		}

		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( (int) max( 120, $budget * 4 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		try {
			$runner = new self( $job );
			$runner->run( microtime( true ) + $budget );
			$job = $runner->job;
		} finally {
			self::unlock( $lock );
		}

		return $job;
	}

	/** @param float $deadline microtime(true) after which no new file is started. */
	private function run( float $deadline ): void {
		while ( 'running' === $this->job['status'] && microtime( true ) < $deadline ) {
			$stored = self::stored_status( (string) $this->job['id'] );
			if ( 'running' !== $stored ) {
				// Paused or cancelled from another request, or replaced by another job.
				$this->job['status'] = $stored;
				break;
			}

			$phase = $this->job['phases'][ $this->job['phase'] ] ?? null;
			if ( null === $phase ) {
				$this->finish();
				break;
			}

			if ( ! isset( $this->job['progress'][ $phase ] ) ) {
				$this->enter( $phase );
			}

			$result = $this->{'phase_' . $phase}( $deadline );
			if ( is_wp_error( $result ) ) {
				$this->job['status']  = 'failed';
				$this->job['message'] = $result->get_error_message();
				Simple_Storage_Log::error( self::type_label( (string) $this->job['type'] ) . ': ' . $result->get_error_message() );
				break;
			}

			if ( true === $result ) {
				$this->complete_phase( $phase );
			}

			$this->save();
		}

		$this->save();
	}

	private function enter( string $phase ): void {
		$totals = array(
			'files' => 0,
			'bytes' => 0,
		);

		switch ( $phase ) {
			case 'upload':
				$totals = Simple_Storage_Index::totals( array( 'local' => 1, 'remote' => 0 ) );
				break;
			case 'verify':
				$totals = Simple_Storage_Index::totals( array( 'local' => 1, 'remote' => 1, 'verified' => 0 ) );
				break;
			case 'delete_local':
				$totals = Simple_Storage_Index::totals( array( 'local' => 1, 'remote' => 1, 'verified' => 1 ) );
				break;
			case 'download':
				$totals = Simple_Storage_Index::totals( array( 'local' => 0, 'remote' => 1 ) );
				break;
			case 'delete_remote':
				$totals = Simple_Storage_Index::totals( array( 'local' => 1, 'remote' => 1 ) );
				break;
		}

		$this->job['cursor']             = 0;
		$this->job['progress'][ $phase ] = array(
			'total'       => $totals['files'],
			'total_bytes' => $totals['bytes'],
			'done'        => 0,
			'bytes'       => 0,
			'failed'      => 0,
		);
	}

	private function complete_phase( string $phase ): void {
		$progress = $this->job['progress'][ $phase ] ?? array();
		if ( ! empty( $progress['failed'] ) ) {
			Simple_Storage_Log::warning(
				/* translators: 1: phase name, 2: number of files. */
				sprintf( __( '%1$s: %2$d files failed; see the error list.', 'simple-storage' ), self::phase_label( $phase ), (int) $progress['failed'] )
			);
		}

		++$this->job['phase'];
		$this->job['cursor']  = 0;
		$this->job['current'] = '';
	}

	private function finish(): void {
		$this->job['status']      = 'done';
		$this->job['finished_at'] = time();
		$this->job['current']     = '';

		$failed = 0;
		foreach ( $this->job['progress'] as $progress ) {
			$failed += (int) ( $progress['failed'] ?? 0 );
		}

		$message = self::type_label( (string) $this->job['type'] ) . ': ' . __( 'finished.', 'simple-storage' );
		if ( $failed > 0 ) {
			/* translators: %d: number of files. */
			$message .= ' ' . sprintf( _n( '%d file failed.', '%d files failed.', $failed, 'simple-storage' ), $failed );
		}
		$this->job['message'] = $message;

		if ( $failed > 0 ) {
			Simple_Storage_Log::warning( $message );
		} else {
			Simple_Storage_Log::info( $message );
		}
	}

	private function save(): void {
		// A pause or cancel saved by another request while this step worked must not be
		// overwritten, and a job that was replaced by a newer one must not be written back at all.
		$stored = self::stored_status( (string) $this->job['id'] );
		if ( 'replaced' === $stored ) {
			$this->job['status'] = 'cancelled';

			return;
		}
		if ( 'running' === $this->job['status'] && in_array( $stored, array( 'paused', 'cancelled' ), true ) ) {
			$this->job['status'] = $stored;
		}

		$this->job['updated_at'] = time();
		wp_cache_delete( self::OPTION, 'options' );
		update_option( self::OPTION, $this->job, false );
	}

	/**
	 * Status of this job as stored in the database, bypassing the options cache of this request;
	 * "replaced" when another job has taken its place.
	 */
	private static function stored_status( string $id ): string {
		global $wpdb;

		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$job   = is_string( $value ) ? maybe_unserialize( $value ) : null;
		if ( ! is_array( $job ) ) {
			return 'cancelled';
		}
		if ( (string) ( $job['id'] ?? '' ) !== $id ) {
			return 'replaced';
		}

		return (string) ( $job['status'] ?? 'cancelled' );
	}

	private function warn( string $message ): void {
		$this->job['warnings'][] = $message;
		$this->job['warnings']   = array_slice( $this->job['warnings'], -20 );
		Simple_Storage_Log::warning( $message );
	}

	/** @return Simple_Storage_Client|WP_Error */
	private function client() {
		if ( null === $this->client ) {
			$client = Simple_Storage_Client::create();
			if ( is_wp_error( $client ) ) {
				return $client;
			}
			$client->set_heartbeat( array( self::class, 'heartbeat' ) );
			$this->client = $client;
		}

		return $this->client;
	}

	private function count( string $phase, int $bytes, bool $failed = false ): void {
		$progress = &$this->job['progress'][ $phase ];
		++$progress['done'];
		$progress['bytes'] += max( 0, $bytes );
		if ( $failed ) {
			++$progress['failed'];
		}
	}

	/** Process index rows of one state in id order until the phase is done or time is up. */
	private function each_row( string $phase, array $state, float $deadline, callable $operation ) {
		while ( microtime( true ) < $deadline ) {
			$rows = Simple_Storage_Index::next( $state, (int) $this->job['cursor'], self::BATCH );
			if ( empty( $rows ) ) {
				return true;
			}

			foreach ( $rows as $row ) {
				$this->job['cursor']  = (int) $row['id'];
				$this->job['current'] = (string) $row['path'];

				$result = $operation( $row );
				self::heartbeat();
				if ( is_wp_error( $result ) ) {
					$this->count( $phase, 0, true );
					if ( $this->job['progress'][ $phase ]['failed'] <= 20 ) {
						Simple_Storage_Log::error( $row['path'] . ': ' . $result->get_error_message() );
					}
				} else {
					$this->count( $phase, is_int( $result ) ? $result : (int) $row['size'] );
				}

				if ( microtime( true ) >= $deadline ) {
					return false;
				}
			}
		}

		return false;
	}

	/** @return bool|WP_Error */
	private function phase_check( float $deadline ) {
		if ( 'push' === $this->job['type'] ) {
			$report = Simple_Storage_Tester::run();
			if ( ! $report['ok'] ) {
				$last = end( $report['steps'] );

				return new WP_Error(
					'simple_storage_check',
					/* translators: %s: failed test step and its message. */
					sprintf( __( 'Connection test failed: %s', 'simple-storage' ), trim( ( $last['label'] ?? '' ) . ' — ' . ( $last['message'] ?? '' ), ' —' ) )
				);
			}

			return true;
		}

		$client = $this->client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		$token = $client->authenticate( true );

		return is_wp_error( $token ) ? $token : true;
	}

	/** @return bool */
	private function phase_local_dirs( float $deadline ) {
		$base = Simple_Storage_Paths::uploads()['basedir'];
		$dirs = array();

		foreach ( (array) @scandir( $base ) as $year ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! preg_match( '/^[0-9]{4}$/', (string) $year ) || ! is_dir( $base . '/' . $year ) || is_link( $base . '/' . $year ) ) {
				continue;
			}
			foreach ( (array) @scandir( $base . '/' . $year ) as $month ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$relative = $year . '/' . $month;
				if ( Simple_Storage_Paths::is_month_dir( $relative ) && is_dir( $base . '/' . $relative ) && ! is_link( $base . '/' . $relative ) ) {
					$dirs[] = $relative;
				}
			}
		}

		sort( $dirs );
		$this->job['dirs']       = $dirs;
		$this->job['dir_pos']    = 0;
		$this->job['local_scan'] = self::next_scan_id();

		$this->job['progress']['local_scan'] = array(
			'total'       => count( $dirs ),
			'total_bytes' => 0,
			'done'        => 0,
			'bytes'       => 0,
			'failed'      => 0,
		);

		return true;
	}

	/** @return bool */
	private function phase_local_scan( float $deadline ) {
		while ( $this->job['dir_pos'] < count( $this->job['dirs'] ) ) {
			$dir                  = (string) $this->job['dirs'][ $this->job['dir_pos'] ];
			$this->job['current'] = $dir;

			$files = self::scan_local_dir( $dir );
			Simple_Storage_Index::upsert_local( $files, (int) $this->job['local_scan'] );

			$this->job['progress']['local_scan']['bytes'] += array_sum( array_column( $files, 'size' ) );
			++$this->job['progress']['local_scan']['done'];
			++$this->job['dir_pos'];

			if ( microtime( true ) >= $deadline ) {
				return $this->job['dir_pos'] >= count( $this->job['dirs'] );
			}
		}

		return true;
	}

	/**
	 * Every regular media file below one YYYY/MM folder; symlinks and hidden files are skipped.
	 *
	 * @return array<int, array{path: string, size: int, mtime: int}>
	 */
	public static function scan_local_dir( string $dir ): array {
		$root  = Simple_Storage_Paths::local( $dir );
		$files = array();
		if ( ! is_dir( $root ) ) {
			return $files;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO ),
			RecursiveIteratorIterator::LEAVES_ONLY,
			RecursiveIteratorIterator::CATCH_GET_CHILD
		);

		foreach ( $iterator as $file ) {
			/** @var SplFileInfo $file */
			if ( $file->isLink() || ! $file->isFile() ) {
				continue;
			}

			$relative = Simple_Storage_Paths::relative_from_local( $file->getPathname() );
			if ( null === $relative ) {
				continue;
			}

			$files[] = array(
				'path'  => $relative,
				'size'  => (int) $file->getSize(),
				'mtime' => (int) $file->getMTime(),
			);
		}

		return $files;
	}

	/** @return bool */
	private function phase_local_cleanup( float $deadline ) {
		$removed = Simple_Storage_Index::finish_local_scan( (int) $this->job['local_scan'] );
		Simple_Storage_Settings::update_state( array( 'last_index' => time() ) );
		if ( $removed > 0 ) {
			/* translators: %d: number of files. */
			Simple_Storage_Log::info( sprintf( __( '%d files that no longer exist were removed from the index.', 'simple-storage' ), $removed ) );
		}

		return true;
	}

	/** @return bool|WP_Error */
	private function phase_remote_dirs( float $deadline ) {
		$client = $this->client();
		if ( is_wp_error( $client ) ) {
			return $this->remote_failure( $client );
		}

		$root  = Simple_Storage_Paths::remote_root();
		$dirs  = array();
		$years = $client->list_dir( $root );
		if ( is_wp_error( $years ) ) {
			if ( 'simple_storage_not_found' !== $years->get_error_code() ) {
				return $this->remote_failure( $years );
			}
			$years = array();
		}

		foreach ( $years as $year ) {
			$name = basename( $year['path'] );
			if ( ! $year['directory'] || ! preg_match( '/^[0-9]{4}$/', $name ) ) {
				continue;
			}

			$months = $client->list_dir( $root . '/' . $name );
			if ( is_wp_error( $months ) ) {
				return $this->remote_failure( $months );
			}
			foreach ( $months as $month ) {
				$relative = $name . '/' . basename( $month['path'] );
				if ( $month['directory'] && Simple_Storage_Paths::is_month_dir( $relative ) ) {
					$dirs[] = $relative;
				}
			}
		}

		sort( $dirs );
		$this->job['dirs']        = $dirs;
		$this->job['dir_pos']     = 0;
		$this->job['prune_pos']   = 0;
		$this->job['remote_scan'] = self::next_scan_id();
		$this->job['remote_seen'] = 0;

		$this->job['progress']['remote_scan'] = array(
			'total'       => count( $dirs ),
			'total_bytes' => 0,
			'done'        => 0,
			'bytes'       => 0,
			'failed'      => 0,
		);

		return true;
	}

	/** @return bool|WP_Error */
	private function phase_remote_scan( float $deadline ) {
		$client = $this->client();
		if ( is_wp_error( $client ) ) {
			return $this->remote_failure( $client );
		}

		while ( $this->job['dir_pos'] < count( $this->job['dirs'] ) ) {
			$dir                  = (string) $this->job['dirs'][ $this->job['dir_pos'] ];
			$this->job['current'] = $dir;

			$items = $client->list_dir( Simple_Storage_Paths::remote( $dir ), true );
			if ( is_wp_error( $items ) && 'simple_storage_not_found' !== $items->get_error_code() ) {
				return $this->remote_failure( $items );
			}

			$files = array();
			foreach ( is_wp_error( $items ) ? array() : $items as $item ) {
				$relative = $item['directory'] ? null : Simple_Storage_Paths::relative_from_remote( $item['path'] );
				if ( null !== $relative ) {
					$files[] = array(
						'path' => $relative,
						'size' => $item['size'],
					);
				}
			}

			Simple_Storage_Index::upsert_remote( $files, (int) $this->job['remote_scan'] );
			$this->job['remote_seen'] += count( $files );
			$this->job['progress']['remote_scan']['bytes'] += array_sum( array_column( $files, 'size' ) );
			++$this->job['progress']['remote_scan']['done'];
			++$this->job['dir_pos'];

			if ( microtime( true ) >= $deadline ) {
				return $this->job['dir_pos'] >= count( $this->job['dirs'] );
			}
		}

		return true;
	}

	/** @return bool */
	private function phase_remote_cleanup( float $deadline ) {
		$known = Simple_Storage_Index::totals( array( 'remote' => 1 ) )['files'];
		if ( 0 === (int) $this->job['remote_seen'] && $known > 0 ) {
			// An empty listing for a site that has files in the storage is far likelier to be a
			// wrong folder or a storage problem than real loss; keep the index as it is.
			$this->warn(
				/* translators: %d: number of files. */
				sprintf( __( 'The storage folder looks empty although %d files are recorded there; the index was left unchanged. Check the site folder setting.', 'simple-storage' ), $known )
			);

			return true;
		}

		// A listing can miss a file (a folder created after the folder list was taken, a storage
		// hiccup); before the index forgets a remote copy, the storage is asked about it directly.
		$scan   = (int) $this->job['remote_scan'];
		$unseen = Simple_Storage_Index::count_unseen_remote( $scan );
		if ( $unseen > self::UNSEEN_CONFIRM_LIMIT ) {
			$this->warn(
				/* translators: %d: number of files. */
				sprintf( __( '%d recorded files were not found in the storage listing; the index was left unchanged. Index again later, and check the storage panel if this repeats.', 'simple-storage' ), $unseen )
			);

			return true;
		}
		if ( $unseen > 0 ) {
			$client = $this->client();
			if ( is_wp_error( $client ) ) {
				return $this->remote_failure( $client );
			}
			foreach ( Simple_Storage_Index::unseen_remote( $scan, self::UNSEEN_CONFIRM_LIMIT ) as $row ) {
				$stat = $client->stat( Simple_Storage_Paths::remote( (string) $row['path'] ) );
				if ( is_wp_error( $stat ) ) {
					return $this->remote_failure( $stat );
				}
				if ( $stat['exists'] && ! $stat['directory'] ) {
					Simple_Storage_Index::update(
						(int) $row['id'],
						array(
							'remote_scan' => $scan,
							'remote_size' => $stat['size'] ?? $row['remote_size'],
						)
					);
				}
			}
		}

		$lost = Simple_Storage_Index::finish_remote_scan( $scan );
		if ( ! empty( $lost ) ) {
			$this->warn(
				/* translators: 1: number of files, 2: list of paths. */
				sprintf( __( '%1$d files exist neither locally nor in the storage: %2$s', 'simple-storage' ), count( $lost ), implode( ', ', array_slice( $lost, 0, 10 ) ) )
			);
		}

		return true;
	}

	/**
	 * An index job keeps its local results when the storage is unreachable; other jobs stop.
	 *
	 * @return bool|WP_Error
	 */
	private function remote_failure( WP_Error $error ) {
		if ( 'index' !== $this->job['type'] ) {
			return $error;
		}

		/* translators: %s: error message. */
		$this->warn( sprintf( __( 'The storage could not be read, only local files were indexed: %s', 'simple-storage' ), $error->get_error_message() ) );
		$this->job['phase'] = count( $this->job['phases'] ) - 1;

		return true;
	}

	/** @return bool|WP_Error */
	private function phase_upload( float $deadline ) {
		$client = $this->client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		return $this->each_row(
			'upload',
			array( 'local' => 1, 'remote' => 0 ),
			$deadline,
			static fn( array $row ) => Simple_Storage_Transfer::upload( $client, $row )
		);
	}

	/** @return bool|WP_Error */
	private function phase_verify( float $deadline ) {
		$client = $this->client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		return $this->each_row(
			'verify',
			array( 'local' => 1, 'remote' => 1, 'verified' => 0 ),
			$deadline,
			static function ( array $row ) use ( $client ) {
				$verified = Simple_Storage_Transfer::verify( $client, $row );
				if ( is_wp_error( $verified ) ) {
					return $verified;
				}

				return true === $verified ? (int) $row['size'] : 0;
			}
		);
	}

	/** Serving from the storage is switched on before any local file disappears. @return bool */
	private function phase_enable_delivery( float $deadline ) {
		if ( ! Simple_Storage_Delivery::active() ) {
			$enabled = Simple_Storage_Delivery::enable();
			if ( is_wp_error( $enabled ) ) {
				return $enabled;
			}
		} else {
			$results = Simple_Storage_Delivery::sync();
			if ( 'proxy' === Simple_Storage_Settings::delivery_mode() && is_wp_error( $results['config'] ) ) {
				return $results['config'];
			}
		}

		return true;
	}

	/**
	 * Files that exist only in the storage must reach visitors at their uploads addresses before
	 * any local copy is deleted: one probe per extension about to lose its local copies. If none
	 * is served the job stops with every local file in place; extensions that fail keep their
	 * local files (delete_local checks each file). Only a passed check switches the site to the
	 * "remote" mode in which new uploads follow automatically.
	 *
	 * @return bool|WP_Error
	 */
	private function phase_check_delivery( float $deadline ) {
		$extensions = Simple_Storage_Index::extensions( array( 'local' => 1, 'remote' => 1, 'verified' => 1 ), 20 );
		$report     = Simple_Storage_Delivery::verify_delivery( empty( $extensions ) ? array( 'txt' ) : $extensions );
		if ( is_wp_error( $report ) ) {
			return $report;
		}
		if ( empty( $report['ok'] ) ) {
			return new WP_Error( 'simple_storage_delivery_check', (string) reset( $report['failed'] ) );
		}
		foreach ( $report['failed'] as $extension => $message ) {
			/* translators: 1: file extension, 2: error message. */
			$this->warn( sprintf( __( 'Files with the extension .%1$s are not served from the storage and stay local: %2$s', 'simple-storage' ), $extension, $message ) );
		}

		Simple_Storage_Settings::update_state( array( 'mode' => 'remote' ) );

		return true;
	}

	/** @return bool|WP_Error */
	private function phase_delete_local( float $deadline ) {
		$client = $this->client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		if ( ! Simple_Storage_Delivery::active() ) {
			// Never remove local copies while nothing serves the remote ones.
			$this->warn( __( 'Serving from the storage is off, so local copies were kept.', 'simple-storage' ) );

			return true;
		}

		return $this->each_row(
			'delete_local',
			array( 'local' => 1, 'remote' => 1, 'verified' => 1 ),
			$deadline,
			static function ( array $row ) use ( $client ) {
				$extension = Simple_Storage_Paths::extension( (string) $row['path'] );
				if ( ! Simple_Storage_Delivery::ensure_extension_verified( $extension ) ) {
					/* translators: %s: file extension. */
					$message = sprintf( __( 'Serving .%s files from the storage is not verified, so the local file was kept.', 'simple-storage' ), $extension );
					Simple_Storage_Index::update( (int) $row['id'], array( 'error' => $message ) );

					return new WP_Error( 'simple_storage_delivery_check', $message );
				}

				return Simple_Storage_Transfer::remove_local( $client, $row );
			}
		);
	}

	/** @return bool */
	private function phase_finish_push( float $deadline ) {
		$left = Simple_Storage_Index::totals( array( 'local' => 1 ) )['files'];
		if ( $left > 0 ) {
			/* translators: %d: number of files. */
			$this->warn( sprintf( __( '%d files are still stored locally; run the transfer again after checking the errors.', 'simple-storage' ), $left ) );
		}

		return true;
	}

	/** @return bool|WP_Error */
	private function phase_download( float $deadline ) {
		$client = $this->client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		if ( 0 === (int) $this->job['cursor'] && 0 === (int) $this->job['progress']['download']['done'] ) {
			$need = (int) $this->job['progress']['download']['total_bytes'];
			$free = function_exists( 'disk_free_space' ) ? @disk_free_space( Simple_Storage_Paths::uploads()['basedir'] ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false !== $free && $free < $need * 1.05 + 50 * MB_IN_BYTES ) {
				return new WP_Error(
					'simple_storage_disk',
					/* translators: 1: required size, 2: free size. */
					sprintf( __( 'Not enough disk space: %1$s is needed, %2$s is free.', 'simple-storage' ), size_format( $need ), size_format( (int) $free ) )
				);
			}
		}

		return $this->each_row(
			'download',
			array( 'local' => 0, 'remote' => 1 ),
			$deadline,
			static fn( array $row ) => Simple_Storage_Transfer::download( $client, $row )
		);
	}

	/** @return bool|WP_Error */
	private function phase_delete_remote( float $deadline ) {
		$client = $this->client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		return $this->each_row(
			'delete_remote',
			array( 'local' => 1, 'remote' => 1 ),
			$deadline,
			static fn( array $row ) => Simple_Storage_Transfer::remove_remote( $client, $row )
		);
	}

	/** Remove the folders the pull left empty in the storage, month by month. @return bool|WP_Error */
	private function phase_prune_remote( float $deadline ) {
		$client = $this->client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$this->job['prune_pos'] = (int) ( $this->job['prune_pos'] ?? 0 );
		while ( $this->job['prune_pos'] < count( $this->job['dirs'] ) ) {
			$dir                  = (string) $this->job['dirs'][ $this->job['prune_pos'] ];
			$this->job['current'] = $dir;

			// A folder that cannot be pruned now stays; the pull does not fail over it.
			Simple_Storage_Prune::prune_locked( $client, $dir );
			++$this->job['prune_pos'];

			if ( microtime( true ) >= $deadline ) {
				return $this->job['prune_pos'] >= count( $this->job['dirs'] );
			}
		}

		return true;
	}

	/** @return bool */
	private function phase_finish_pull( float $deadline ) {
		$remaining = Simple_Storage_Index::remote_only_count();
		if ( 0 === $remaining ) {
			if ( Simple_Storage_Delivery::active() ) {
				Simple_Storage_Delivery::disable();
			}
			$this->warn( __( 'All files are back in WordPress. If the site is behind Cloudflare or another CDN, purge its cache so that cached redirects to the storage disappear.', 'simple-storage' ) );
		} else {
			/* translators: %d: number of files. */
			$this->warn( sprintf( __( '%d files are still only in the storage, so serving from the storage stays on. Check the errors and run the transfer again.', 'simple-storage' ), $remaining ) );
		}

		return true;
	}

	private static function next_scan_id(): int {
		$id = (int) get_option( self::SCAN_SEQ_OPTION, 0 ) + 1;
		update_option( self::SCAN_SEQ_OPTION, $id, false );

		return $id;
	}

	/**
	 * Atomic lock row in the options table, shared by job steps and the automatic offload.
	 * add_option() cannot be used: it overwrites on conflict.
	 */
	public static function lock(): ?string {
		global $wpdb;

		$token = wp_generate_password( 20, false );
		$value = maybe_serialize(
			array(
				'token'   => $token,
				'expires' => time() + self::LOCK_TTL,
			)
		);

		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", self::LOCK_OPTION, $value ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( 1 === (int) $inserted ) {
			self::$held = $token;
			self::$beat = time();

			return $token;
		}

		$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$lock    = is_string( $current ) ? maybe_unserialize( $current ) : null;
		if ( is_array( $lock ) && (int) ( $lock['expires'] ?? 0 ) < time() ) {
			$taken = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, self::LOCK_OPTION, $current ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( 1 === (int) $taken ) {
				self::$held = $token;
				self::$beat = time();

				return $token;
			}
		}

		return null;
	}

	public static function unlock( string $token ): void {
		global $wpdb;

		if ( self::$held === $token ) {
			self::$held = null;
		}

		$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$lock    = is_string( $current ) ? maybe_unserialize( $current ) : null;
		if ( is_array( $lock ) && ( $lock['token'] ?? '' ) === $token ) {
			// Only the row just read: an expired lock may have been taken over in between.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK_OPTION, $current ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		wp_cache_delete( self::LOCK_OPTION, 'options' );
	}

	/**
	 * Extend the lock this request holds, at most every 30 seconds: called between files and
	 * during long transfers, so a large file never outlives the lock.
	 */
	public static function heartbeat(): void {
		global $wpdb;

		if ( null === self::$held || time() - self::$beat < 30 ) {
			return;
		}
		self::$beat = time();

		$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$lock    = is_string( $current ) ? maybe_unserialize( $current ) : null;
		if ( ! is_array( $lock ) || ( $lock['token'] ?? '' ) !== self::$held ) {
			return;
		}

		$value = maybe_serialize(
			array(
				'token'   => self::$held,
				'expires' => time() + self::LOCK_TTL,
			)
		);
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, self::LOCK_OPTION, $current ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Whether this request still holds the lock, with at least this many seconds left: checked
	 * right before work that must never overlap an upload (deleting a storage folder).
	 */
	public static function holds_lock( int $margin = 0 ): bool {
		global $wpdb;

		if ( null === self::$held ) {
			return false;
		}

		$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$lock    = is_string( $current ) ? maybe_unserialize( $current ) : null;

		return is_array( $lock ) && ( $lock['token'] ?? '' ) === self::$held && (int) ( $lock['expires'] ?? 0 ) - time() >= $margin;
	}

	public static function lock_lost(): WP_Error {
		return new WP_Error( 'simple_storage_lock_lost', __( 'The job lock was lost; the work will be done again later.', 'simple-storage' ) );
	}

	/** Whether a step or an automatic offload currently holds the lock. */
	public static function is_locked(): bool {
		global $wpdb;

		$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$lock    = is_string( $current ) ? maybe_unserialize( $current ) : null;

		return is_array( $lock ) && (int) ( $lock['expires'] ?? 0 ) >= time();
	}

	public static function type_label( string $type ): string {
		$labels = array(
			'index' => __( 'Indexing', 'simple-storage' ),
			'push'  => __( 'Transfer to the storage', 'simple-storage' ),
			'pull'  => __( 'Return from the storage', 'simple-storage' ),
		);

		return $labels[ $type ] ?? $type;
	}

	public static function phase_label( string $phase ): string {
		$labels = array(
			'check'           => __( 'Connection check', 'simple-storage' ),
			'local_dirs'      => __( 'Finding media folders', 'simple-storage' ),
			'local_scan'      => __( 'Indexing local files', 'simple-storage' ),
			'local_cleanup'   => __( 'Updating the index', 'simple-storage' ),
			'remote_dirs'     => __( 'Finding folders in the storage', 'simple-storage' ),
			'remote_scan'     => __( 'Indexing files in the storage', 'simple-storage' ),
			'remote_cleanup'  => __( 'Reconciling the index', 'simple-storage' ),
			'upload'          => __( 'Copying to the storage', 'simple-storage' ),
			'verify'          => __( 'Verifying copies', 'simple-storage' ),
			'enable_delivery' => __( 'Enabling serving from the storage', 'simple-storage' ),
			'check_delivery'  => __( 'Checking serving from the storage', 'simple-storage' ),
			'delete_local'    => __( 'Deleting local copies', 'simple-storage' ),
			'finish_push'     => __( 'Finishing', 'simple-storage' ),
			'download'        => __( 'Downloading to WordPress', 'simple-storage' ),
			'delete_remote'   => __( 'Deleting copies in the storage', 'simple-storage' ),
			'prune_remote'    => __( 'Removing empty storage folders', 'simple-storage' ),
			'finish_pull'     => __( 'Finishing', 'simple-storage' ),
		);

		return $labels[ $phase ] ?? $phase;
	}

	/** Phases with per-file progress bars. @return array<int, string> */
	public static function measured_phases(): array {
		return array( 'local_scan', 'remote_scan', 'upload', 'verify', 'delete_local', 'download', 'delete_remote' );
	}
}
