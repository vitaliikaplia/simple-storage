<?php
/**
 * Client for the Hosting Ukraine storage content API (https://www.ukraine.com.ua/wiki/storage/api/).
 *
 * Uploads and downloads are streamed through cURL so that large files never sit in memory, and one
 * cURL handle is reused so that a batch of small files shares a keep-alive connection.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_Storage_Client {
	private const TOKEN_OPTION = 'simple_storage_token';
	private const MAX_RETRIES = 3;

	private string $base;
	private string $login;
	private string $password;

	/** @var resource|\CurlHandle|null */
	private $curl = null;
	private ?string $token = null;
	private ?bool $write_access = null;

	/** @var array<string, bool> Remote directories known to exist during this request. */
	private array $known_dirs = array();

	/** @var callable|null Called while a file is being transferred, to keep a job lock alive. */
	private $heartbeat = null;

	public function __construct( string $base, string $login, string $password ) {
		$this->base     = untrailingslashit( $base );
		$this->login    = $login;
		$this->password = $password;
	}

	/** @return Simple_Storage_Client|WP_Error */
	public static function create() {
		if ( ! function_exists( 'curl_init' ) ) {
			return new WP_Error( 'simple_storage_no_curl', __( 'The PHP cURL extension is required.', 'simple-storage' ) );
		}
		if ( ! Simple_Storage_Settings::is_configured() ) {
			return new WP_Error( 'simple_storage_not_configured', __( 'The storage connection is not configured.', 'simple-storage' ) );
		}

		$connection = Simple_Storage_Settings::connection();

		return new self( $connection['host'], $connection['login'], $connection['password'] );
	}

	public function __destruct() {
		$this->close();
	}

	/** Release the cURL handle; curl_close() is a no-op since PHP 8.0 and deprecated in 8.5. */
	public function close(): void {
		$this->curl = null;
	}

	/** Call $callback regularly during uploads and downloads (it should throttle itself). */
	public function set_heartbeat( ?callable $callback ): void {
		$this->heartbeat = $callback;
	}

	/** @return array<int, mixed> Progress options that invoke the heartbeat during a transfer. */
	private function progress_options(): array {
		if ( null === $this->heartbeat ) {
			return array();
		}

		$heartbeat = $this->heartbeat;

		// CURLOPT_XFERINFOFUNCTION exists only since PHP 8.2; on 8.1 the older progress callback
		// takes the same arguments.
		return array(
			CURLOPT_NOPROGRESS => false,
			( defined( 'CURLOPT_XFERINFOFUNCTION' ) ? constant( 'CURLOPT_XFERINFOFUNCTION' ) : CURLOPT_PROGRESSFUNCTION ) => static function () use ( $heartbeat ): int {
				$heartbeat();

				return 0;
			},
		);
	}

	public static function forget_token(): void {
		delete_option( self::TOKEN_OPTION );
	}

	/** Whether the authenticated user may write, as reported by the last authorized response. */
	public function write_access(): ?bool {
		return $this->write_access;
	}

	/**
	 * Get a token, reusing the stored one while it is valid for at least five more minutes.
	 *
	 * @return string|WP_Error
	 */
	public function authenticate( bool $force = false ) {
		// Keyed with the WordPress salts: a plain hash of the password in the database could be
		// brute-forced offline from a database-only leak.
		$key = hash_hmac( 'sha256', $this->base . "\n" . $this->login . "\n" . $this->password, wp_salt( 'auth' ) );

		if ( ! $force ) {
			if ( null !== $this->token ) {
				return $this->token;
			}

			$stored = get_option( self::TOKEN_OPTION, array() );
			if ( is_array( $stored ) && ( $stored['key'] ?? '' ) === $key && (int) ( $stored['till'] ?? 0 ) > time() + 300 ) {
				$token = Simple_Storage_Settings::decrypt( (string) ( $stored['token'] ?? '' ) );
				if ( '' !== $token ) {
					$this->token = $token;

					return $token;
				}
			}
		}

		$response = $this->call(
			'PUT',
			'auth',
			array(
				'auth'      => false,
				'multipart' => array(
					'login'    => $this->login,
					'password' => $this->password,
					'remember' => '1',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== $response['status'] ) {
			return $this->error_from( $response, __( 'Authorization failed. Check the storage address, login and password.', 'simple-storage' ) );
		}

		$json  = json_decode( $response['body'], true );
		$data  = is_array( $json ) && is_array( $json['data'] ?? null ) ? $json['data'] : ( is_array( $json ) ? $json : array() );
		$token = (string) ( $data['token'] ?? '' );
		if ( '' === $token ) {
			return new WP_Error( 'simple_storage_auth', __( 'The storage did not return an access token.', 'simple-storage' ) );
		}

		$this->token = $token;
		update_option(
			self::TOKEN_OPTION,
			array(
				'key'   => $key,
				'token' => Simple_Storage_Settings::encrypt( $token ),
				'till'  => self::parse_time( $data['till'] ?? null, time() + 6 * HOUR_IN_SECONDS ),
			),
			false
		);

		return $token;
	}

	/**
	 * File or directory metadata.
	 *
	 * @return array{exists: bool, size: int|null, directory: bool}|WP_Error
	 */
	public function stat( string $path ) {
		$response = $this->call( 'HEAD', 'file', array( 'query' => array( 'path' => $path ) ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 404 === $response['status'] ) {
			return array(
				'exists'    => false,
				'size'      => null,
				'directory' => false,
			);
		}
		if ( 200 !== $response['status'] ) {
			return $this->error_from( $response );
		}

		$size      = $response['headers']['x-stat-size'] ?? null;
		$directory = $response['headers']['x-stat-directory'] ?? '';

		return array(
			'exists'    => true,
			'size'      => null !== $size && is_numeric( $size ) ? (int) $size : null,
			'directory' => in_array( strtolower( (string) $directory ), array( '1', 'true', 'yes' ), true ),
		);
	}

	/** @return bool|WP_Error */
	public function upload( string $path, string $file, bool $overwrite = true ) {
		$size = @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $size ) {
			return new WP_Error( 'simple_storage_local_missing', __( 'The local file cannot be read.', 'simple-storage' ) );
		}

		$response = $this->call(
			'PUT',
			'file',
			array(
				'query'  => array(
					'path'      => $path,
					'overwrite' => $overwrite ? '1' : '0',
				),
				'infile' => $file,
				'size'   => (int) $size,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== $response['status'] ) {
			return $this->error_from( $response );
		}

		return true;
	}

	/** Create one directory; an existing directory is not an error. @return bool|WP_Error */
	public function create_dir( string $path ) {
		$response = $this->call( 'PUT', 'directory', array( 'multipart' => array( 'path' => $path ) ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 === $response['status'] ) {
			$this->known_dirs[ $path ] = true;

			return true;
		}

		// The API answers 400 with a description when the directory is already there.
		$stat = $this->stat( $path );
		if ( ! is_wp_error( $stat ) && $stat['exists'] ) {
			$this->known_dirs[ $path ] = true;

			return true;
		}

		return $this->error_from( $response );
	}

	/** Create every missing directory of an absolute remote path. @return bool|WP_Error */
	public function ensure_dir( string $path ) {
		$current = '';
		foreach ( array_filter( explode( '/', $path ), 'strlen' ) as $segment ) {
			$current .= '/' . $segment;
			if ( isset( $this->known_dirs[ $current ] ) ) {
				continue;
			}

			$stat = $this->stat( $current );
			if ( is_wp_error( $stat ) ) {
				return $stat;
			}
			if ( $stat['exists'] ) {
				$this->known_dirs[ $current ] = true;
				continue;
			}

			$created = $this->create_dir( $current );
			if ( is_wp_error( $created ) ) {
				return $created;
			}
		}

		return true;
	}

	/**
	 * Directory listing. A missing directory is reported with the error code simple_storage_not_found.
	 *
	 * @return array<int, array{path: string, size: int, directory: bool, uri: string}>|WP_Error
	 */
	public function list_dir( string $path, bool $recursive = false ) {
		$response = $this->call(
			'GET',
			'directory',
			array(
				'query' => array(
					'path'      => $path,
					'recursive' => $recursive ? '1' : '0',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 404 === $response['status'] ) {
			return new WP_Error( 'simple_storage_not_found', __( 'The directory does not exist in the storage.', 'simple-storage' ) );
		}
		if ( 200 !== $response['status'] ) {
			return $this->error_from( $response );
		}

		return self::parse_listing( $response['body'] );
	}

	/**
	 * Accepts a JSON array, an object with the list in "data" (or "data.items") and the line-per-item
	 * iterator format, since the documentation does not show the envelope.
	 *
	 * @return array<int, array{path: string, size: int, directory: bool, uri: string}>|WP_Error
	 */
	public static function parse_listing( string $body ) {
		$json = json_decode( $body, true );
		if ( is_array( $json ) ) {
			if ( isset( $json['data'] ) && is_array( $json['data'] ) ) {
				$json = isset( $json['data']['items'] ) && is_array( $json['data']['items'] ) ? $json['data']['items'] : $json['data'];
			}
			$items = array_is_list( $json ) ? $json : array( $json );
		} else {
			$items = array();
			foreach ( preg_split( '/\r?\n/', $body ) as $line ) {
				$line = trim( $line );
				if ( '' === $line ) {
					continue;
				}
				$item = json_decode( $line, true );
				if ( ! is_array( $item ) ) {
					return new WP_Error( 'simple_storage_bad_listing', __( 'The storage returned a directory listing that cannot be read.', 'simple-storage' ) );
				}
				$items[] = $item;
			}
		}

		$list = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['path'] ) ) {
				continue;
			}
			$list[] = array(
				'path'      => '/' . ltrim( (string) $item['path'], '/' ),
				'size'      => (int) ( $item['size'] ?? 0 ),
				'directory' => ! empty( $item['isDirectory'] ),
				'uri'       => (string) ( $item['uri'] ?? '' ),
			);
		}

		return $list;
	}

	/** Delete a file; a missing file counts as deleted. @return bool|WP_Error */
	public function delete_file( string $path ) {
		return $this->delete( 'file', $path );
	}

	/** Delete a directory with everything in it; a missing one counts as deleted. @return bool|WP_Error */
	public function delete_dir( string $path ) {
		unset( $this->known_dirs[ $path ] );

		return $this->delete( 'directory', $path );
	}

	/** @return bool|WP_Error */
	private function delete( string $endpoint, string $path ) {
		$response = $this->call( 'DELETE', $endpoint, array( 'query' => array( 'path' => $path ) ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 === $response['status'] || 404 === $response['status'] ) {
			return true;
		}

		return $this->error_from( $response );
	}

	/**
	 * Download a public URL while hashing it, optionally into a local file.
	 *
	 * @return array{status: int, size: int, sha256: string}|WP_Error
	 */
	public function fetch( string $url, ?string $destination = null ) {
		$handle = null;
		if ( null !== $destination ) {
			$handle = @fopen( $destination, 'wb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( false === $handle ) {
				return new WP_Error( 'simple_storage_local_write', __( 'The local file cannot be written.', 'simple-storage' ) );
			}
		}

		$attempt = 0;
		do {
			$hash   = hash_init( 'sha256' );
			$size   = 0;
			$status = 0;
			$error  = null;

			if ( null !== $handle ) {
				ftruncate( $handle, 0 );
				rewind( $handle );
			}

			$curl = $this->handle();
			curl_setopt_array(
				$curl,
				$this->base_options() + $this->progress_options() + array(
					CURLOPT_URL            => $url,
					CURLOPT_HTTPGET        => true,
					CURLOPT_FOLLOWLOCATION => true,
					CURLOPT_MAXREDIRS      => 3,
					CURLOPT_TIMEOUT        => 0,
					CURLOPT_LOW_SPEED_LIMIT => 1024,
					CURLOPT_LOW_SPEED_TIME => 60,
					CURLOPT_HEADERFUNCTION => static function ( $ch, string $line ) use ( &$status ): int {
						if ( preg_match( '#^HTTP/\S+\s+(\d{3})#', $line, $match ) ) {
							$status = (int) $match[1];
						}

						return strlen( $line );
					},
					CURLOPT_WRITEFUNCTION  => static function ( $ch, string $chunk ) use ( &$hash, &$size, &$status, $handle ): int {
						if ( 200 === $status ) {
							hash_update( $hash, $chunk );
							$size += strlen( $chunk );
							if ( null !== $handle && strlen( $chunk ) !== fwrite( $handle, $chunk ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
								return 0;
							}
						}

						return strlen( $chunk );
					},
				)
			);

			if ( false === curl_exec( $curl ) ) {
				$error = new WP_Error( 'simple_storage_http', curl_error( $curl ) );
			}
			++$attempt;
		} while ( ( null !== $error || in_array( $status, array( 429, 502, 503, 504 ), true ) ) && $attempt < self::MAX_RETRIES && self::backoff( $attempt ) );

		if ( null !== $handle ) {
			fflush( $handle );
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		if ( null !== $error ) {
			return $error;
		}

		return array(
			'status' => $status,
			'size'   => $size,
			'sha256' => hash_final( $hash ),
		);
	}

	/**
	 * Perform one API request with token refresh on 401 and backoff on 429 and 502/503.
	 *
	 * @param array<string, mixed> $args query, multipart, infile + size, auth (default true).
	 * @return array{status: int, headers: array<string, string>, body: string}|WP_Error
	 */
	private function call( string $method, string $endpoint, array $args = array() ) {
		$auth      = $args['auth'] ?? true;
		$refreshed = false;
		$attempt   = 0;

		while ( true ) {
			$token = '';
			if ( $auth ) {
				$token = $this->authenticate();
				if ( is_wp_error( $token ) ) {
					return $token;
				}
			}

			$response = $this->send( $method, $endpoint, $args, $token );
			++$attempt;

			if ( is_wp_error( $response ) ) {
				if ( 'simple_storage_http' === $response->get_error_code() && $attempt < self::MAX_RETRIES && self::backoff( $attempt ) ) {
					continue;
				}

				return $response;
			}

			if ( $auth && 401 === $response['status'] && ! $refreshed ) {
				$refreshed   = true;
				$this->token = null;
				$token       = $this->authenticate( true );
				if ( is_wp_error( $token ) ) {
					return $token;
				}
				continue;
			}

			if ( in_array( $response['status'], array( 429, 502, 503 ), true ) && $attempt < self::MAX_RETRIES ) {
				$wait = isset( $response['headers']['retry-after'] ) ? min( 10, max( 1, (int) $response['headers']['retry-after'] ) ) : 0;
				if ( self::backoff( $attempt, $wait ) ) {
					continue;
				}
			}

			if ( isset( $response['headers']['x-user-write'] ) ) {
				$this->write_access = '1' === trim( $response['headers']['x-user-write'] );
			}

			return $response;
		}
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array{status: int, headers: array<string, string>, body: string}|WP_Error
	 */
	private function send( string $method, string $endpoint, array $args, string $token ) {
		$url = $this->base . '/~/api/' . $endpoint;
		if ( ! empty( $args['query'] ) ) {
			$url .= '?' . http_build_query( $args['query'], '', '&', PHP_QUERY_RFC3986 );
		}

		$headers = array( 'Accept: application/json' );
		if ( '' !== $token ) {
			$headers[] = 'Authorization: Bearer ' . $token;
		}

		$response_headers = array();
		$options          = $this->base_options() + array(
			CURLOPT_URL            => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT        => 60,
			CURLOPT_HEADERFUNCTION => static function ( $ch, string $line ) use ( &$response_headers ): int {
				$parts = explode( ':', $line, 2 );
				if ( 2 === count( $parts ) ) {
					$response_headers[ strtolower( trim( $parts[0] ) ) ] = trim( $parts[1] );
				}

				return strlen( $line );
			},
		);

		$infile = null;
		if ( isset( $args['infile'] ) ) {
			$infile = @fopen( (string) $args['infile'], 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( false === $infile ) {
				return new WP_Error( 'simple_storage_local_missing', __( 'The local file cannot be read.', 'simple-storage' ) );
			}
			$headers[]                         = 'Content-Type: application/octet-stream';
			$headers[]                         = 'Expect:';
			$options[ CURLOPT_UPLOAD ]         = true;
			$options[ CURLOPT_INFILE ]         = $infile;
			$options[ CURLOPT_INFILESIZE ]     = (int) $args['size'];
			$options[ CURLOPT_TIMEOUT ]        = 0;
			$options[ CURLOPT_LOW_SPEED_LIMIT ] = 1024;
			$options[ CURLOPT_LOW_SPEED_TIME ] = 60;
			$options                           = $this->progress_options() + $options;
		} elseif ( 'HEAD' === $method ) {
			$options[ CURLOPT_NOBODY ] = true;
		} else {
			$options[ CURLOPT_CUSTOMREQUEST ] = $method;
			if ( isset( $args['multipart'] ) ) {
				$options[ CURLOPT_POSTFIELDS ] = $args['multipart'];
			}
		}

		$options[ CURLOPT_HTTPHEADER ] = $headers;

		$curl = $this->handle();
		curl_setopt_array( $curl, $options );
		$body   = curl_exec( $curl );
		$status = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
		$error  = curl_error( $curl );

		if ( null !== $infile ) {
			fclose( $infile ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		if ( false === $body ) {
			/* translators: %s: cURL error message. */
			return new WP_Error( 'simple_storage_http', sprintf( __( 'Storage request failed: %s', 'simple-storage' ), $error ) );
		}

		return array(
			'status'  => $status,
			'headers' => $response_headers,
			'body'    => is_string( $body ) ? $body : '',
		);
	}

	/** @return resource|\CurlHandle */
	private function handle() {
		if ( null === $this->curl ) {
			$this->curl = curl_init();
		} else {
			curl_reset( $this->curl );
		}

		return $this->curl;
	}

	/** @return array<int, mixed> */
	private function base_options(): array {
		$options = array(
			CURLOPT_CONNECTTIMEOUT  => 15,
			CURLOPT_USERAGENT       => 'SimpleStorage/' . SIMPLE_STORAGE_VERSION . '; ' . home_url( '/' ),
			CURLOPT_SSL_VERIFYPEER  => true,
			CURLOPT_SSL_VERIFYHOST  => 2,
			CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
		);

		$bundle = ABSPATH . WPINC . '/certificates/ca-bundle.crt';
		if ( is_readable( $bundle ) ) {
			$options[ CURLOPT_CAINFO ] = $bundle;
		}

		return $options;
	}

	/** Sleep before the next attempt; returns true so it can sit inside a loop condition. */
	private static function backoff( int $attempt, int $seconds = 0 ): bool {
		sleep( $seconds > 0 ? $seconds : min( 8, 2 ** ( $attempt - 1 ) ) );

		return true;
	}

	/** @param array{status: int, headers: array<string, string>, body: string} $response */
	private function error_from( array $response, string $fallback = '' ): WP_Error {
		$message = '';
		$json    = json_decode( $response['body'], true );
		if ( is_array( $json ) ) {
			foreach ( array( 'error', 'message', 'description' ) as $key ) {
				if ( isset( $json[ $key ] ) && is_string( $json[ $key ] ) ) {
					$message = $json[ $key ];
					break;
				}
				if ( isset( $json['data'][ $key ] ) && is_string( $json['data'][ $key ] ) ) {
					$message = $json['data'][ $key ];
					break;
				}
			}
		}
		if ( '' === $message ) {
			$message = trim( wp_strip_all_tags( substr( $response['body'], 0, 300 ) ) );
		}
		if ( '' === $message ) {
			$message = '' !== $fallback ? $fallback : __( 'Unexpected storage response.', 'simple-storage' );
		}

		return new WP_Error(
			'simple_storage_api',
			/* translators: 1: HTTP status code, 2: error description. */
			sprintf( __( 'Storage answered %1$d: %2$s', 'simple-storage' ), $response['status'], $message ),
			array( 'status' => $response['status'] )
		);
	}

	/** Unix time from seconds, milliseconds or a date string. */
	private static function parse_time( $value, int $fallback ): int {
		if ( is_numeric( $value ) ) {
			$time = (int) $value;

			return $time > 100000000000 ? intdiv( $time, 1000 ) : $time;
		}
		if ( is_string( $value ) && '' !== $value ) {
			$time = strtotime( $value );
			if ( false !== $time ) {
				return $time;
			}
		}

		return $fallback;
	}
}
