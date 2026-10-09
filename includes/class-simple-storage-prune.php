<?php
/**
 * Storage folders that deleting files left empty.
 *
 * The storage keeps a folder after its last file is deleted, so deleting attachments (or bringing
 * everything back) would leave empty YYYY/MM and YYYY folders behind. A successful remote delete
 * schedules a run for its month folder; the run removes the month's empty subfolders, the month
 * once nothing is left in it, and then the year once it is empty too.
 *
 * Deleting a folder in the storage deletes everything in it, so a folder goes only when:
 * - the run holds the job lock, which every upload into the media folders holds as well, and still
 *   holds it with time to spare right before each deletion;
 * - a listing in a recognised format shows no file in it, and the folder exists as a folder (the
 *   storage lists a missing folder as empty);
 * - the index knows no file in the storage under it.
 * The site folder itself, and anything outside the year and month folders, is never touched.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_Storage_Prune {
	public const HOOK = 'simple_storage_prune_dir';

	/** Deletions in one month within this time share a run. */
	private const DELAY = MINUTE_IN_SECONDS;

	/**
	 * Seconds of lock a folder deletion needs: it is sent once, without retries, and one storage
	 * request is cut off after 60 seconds.
	 */
	private const LOCK_MARGIN = 200;

	/** A folder the storage keeps refusing is left after this many further runs. */
	private const MAX_ATTEMPTS = 3;

	public static function init(): void {
		add_action( self::HOOK, array( self::class, 'run' ), 10, 2 );
	}

	/** A file was deleted in the storage: its month folder may be empty now. */
	public static function schedule( string $relative, int $delay = self::DELAY ): void {
		$month = Simple_Storage_Paths::month_dir( $relative );
		if ( self::prunable( $month ) ) {
			Simple_Storage_Runner::schedule_event( time() + $delay, self::HOOK, array( $month ) );
		}
	}

	/** A month folder of media; "0000/00" is where serving checks put their probe files. */
	private static function prunable( string $month ): bool {
		return Simple_Storage_Paths::is_month_dir( $month ) && ! str_starts_with( $month, '0000/' );
	}

	/**
	 * Cron: remove what deleting files left empty in one month folder, and its year.
	 *
	 * @param mixed $month
	 * @param mixed $attempt How many runs for this folder failed before.
	 */
	public static function run( $month, $attempt = 0 ): void {
		$month   = (string) $month;
		$attempt = (int) $attempt;
		if ( ! self::prunable( $month ) || ! Simple_Storage_Settings::is_configured() ) {
			return;
		}

		// As the automatic offload does: never alongside a job or a regeneration, and only with
		// the lock, which keeps every upload out of the folders meanwhile.
		if ( Simple_Storage_Jobs::is_active() || Simple_Storage_Media::regeneration_running() ) {
			self::schedule( $month, 5 * MINUTE_IN_SECONDS );

			return;
		}
		$lock = Simple_Storage_Jobs::lock();
		if ( null === $lock ) {
			self::schedule( $month, 5 * MINUTE_IN_SECONDS );

			return;
		}

		try {
			// A job may have started between the check and the lock; it does not take the lock.
			if ( Simple_Storage_Jobs::is_active() ) {
				self::schedule( $month, 5 * MINUTE_IN_SECONDS );

				return;
			}

			$client = Simple_Storage_Client::create();
			$result = is_wp_error( $client ) ? $client : self::prune_locked( $client, $month );
			if ( is_wp_error( $result ) ) {
				// Logged on the first failure and when giving up, not on every try in between.
				if ( 0 === $attempt || $attempt >= self::MAX_ATTEMPTS ) {
					Simple_Storage_Log::error( $month . ': ' . $result->get_error_message() );
				}
				if ( $attempt < self::MAX_ATTEMPTS ) {
					Simple_Storage_Runner::schedule_event( time() + 15 * MINUTE_IN_SECONDS * ( $attempt + 1 ), self::HOOK, array( $month, $attempt + 1 ) );
				}
			}
		} finally {
			Simple_Storage_Jobs::unlock( $lock );
		}
	}

	/**
	 * Remove the empty folders of one month, the month once it holds no file, then its year once
	 * that is empty. Only for code that holds the job lock (a job step, the scheduled run).
	 *
	 * @return true|WP_Error An error worth another try later: the storage did not answer, or the
	 *                       lock was lost. A listing that cannot be read is logged and left alone.
	 */
	public static function prune_locked( Simple_Storage_Client $client, string $month ) {
		if ( ! self::prunable( $month ) ) {
			return true;
		}

		// The storage lists a missing folder as empty; a "not found" answer is not a listing at all,
		// so nothing in the folder was seen and nothing is deleted.
		$items = $client->list_dir( Simple_Storage_Paths::remote( $month ), true, true );
		if ( is_wp_error( $items ) ) {
			return 'simple_storage_not_found' === $items->get_error_code() ? true : self::listing_error( $month, $items );
		}

		$files = array();
		$dirs  = array();
		foreach ( $items as $item ) {
			$relative = self::relative( $item['path'] );
			if ( null === $relative || ! str_starts_with( $relative, $month . '/' ) ) {
				// Not the folder that was asked for: nothing in this listing can be trusted.
				return self::listing_error( $month, new WP_Error( 'simple_storage_bad_listing', __( 'The storage returned a directory listing that cannot be read.', 'simple-storage' ) ) );
			}
			if ( $item['directory'] ) {
				$dirs[] = $relative;
			} else {
				$files[] = $relative;
			}
		}

		// The month with no file at all goes whole; otherwise the topmost subfolders without one.
		$empty = array();
		if ( empty( $files ) ) {
			$empty[] = $month;
		} else {
			sort( $dirs );
			foreach ( $dirs as $dir ) {
				foreach ( $empty as $gone ) {
					if ( str_starts_with( $dir, $gone . '/' ) ) {
						continue 2;
					}
				}
				foreach ( $files as $file ) {
					if ( str_starts_with( $file, $dir . '/' ) ) {
						continue 2;
					}
				}
				$empty[] = $dir;
			}
		}

		$month_gone = false;
		foreach ( $empty as $dir ) {
			$gone = self::delete_if_empty( $client, $dir );
			if ( is_wp_error( $gone ) ) {
				return $gone;
			}
			if ( $dir === $month ) {
				$month_gone = $gone;
			}
		}
		if ( ! $month_gone ) {
			return true;
		}

		$year  = substr( $month, 0, 4 );
		$items = $client->list_dir( Simple_Storage_Paths::remote( $year ), false, true );
		if ( is_wp_error( $items ) ) {
			return 'simple_storage_not_found' === $items->get_error_code() ? true : self::listing_error( $year, $items );
		}
		if ( ! empty( $items ) ) {
			return true;
		}

		$gone = self::delete_if_empty( $client, $year );

		return is_wp_error( $gone ) ? $gone : true;
	}

	/**
	 * Delete a folder a listing showed without files, after the last checks.
	 *
	 * @return bool|WP_Error Whether the folder is gone now (deleted, or not there at all).
	 */
	private static function delete_if_empty( Simple_Storage_Client $client, string $relative ) {
		// The index knows files the storage may not list yet (a listing can lag behind an upload).
		if ( Simple_Storage_Index::has_remote_under( $relative ) ) {
			return false;
		}

		$path = Simple_Storage_Paths::remote( $relative );
		$stat = $client->stat( $path );
		if ( is_wp_error( $stat ) ) {
			return $stat;
		}
		if ( ! $stat['exists'] ) {
			return true;
		}
		if ( ! $stat['directory'] ) {
			return false;
		}

		// The lock keeps uploads out only while it is ours, for as long as this request may take.
		Simple_Storage_Jobs::heartbeat();
		if ( ! Simple_Storage_Jobs::holds_lock( self::LOCK_MARGIN ) ) {
			return Simple_Storage_Jobs::lock_lost();
		}

		$deleted = $client->delete_dir( $path, true );
		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}

		/* translators: %s: folder path. */
		Simple_Storage_Log::info( sprintf( __( 'Removed the empty folder %s from the storage.', 'simple-storage' ), $relative ) );

		return true;
	}

	/** @return true|WP_Error A listing in an unknown format is not retried: it would not change. */
	private static function listing_error( string $relative, WP_Error $error ) {
		if ( 'simple_storage_bad_listing' === $error->get_error_code() ) {
			Simple_Storage_Log::error( $relative . ': ' . $error->get_error_message() );

			return true;
		}

		return $error;
	}

	/** Path of a storage item relative to the site folder, or null outside it. */
	private static function relative( string $remote ): ?string {
		$root   = Simple_Storage_Paths::remote_root() . '/';
		$remote = rtrim( $remote, '/' );

		return str_starts_with( $remote, $root ) && strlen( $remote ) > strlen( $root ) ? substr( $remote, strlen( $root ) ) : null;
	}
}
