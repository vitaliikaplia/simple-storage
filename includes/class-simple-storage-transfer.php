<?php
/**
 * Single-file operations shared by the migration jobs and the automatic offload of new uploads.
 *
 * Each operation re-checks the real state of the file, records the outcome in the index and never
 * removes the last verified copy: a local file goes only after its remote copy was verified, and a
 * remote file goes only after the local copy matches it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_Storage_Transfer {
	/**
	 * Upload a local file and confirm its size in the storage.
	 *
	 * @param array<string, mixed> $row Index row.
	 * @return int|WP_Error Bytes uploaded; 0 when the row needed no upload.
	 */
	public static function upload( Simple_Storage_Client $client, array $row ) {
		$path  = (string) $row['path'];
		$local = Simple_Storage_Paths::local( $path );

		if ( Simple_Storage_Paths::is_excluded( $path ) ) {
			return self::fail( $row, self::excluded() );
		}

		clearstatcache( true, $local );
		if ( ! is_file( $local ) ) {
			self::forget_local( $row );

			return 0;
		}

		$size  = (int) filesize( $local );
		$mtime = (int) filemtime( $local );
		if ( $size !== (int) $row['size'] || $mtime !== (int) $row['mtime'] ) {
			Simple_Storage_Index::update(
				(int) $row['id'],
				array(
					'size'  => $size,
					'mtime' => $mtime,
				)
			);
		}

		$remote = Simple_Storage_Paths::remote( $path );
		$dir    = $client->ensure_dir( dirname( $remote ) );
		if ( is_wp_error( $dir ) ) {
			return self::fail( $row, $dir );
		}

		// Every upload runs under the job lock, which keeps folder removal out (Simple_Storage_Prune):
		// one whose lock lapsed meanwhile and went to another request must not write.
		Simple_Storage_Jobs::heartbeat();
		if ( ! Simple_Storage_Jobs::holds_lock( 60 ) ) {
			return self::fail( $row, Simple_Storage_Jobs::lock_lost() );
		}

		$uploaded = $client->upload( $remote, $local, true );
		if ( is_wp_error( $uploaded ) ) {
			return self::fail( $row, $uploaded );
		}

		$stat = $client->stat( $remote );
		if ( is_wp_error( $stat ) ) {
			return self::fail( $row, $stat );
		}
		if ( ! $stat['exists'] || ( null !== $stat['size'] && $stat['size'] !== $size ) ) {
			return self::fail( $row, new WP_Error( 'simple_storage_size', __( 'The uploaded file has a different size in the storage.', 'simple-storage' ) ) );
		}

		Simple_Storage_Index::update(
			(int) $row['id'],
			array(
				'remote'      => 1,
				'verified'    => 0,
				'remote_size' => $size,
				'sha256'      => null,
				'error'       => null,
			)
		);

		return $size;
	}

	/**
	 * Verify the remote copy against the local file: the size through the API, and the public
	 * address either by its headers or, in strict mode, by downloading it and comparing SHA-256.
	 *
	 * @param array<string, mixed> $row
	 * @return bool|WP_Error True when verified.
	 */
	public static function verify( Simple_Storage_Client $client, array $row ) {
		$path  = (string) $row['path'];
		$local = Simple_Storage_Paths::local( $path );

		clearstatcache( true, $local );
		if ( ! is_file( $local ) ) {
			Simple_Storage_Index::update( (int) $row['id'], array( 'local' => 0 ) );

			return false;
		}

		$size = (int) filesize( $local );
		$stat = $client->stat( Simple_Storage_Paths::remote( $path ) );
		if ( is_wp_error( $stat ) ) {
			return self::fail( $row, $stat );
		}
		if ( ! $stat['exists'] || ( null !== $stat['size'] && $stat['size'] !== $size ) ) {
			Simple_Storage_Index::update(
				(int) $row['id'],
				array(
					'remote'      => 0,
					'verified'    => 0,
					'remote_size' => null,
				)
			);

			return self::fail( $row, new WP_Error( 'simple_storage_size', __( 'The remote copy is missing or has a different size; it will be uploaded again.', 'simple-storage' ) ) );
		}

		// Kept in every mode: a later download is compared with it.
		$sha256 = (string) hash_file( 'sha256', $local );
		$strict = Simple_Storage_Settings::strict_verify();
		$url    = Simple_Storage_Paths::public_url( $path );
		$fetched = $strict ? $client->fetch( $url ) : $client->head( $url );
		if ( ! $strict && ! is_wp_error( $fetched ) && null === $fetched['size'] ) {
			// No Content-Length (a chunked answer): the size is known only after reading the body.
			$fetched = $client->fetch( $url );
		}
		if ( is_wp_error( $fetched ) ) {
			return self::fail( $row, $fetched );
		}
		if ( 200 !== $fetched['status'] ) {
			return self::fail(
				$row,
				/* translators: %d: HTTP status code. */
				new WP_Error( 'simple_storage_public', sprintf( __( 'The public address answered %d. Check public access in the storage panel.', 'simple-storage' ), $fetched['status'] ) )
			);
		}
		if ( $fetched['size'] !== $size || ( $strict && $fetched['sha256'] !== $sha256 ) ) {
			Simple_Storage_Index::update(
				(int) $row['id'],
				array(
					'remote'   => 0,
					'verified' => 0,
				)
			);

			return self::fail( $row, new WP_Error( 'simple_storage_integrity', __( 'The downloaded copy differs from the local file; it will be uploaded again.', 'simple-storage' ) ) );
		}

		Simple_Storage_Index::update(
			(int) $row['id'],
			array(
				'verified' => 1,
				'sha256'   => $sha256,
				'error'    => null,
			)
		);

		return true;
	}

	/**
	 * Remove a verified local copy, unless the file changed after verification or the remote copy
	 * is no longer there: a verification may be old (a cancelled push, another storage address,
	 * files deleted in the storage panel), so the storage is asked once more right before unlink.
	 *
	 * @param array<string, mixed> $row
	 * @return int|WP_Error Bytes freed.
	 */
	public static function remove_local( Simple_Storage_Client $client, array $row ) {
		$local = Simple_Storage_Paths::local( (string) $row['path'] );

		clearstatcache( true, $local );
		if ( ! is_file( $local ) ) {
			Simple_Storage_Index::update( (int) $row['id'], array( 'local' => 0 ) );

			return 0;
		}

		$size = (int) filesize( $local );
		if ( empty( $row['verified'] ) || empty( $row['remote'] ) || $size !== (int) $row['size'] || (int) filemtime( $local ) !== (int) $row['mtime'] ) {
			Simple_Storage_Index::update(
				(int) $row['id'],
				array(
					'remote'   => 0,
					'verified' => 0,
				)
			);

			return self::fail( $row, new WP_Error( 'simple_storage_changed', __( 'The file changed after verification and was kept; it will be uploaded again.', 'simple-storage' ) ) );
		}

		$stat = $client->stat( Simple_Storage_Paths::remote( (string) $row['path'] ) );
		if ( is_wp_error( $stat ) ) {
			return self::fail( $row, $stat );
		}
		if ( ! $stat['exists'] || $stat['directory'] || ( null !== $stat['size'] && $stat['size'] !== $size ) ) {
			Simple_Storage_Index::update(
				(int) $row['id'],
				array(
					'remote'      => 0,
					'verified'    => 0,
					'remote_size' => null,
				)
			);

			return self::fail( $row, new WP_Error( 'simple_storage_remote_gone', __( 'The copy in the storage is missing or differs, so the local file was kept; it will be uploaded again.', 'simple-storage' ) ) );
		}

		// The index comes first: should the request die here, the next scan finds a file it can
		// account for, while the other order leaves the name looking free to a new upload, which
		// would overwrite the only copy in the storage.
		Simple_Storage_Index::update(
			(int) $row['id'],
			array(
				'local' => 0,
				'error' => null,
			)
		);

		// Deliberately not wp_delete_file(): its filter would also delete the remote copy.
		if ( ! @unlink( $local ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			Simple_Storage_Index::update( (int) $row['id'], array( 'local' => 1 ) );

			return self::fail( $row, new WP_Error( 'simple_storage_unlink', __( 'The local file could not be deleted.', 'simple-storage' ) ) );
		}

		return $size;
	}

	/**
	 * Download a remote file into the uploads directory through a temporary file.
	 *
	 * @param array<string, mixed> $row
	 * @return int|WP_Error Bytes downloaded.
	 */
	public static function download( Simple_Storage_Client $client, array $row ) {
		$path     = (string) $row['path'];
		$local    = Simple_Storage_Paths::local( $path );
		$expected = null !== $row['remote_size'] ? (int) $row['remote_size'] : (int) $row['size'];

		if ( Simple_Storage_Paths::is_excluded( $path ) ) {
			return self::fail( $row, self::excluded() );
		}

		clearstatcache( true, $local );
		if ( is_file( $local ) ) {
			return self::match_local( $client, $row );
		}

		if ( ! wp_mkdir_p( dirname( $local ) ) ) {
			return self::fail( $row, new WP_Error( 'simple_storage_mkdir', __( 'The local folder could not be created.', 'simple-storage' ) ) );
		}

		// A name of its own per download: two runners must never write into the same file.
		$temporary = $local . '.' . strtolower( wp_generate_password( 8, false, false ) ) . Simple_Storage_Paths::PART_SUFFIX;
		$fetched   = $client->fetch( Simple_Storage_Paths::public_url( $path ), $temporary );
		$error     = null;
		if ( is_wp_error( $fetched ) ) {
			$error = $fetched;
		} elseif ( 200 !== $fetched['status'] ) {
			/* translators: %d: HTTP status code. */
			$error = new WP_Error( 'simple_storage_public', sprintf( __( 'The public address answered %d.', 'simple-storage' ), $fetched['status'] ) );
		} elseif ( $fetched['size'] !== $expected || ( ! empty( $row['sha256'] ) && $fetched['sha256'] !== $row['sha256'] ) ) {
			$error = new WP_Error( 'simple_storage_integrity', __( 'The downloaded file does not match the recorded size or checksum.', 'simple-storage' ) );
		}

		if ( null !== $error || ! @rename( $temporary, $local ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			@unlink( $temporary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink

			return self::fail( $row, $error ?? new WP_Error( 'simple_storage_rename', __( 'The downloaded file could not be moved into place.', 'simple-storage' ) ) );
		}

		// Permissions as WordPress gives new uploads; the original modification time keeps
		// Last-Modified and ETag of the file stable for browser and CDN caches.
		$stat  = stat( dirname( $local ) );
		$perms = $stat ? $stat['mode'] & 0000666 : 0644;
		@chmod( $local, $perms ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		if ( (int) $row['mtime'] > 0 ) {
			@touch( $local, (int) $row['mtime'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		self::mark_local( $row, $local, $fetched['sha256'] );

		return $expected;
	}

	/**
	 * Delete the remote copy once the local file matches it.
	 *
	 * @param array<string, mixed> $row
	 * @return int|WP_Error Bytes removed from the storage.
	 */
	public static function remove_remote( Simple_Storage_Client $client, array $row ) {
		$path  = (string) $row['path'];
		$local = Simple_Storage_Paths::local( $path );

		clearstatcache( true, $local );
		if ( ! is_file( $local ) ) {
			Simple_Storage_Index::update( (int) $row['id'], array( 'local' => 0 ) );

			return self::fail( $row, new WP_Error( 'simple_storage_local_missing', __( 'The local copy is missing, so the remote copy was kept.', 'simple-storage' ) ) );
		}

		$size     = (int) filesize( $local );
		$expected = null !== $row['remote_size'] ? (int) $row['remote_size'] : (int) $row['size'];
		if ( $size !== $expected || ( ! empty( $row['sha256'] ) && hash_file( 'sha256', $local ) !== $row['sha256'] ) ) {
			return self::fail( $row, new WP_Error( 'simple_storage_integrity', __( 'The local file does not match the remote copy, so the remote copy was kept.', 'simple-storage' ) ) );
		}

		$deleted = $client->delete_file( Simple_Storage_Paths::remote( $path ) );
		if ( is_wp_error( $deleted ) ) {
			return self::fail( $row, $deleted );
		}

		Simple_Storage_Index::update(
			(int) $row['id'],
			array(
				'remote'      => 0,
				'verified'    => 0,
				'remote_size' => null,
				'error'       => null,
			)
		);

		return $expected;
	}

	/**
	 * A local file is where a file that lives in the storage is recorded: a download found it in
	 * place, or it was brought back by hand (FTP) under a new modification time, which the index
	 * records as a name conflict. It counts as the copy only when its contents are proven equal;
	 * an equal size proves nothing. Then the row describes both copies again and the conflict is
	 * gone. Anything else is a name conflict: both files stay, and neither is deleted as the
	 * "copy" of the other.
	 *
	 * @param array<string, mixed> $row
	 * @return int|WP_Error 0 when the local file is the copy.
	 */
	public static function match_local( Simple_Storage_Client $client, array $row ) {
		$local    = Simple_Storage_Paths::local( (string) $row['path'] );
		$expected = null !== $row['remote_size'] ? (int) $row['remote_size'] : (int) $row['size'];

		clearstatcache( true, $local );
		if ( ! is_file( $local ) ) {
			return new WP_Error( 'simple_storage_local_missing', __( 'The local file is gone.', 'simple-storage' ) );
		}

		if ( (int) filesize( $local ) === $expected ) {
			$sha256  = (string) hash_file( 'sha256', $local );
			$remote  = (string) ( $row['sha256'] ?? '' );
			$fetched = false;
			if ( '' === $remote ) {
				// Known only from a storage listing: the storage has to show its copy.
				$response = $client->fetch( Simple_Storage_Paths::public_url( (string) $row['path'] ) );
				if ( ! is_wp_error( $response ) && 200 !== $response['status'] ) {
					/* translators: %d: HTTP status code. */
					$response = new WP_Error( 'simple_storage_public', sprintf( __( 'The public address answered %d.', 'simple-storage' ), $response['status'] ) );
				}
				if ( is_wp_error( $response ) ) {
					// A conflict keeps its own message: the storage was only out of reach this time.
					return $row['conflict'] ? $response : self::fail( $row, $response );
				}
				$remote  = $response['sha256'];
				$fetched = true;
			}
			if ( $remote === $sha256 ) {
				self::mark_local( $row, $local, $sha256 );
				$fields = array( 'conflict' => 0 );
				if ( $fetched ) {
					// The copy the storage serves was just compared byte for byte.
					$fields['verified'] = 1;
				}
				Simple_Storage_Index::update( (int) $row['id'], $fields );

				return 0;
			}
		}

		$message = Simple_Storage_Index::conflict_message();
		Simple_Storage_Index::update(
			(int) $row['id'],
			array(
				'local'    => 1,
				'conflict' => 1,
				'error'    => mb_substr( $message, 0, 250 ),
			)
		);

		return new WP_Error( 'simple_storage_conflict', $message );
	}

	private static function excluded(): WP_Error {
		return new WP_Error( 'simple_storage_excluded', __( 'Files of this type are not moved to the storage or brought back from it.', 'simple-storage' ) );
	}

	/** @param array<string, mixed> $row */
	private static function mark_local( array $row, string $local, ?string $sha256 ): void {
		$fields = array(
			'local' => 1,
			'size'  => (int) filesize( $local ),
			'mtime' => (int) filemtime( $local ),
			'error' => null,
		);
		if ( null !== $sha256 ) {
			$fields['sha256'] = $sha256;
		}

		Simple_Storage_Index::update( (int) $row['id'], $fields );
	}

	/** @param array<string, mixed> $row */
	private static function forget_local( array $row ): void {
		if ( empty( $row['remote'] ) ) {
			Simple_Storage_Index::delete( (int) $row['id'] );
		} else {
			Simple_Storage_Index::update( (int) $row['id'], array( 'local' => 0 ) );
		}
	}

	/** @param array<string, mixed> $row */
	private static function fail( array $row, WP_Error $error ): WP_Error {
		Simple_Storage_Index::update( (int) $row['id'], array( 'error' => mb_substr( $error->get_error_message(), 0, 250 ) ) );

		return $error;
	}
}
