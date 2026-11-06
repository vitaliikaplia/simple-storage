<?php
/**
 * WP-CLI commands. They run the same jobs as the admin page, without a browser tab to keep open.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Move WordPress media to the Hosting Ukraine storage and back.
 */
final class Simple_Storage_CLI {
	/**
	 * Show the index statistics, serving state and the current job.
	 *
	 * @when after_wp_load
	 */
	public function status(): void {
		$stats = Simple_Storage_Index::stats();
		$state = Simple_Storage_Settings::state();
		foreach ( array( 'total', 'local_only', 'both', 'remote_only' ) as $key ) {
			WP_CLI::log( sprintf( '%-12s %8d files  %s', $key, $stats[ $key ]['files'], size_format( $stats[ $key ]['bytes'], 1 ) ?: '0 B' ) );
		}
		WP_CLI::log( sprintf( 'errors       %8d', $stats['errors'] ) );
		WP_CLI::log( 'serving from storage: ' . ( Simple_Storage_Delivery::active() ? 'on' : 'off' ) . ', mode: ' . $state['mode'] . ', auto offload: ' . ( Simple_Storage_Media::auto_enabled() ? 'on' : 'off' ) );

		$job = Simple_Storage_Jobs::current();
		if ( null !== $job ) {
			WP_CLI::log( sprintf( 'job: %s (%s)', $job['type'], $job['status'] ) );
		}
	}

	/**
	 * Test the storage connection with a small file.
	 *
	 * @when after_wp_load
	 */
	public function test(): void {
		$report = Simple_Storage_Tester::run();
		foreach ( $report['steps'] as $step ) {
			WP_CLI::log( ( $step['ok'] ? '[ok]   ' : '[fail] ' ) . $step['label'] . ( '' !== $step['message'] ? ' — ' . $step['message'] : '' ) );
		}
		$report['ok'] ? WP_CLI::success( 'Connection works.' ) : WP_CLI::error( 'Connection test failed.' );
	}

	/**
	 * Index the media files (and the storage, when configured).
	 *
	 * ## OPTIONS
	 *
	 * [--confirm-missing]
	 * : Ask the storage about every recorded file its listing did not show, however many there
	 * are, and forget the ones it no longer has.
	 *
	 * @when after_wp_load
	 */
	public function index( array $args = array(), array $assoc = array() ): void {
		$this->run_job( 'index', array( 'confirm_missing' => ! empty( $assoc['confirm-missing'] ) ) );
	}

	/**
	 * Move every media file to the storage: copy, verify, enable serving, delete local copies.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * @when after_wp_load
	 */
	public function push( array $args, array $assoc ): void {
		WP_CLI::confirm( 'Local copies will be deleted after verification. Continue?', $assoc );
		$this->run_job( 'push' );
	}

	/**
	 * Return every media file to WordPress: download, verify, delete from the storage, disable serving.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * @when after_wp_load
	 */
	public function pull( array $args, array $assoc ): void {
		WP_CLI::confirm( 'Remote copies will be deleted after the files are back. Continue?', $assoc );
		$this->run_job( 'pull' );
	}

	/**
	 * Take over files uploaded to the storage by hand: compare them with the Media Library and,
	 * when nothing is missing, turn on serving. Nothing is copied.
	 *
	 * ## OPTIONS
	 *
	 * [--accept-missing]
	 * : Connect although files are missing or differ in size.
	 *
	 * @when after_wp_load
	 */
	public function adopt( array $args = array(), array $assoc = array() ): void {
		$this->run_job( 'adopt', array( 'accept_missing' => ! empty( $assoc['accept-missing'] ) ) );
	}

	/**
	 * Continue a paused or failed job.
	 *
	 * @when after_wp_load
	 */
	public function resume(): void {
		$job = Simple_Storage_Jobs::resume();
		if ( is_wp_error( $job ) ) {
			WP_CLI::error( $job->get_error_message() );
		}
		$this->loop();
	}

