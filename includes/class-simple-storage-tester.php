<?php
/**
 * Connection test: a real round trip of a small file through the API and the public address.
 * It works in its own folder next to the media and never touches real files.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_Storage_Tester {
	public const TEST_DIR = 'simple-storage-check';

	/**
	 * @return array{ok: bool, time: int, steps: array<int, array{label: string, ok: bool, message: string}>}
	 */
	public static function run(): array {
		$steps  = array();
		$result = static function ( bool $ok ) use ( &$steps ): array {
			$report = array(
				'ok'    => $ok,
				'time'  => time(),
				'steps' => $steps,
			);
			Simple_Storage_Settings::update_state( array( 'last_test' => $report ) );

			return $report;
		};
		$step = static function ( string $label, bool $ok, string $message = '' ) use ( &$steps ): bool {
			$steps[] = array(
				'label'   => $label,
				'ok'      => $ok,
				'message' => $message,
			);

			return $ok;
		};

		$client = Simple_Storage_Client::create();
		if ( is_wp_error( $client ) ) {
			$step( __( 'Settings', 'simple-storage' ), false, $client->get_error_message() );

			return $result( false );
		}

		$token = $client->authenticate( true );
		if ( ! $step( __( 'Authorization', 'simple-storage' ), ! is_wp_error( $token ), is_wp_error( $token ) ? $token->get_error_message() : '' ) ) {
			return $result( false );
		}

		$dir   = Simple_Storage_Paths::remote_root() . '/' . self::TEST_DIR;
		$ready = $client->ensure_dir( $dir );
		if ( false === $client->write_access() ) {
			$step( __( 'Write access', 'simple-storage' ), false, __( 'The storage user has no "Write" right. Enable Read, Write and Content for this user and test again.', 'simple-storage' ) );

			return $result( false );
		}
		if ( ! $step( __( 'Site folder', 'simple-storage' ), ! is_wp_error( $ready ), is_wp_error( $ready ) ? $ready->get_error_message() : Simple_Storage_Paths::remote_root() ) ) {
			return $result( false );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$local = wp_tempnam( 'simple-storage-check' );
		$body  = 'Simple Storage connection check ' . wp_generate_password( 32, false );
		file_put_contents( $local, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$name   = 'check-' . strtolower( wp_generate_password( 8, false ) ) . '.txt';
		$remote = $dir . '/' . $name;

		try {
			$uploaded = $client->upload( $remote, $local, true );
			if ( ! $step( __( 'Upload', 'simple-storage' ), ! is_wp_error( $uploaded ), is_wp_error( $uploaded ) ? $uploaded->get_error_message() : '' ) ) {
				return $result( false );
			}

			$stat    = $client->stat( $remote );
			$stat_ok = ! is_wp_error( $stat ) && $stat['exists'] && ( null === $stat['size'] || strlen( $body ) === $stat['size'] );
			if ( ! $step( __( 'File size in the storage', 'simple-storage' ), $stat_ok, is_wp_error( $stat ) ? $stat->get_error_message() : '' ) ) {
				return $result( false );
			}

			$listing = $client->list_dir( $dir );
			$uri     = '';
			if ( ! is_wp_error( $listing ) ) {
				foreach ( $listing as $item ) {
					if ( $item['path'] === $remote ) {
						$uri = $item['uri'];
					}
				}
			}
			$uri_path = (string) wp_parse_url( $uri, PHP_URL_PATH );
			if ( str_starts_with( $uri_path, '/~/secure/' ) || str_starts_with( $uri_path, '/secure/' ) ) {
				$step( __( 'Public access', 'simple-storage' ), false, __( 'Public access is off: in the storage panel, on the "Users and API" tab, give the "Public access" user the "Read" right (without "Content").', 'simple-storage' ) );

				return $result( false );
			}
			if ( is_wp_error( $listing ) ) {
				$step( __( 'Directory listing', 'simple-storage' ), false, $listing->get_error_message() );

				return $result( false );
			}

			$url     = Simple_Storage_Settings::public_base() . Simple_Storage_Paths::encode( $remote );
			$fetched = $client->fetch( $url );
			$fetch_ok = ! is_wp_error( $fetched ) && 200 === $fetched['status'] && hash( 'sha256', $body ) === $fetched['sha256'];
			if ( is_wp_error( $fetched ) ) {
				$message = $fetched->get_error_message();
			} elseif ( 200 !== $fetched['status'] ) {
				/* translators: 1: URL, 2: HTTP status code. */
				$message = sprintf( __( '%1$s answered %2$d. Check public access and, for your own domain, its DNS record and certificate.', 'simple-storage' ), $url, $fetched['status'] );
				if ( '' !== $uri_path && $uri_path !== $remote ) {
					/* translators: %s: URI reported by the storage. */
					$message .= ' ' . sprintf( __( 'The storage reports the direct link as %s.', 'simple-storage' ), $uri );
				}
			} elseif ( ! $fetch_ok ) {
				$message = __( 'The downloaded file differs from the uploaded one.', 'simple-storage' );
			} else {
				$message = $url;
			}
			if ( ! $step( __( 'Public download', 'simple-storage' ), $fetch_ok, $message ) ) {
				return $result( false );
			}
		} finally {
			wp_delete_file( $local );
			$client->delete_file( $remote );
			$client->delete_dir( $dir );
		}

		return $result( true );
	}
}
