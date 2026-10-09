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

		$sha256  = (string) hash_file( 'sha256', $local );
		$fetched = $client->fetch( Simple_Storage_Paths::public_url( $path ) );
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
		if ( $fetched['size'] !== $size || ( Simple_Storage_Settings::strict_verify() && $fetched['sha256'] !== $sha256 ) ) {
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

		// Deliberately not wp_delete_file(): its filter would also delete the remote copy.
		if ( ! @unlink( $local ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			return self::fail( $row, new WP_Error( 'simple_storage_unlink', __( 'The local file could not be deleted.', 'simple-storage' ) ) );
		}

		Simple_Storage_Index::update(
			(int) $row['id'],
			array(
				'local' => 0,
				'error' => null,
			)
		);

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

		clearstatcache( true, $local );
		if ( is_file( $local ) ) {
			if ( (int) filesize( $local ) !== $expected ) {
				return self::fail( $row, new WP_Error( 'simple_storage_conflict', __( 'A different local file already exists at this path; it was kept and the remote copy was not downloaded.', 'simple-storage' ) ) );
			}

			self::mark_local( $row, $local, null );

			return 0;
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