	/**
	 * Cancel the current job.
	 *
	 * @when after_wp_load
	 */
	public function cancel(): void {
		Simple_Storage_Jobs::cancel();
		WP_CLI::success( 'Cancelled.' );
	}

	/**
	 * Turn serving from the storage on or off.
	 *
	 * ## OPTIONS
	 *
	 * <state>
	 * : on or off
	 * ---
	 * options:
	 *   - on
	 *   - off
	 * ---
	 *
	 * @when after_wp_load
	 */
	public function delivery( array $args ): void {
		if ( 'on' === $args[0] ) {
			$result = Simple_Storage_Delivery::enable();
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}
			// New uploads follow into the storage only once serving is proven.
			$report = Simple_Storage_Delivery::verify_current();
			if ( is_wp_error( $report ) ) {
				WP_CLI::error( $report->get_error_message() );
			}
			foreach ( $report['failed'] as $extension => $message ) {
				WP_CLI::warning( ( '' !== (string) $extension ? '.' . $extension . ': ' : '' ) . $message );
			}
			if ( empty( $report['ok'] ) ) {
				WP_CLI::error( 'Serving could not be verified.' );
			}
		} else {
			Simple_Storage_Delivery::disable();
		}
		WP_CLI::success( 'Serving from the storage: ' . $args[0] . '.' );
	}

	/** @param array<string, mixed> $options */
	private function run_job( string $type, array $options = array() ): void {
		$job = Simple_Storage_Jobs::start( $type, $options );
		if ( is_wp_error( $job ) ) {
			WP_CLI::error( $job->get_error_message() );
		}
		$this->loop();
	}

	private function loop(): void {
		$last = '';
		$busy = 0;
		while ( true ) {
			$job = Simple_Storage_Jobs::step( 30.0 );
			if ( is_wp_error( $job ) && 'simple_storage_job_locked' === $job->get_error_code() && $busy < 360 ) {
				// The admin page or another process is working on the job right now: wait for it.
				if ( 0 === $busy ) {
					WP_CLI::log( $job->get_error_message() . ' Waiting…' );
				}
				++$busy;
				sleep( 5 );
				continue;
			}
			$busy = 0;
			if ( is_wp_error( $job ) ) {
				WP_CLI::error( $job->get_error_message() );
			}

			$phase = $job['phases'][ $job['phase'] ] ?? '';
			if ( '' !== $phase && isset( $job['progress'][ $phase ] ) ) {
				$progress = $job['progress'][ $phase ];
				$line     = sprintf( '%s: %d/%d (%s)%s', Simple_Storage_Jobs::phase_label( $phase ), $progress['done'], $progress['total'], size_format( $progress['bytes'], 1 ) ?: '0 B', $progress['failed'] ? ', failed ' . $progress['failed'] : '' );
				if ( $line !== $last ) {
					WP_CLI::log( $line );
					$last = $line;
				}
			}

			if ( 'running' !== $job['status'] ) {
				break;
			}
		}

		foreach ( (array) $job['warnings'] as $warning ) {
			WP_CLI::warning( $warning );
		}
		$report = (array) ( $job['adopt'] ?? array() );
		foreach ( array( 'missing' => 'missing', 'mismatch' => 'different size', 'outside' => 'missing outside the media folders' ) as $kind => $label ) {
			if ( ! empty( $report[ $kind ] ) ) {
				WP_CLI::log( sprintf( '%s: %d', $label, $report[ $kind ] ) );
				foreach ( (array) $report[ $kind . '_paths' ] as $path ) {
					WP_CLI::log( '  ' . $path );
				}
			}
		}
		if ( ! empty( $report['blocked'] ) ) {
			WP_CLI::error( (string) $job['message'] );
		}
		if ( 'done' === $job['status'] ) {
			WP_CLI::success( (string) $job['message'] );
		} else {
			WP_CLI::error( $job['status'] . ( '' !== $job['message'] ? ': ' . $job['message'] : '' ) );
		}
	}
}
