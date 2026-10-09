<?php
/**
 * Index table of media files and where each one currently lives.
 *
 * local    — the file exists in the uploads directory;
 * remote   — the file was uploaded to the storage and its size matched;
 * verified — the remote copy passed verification and the local copy may be removed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_Storage_Index {
	public const DB_VERSION = 1;
	public const DB_VERSION_OPTION = 'simple_storage_db_version';

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'simple_storage_files';
	}

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				path_hash char(40) NOT NULL,
				path varchar(1024) NOT NULL,
				size bigint(20) unsigned NOT NULL DEFAULT 0,
				mtime int(10) unsigned NOT NULL DEFAULT 0,
				local tinyint(1) unsigned NOT NULL DEFAULT 0,
				remote tinyint(1) unsigned NOT NULL DEFAULT 0,
				verified tinyint(1) unsigned NOT NULL DEFAULT 0,
				remote_size bigint(20) unsigned DEFAULT NULL,
				sha256 char(64) DEFAULT NULL,
				local_scan int(10) unsigned NOT NULL DEFAULT 0,
				remote_scan int(10) unsigned NOT NULL DEFAULT 0,
				conflict tinyint(1) unsigned NOT NULL DEFAULT 0,
				error varchar(255) DEFAULT NULL,
				updated_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
				PRIMARY KEY  (id),
				UNIQUE KEY path_hash (path_hash),
				KEY state (local,remote,verified)
			) {$charset};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
	}

	public static function maybe_install(): void {
		if ( (int) get_option( self::DB_VERSION_OPTION, 0 ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	public static function drop(): void {
		global $wpdb;

		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		delete_option( self::DB_VERSION_OPTION );
	}

	public static function hash( string $path ): string {
		return sha1( $path );
	}

	/** @return array<string, mixed>|null */
	public static function get( string $path ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE path_hash = %s', self::hash( $path ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $row ) ? self::cast( $row ) : null;
	}

	/**
	 * Rows whose path starts with a prefix ("2024/05/photo-"), keyed by path.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function with_prefix( string $prefix ): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE path LIKE %s', $wpdb->esc_like( $prefix ) . '%' ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		$result = array();
		foreach ( (array) $rows as $row ) {
			$row                    = self::cast( $row );
			$result[ $row['path'] ] = $row;
		}

		return $result;
	}

	/**
	 * Rows of one folder keyed by path, for folder-level scans.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function in_dir( string $dir ): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE path LIKE %s', $wpdb->esc_like( $dir . '/' ) . '%' ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		$result = array();
		foreach ( (array) $rows as $row ) {
			$row                    = self::cast( $row );
			$result[ $row['path'] ] = $row;
		}

		return $result;
	}

	/**
	 * Record files seen in the uploads directory during a scan.
	 *
	 * @param array<int, array{path: string, size: int, mtime: int}> $files
	 */
	public static function upsert_local( array $files, int $scan ): void {
		global $wpdb;

		foreach ( array_chunk( $files, 200 ) as $chunk ) {
			$values = array();
			foreach ( $chunk as $file ) {
				$values[] = $wpdb->prepare( '(%s, %s, %d, %d, 1, %d, %s)', self::hash( $file['path'] ), $file['path'], $file['size'], $file['mtime'], $scan, current_time( 'mysql', true ) );
			}

			// A changed file invalidates the remote copy: it must be uploaded and verified again.
			// A different file appearing where the index knows a file that lives only in the storage
			// is a name conflict instead: the remote original must never be overwritten by it, so
			// the row keeps remote = 1 and is set aside (conflict = 1) until someone resolves it.
			// MySQL applies these assignments left to right: size, mtime and local are compared with
			// their old values, while sha256, remote, size and mtime already see the new conflict
			// flag. A conflict row keeps describing the remote original (its size, mtime and
			// checksum), so it is restored exactly once the foreign local file is gone.
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				'INSERT INTO ' . self::table() . ' (path_hash, path, size, mtime, local, local_scan, updated_at) VALUES ' . implode( ',', $values ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				. " ON DUPLICATE KEY UPDATE
					conflict = IF(local = 0 AND remote = 1 AND (size <> VALUES(size) OR (mtime <> 0 AND mtime <> VALUES(mtime))), 1, conflict),
					error = IF(local = 0 AND remote = 1 AND (size <> VALUES(size) OR (mtime <> 0 AND mtime <> VALUES(mtime))), '" . esc_sql( self::conflict_message() ) . "', error),
					verified = IF(size = VALUES(size) AND mtime = VALUES(mtime), verified, 0),
					sha256 = IF(conflict = 1 OR (size = VALUES(size) AND mtime = VALUES(mtime)), sha256, NULL),
					remote = IF(conflict = 1 OR (size = VALUES(size) AND mtime = VALUES(mtime)), remote, 0),
					size = IF(conflict = 1, size, VALUES(size)), mtime = IF(conflict = 1, mtime, VALUES(mtime)), local = 1,
					local_scan = VALUES(local_scan), updated_at = VALUES(updated_at)"
			);
		}
	}

	/**
	 * Record files seen in the storage during a scan.
	 *
	 * @param array<int, array{path: string, size: int}> $files
	 */
	public static function upsert_remote( array $files, int $scan ): void {
		global $wpdb;

		foreach ( array_chunk( $files, 200 ) as $chunk ) {
			$values = array();
			foreach ( $chunk as $file ) {
				$values[] = $wpdb->prepare( '(%s, %s, %d, 0, 1, %d, %d, %s)', self::hash( $file['path'] ), $file['path'], $file['size'], $file['size'], $scan, current_time( 'mysql', true ) );
			}

			// A remote copy whose size no longer matches the local file cannot stand in for it, and
			// an equal size alone never turns a local file into a "copied" one: only an upload or a
			// verification does. Conflict rows keep remote = 1 whatever the local file is, because
			// the remote object is someone else's original. Remote-only rows take the listed size.
			// MySQL applies these assignments left to right, so size changes only after the comparisons.
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				'INSERT INTO ' . self::table() . ' (path_hash, path, size, local, remote, remote_size, remote_scan, updated_at) VALUES ' . implode( ',', $values ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				. ' ON DUPLICATE KEY UPDATE
					verified = IF(conflict = 0 AND local = 1 AND size <> VALUES(remote_size), 0, verified),
					remote = IF(conflict = 1, 1, IF(local = 1, IF(remote = 1 AND size <> VALUES(remote_size), 0, remote), 1)),
					size = IF(local = 1, size, VALUES(remote_size)),
					remote_size = VALUES(remote_size), remote_scan = VALUES(remote_scan), updated_at = VALUES(updated_at)'
			);
		}
	}

	public static function conflict_message(): string {
		return __( 'Name conflict: a different local file appeared where a file that lives only in the storage is recorded. Both were kept; rename or remove one of them and index again.', 'simple-storage' );
	}

	/** @param array<string, mixed> $fields */
	public static function update( int $id, array $fields ): void {
		global $wpdb;

		$fields['updated_at'] = current_time( 'mysql', true );
		$wpdb->update( self::table(), $fields, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function delete( int $id ): void {
		global $wpdb;

		$wpdb->delete( self::table(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function delete_path( string $path ): void {
		global $wpdb;

		$wpdb->delete( self::table(), array( 'path_hash' => self::hash( $path ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Insert or update one file outside a full scan (automatic offload of new uploads).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function touch_local( string $path, int $size, int $mtime ): ?array {
		self::upsert_local(
			array(
				array(
					'path'  => $path,
					'size'  => $size,
					'mtime' => $mtime,
				),
			),
			0
		);

		return self::get( $path );
	}

	/**
	 * Next rows in id order matching a state filter.
	 *
	 * @param array<string, int> $state e.g. array( 'local' => 1, 'remote' => 0 ).
	 * @return array<int, array<string, mixed>>
	 */
	public static function next( array $state, int $after_id, int $limit ): array {
		global $wpdb;

		$where = array( 'id > %d', 'conflict = 0' );
		$args  = array( $after_id );
		foreach ( $state as $column => $value ) {
			if ( in_array( $column, array( 'local', 'remote', 'verified' ), true ) ) {
				$where[] = "{$column} = %d";
				$args[]  = $value;
			}
		}
		$args[] = $limit;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id ASC LIMIT %d', $args ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		return array_map( array( self::class, 'cast' ), (array) $rows );
	}

	/**
	 * Count and bytes of rows matching a state filter.
	 *
	 * @param array<string, int> $state
	 * @return array{files: int, bytes: int}
	 */
	public static function totals( array $state ): array {
		global $wpdb;

		$where = array( 'conflict = 0' );
		$args  = array();
		foreach ( $state as $column => $value ) {
			if ( in_array( $column, array( 'local', 'remote', 'verified' ), true ) ) {
				$where[] = "{$column} = %d";
				$args[]  = $value;
			}
		}

		$sql = 'SELECT COUNT(*) AS files, COALESCE(SUM(size), 0) AS bytes FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where );
		$row = $wpdb->get_row( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'files' => (int) ( $row['files'] ?? 0 ),
			'bytes' => (int) ( $row['bytes'] ?? 0 ),
		);
	}

	/** @return array<string, array{files: int, bytes: int}|int> */
	public static function stats(): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT local, remote, COUNT(*) AS files, COALESCE(SUM(size), 0) AS bytes FROM ' . self::table() . ' GROUP BY local, remote', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		$stats = array(
			'total'       => array( 'files' => 0, 'bytes' => 0 ),
			'local_only'  => array( 'files' => 0, 'bytes' => 0 ),
			'both'        => array( 'files' => 0, 'bytes' => 0 ),
			'remote_only' => array( 'files' => 0, 'bytes' => 0 ),
			'missing'     => array( 'files' => 0, 'bytes' => 0 ),
		);
		foreach ( (array) $rows as $row ) {
			$local  = (int) $row['local'];
			$remote = (int) $row['remote'];
			$key    = $local && $remote ? 'both' : ( $local ? 'local_only' : ( $remote ? 'remote_only' : 'missing' ) );

			$stats[ $key ]['files'] += (int) $row['files'];
			$stats[ $key ]['bytes'] += (int) $row['bytes'];
			if ( 'missing' !== $key ) {
				$stats['total']['files'] += (int) $row['files'];
				$stats['total']['bytes'] += (int) $row['bytes'];
			}
		}

		$stats['errors'] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE error IS NOT NULL' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		return $stats;
	}

	/** @return array<int, array{path: string, error: string}> */
	public static function errors( int $limit = 50 ): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT path, error FROM ' . self::table() . ' WHERE error IS NOT NULL ORDER BY updated_at DESC LIMIT %d', $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		return array_map(
			static fn( array $row ): array => array(
				'path'  => (string) $row['path'],
				'error' => (string) $row['error'],
			),
			(array) $rows
		);
	}

	public static function clear_errors(): void {
		global $wpdb;

		$wpdb->query( 'UPDATE ' . self::table() . ' SET error = NULL WHERE error IS NOT NULL AND conflict = 0' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Distinct lowercase extensions of rows in a state, "" for names without one.
	 *
	 * @param array<string, int> $state
	 * @return array<int, string>
	 */
	public static function extensions( array $state, int $limit = 50 ): array {
		global $wpdb;

		$where = array( 'conflict = 0' );
		$args  = array();
		foreach ( $state as $column => $value ) {
			if ( in_array( $column, array( 'local', 'remote', 'verified' ), true ) ) {
				$where[] = "{$column} = %d";
				$args[]  = $value;
			}
		}
		$args[] = $limit;

		$sql = "SELECT DISTINCT IF(LOCATE('.', SUBSTRING_INDEX(path, '/', -1)) = 0, '', LOWER(SUBSTRING_INDEX(path, '.', -1))) AS ext FROM " . self::table() . ' WHERE ' . implode( ' AND ', $where ) . ' LIMIT %d';
		$col = $wpdb->get_col( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		return array_values( array_filter( array_map( 'strval', (array) $col ), static fn( string $ext ): bool => 1 === preg_match( '/^[a-z0-9]{0,10}$/', $ext ) ) );
	}

	public static function has_remote_files(): bool {
		global $wpdb;

		if ( (int) get_option( self::DB_VERSION_OPTION, 0 ) < 1 ) {
			return false;
		}

		return (bool) $wpdb->get_var( 'SELECT 1 FROM ' . self::table() . ' WHERE remote = 1 LIMIT 1' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function remote_only_count(): int {
		return self::totals(
			array(
				'local'  => 0,
				'remote' => 1,
			)
		)['files'];
	}

	/** Rows of the local scan that were not seen again: the file left the uploads directory. */
	public static function finish_local_scan( int $scan ): int {
		global $wpdb;

		$table = self::table();
		// A conflicting local file that is gone resolves its conflict: the remote original stays.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET local = 0, verified = IF(remote = 1 AND conflict = 0, verified, 0), error = IF(conflict = 1, NULL, error), conflict = 0 WHERE local = 1 AND local_scan <> %d", $scan ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $wpdb->query( "DELETE FROM {$table} WHERE local = 0 AND remote = 0" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Rows recorded as remote that the current remote scan did not list.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function unseen_remote( int $scan, int $limit ): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE remote = 1 AND remote_scan <> %d ORDER BY id ASC LIMIT %d', $scan, $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		return array_map( array( self::class, 'cast' ), (array) $rows );
	}

	public static function count_unseen_remote( int $scan ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE remote = 1 AND remote_scan <> %d', $scan ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Every recorded remote copy must be verified again, e.g. after the storage address changed. */
	public static function reset_verification(): void {
		global $wpdb;

		if ( (int) get_option( self::DB_VERSION_OPTION, 0 ) < 1 ) {
			return;
		}
		$wpdb->query( 'UPDATE ' . self::table() . ' SET verified = 0 WHERE verified = 1' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Rows of the remote scan that were not seen again: the remote copy is gone. Returns the paths
	 * that now exist nowhere, which is data loss the administrator has to know about.
	 *
	 * @return array<int, string>
	 */
	public static function finish_remote_scan( int $scan ): array {
		global $wpdb;

		$table = self::table();
		// A conflict whose remote original is gone is no conflict any more.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET remote = 0, verified = 0, remote_size = NULL, error = IF(conflict = 1, NULL, error), conflict = 0 WHERE remote = 1 AND remote_scan <> %d", $scan ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$lost = $wpdb->get_col( "SELECT path FROM {$table} WHERE local = 0 AND remote = 0 LIMIT 100" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$table} WHERE local = 0 AND remote = 0" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( 'strval', (array) $lost );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function cast( array $row ): array {
		foreach ( array( 'id', 'size', 'mtime', 'local', 'remote', 'verified', 'local_scan', 'remote_scan', 'conflict' ) as $key ) {
			$row[ $key ] = (int) ( $row[ $key ] ?? 0 );
		}
		$row['remote_size'] = null === ( $row['remote_size'] ?? null ) ? null : (int) $row['remote_size'];

		return $row;
	}
}
