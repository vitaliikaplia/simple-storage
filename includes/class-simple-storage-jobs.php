<?php
/**
 * Resumable jobs driven in short steps by the admin page (AJAX) or WP-CLI.
 *
 * index — scan the uploads folders (and the storage, when configured) into the index;
 * push  — copy everything to the storage, verify it, enable serving from it, delete local copies;
 * pull  — download everything back, verify it, delete the remote copies, disable serving;
 * adopt — take over files someone put into the storage by hand: nothing is copied, the storage is
 *         compared with the Media Library and, when nothing is missing, serving is switched on.
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
		'index'        => array( 'local_dirs', 'local_scan', 'local_cleanup', 'remote_dirs', 'remote_scan', 'remote_cleanup' ),
		'push'         => array( 'check', 'local_dirs', 'local_scan', 'local_cleanup', 'upload', 'verify', 'enable_delivery', 'check_delivery', 'delete_local', 'finish_push' ),
		'pull'         => array( 'check', 'remote_dirs', 'remote_scan', 'remote_cleanup', 'download', 'delete_remote', 'prune_remote', 'finish_pull' ),
		'adopt'        => array( 'check', 'local_dirs', 'local_scan', 'local_cleanup', 'remote_dirs', 'remote_scan', 'remote_cleanup', 'reconcile', 'verify_listed', 'adopt_compare', 'enable_delivery', 'check_delivery', 'finish_adopt' ),
		// Step by step: copy (never deletes), switch (never copies), delete (only a complete move).
		'copy_remote'  => array( 'check', 'local_dirs', 'local_scan', 'local_cleanup', 'remote_dirs', 'remote_scan', 'remote_cleanup', 'reconcile', 'verify_listed', 'upload', 'verify', 'finish_copy_remote' ),
		'copy_local'   => array( 'check', 'local_dirs', 'local_scan', 'local_cleanup', 'remote_dirs', 'remote_scan', 'remote_cleanup', 'reconcile', 'download', 'finish_copy_local' ),
		'switch_local' => array( 'check', 'local_dirs', 'local_scan', 'local_cleanup', 'remote_dirs', 'remote_scan', 'remote_cleanup', 'reconcile', 'local_compare', 'disable_delivery', 'finish_switch_local' ),
		'clean_local'  => array( 'check', 'local_dirs', 'local_scan', 'local_cleanup', 'remote_dirs', 'remote_scan', 'remote_cleanup', 'reconcile', 'verify_listed', 'verify', 'local_gate', 'enable_delivery', 'check_delivery', 'delete_local', 'finish_clean_local' ),
		'clean_remote' => array( 'check', 'local_dirs', 'local_scan', 'local_cleanup', 'remote_dirs', 'remote_scan', 'remote_cleanup', 'reconcile', 'verify_listed', 'remote_gate', 'delete_remote', 'prune_remote', 'finish_clean_remote' ),
	);

	/** Paths of each kind kept for the report of a step-by-step job. */
	private const REPORT_SAMPLES = 20;

	/** Paths of each kind kept for the report of an adopt job. */
	private const ADOPT_SAMPLES = 20;

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

	/**
	 * @param array<string, mixed> $options confirm_missing: ask the storage about every recorded
	 *                                      file its listing did not show, however many there are.
	 *                                      accept_missing: adopt although files are missing.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function start( string $type, array $options = array() ) {
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
			'confirm_missing' => ! empty( $options['confirm_missing'] ),
			'accept_missing'  => ! empty( $options['accept_missing'] ),
			'skipped'     => array(),
			'progress'    => array(),
			'warnings'    => array(),
			'message'     => '',
			'current'     => '',
			'started_at'  => time(),
			'updated_at'  => time(),
			'finished_at' => 0,
		);

		if ( in_array( $type, array( 'pull', 'copy_local' ), true ) ) {
			// New uploads must stay local from now on, or they would race the download, and the
			// automatic offload would move what was downloaded out again. A cancelled job gives
			// the old mode back.
			$job['previous_mode'] = Simple_Storage_Settings::state()['mode'];
			Simple_Storage_Settings::update_state( array( 'mode' => 'local' ) );
		}

		update_option( self::OPTION, $job, false );
		Simple_Storage_Log::info( self::type_label( $type ) . ': ' . __( 'started.', 'simple-storage' ) );

		return $job;
	}

	/**
	 * Continue a paused or failed job. A running one needs no change: nothing runs a job on its
	 * own, so after a closed tab it waits in that state for someone to pick it up.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function resume() {
		$job = self::current();
		if ( null === $job || ! in_array( $job['status'], array( 'running', 'paused', 'failed' ), true ) ) {
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
			if ( 'remote' === ( $job['previous_mode'] ?? '' ) && Simple_Storage_Delivery::active() ) {
				// The site still serves from the storage, so new uploads follow the files there.
				Simple_Storage_Settings::update_state( array( 'mode' => 'remote' ) );
			}
			Simple_Storage_Log::warning( self::type_label( (string) $job['type'] ) . ': ' . __( 'cancelled.', 'simple-storage' ) );
		}
	}

	/** Forget a job that has ended, with its progress and warnings. */
	public static function dismiss(): void {
		$job = self::current();
		if ( null !== $job && in_array( $job['status'], array( 'done', 'cancelled' ), true ) ) {
			delete_option( self::OPTION );
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

		// The holder of the lock until now may have moved the job on: what was read above is old.
		wp_cache_delete( self::OPTION, 'options' );
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
			case 'adopt_compare':
			case 'local_compare':
				$totals['files'] = Simple_Storage_Media::distinct_attachment_count();
				break;
			case 'reconcile':
				$report = Simple_Storage_Index::report( 'conflict', 0 );
				$totals = array(
					'files' => $report['count'],
					'bytes' => $report['bytes'],
				);
				break;
			case 'verify_listed':
				$report = Simple_Storage_Index::report( 'listed', 0, (int) $this->job['remote_scan'] );
				$totals = array(
					'files' => $report['count'],
					'bytes' => $report['bytes'],
				);
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
		// Comparisons and checks have a report of their own, not rows with errors.
		if ( ! empty( $progress['failed'] ) && ! in_array( $phase, array( 'adopt_compare', 'local_compare', 'local_gate', 'remote_gate' ), true ) ) {
			Simple_Storage_Log::warning(
				/* translators: 1: phase name, 2: number of files. */
				sprintf( __( '%1$s: %2$d files failed; see the error list.', 'simple-storage' ), self::phase_label( $phase ), (int) $progress['failed'] )
			);
		}

		if ( 'remote_scan' === $phase && $this->job['dir_pos'] >= count( $this->job['dirs'] ) ) {
			// A complete listing: the overview counts the local files it showed in the storage too.
			Simple_Storage_Settings::update_state( array( 'last_remote_scan' => (int) $this->job['remote_scan'] ) );
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
		if ( 'adopt' === $this->job['type'] ) {
			$message = self::adopt_message( (array) ( $this->job['adopt'] ?? array() ) );
		} elseif ( isset( $this->job['summary'] ) ) {
			// A step-by-step job says itself what happened, and what did not.
			$message = (string) $this->job['summary'];
		} elseif ( $failed > 0 ) {
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
		return $this->each( $phase, static fn( int $after ): array => Simple_Storage_Index::next( $state, $after, self::BATCH ), $deadline, $operation );
	}

	/**
	 * Process the rows a query returns after the cursor, in id order, until the phase is done or
	 * time is up.
	 *
	 * @param callable(int): array<int, array<string, mixed>> $fetch
	 */
	private function each( string $phase, callable $fetch, float $deadline, callable $operation ) {
		while ( microtime( true ) < $deadline ) {
			$rows = $fetch( (int) $this->job['cursor'] );
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
		// These depend on the public address as well: test it before anything is decided.
		if ( in_array( $this->job['type'], array( 'push', 'adopt', 'copy_remote', 'clean_local' ), true ) ) {
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

	/** @return bool|WP_Error */
	private function phase_local_scan( float $deadline ) {
		while ( $this->job['dir_pos'] < count( $this->job['dirs'] ) ) {
			$dir                  = (string) $this->job['dirs'][ $this->job['dir_pos'] ];
			$this->job['current'] = $dir;

			$skipped = array();
			$files   = self::scan_local_dir( $dir, $skipped );
			if ( ! Simple_Storage_Index::upsert_local( $files, (int) $this->job['local_scan'] ) ) {
				return self::index_error();
			}
			$this->note_skipped( $skipped );

			$this->job['progress']['local_scan']['bytes'] += array_sum( array_column( $files, 'size' ) );
			++$this->job['progress']['local_scan']['done'];
			++$this->job['dir_pos'];

			if ( microtime( true ) >= $deadline ) {
				return $this->job['dir_pos'] >= count( $this->job['dirs'] );
			}
		}

		return true;
	}

	private static function index_error(): WP_Error {
		global $wpdb;

		/* translators: %s: database error. */
		return new WP_Error( 'simple_storage_index', sprintf( __( 'The index could not be saved: %s', 'simple-storage' ), (string) $wpdb->last_error ) );
	}

	/**
	 * Remember what a scan left out, by reason: the count and the first few paths.
	 *
	 * @param array<string, array<int, string>> $skipped
	 */
	private function note_skipped( array $skipped ): void {
		foreach ( $skipped as $reason => $paths ) {
			$note = $this->job['skipped'][ $reason ] ?? array(
				'count' => 0,
				'paths' => array(),
			);

			$note['count'] += count( $paths );
			$note['paths']  = array_slice( array_merge( $note['paths'], $paths ), 0, 5 );

			$this->job['skipped'][ $reason ] = $note;
		}
	}

	/**
	 * Say what a scan left out, once: nothing must stay behind without anyone knowing.
	 */
	private function warn_skipped(): void {
		$messages = array(
			/* translators: 1: number of files, 2: list of paths. */
			'excluded' => __( '%1$d files were left out: code and data files stay out of the public storage (filter simple_storage_excluded_file). %2$s', 'simple-storage' ),
			/* translators: 1: number of files, 2: list of paths. */
			'invalid'  => __( '%1$d files were left out: their names are not valid UTF-8, so the index cannot record them. Rename them. %2$s', 'simple-storage' ),
		);

		foreach ( (array) ( $this->job['skipped'] ?? array() ) as $reason => $note ) {
			if ( isset( $messages[ $reason ] ) ) {
				$this->warn( sprintf( $messages[ $reason ], (int) $note['count'], implode( ', ', (array) $note['paths'] ) ) );
			}
		}
		$this->job['skipped'] = array();
	}

	/**
	 * Every regular media file below one YYYY/MM folder; symlinks and hidden files are skipped.
	 *
	 * @param array<string, array<int, string>>|null $skipped Files left out for a reason someone
	 *                                                        should hear about, by reason.
	 * @return array<int, array{path: string, size: int, mtime: int}>
	 */
	public static function scan_local_dir( string $dir, ?array &$skipped = null ): array {
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
				if ( null !== $skipped && '' === wp_check_invalid_utf8( $file->getPathname() ) ) {
					$skipped['invalid'][] = $dir . '/' . rawurlencode( $file->getFilename() );
				}
				continue;
			}
			if ( Simple_Storage_Paths::is_excluded( $relative ) ) {
				if ( null !== $skipped ) {
					$skipped['excluded'][] = $relative;
				}
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
		$this->warn_skipped();

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
				if ( null === $relative ) {
					continue;
				}
				if ( Simple_Storage_Paths::is_excluded( $relative ) ) {
					// Nothing the site put there: it must not come back through the index.
					$this->note_skipped( array( 'excluded' => array( $relative ) ) );
					continue;
				}
				$files[] = array(
					'path' => $relative,
					'size' => $item['size'],
				);
			}

			if ( ! Simple_Storage_Index::upsert_remote( $files, (int) $this->job['remote_scan'] ) ) {
				return $this->remote_failure( self::index_error() );
			}
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

	/** @return bool|WP_Error */
	private function phase_remote_cleanup( float $deadline ) {
		$this->warn_skipped();

		$force = ! empty( $this->job['confirm_missing'] );
		$known = Simple_Storage_Index::totals( array( 'remote' => 1 ) )['files'];
		if ( 0 === (int) $this->job['remote_seen'] && $known > 0 && ! $force ) {
			// An empty listing for a site that has files in the storage is far likelier to be a
			// wrong folder or a storage problem than real loss; keep the index as it is.
			$this->warn(
				/* translators: %d: number of files. */
				sprintf( __( 'The storage folder looks empty although %d files are recorded there; the index was left unchanged. Check the site folder setting. If the files are really gone, run: wp simple-storage index --confirm-missing', 'simple-storage' ), $known )
			);

			return true;
		}

		// A listing can miss a file (a folder created after the folder list was taken, a storage
		// hiccup); before the index forgets a remote copy, the storage is asked about it directly.
		$scan   = (int) $this->job['remote_scan'];
		$unseen = Simple_Storage_Index::count_unseen_remote( $scan );
		if ( $unseen > self::UNSEEN_CONFIRM_LIMIT && ! $force ) {
			$this->warn(
				/* translators: %d: number of files. */
				sprintf( __( '%d recorded files were not found in the storage listing; the index was left unchanged. Index again later, and check the storage panel if this repeats. If the files are really gone, run: wp simple-storage index --confirm-missing', 'simple-storage' ), $unseen )
			);

			return true;
		}
		if ( $unseen > 0 ) {
			$client = $this->client();
			if ( is_wp_error( $client ) ) {
				return $this->remote_failure( $client );
			}
			// Row by row from a cursor: with many files this takes more than one step.
			while ( array() !== ( $rows = Simple_Storage_Index::unseen_remote( $scan, self::BATCH, (int) $this->job['cursor'] ) ) ) {
				foreach ( $rows as $row ) {
					$this->job['cursor'] = (int) $row['id'];

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
					self::heartbeat();
				}
				if ( microtime( true ) >= $deadline ) {
					return false;
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

	/**
	 * Compare the storage with what the Media Library needs, attachment by attachment. A file counts
	 * as there when the storage has it or it is on the disk (some may have stayed on the site).
	 *
	 * @return bool
	 */
	private function phase_adopt_compare( float $deadline ) {
		if ( ! isset( $this->job['adopt'] ) ) {
			$this->job['adopt'] = array(
				'attachments'    => 0,
				'files'          => 0,
				'found'          => 0,
				'on_disk'        => 0,
				'missing'        => 0,
				'mismatch'       => 0,
				'outside'        => 0,
				'extra'          => 0,
				'missing_paths'  => array(),
				'mismatch_paths' => array(),
				'outside_paths'  => array(),
				'blocked'        => false,
				'folder'         => Simple_Storage_Paths::remote_root(),
			);
		}

		while ( microtime( true ) < $deadline ) {
			$ids = Simple_Storage_Media::distinct_attachments( (int) $this->job['cursor'], self::BATCH );
			if ( empty( $ids ) ) {
				$this->conclude_adopt();

				return true;
			}

			foreach ( $ids as $id ) {
				$this->job['cursor'] = $id;
				$this->compare_attachment( $id );
				wp_cache_delete( $id, 'post_meta' );
				++$this->job['progress']['adopt_compare']['done'];
				self::heartbeat();
			}
		}

		return false;
	}

	private function compare_attachment( int $id ): void {
		$report   = &$this->job['adopt'];
		$attached = (string) get_post_meta( $id, '_wp_attached_file', true );

		$this->job['current'] = $attached;
		++$report['attachments'];

		if ( ! Simple_Storage_Paths::is_media_path( $attached ) ) {
			// Outside the year and month folders nothing moves: such a file has to be on the disk.
			if ( ! file_exists( Simple_Storage_Paths::local( $attached ) ) ) {
				$this->note_adopt( 'outside', $attached );
			}

			return;
		}

		$expected = Simple_Storage_Media::expected_files( $id );
		$rows     = Simple_Storage_Index::by_paths( array_keys( $expected ) );
		foreach ( $expected as $path => $size ) {
			$path = (string) $path;
			$row  = $rows[ $path ] ?? null;
			++$report['files'];

			if ( null !== $row && $row['remote'] && ! $row['conflict'] ) {
				++$report['found'];
				if ( null !== $size && (int) $row['remote_size'] !== $size ) {
					$this->note_adopt( 'mismatch', $path );
				}
			} elseif ( file_exists( Simple_Storage_Paths::local( $path ) ) ) {
				++$report['on_disk'];
			} else {
				$this->note_adopt( 'missing', $path );
			}
		}
	}

	private function note_adopt( string $kind, string $path ): void {
		++$this->job['adopt'][ $kind ];
		if ( count( $this->job['adopt'][ $kind . '_paths' ] ) < self::ADOPT_SAMPLES ) {
			$this->job['adopt'][ $kind . '_paths' ][] = $path;
		}
	}

	/**
	 * Decide on the comparison. Anything missing stops here with nothing changed, unless it was
	 * accepted: sizes that were never generated are missing on the old site as well. With no file
	 * of the Media Library in the storage there is nothing to accept: the folder is wrong.
	 */
	private function conclude_adopt(): void {
		$report = &$this->job['adopt'];

		$report['extra'] = max( 0, Simple_Storage_Index::totals( array( 'local' => 0, 'remote' => 1 ) )['files'] - (int) $report['found'] );

		$problems          = (int) $report['missing'] + (int) $report['mismatch'] + (int) $report['outside'];
		$report['blocked'] = 0 === (int) $report['found'] || ( $problems > 0 && empty( $this->job['accept_missing'] ) );

		$this->job['progress']['adopt_compare']['failed'] = $problems;
		if ( $report['blocked'] ) {
			$this->stop_before_finish();
		}
	}

	/**
	 * A check that did not pass ends the job right here: every phase between the current one and
	 * the last is skipped, so nothing is switched, copied or deleted.
	 */
	private function stop_before_finish(): void {
		$last = count( $this->job['phases'] ) - 1;

		$this->job['skipped_phases'] = array_slice( $this->job['phases'], $this->job['phase'] + 1, max( 0, $last - $this->job['phase'] - 1 ) );
		// The current phase completes next and moves the job on to the last one.
		$this->job['phase'] = $last - 1;
	}

	/** @param array<string, mixed> $report */
	private static function adopt_message( array $report ): string {
		$problems = (int) ( $report['missing'] ?? 0 ) + (int) ( $report['outside'] ?? 0 );
		if ( ! empty( $report['blocked'] ) ) {
			if ( 0 === (int) ( $report['found'] ?? 0 ) ) {
				/* translators: %s: folder in the storage. */
				return sprintf( __( 'Nothing was connected: the storage has no file of the Media Library in %s. Check the folder name and the year and month structure.', 'simple-storage' ), (string) ( $report['folder'] ?? '' ) );
			}

			/* translators: 1: number of missing files, 2: number of files with another size. */
			return sprintf( __( 'Nothing was connected: %1$d files are missing and %2$d differ in size. Upload them and check again, or connect anyway.', 'simple-storage' ), $problems, (int) ( $report['mismatch'] ?? 0 ) );
		}
		if ( $problems + (int) ( $report['mismatch'] ?? 0 ) > 0 ) {
			/* translators: 1: number of files, 2: number of missing files, 3: number of files with another size. */
			return sprintf( __( 'Connected: the site serves %1$d files from the storage; %2$d are missing and %3$d differ in size.', 'simple-storage' ), (int) ( $report['found'] ?? 0 ), $problems, (int) ( $report['mismatch'] ?? 0 ) );
		}

		/* translators: %d: number of files. */
		return sprintf( __( 'Connected: the site serves %d files from the storage.', 'simple-storage' ), (int) ( $report['found'] ?? 0 ) );
	}

	/** @return bool */
	private function phase_finish_adopt( float $deadline ) {
		return true;
	}

	/**
	 * Files at the same path on both sides that the index cannot take for copies of each other:
	 * brought back by hand (FTP) under new modification times, or a different file in the way.
	 * The same contents (SHA-256) make the local file the copy; a different one stays a name
	 * conflict, and neither of the two is ever deleted as the copy of the other.
	 *
	 * @return bool|WP_Error
	 */
	private function phase_reconcile( float $deadline ) {
		$client = $this->client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		return $this->each(
			'reconcile',
			static fn( int $after ): array => Simple_Storage_Index::next_conflicts( $after, self::BATCH ),
			$deadline,
			static function ( array $row ) use ( $client ) {
				$matched = Simple_Storage_Transfer::match_local( $client, $row );

				return is_wp_error( $matched ) ? $matched : (int) $row['size'];
			}
		);
	}

	/**
	 * Local files that the storage already has under the same name and size (uploaded by hand, or
	 * left there by an earlier transfer) are checked like any copy, instead of being uploaded once
	 * more or left behind in the storage unnoticed. Only a passed check makes them copies.
	 *
	 * @return bool|WP_Error
	 */
	private function phase_verify_listed( float $deadline ) {
		$client = $this->client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		$scan = (int) $this->job['remote_scan'];

		return $this->each(
			'verify_listed',
			static fn( int $after ): array => Simple_Storage_Index::next_listed( $scan, $after, self::BATCH ),
			$deadline,
			static function ( array $row ) use ( $client ) {
				Simple_Storage_Index::update(
					(int) $row['id'],
					array(
						'remote'   => 1,
						'verified' => 0,
					)
				);
				$row['remote']   = 1;
				$row['verified'] = 0;

				$verified = Simple_Storage_Transfer::verify( $client, $row );
				if ( true === $verified ) {
					return (int) $row['size'];
				}

				// Not proven (another file under that name, or the storage out of reach this time):
				// no copy yet, and no failure either — the listing was only a hint. Copying uploads
				// such a file like any other; deleting leaves it where it is.
				$current = Simple_Storage_Index::get( (string) $row['path'] );
				if ( null !== $current && $current['local'] ) {
					Simple_Storage_Index::update(
						(int) $row['id'],
						array(
							'remote'   => 0,
							'verified' => 0,
							'error'    => null,
						)
					);
				}

				return 0;
			}
		);
	}

	/**
	 * Switching to WordPress: every file the Media Library needs must be on the site disk. Files
	 * that exist only in the storage, or differ from the file at the same path, would break, so
	 * they always stop the switch; files missing on both sides can be accepted.
	 *
	 * @return bool
	 */
	private function phase_local_compare( float $deadline ) {
		if ( ! isset( $this->job['report'] ) ) {
			$this->job['report'] = self::new_report( array( 'attachments', 'files', 'on_disk', 'only_remote', 'conflicts', 'missing', 'outside' ) );
		}

		while ( microtime( true ) < $deadline ) {
			$ids = Simple_Storage_Media::distinct_attachments( (int) $this->job['cursor'], self::BATCH );
			if ( empty( $ids ) ) {
				$this->conclude_switch_local();

				return true;
			}

			foreach ( $ids as $id ) {
				$this->job['cursor'] = $id;
				$this->check_on_disk( $id );
				wp_cache_delete( $id, 'post_meta' );
				++$this->job['progress']['local_compare']['done'];
				self::heartbeat();
			}
		}

		return false;
	}

	private function check_on_disk( int $id ): void {
		$attached = (string) get_post_meta( $id, '_wp_attached_file', true );

		$this->job['current'] = $attached;
		++$this->job['report']['counts']['attachments'];

		if ( ! Simple_Storage_Paths::is_media_path( $attached ) ) {
			// Outside the year and month folders nothing moves: such a file has to be on the disk.
			if ( ! file_exists( Simple_Storage_Paths::local( $attached ) ) ) {
				$this->note( 'outside', $attached );
			}

			return;
		}

		$expected = Simple_Storage_Media::expected_files( $id );
		$rows     = Simple_Storage_Index::by_paths( array_keys( $expected ) );
		foreach ( array_keys( $expected ) as $path ) {
			$path = (string) $path;
			++$this->job['report']['counts']['files'];

			if ( file_exists( Simple_Storage_Paths::local( $path ) ) ) {
				++$this->job['report']['counts']['on_disk'];
			} elseif ( empty( $rows[ $path ]['remote'] ) ) {
				// In the storage it is counted with the rest of the files that live only there.
				$this->note( 'missing', $path );
			}
		}
	}

	private function conclude_switch_local(): void {
		$report = &$this->job['report'];

		$this->take( 'only_remote', Simple_Storage_Index::report( 'remote_only', self::REPORT_SAMPLES ) );
		$this->take( 'conflicts', Simple_Storage_Index::report( 'conflict', self::REPORT_SAMPLES ) );

		$breaking = (int) $report['counts']['only_remote'] + (int) $report['counts']['conflicts'];
		$missing  = (int) $report['counts']['missing'] + (int) $report['counts']['outside'];

		$report['can_accept'] = 0 === $breaking && $missing > 0;
		$report['blocked']    = $breaking > 0 || ( $missing > 0 && empty( $this->job['accept_missing'] ) );

		$this->job['progress']['local_compare']['failed'] = $breaking + $missing;
		if ( $report['blocked'] ) {
			$this->stop_before_finish();
		}
	}

	/** Every file is in WordPress: serving from the storage goes off, new uploads stay. @return bool */
	private function phase_disable_delivery( float $deadline ) {
		if ( Simple_Storage_Delivery::active() ) {
			Simple_Storage_Delivery::disable();
		}
		Simple_Storage_Settings::update_state( array( 'mode' => 'local' ) );

		return true;
	}

	/**
	 * Local copies are deleted only once the move is complete: every local file has a verified
	 * copy in the storage. Otherwise nothing is deleted, and the report names what is missing.
	 *
	 * @return bool
	 */
	private function phase_local_gate( float $deadline ) {
		$this->job['report'] = self::new_report( array( 'local_total', 'with_copy', 'uncopied', 'conflicts' ) );
		$this->take( 'uncopied', Simple_Storage_Index::report( 'local_uncopied', self::REPORT_SAMPLES ) );
		$this->take( 'conflicts', Simple_Storage_Index::report( 'conflict', self::REPORT_SAMPLES ) );

		$counts                = &$this->job['report']['counts'];
		$counts['local_total'] = Simple_Storage_Index::totals( array( 'local' => 1 ) )['files'] + $counts['conflicts'];
		$counts['with_copy']   = Simple_Storage_Index::totals( array( 'local' => 1, 'remote' => 1, 'verified' => 1 ) )['files'];

		$missing                                       = $counts['uncopied'] + $counts['conflicts'];
		$this->job['report']['blocked']                = $missing > 0;
		$this->job['progress']['local_gate']['failed'] = $missing;
		if ( $missing > 0 ) {
			$this->stop_before_finish();
		}

		return true;
	}

	/**
	 * Copies in the storage are deleted only once the move is complete: every file in the storage
	 * is in WordPress too, with the same contents. Otherwise nothing is deleted.
	 *
	 * @return bool
	 */
	private function phase_remote_gate( float $deadline ) {
		$this->job['report'] = self::new_report( array( 'remote_total', 'with_local', 'only_remote', 'conflicts' ) );
		$this->take( 'only_remote', Simple_Storage_Index::report( 'remote_only', self::REPORT_SAMPLES ) );
		$this->take( 'conflicts', Simple_Storage_Index::report( 'conflict', self::REPORT_SAMPLES ) );

		$counts                 = &$this->job['report']['counts'];
		$counts['with_local']   = Simple_Storage_Index::totals( array( 'local' => 1, 'remote' => 1 ) )['files'];
		$counts['remote_total'] = $counts['with_local'] + $counts['only_remote'] + $counts['conflicts'];

		$missing                                        = $counts['only_remote'] + $counts['conflicts'];
		$this->job['report']['blocked']                 = $missing > 0;
		$this->job['progress']['remote_gate']['failed'] = $missing;
		if ( $missing > 0 ) {
			$this->stop_before_finish();

			return true;
		}

		// From here on new uploads stay in WordPress; a cancelled job gives the old mode back.
		$this->job['previous_mode'] = Simple_Storage_Settings::state()['mode'];
		Simple_Storage_Settings::update_state( array( 'mode' => 'local' ) );

		return true;
	}

	/** @return bool */
	private function phase_finish_copy_remote( float $deadline ) {
		$left      = Simple_Storage_Index::report( 'local_uncopied', 0 )['count'];
		$conflicts = Simple_Storage_Index::report( 'conflict', 0 )['count'];

		$this->job['summary'] = 0 === $left + $conflicts
			? __( 'Copied: every file in WordPress has a verified copy in the storage. Nothing was deleted, and the site serves its files as before.', 'simple-storage' )
			/* translators: %d: number of files. */
			: sprintf( __( 'Copied, but %d files in WordPress still have no verified copy in the storage, or differ from the file at the same path there. Nothing was deleted. Check the errors and copy again.', 'simple-storage' ), $left + $conflicts );

		return true;
	}

	/** @return bool */
	private function phase_finish_copy_local( float $deadline ) {
		$left = Simple_Storage_Index::remote_only_count();

		$this->job['summary'] = 0 === $left
			? __( 'Copied: every file in the storage is in WordPress too. Nothing was deleted, and new uploads stay in WordPress from now on.', 'simple-storage' )
			/* translators: %d: number of files. */
			: sprintf( __( 'Copied, but %d files are still only in the storage, name conflicts included. Nothing was deleted. Check the errors and copy again.', 'simple-storage' ), $left );

		return true;
	}

	/** @return bool */
	private function phase_finish_switch_local( float $deadline ) {
		$counts   = (array) ( $this->job['report']['counts'] ?? array() );
		$breaking = (int) ( $counts['only_remote'] ?? 0 ) + (int) ( $counts['conflicts'] ?? 0 );
		$missing  = (int) ( $counts['missing'] ?? 0 ) + (int) ( $counts['outside'] ?? 0 );

		if ( ! empty( $this->job['report']['blocked'] ) ) {
			$this->job['summary'] = $breaking > 0
				? __( 'Nothing was switched: some files are only in the storage, or differ from the file at the same path in WordPress; the report lists them. Bring them back (or copy everything to WordPress) and check again.', 'simple-storage' )
				/* translators: %d: number of files. */
				: sprintf( __( 'Nothing was switched: %d files the Media Library needs are missing both in WordPress and in the storage. Upload them and check again, or switch anyway.', 'simple-storage' ), $missing );
		} else {
			$this->job['summary'] = $missing > 0
				/* translators: %d: number of files. */
				? sprintf( __( 'Switched: the site serves its files from WordPress, and serving from the storage is off; %d files the Media Library needs are missing. The copies in the storage were kept.', 'simple-storage' ), $missing )
				: __( 'Switched: the site serves its files from WordPress, and serving from the storage is off. The copies in the storage were kept.', 'simple-storage' );
		}

		return true;
	}

	/** @return bool */
	private function phase_finish_clean_local( float $deadline ) {
		if ( ! empty( $this->job['report']['blocked'] ) ) {
			$this->job['summary'] = __( 'Nothing was deleted: not every file in WordPress has a verified copy in the storage; the report lists the ones that do not. Copy everything to the storage first.', 'simple-storage' );

			return true;
		}

		$left = Simple_Storage_Index::totals( array( 'local' => 1 ) )['files'];

		$this->job['summary'] = 0 === $left
			? __( 'Deleted: the site serves every file from the storage, and no copy is left in WordPress.', 'simple-storage' )
			/* translators: %d: number of files. */
			: sprintf( __( 'Deleted, but %d files stay in WordPress: serving their extension from the storage did not work, or the deletion failed. See the warnings and errors.', 'simple-storage' ), $left );

		return true;
	}

	/** @return bool */
	private function phase_finish_clean_remote( float $deadline ) {
		if ( ! empty( $this->job['report']['blocked'] ) ) {
			$this->job['summary'] = __( 'Nothing was deleted: not every file in the storage is in WordPress with the same contents; the report lists the ones that are not. Copy everything to WordPress first.', 'simple-storage' );

			return true;
		}

		$kept = Simple_Storage_Index::report( 'listed_any', 5, (int) $this->job['remote_scan'] );
		if ( $kept['count'] > 0 ) {
			$this->warn(
				/* translators: 1: number of files, 2: list of paths. */
				sprintf( __( '%1$d files in the storage differ from the files at the same paths in WordPress, so they were kept. Remove them in the storage panel if nobody needs them: %2$s', 'simple-storage' ), $kept['count'], implode( ', ', $kept['paths'] ) )
			);
		}

		$left = Simple_Storage_Index::totals( array( 'remote' => 1 ) )['files'];
		if ( 0 === Simple_Storage_Index::remote_only_count() ) {
			if ( Simple_Storage_Delivery::active() ) {
				Simple_Storage_Delivery::disable();
			}
			$this->warn( __( 'All files are back in WordPress. If the site is behind Cloudflare or another CDN, purge its cache so that cached redirects to the storage disappear.', 'simple-storage' ) );
		}

		$this->job['summary'] = 0 === $left
			? __( 'Deleted: the site serves every file from WordPress, and no copy is left in the storage.', 'simple-storage' )
			/* translators: %d: number of files. */
			: sprintf( __( 'Deleted, but %d copies in the storage could not be deleted. See the errors and run the job again.', 'simple-storage' ), $left );

		return true;
	}

	/**
	 * @param array<int, string> $keys Counts of the report, in the order they are shown.
	 * @return array<string, mixed>
	 */
	private static function new_report( array $keys ): array {
		return array(
			'counts'     => array_fill_keys( $keys, 0 ),
			'paths'      => array(),
			'blocked'    => false,
			'can_accept' => false,
		);
	}

	/** One more file of a kind in the report of a step-by-step job. */
	private function note( string $kind, string $path ): void {
		++$this->job['report']['counts'][ $kind ];
		if ( count( $this->job['report']['paths'][ $kind ] ?? array() ) < self::REPORT_SAMPLES ) {
			$this->job['report']['paths'][ $kind ][] = $path;
		}
	}

	/**
	 * A kind of files the index counts, into the report of a step-by-step job.
	 *
	 * @param array{count: int, bytes: int, paths: array<int, string>} $found
	 */
	private function take( string $kind, array $found ): void {
		$this->job['report']['counts'][ $kind ] = $found['count'];
		if ( ! empty( $found['paths'] ) ) {
			$this->job['report']['paths'][ $kind ] = $found['paths'];
		}
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
		// Adopted files have no local copy to fall back on: every extension must be proven.
		$adopting   = 'adopt' === $this->job['type'];
		$extensions = $adopting ? Simple_Storage_Delivery::extensions_to_verify() : Simple_Storage_Index::extensions( array( 'local' => 1, 'remote' => 1, 'verified' => 1 ), 20 );
		$report     = Simple_Storage_Delivery::verify_delivery( empty( $extensions ) ? array( 'txt' ) : $extensions );
		if ( is_wp_error( $report ) ) {
			return $report;
		}
		if ( empty( $report['ok'] ) ) {
			return new WP_Error( 'simple_storage_delivery_check', (string) reset( $report['failed'] ) );
		}
		foreach ( $report['failed'] as $extension => $message ) {
			$this->warn(
				$adopting
					/* translators: 1: file extension, 2: error message. */
					? sprintf( __( 'Files with the extension .%1$s are not served from the storage, and the site has no copy of them: %2$s', 'simple-storage' ), $extension, $message )
					/* translators: 1: file extension, 2: error message. */
					: sprintf( __( 'Files with the extension .%1$s are not served from the storage and stay local: %2$s', 'simple-storage' ), $extension, $message )
			);
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
			$this->warn( sprintf( __( '%d files are still only in the storage, name conflicts included, so serving from the storage stays on. Check the errors and run the transfer again.', 'simple-storage' ), $remaining ) );
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
			'index'        => __( 'Indexing', 'simple-storage' ),
			'push'         => __( 'Transfer to the storage', 'simple-storage' ),
			'pull'         => __( 'Return from the storage', 'simple-storage' ),
			'adopt'        => __( 'Switching to the storage', 'simple-storage' ),
			'copy_remote'  => __( 'Copying everything to the storage', 'simple-storage' ),
			'copy_local'   => __( 'Copying everything to WordPress', 'simple-storage' ),
			'switch_local' => __( 'Switching to WordPress', 'simple-storage' ),
			'clean_local'  => __( 'Deleting the copies in WordPress', 'simple-storage' ),
			'clean_remote' => __( 'Deleting the copies in the storage', 'simple-storage' ),
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
			'adopt_compare'   => __( 'Comparing with the Media Library', 'simple-storage' ),
			'finish_adopt'    => __( 'Finishing', 'simple-storage' ),
			'reconcile'       => __( 'Matching files at the same paths', 'simple-storage' ),
			'verify_listed'   => __( 'Checking copies already in the storage', 'simple-storage' ),
			'local_compare'   => __( 'Comparing the Media Library with the site disk', 'simple-storage' ),
			'disable_delivery' => __( 'Turning off serving from the storage', 'simple-storage' ),
			'local_gate'      => __( 'Checking that every file has a copy in the storage', 'simple-storage' ),
			'remote_gate'     => __( 'Checking that every file is in WordPress', 'simple-storage' ),
		);
		if ( str_starts_with( $phase, 'finish_' ) ) {
			return __( 'Finishing', 'simple-storage' );
		}

		return $labels[ $phase ] ?? $phase;
	}

	/** Phases with per-file progress bars. @return array<int, string> */
	public static function measured_phases(): array {
		return array( 'local_scan', 'remote_scan', 'reconcile', 'verify_listed', 'upload', 'verify', 'delete_local', 'download', 'delete_remote', 'adopt_compare', 'local_compare' );
	}

	/**
	 * The report of a job that compares or checks before it changes anything, for the page and
	 * WP-CLI: counts with their labels, the paths of each kind of problem, and whether the job
	 * stopped and may be run again accepting what is missing.
	 *
	 * @param array<string, mixed> $job
	 * @return array{rows: array<int, array{0: string, 1: int}>, lists: array<int, array{0: string, 1: array<int, string>}>, blocked: bool, canAccept: bool, acceptLabel: string, acceptConfirm: string}|null
	 */
	public static function report_view( array $job ): ?array {
		$labels = array(
			'attachments'  => __( 'Attachments checked:', 'simple-storage' ),
			'files'        => __( 'Files they need:', 'simple-storage' ),
			'found'        => __( 'In the storage:', 'simple-storage' ),
			'on_disk'      => __( 'On the site disk:', 'simple-storage' ),
			'missing'      => __( 'Missing:', 'simple-storage' ),
			'mismatch'     => __( 'Different size (image optimizers can cause this):', 'simple-storage' ),
			'outside'      => __( 'Missing outside the year and month folders (these belong on the site disk):', 'simple-storage' ),
			'extra'        => __( 'In the storage without an attachment (theme copies, leftovers):', 'simple-storage' ),
			'only_remote'  => __( 'Only in the storage:', 'simple-storage' ),
			'conflicts'    => __( 'A different file at the same path on the other side:', 'simple-storage' ),
			'local_total'  => __( 'Files in WordPress:', 'simple-storage' ),
			'with_copy'    => __( 'With a verified copy in the storage:', 'simple-storage' ),
			'uncopied'     => __( 'Without a verified copy in the storage:', 'simple-storage' ),
			'remote_total' => __( 'Files in the storage:', 'simple-storage' ),
			'with_local'   => __( 'The same file in WordPress:', 'simple-storage' ),
		);

		if ( isset( $job['adopt'] ) && is_array( $job['adopt'] ) ) {
			$adopt  = $job['adopt'];
			$report = array(
				'counts'     => array(),
				'paths'      => array(),
				'blocked'    => ! empty( $adopt['blocked'] ),
				'can_accept' => ! empty( $adopt['blocked'] ) && (int) ( $adopt['found'] ?? 0 ) > 0,
			);
			foreach ( array( 'attachments', 'files', 'found', 'on_disk', 'missing', 'mismatch', 'outside', 'extra' ) as $kind ) {
				$report['counts'][ $kind ] = (int) ( $adopt[ $kind ] ?? 0 );
			}
			foreach ( array( 'missing', 'mismatch', 'outside' ) as $kind ) {
				$report['paths'][ $kind ] = (array) ( $adopt[ $kind . '_paths' ] ?? array() );
			}
			$always = 3;
		} elseif ( isset( $job['report'] ) && is_array( $job['report'] ) ) {
			$report = $job['report'];
			$always = 'switch_local' === $job['type'] ? 3 : 2;
		} else {
			return null;
		}

		$rows     = array();
		$position = 0;
		foreach ( (array) $report['counts'] as $kind => $count ) {
			if ( $position++ < $always || (int) $count > 0 ) {
				$rows[] = array( $labels[ $kind ] ?? (string) $kind, (int) $count );
			}
		}
		$lists = array();
		foreach ( (array) $report['paths'] as $kind => $paths ) {
			if ( ! empty( $paths ) ) {
				$lists[] = array( $labels[ $kind ] ?? (string) $kind, array_values( array_map( 'strval', (array) $paths ) ) );
			}
		}

		$adopting = 'adopt' === $job['type'];

		return array(
			'rows'          => $rows,
			'lists'         => $lists,
			'blocked'       => ! empty( $report['blocked'] ),
			'canAccept'     => ! empty( $report['can_accept'] ),
			'acceptLabel'   => $adopting ? __( 'Connect anyway', 'simple-storage' ) : __( 'Switch anyway', 'simple-storage' ),
			'acceptConfirm' => $adopting
				? __( "The site will serve what the storage has. Files that are missing stay broken until they are uploaded.\n\nConnect anyway?", 'simple-storage' )
				: __( "The site will serve its files from WordPress. Files that are missing stay broken until they are uploaded.\n\nSwitch anyway?", 'simple-storage' ),
		);
	}
}
