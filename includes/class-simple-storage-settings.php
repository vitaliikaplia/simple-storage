<?php
/**
 * Plugin settings, credentials and persistent runtime state.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_Storage_Settings {
	public const OPTION = 'simple_storage_settings';
	public const STATE_OPTION = 'simple_storage_state';

	private const SECRET_PREFIX = 'ss1:';

	/** @var array<string, mixed>|null */
	private static ?array $cache = null;

	/** @return array<string, mixed> */
	public static function defaults(): array {
		return array(
			'host'            => '',
			'login'           => '',
			'password'        => '',
			'public_url'      => '',
			'prefix'          => '',
			'delivery_mode'   => 'proxy',
			'strict_verify'   => true,
			'auto_offload'    => true,
			'redirect_status' => 302,
		);
	}

	/** @return array<string, mixed> */
	public static function get(): array {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}

		return self::$cache;
	}

	public static function flush_cache(): void {
		self::$cache = null;
	}

	/**
	 * Sanitize the settings form. An empty password keeps the stored one.
	 *
	 * WordPress runs this callback twice when the option does not exist yet (update_option() falls
	 * through to add_option()), so an already encrypted password must pass through unchanged.
	 * options.php unslashes the input before it gets here.
	 *
	 * @param mixed $input Raw form input.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$input   = is_array( $input ) ? $input : array();
		$current = self::get();
		$clean   = self::defaults();

		// A field a constant replaces is not in the form: what is stored stays.
		$clean['host']       = defined( 'SIMPLE_STORAGE_HOST' ) ? (string) $current['host'] : self::checked_address( (string) ( $input['host'] ?? '' ), (string) $current['host'], __( 'The storage address is not valid, so the previous one was kept.', 'simple-storage' ) );
		$clean['login']      = defined( 'SIMPLE_STORAGE_LOGIN' ) ? (string) $current['login'] : sanitize_text_field( (string) ( $input['login'] ?? '' ) );
		$clean['public_url'] = self::checked_address( (string) ( $input['public_url'] ?? '' ), (string) $current['public_url'], __( 'The public address is not valid, so the previous one was kept.', 'simple-storage' ) );

		$password = (string) ( $input['password'] ?? '' );
		if ( '' === $password && '' !== $clean['host'] && '' !== (string) $current['host'] && $clean['host'] !== $current['host'] && '' !== (string) $current['password'] && ! defined( 'SIMPLE_STORAGE_PASSWORD' ) ) {
			// The next request would send the stored password to the new address.
			$clean['host'] = (string) $current['host'];
			self::report( __( 'To change the storage address, enter the password as well: the saved one is never sent to a new address. The previous address was kept.', 'simple-storage' ) );
		}
		if ( '' === $password ) {
			$clean['password'] = (string) $current['password'];
		} elseif ( str_starts_with( $password, self::SECRET_PREFIX ) && '' !== self::decrypt( $password ) ) {
			$clean['password'] = $password;
		} else {
			$clean['password'] = self::encrypt( $password );
		}

		$prefix = self::sanitize_prefix( (string) ( $input['prefix'] ?? '' ) );
		if ( Simple_Storage_Index::has_remote_files() && '' !== (string) $current['prefix'] ) {
			// The prefix is part of every stored remote path; it cannot move under existing files.
			$prefix = (string) $current['prefix'];
		}
		$clean['prefix'] = '' !== $prefix ? $prefix : self::default_prefix();

		$clean['delivery_mode']   = 'direct' === ( $input['delivery_mode'] ?? '' ) ? 'direct' : 'proxy';
		$clean['strict_verify']   = ! empty( $input['strict_verify'] );
		$clean['auto_offload']    = ! empty( $input['auto_offload'] );
		$clean['redirect_status'] = 301 === (int) ( $input['redirect_status'] ?? 302 ) ? 301 : 302;

		self::$cache = null;

		return $clean;
	}

	/** An address from the form, or the current one when what was typed is not an address. */
	private static function checked_address( string $value, string $current, string $message ): string {
		$address = self::sanitize_host( $value );
		if ( '' === $address && '' !== trim( $value ) ) {
			self::report( $message );

			return $current;
		}

		return $address;
	}

	/** Show an error on the settings page after the redirect; it replaces "Settings saved". */
	private static function report( string $message ): void {
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( self::OPTION, 'simple_storage_invalid', $message );
		}
	}

	/**
	 * Accepts "abc.cdn.express", "https://abc.cdn.express/" or, for local testing, "http://127.0.0.1:8899".
	 */
	public static function sanitize_host( string $host ): string {
		$host = trim( $host );
		if ( '' === $host ) {
			return '';
		}
		if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $host ) ) {
			$host = 'https://' . $host;
		}

		$parts = wp_parse_url( $host );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? 'https' ) );
		$name   = strtolower( (string) $parts['host'] );
		if ( ! preg_match( '/^[a-z0-9.-]+$/', $name ) ) {
			return '';
		}
		if ( 'https' !== $scheme && ! ( 'http' === $scheme && self::is_local_host( $name ) ) ) {
			$scheme = 'https';
		}

		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';

		return $scheme . '://' . $name . $port;
	}

	/** One or more path segments of letters, digits, dots, dashes and underscores. */
	public static function sanitize_prefix( string $prefix ): string {
		$segments = array();
		foreach ( explode( '/', str_replace( '\\', '/', trim( $prefix ) ) ) as $segment ) {
			$segment = strtolower( (string) preg_replace( '/[^A-Za-z0-9._-]+/', '-', $segment ) );
			$segment = trim( $segment, '.-' );
			if ( '' !== $segment ) {
				$segments[] = $segment;
			}
		}

		return implode( '/', $segments );
	}

	public static function default_prefix(): string {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$path = (string) wp_parse_url( home_url(), PHP_URL_PATH );

		$prefix = self::sanitize_prefix( $host . ( '' !== trim( $path, '/' ) ? '-' . str_replace( '/', '-', trim( $path, '/' ) ) : '' ) );

		return '' !== $prefix ? $prefix : 'wordpress';
	}

	public static function is_local_host( string $host ): bool {
		$host = strtolower( $host );

		return in_array( $host, array( 'localhost', '127.0.0.1', '::1', '[::1]' ), true )
			|| str_ends_with( $host, '.test' )
			|| str_ends_with( $host, '.localhost' );
	}

	/**
	 * Connection details with wp-config.php constants taking precedence over the stored settings.
	 *
	 * @return array{host: string, login: string, password: string}
	 */
	public static function connection(): array {
		$settings = self::get();

		$host     = defined( 'SIMPLE_STORAGE_HOST' ) ? self::sanitize_host( (string) SIMPLE_STORAGE_HOST ) : (string) $settings['host'];
		$login    = defined( 'SIMPLE_STORAGE_LOGIN' ) ? (string) SIMPLE_STORAGE_LOGIN : (string) $settings['login'];
		$password = defined( 'SIMPLE_STORAGE_PASSWORD' ) ? (string) SIMPLE_STORAGE_PASSWORD : self::decrypt( (string) $settings['password'] );

		return array(
			'host'     => $host,
			'login'    => $login,
			'password' => $password,
		);
	}

	public static function is_configured(): bool {
		$connection = self::connection();

		return '' !== $connection['host'] && '' !== $connection['login'] && '' !== $connection['password'];
	}

	/** Which connection fields come from wp-config.php constants. @return array<string, bool> */
	public static function constant_overrides(): array {
		return array(
			'host'     => defined( 'SIMPLE_STORAGE_HOST' ),
			'login'    => defined( 'SIMPLE_STORAGE_LOGIN' ),
			'password' => defined( 'SIMPLE_STORAGE_PASSWORD' ),
		);
	}

	public static function has_stored_password(): bool {
		return '' !== (string) self::get()['password'];
	}

	/** Base URL visitors download files from: the custom public URL or the storage address. */
	public static function public_base(): string {
		$settings = self::get();
		$base     = '' !== (string) $settings['public_url'] ? (string) $settings['public_url'] : self::connection()['host'];

		return untrailingslashit( $base );
	}

	public static function prefix(): string {
		$prefix = (string) self::get()['prefix'];

		return '' !== $prefix ? $prefix : self::default_prefix();
	}

	/**
	 * "proxy": missing files are streamed by proxy.php under their original uploads addresses.
	 * "direct": pages link to the storage and old addresses are redirected there.
	 */
	public static function delivery_mode(): string {
		return 'direct' === self::get()['delivery_mode'] ? 'direct' : 'proxy';
	}

	public static function strict_verify(): bool {
		return (bool) self::get()['strict_verify'];
	}

	public static function auto_offload(): bool {
		return (bool) self::get()['auto_offload'];
	}

	public static function redirect_status(): int {
		return 301 === (int) self::get()['redirect_status'] ? 301 : 302;
	}

	/**
	 * Runtime state that the settings form never touches.
	 *
	 * delivery: serve missing local files from the storage.
	 * mode: "remote" once files were moved to the storage (new uploads follow), "local" otherwise.
	 *
	 * proxy: result of the last probe for an Apache proxy (Simple_Storage_Delivery::probe_proxy()).
	 * verified_delivery: extensions whose end-to-end serving check passed, with the settings
	 * signature it is valid for (Simple_Storage_Delivery::verify_delivery()).
	 * delivery_problem: why the last serving check served nothing, with what the server answers
	 * around it (Simple_Storage_Delivery::explain()); empty once a check passes or serving is off.
	 * last_remote_scan: number of the last complete listing of the storage, whose local files it
	 * showed there under the same name the overview counts.
	 * direction: "remote" or "local", where the last job the administrator started was moving the
	 * files; the overview suggests the next step that way.
	 *
	 * @return array{delivery: bool, mode: string, last_test: array<string, mixed>, last_index: int, last_remote_scan: int, direction: string, proxy: array<string, mixed>, verified_delivery: array<string, mixed>, delivery_problem: string}
	 */
	public static function state(): array {
		$state = get_option( self::STATE_OPTION, array() );
		$state = is_array( $state ) ? $state : array();

		return array(
			'delivery'   => ! empty( $state['delivery'] ),
			'mode'       => 'remote' === ( $state['mode'] ?? '' ) ? 'remote' : 'local',
			'last_test'  => is_array( $state['last_test'] ?? null ) ? $state['last_test'] : array(),
			'last_index' => (int) ( $state['last_index'] ?? 0 ),
			'last_remote_scan' => (int) ( $state['last_remote_scan'] ?? 0 ),
			'direction'  => 'local' === ( $state['direction'] ?? '' ) ? 'local' : 'remote',
			'proxy'      => is_array( $state['proxy'] ?? null ) ? $state['proxy'] : array(),
			'verified_delivery' => is_array( $state['verified_delivery'] ?? null ) ? $state['verified_delivery'] : array(),
			'delivery_problem'  => is_string( $state['delivery_problem'] ?? null ) ? $state['delivery_problem'] : '',
		);
	}

	/**
	 * Merge changes into the stored state. The option is read from the database itself, so a slow
	 * request (a connection test, a probe) cannot write back delivery or mode values that a job
	 * changed in the meantime.
	 *
	 * @param array<string, mixed> $changes
	 */
	public static function update_state( array $changes ): void {
		global $wpdb;

		$raw    = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::STATE_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$stored = is_string( $raw ) ? maybe_unserialize( $raw ) : array();
		$state  = array_merge( is_array( $stored ) ? $stored : array(), $changes );

		wp_cache_delete( self::STATE_OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		update_option( self::STATE_OPTION, $state, true );
	}

	private static function encryption_key(): string {
		return sodium_crypto_generichash( wp_salt( 'auth' ) . '|simple-storage', '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	public static function encrypt( string $plain ): string {
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plain, $nonce, self::encryption_key() );

		return self::SECRET_PREFIX . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/** Returns an empty string when the value cannot be decrypted, for example after the salts changed. */
	public static function decrypt( string $stored ): string {
		if ( '' === $stored || ! str_starts_with( $stored, self::SECRET_PREFIX ) ) {
			return '';
		}

		$raw = base64_decode( substr( $stored, strlen( self::SECRET_PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}

		$plain = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			self::encryption_key()
		);

		return false === $plain ? '' : $plain;
	}
}
