<?php
/**
 * Serving media that live in the storage. Two modes share everything else:
 *
 * proxy  — addresses never change: uploads/.htaccess hands a request for a missing file to
 *          Apache mod_proxy or to proxy.php, which streams it from the storage;
 * direct — the final HTML of front-end pages links to the storage (an output buffer rewrites it)
 *          and old addresses get a redirect from uploads/.htaccess.
 *
 * Either way only a file that is missing locally comes from the storage, which is also what the
 * .htaccess rule checks, so every file stays reachable while it moves in either direction. On
 * servers that ignore .htaccess but pass missing files to WordPress, the same happens on "init".
 *
 * Nothing is deleted locally until serving was verified end to end for the file's extension: a
 * probe file that exists only in the storage must reach a visitor at its uploads address.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_Storage_Delivery {
	public const MARKER = 'Simple Storage';

	/** At most this many extensions are probed in one verification. */
	private const MAX_EXTENSIONS = 20;

	/** @var array<string, bool> */
	private static array $local = array();

	/** @var array<string, bool> Extensions whose probe failed during this request. */
	private static array $failed = array();

	public static function init(): void {
		if ( ! self::active() ) {
			return;
		}

		add_action( 'init', array( self::class, 'serve_missing' ), 0 );

		if ( 'direct' === Simple_Storage_Settings::delivery_mode() ) {
			// Only the final front-end HTML is rewritten. Filtering wp_get_attachment_url() would
			// hand storage URLs to code that stores them (SEO plugins, editors) and would derive
			// sub-size URLs from the full size without checking which sizes are still local.
			add_action( 'template_redirect', array( self::class, 'start_buffer' ), 1 );
		}
	}

	public static function active(): bool {
		return Simple_Storage_Settings::state()['delivery'] && '' !== Simple_Storage_Settings::public_base();
	}

	/** Storage URL for a missing local media file; any other URL is returned unchanged. */
	public static function url_for( string $url ): string {
		$relative = Simple_Storage_Paths::relative_from_url( $url );
		if ( null === $relative || self::exists_locally( $relative ) ) {
			return $url;
		}

		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );

		return Simple_Storage_Paths::public_url( $relative ) . ( '' !== $query ? '?' . $query : '' );
	}

	private static function rewrites_urls_here(): bool {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			return false;
		}

		return ! function_exists( 'wp_is_serving_rest_request' ) || ! wp_is_serving_rest_request();
	}

	public static function start_buffer(): void {
		if ( ! self::rewrites_urls_here() ) {
			return;
		}

		ob_start( array( self::class, 'rewrite_html' ) );
	}

	/**
	 * Rewrite uploads URLs of missing local files in a page. <textarea> contents are left alone:
	 * they are submitted back by front-end editors and would store storage URLs in content.
	 */
	public static function rewrite_html( string $html ): string {
		if ( '' === $html ) {
			return $html;
		}

		$host_path = (string) preg_replace( '#^(?:https?:)?//#i', '', Simple_Storage_Paths::uploads()['baseurl'] );
		$host      = (string) wp_parse_url( 'http://' . $host_path, PHP_URL_HOST );
		if ( '' === $host_path || '' === $host || false === stripos( $html, $host ) ) {
			return $html;
		}

		$parts = preg_split( '~(<textarea\b[^>]*>.*?</textarea\s*>)~is', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( false === $parts ) {
			return $html;
		}

		foreach ( $parts as $index => $part ) {
			if ( 0 === $index % 2 && '' !== $part ) {
				$parts[ $index ] = self::rewrite_fragment( $part, $host_path );
			}
		}

		return implode( '', $parts );
	}

	/**
	 * A URL counts only when its path ends at a real delimiter (quote, whitespace, bracket, query,
	 * srcset comma, an encoded quote): a path that stops at anything else, such as an "&" of an
	 * entity inside a file name, is left alone rather than judged by a truncated name. Paths may
	 * contain \uXXXX escapes (JSON printed with unescaped slashes, as wp_localize_script() does)
	 * and are decoded before the existence check. On a regex failure the HTML stays unchanged.
	 */
	private static function rewrite_fragment( string $html, string $host_path ): string {
		$plain_char = '(?:[^\s"\'<>()\\\\?#&,]|,(?!\s)|\\\\u[0-9a-fA-F]{4})';
		$plain_end  = '(?=$|[\s"\'<>()\\\\?#,]|&(?:quot|apos|\#0?39|\#x27);)';
		$plain      = '~(?:https?:)?//' . preg_quote( $host_path, '~' ) . '/([0-9]{4}/[0-9]{2}/' . $plain_char . '+)' . $plain_end . '~i';

		$result = preg_replace_callback(
			$plain,
			static function ( array $match ): string {
				$path = str_contains( $match[1], '\\u' ) ? json_decode( '"' . $match[1] . '"' ) : $match[1];

				return is_string( $path ) ? self::replacement( $match[0], $match[1], rawurldecode( $path ), false ) : $match[0];
			},
			$html
		);
		if ( null === $result ) {
			return $html;
		}
		$html = $result;

		if ( str_contains( $html, '\\/' ) ) {
			// Inside JSON a path may also hold \/ and \uXXXX escapes; it ends at a quote (plain,
			// escaped or as an entity) or another delimiter.
			$escaped_char = '(?:[^\s"\'<>()\\\\?#&,]|\\\\/|\\\\u[0-9a-fA-F]{4})';
			$escaped_end  = '(?=$|[\s"\'<>()?#,]|\\\\"|&quot;)';
			$escaped      = '~(?:https?:)?\\\\/\\\\/' . str_replace( '/', '\\\\/', preg_quote( $host_path, '~' ) ) . '\\\\/([0-9]{4}\\\\/[0-9]{2}\\\\/' . $escaped_char . '+)' . $escaped_end . '~i';

			$result = preg_replace_callback(
				$escaped,
				static function ( array $match ): string {
					$decoded = json_decode( '"' . $match[1] . '"' );

					return is_string( $decoded ) ? self::replacement( $match[0], $match[1], rawurldecode( $decoded ), true ) : $match[0];
				},
				$html
			);
			if ( null !== $result ) {
				$html = $result;
			}
		}

		return $html;
	}

	/**
	 * @param string $original The whole matched URL.
	 * @param string $url_path The path as it appears in the page (still escaped inside JSON).
	 * @param string $relative The decoded uploads-relative path.
	 */
	private static function replacement( string $original, string $url_path, string $relative, bool $escaped ): string {
		if ( ! Simple_Storage_Paths::is_media_path( $relative ) || self::exists_locally( $relative ) ) {
			return $original;
		}

		$prefix = Simple_Storage_Paths::public_prefix();

		return $escaped ? str_replace( '/', '\\/', $prefix ) . $url_path : $prefix . $url_path;
	}

	private static function exists_locally( string $relative ): bool {
		if ( ! isset( self::$local[ $relative ] ) ) {
			self::$local[ $relative ] = is_file( Simple_Storage_Paths::local( $relative ) );
		}

		return self::$local[ $relative ];
	}

	/**
	 * Fallback for servers that ignore .htaccess but hand requests for missing static files to
	 * WordPress: stream the file (proxy) or redirect to it (direct) before WordPress runs a query.
	 */
	public static function serve_missing(): void {
		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$base = Simple_Storage_Paths::uploads_url_path() . '/';
		if ( '' === $path || ! str_starts_with( $path, $base ) ) {
			return;
		}

		$relative = rawurldecode( substr( $path, strlen( $base ) ) );
		if ( ! Simple_Storage_Paths::is_media_path( $relative ) || self::exists_locally( $relative ) ) {
			return;
		}

		if ( 'proxy' === Simple_Storage_Settings::delivery_mode() ) {
			Simple_Storage_Proxy::serve( self::proxy_config(), $relative );
			exit;
		}

		wp_redirect( Simple_Storage_Paths::public_url( $relative ), Simple_Storage_Settings::redirect_status(), 'Simple Storage' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- The target is the configured storage.
		exit;
	}

	/** @return bool|WP_Error */
	public static function enable() {
		if ( '' === Simple_Storage_Settings::public_base() ) {
			return new WP_Error( 'simple_storage_not_configured', __( 'The storage connection is not configured.', 'simple-storage' ) );
		}

		Simple_Storage_Settings::update_state( array( 'delivery' => true ) );
		$results = self::sync();
		Simple_Storage_Log::info( __( 'Serving from the storage was enabled.', 'simple-storage' ) );
		if ( 'proxy' === Simple_Storage_Settings::delivery_mode() ) {
			if ( is_wp_error( $results['config'] ) ) {
				// Without its configuration proxy.php answers 404 for every missing file.
				return $results['config'];
			}
			self::probe_proxy();
		}

		return true;
	}

	public static function disable(): void {
		Simple_Storage_Settings::update_state(
			array(
				'delivery'          => false,
				'verified_delivery' => array(),
				'delivery_problem'  => '',
			)
		);
		self::remove_htaccess();
		self::remove_config();
		Simple_Storage_Log::info( __( 'Serving from the storage was disabled.', 'simple-storage' ) );
	}

	/**
	 * Rewrite the proxy configuration and the .htaccess rules after a settings change.
	 *
	 * @return array{config: bool|WP_Error, htaccess: bool|WP_Error}
	 */
	public static function sync(): array {
		if ( ! self::active() ) {
			return array(
				'config'   => true,
				'htaccess' => true,
			);
		}

		$results = array(
			'config'   => self::write_config(),
			'htaccess' => self::write_htaccess(),
		);
		foreach ( $results as $result ) {
			if ( is_wp_error( $result ) ) {
				Simple_Storage_Log::warning( $result->get_error_message() );
			}
		}

		return $results;
	}

	/** What a delivery verification depends on; any change makes it stale. */
	public static function delivery_signature(): string {
		return md5( Simple_Storage_Settings::delivery_mode() . '|' . Simple_Storage_Settings::public_base() . '|' . Simple_Storage_Settings::prefix() . '|' . Simple_Storage_Paths::uploads()['baseurl'] );
	}

	/** @return array<int, string> Extensions verified for the current delivery settings. */
	public static function verified_extensions(): array {
		$verified = Simple_Storage_Settings::state()['verified_delivery'];
		if ( ! self::active() || ( $verified['signature'] ?? '' ) !== self::delivery_signature() ) {
			return array();
		}

		return array_values( array_map( 'strval', (array) ( $verified['extensions'] ?? array() ) ) );
	}

	/** @return array<int, string> Extensions of the files the site serves (or will serve) from the storage. */
	public static function extensions_to_verify(): array {
		$extensions = Simple_Storage_Index::extensions( array( 'remote' => 1 ), self::MAX_EXTENSIONS );

		return empty( $extensions ) ? array( 'txt' ) : $extensions;
	}

	/**
	 * Refresh everything serving depends on and prove it again for the current settings: the
	 * generated rules and configuration, the proxy engine when its result is stale, and every
	 * extension of the files in the storage.
	 *
	 * @return array{ok: array<int, string>, failed: array<string, string>}|WP_Error
	 */
	public static function verify_current() {
		if ( ! self::active() ) {
			return new WP_Error( 'simple_storage_delivery_off', __( 'Serving from the storage is off.', 'simple-storage' ) );
		}

		$results = self::sync();
		if ( 'proxy' === Simple_Storage_Settings::delivery_mode() ) {
			if ( is_wp_error( $results['config'] ) ) {
				return $results['config'];
			}
			if ( ! self::probe_is_current() ) {
				self::probe_proxy();
			}
		}

		return self::verify_delivery( self::extensions_to_verify() );
	}

	/** Whether serving was verified at all for the current settings (the automatic offload needs it). */
	public static function delivery_verified(): bool {
		return ! empty( self::verified_extensions() );
	}

	/**
	 * Whether files with this extension may lose their local copy: serving was verified for it, or
	 * a probe for it succeeds now. A failed probe is not repeated within the same request.
	 */
	public static function ensure_extension_verified( string $extension ): bool {
		$extension = strtolower( $extension );
		if ( in_array( $extension, self::verified_extensions(), true ) ) {
			return true;
		}
		// An extension a probe file cannot carry stays local without a request per file.
		if ( isset( self::$failed[ $extension ] ) || ! self::active() || ! self::probeable( $extension ) ) {
			return false;
		}

		$result = self::verify_delivery( array( $extension ) );

		return ! is_wp_error( $result ) && in_array( $extension, $result['ok'], true );
	}

	/** Whether a probe file can carry this extension. */
	private static function probeable( string $extension ): bool {
		return 1 === preg_match( '/^[a-z0-9]{0,10}$/', $extension );
	}

	/**
	 * End-to-end check that files missing locally really reach visitors at their uploads address:
	 * for every extension a probe file is put into the storage and requested through the site.
	 * Proxy mode must answer 200 with the same bytes; direct mode must redirect to an address that
	 * serves the same bytes. Extensions are probed separately because servers often route image
	 * extensions differently (an nginx static location that never falls back to WordPress).
	 *
	 * @param array<int, string> $extensions
	 * @return array{ok: array<int, string>, failed: array<string, string>}|WP_Error
	 */
	public static function verify_delivery( array $extensions ) {
		if ( ! self::active() ) {
			return new WP_Error( 'simple_storage_delivery_off', __( 'Serving from the storage is off.', 'simple-storage' ) );
		}

		$requested  = array_map( 'strtolower', array_map( 'strval', $extensions ) );
		$extensions = array_slice( array_values( array_unique( array_filter( $requested, array( self::class, 'probeable' ) ) ) ), 0, self::MAX_EXTENSIONS );
		if ( empty( $requested ) ) {
			$extensions = array( 'txt' );
		}

		$mode   = Simple_Storage_Settings::delivery_mode();
		$report = array(
			'ok'     => array(),
			'failed' => array(),
		);
		foreach ( $extensions as $extension ) {
			$result = self::with_probe_file(
				'simple storage check',
				$extension,
				static fn( string $url, string $body ) => self::check_probe( $mode, $url, $body )
			);
			if ( true === $result ) {
				$report['ok'][] = $extension;
				unset( self::$failed[ $extension ] );
			} else {
				$report['failed'][ $extension ] = is_wp_error( $result ) ? $result->get_error_message() : __( 'Unexpected storage response.', 'simple-storage' );
				self::$failed[ $extension ]     = true;
			}
		}

		$stored   = Simple_Storage_Settings::state()['verified_delivery'];
		$verified = ( $stored['signature'] ?? '' ) === self::delivery_signature() ? (array) ( $stored['extensions'] ?? array() ) : array();
		$verified = array_values( array_diff( array_unique( array_merge( $verified, $report['ok'] ) ), array_keys( $report['failed'] ) ) );
		$changes  = array(
			'verified_delivery' => array(
				'signature'  => self::delivery_signature(),
				'extensions' => $verified,
				'time'       => time(),
			),
		);
		if ( ! empty( $report['ok'] ) ) {
			// Serving works: the problem a failed check recorded is over.
			$changes['delivery_problem'] = '';
		}
		Simple_Storage_Settings::update_state( $changes );

		foreach ( $report['failed'] as $extension => $message ) {
			Simple_Storage_Log::warning( $message . ( '' !== $extension ? ' (.' . $extension . ')' : '' ) );
		}

		return $report;
	}

	/** @return bool|WP_Error */
	private static function check_probe( string $mode, string $url, string $body ) {
		$response = self::probe_request( $url, 0 );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 'proxy' === $mode ) {
			if ( 200 === $status && wp_remote_retrieve_body( $response ) === $body ) {
				return true;
			}
		} elseif ( in_array( $status, array( 301, 302, 307, 308 ), true ) ) {
			// mod_rewrite re-escapes the path with lowercase hex, so the redirect target is not
			// compared as text: what matters is that it serves the probe.
			$location = (string) wp_remote_retrieve_header( $response, 'location' );
			$target   = '' !== $location ? self::probe_request( $location, 3 ) : null;
			if ( is_array( $target ) && 200 === (int) wp_remote_retrieve_response_code( $target ) && wp_remote_retrieve_body( $target ) === $body ) {
				return true;
			}
		}

		return new WP_Error(
			'simple_storage_delivery_check',
			/* translators: 1: URL, 2: HTTP status code. */
			sprintf( __( 'A file that exists only in the storage is not served at its site address %1$s (answer %2$d).', 'simple-storage' ), $url, $status )
		);
	}

	/**
	 * What to tell about a serving check that failed: its first failure and, when nothing at all
	 * was served, what the server answers around it. Such a problem is remembered for the overview
	 * until a check passes or serving is turned off.
	 *
	 * @param array{ok: array<int, string>, failed: array<string, string>} $report
	 */
	public static function explain( array $report ): string {
		$first = (string) reset( $report['failed'] );
		if ( ! empty( $report['ok'] ) || empty( $report['failed'] ) ) {
			return $first;
		}

		$causes = self::diagnose();
		if ( empty( $causes ) ) {
			$causes[] = __( 'Check that the uploads/.htaccess rules work or that the server passes missing files to WordPress, and that the proxy configuration next to the plugins folder could be written.', 'simple-storage' );
		}
		$text = trim( $first . ' ' . implode( ' ', $causes ) );
		Simple_Storage_Settings::update_state( array( 'delivery_problem' => $text ) );

		return $text;
	}

	/**
	 * Requests around a failed check that name its cause: a missing file anywhere in uploads (no
	 * rule of the plugin applies to it, so an error there comes from the rest of uploads/.htaccess)
	 * and proxy.php itself (a security rule that forbids PHP in the plugins folder).
	 *
	 * @return array<int, string> Sentences, empty when nothing points at a cause.
	 */
	public static function diagnose(): array {
		$causes  = array();
		$proxy   = 'proxy' === Simple_Storage_Settings::delivery_mode();
		$missing = self::probe_request( Simple_Storage_Paths::uploads()['baseurl'] . '/simple-storage-missing-' . strtolower( wp_generate_password( 8, false, false ) ) . '.txt', 0 );
		$status  = is_wp_error( $missing ) ? 0 : (int) wp_remote_retrieve_response_code( $missing );
		if ( $status >= 500 ) {
			$lines    = array_map(
				/* translators: %s: a line of an .htaccess file, quoted. */
				static fn( string $line ): string => sprintf( __( '“%s”', 'simple-storage' ), $line ),
				self::rejected_lines( self::htaccess_file() )
			);
			$causes[] = empty( $lines )
				/* translators: %d: HTTP status code. */
				? sprintf( __( 'The server answers %d for any missing file in uploads, not only for media: it rejects uploads/.htaccess itself. Its error log names the line.', 'simple-storage' ), $status )
				/* translators: 1: HTTP status code, 2: lines of uploads/.htaccess. */
				: sprintf( __( 'The server answers %1$d for any missing file in uploads, not only for media: it rejects a line of uploads/.htaccess outside the Simple Storage block, most likely %2$s. Many hosts do not allow it in .htaccess: remove or change it.', 'simple-storage' ), $status, implode( ', ', $lines ) );
		}

		if ( $proxy && 'php' === self::proxy_engine() ) {
			$url      = SIMPLE_STORAGE_URL . 'proxy.php';
			$path     = (string) wp_parse_url( $url, PHP_URL_PATH );
			$response = self::probe_request( $url, 0 );
			// proxy.php marks its own answers; anything else came from the server in front of it.
			if ( ! is_wp_error( $response ) && '' === (string) wp_remote_retrieve_header( $response, 'x-simple-storage' ) ) {
				$status    = (int) wp_remote_retrieve_response_code( $response );
				$exception = 'RewriteCond %{REQUEST_URI} !^' . str_replace( '.', '\\.', $path ) . '$';
				if ( 401 === $status || 403 === $status ) {
					$rule     = self::blocking_rule();
					$causes[] = '' === $rule
						/* translators: 1: URL path of proxy.php, 2: HTTP status code, 3: an .htaccess line. */
						? sprintf( __( 'Requests to %1$s are refused (answer %2$d): the server or a security rule forbids PHP files in the plugins folder. Allow this one file; in .htaccess, put %3$s right before the rule that forbids them.', 'simple-storage' ), $path, $status, $exception )
						/* translators: 1: URL path of proxy.php, 2: HTTP status code, 3: an .htaccess line, 4: an .htaccess line. */
						: sprintf( __( 'Requests to %1$s are refused (answer %2$d) by the rule %3$s in the .htaccess of the site, which forbids PHP files in the plugins folder. Put %4$s right before it.', 'simple-storage' ), $path, $status, $rule, $exception );
				} elseif ( $status >= 500 ) {
					/* translators: 1: URL path of proxy.php, 2: HTTP status code. */
					$causes[] = sprintf( __( '%1$s does not run (answer %2$d): see the PHP error log.', 'simple-storage' ), $path, $status );
				}
			}
		}

		if ( $proxy && ! empty( $causes ) ) {
			$causes[] = __( 'Until it is fixed, the "Directly from the storage" mode can serve the pages: it points them at the storage and needs none of these rules.', 'simple-storage' );
		}

		return $causes;
	}

	/**
	 * Lines of an .htaccess file outside the plugin's block that hosts commonly refuse there:
	 * Options with ExecCGI, Includes or FollowSymLinks, and PHP settings that no <IfModule> for
	 * mod_php guards (with PHP-FPM they are unknown directives).
	 *
	 * @return array<int, string>
	 */
	private static function rejected_lines( string $file ): array {
		$text = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$ours = false;
		$php  = array();
		$hits = array();
		foreach ( (array) preg_split( '/\R/', $text ) as $line ) {
			$line = trim( (string) $line );
			if ( str_starts_with( $line, '# BEGIN Simple Storage' ) ) {
				$ours = true;
			} elseif ( str_starts_with( $line, '# END Simple Storage' ) ) {
				$ours = false;
			} elseif ( $ours || '' === $line || '#' === $line[0] ) {
				continue;
			} elseif ( preg_match( '/^<IfModule\s+(!?)\s*([^>\s]+)\s*>/i', $line, $match ) ) {
				$php[] = '' === $match[1] && false !== stripos( $match[2], 'php' );
			} elseif ( preg_match( '#^</IfModule\s*>#i', $line ) ) {
				array_pop( $php );
			} elseif ( preg_match( '/^Options\s.*\b(?:ExecCGI|Includes|FollowSymLinks)\b/i', $line ) ) {
				$hits[] = $line;
			} elseif ( preg_match( '/^php_(?:value|flag|admin_value|admin_flag)\s/i', $line ) && ! in_array( true, $php, true ) ) {
				$hits[] = $line;
			}
		}

		return array_slice( array_values( array_unique( $hits ) ), 0, 3 );
	}

	/** A rewrite rule of the site's .htaccess that forbids PHP files in the plugins folder, or "". */
	private static function blocking_rule(): string {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$file = get_home_path() . '.htaccess';
		$text = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		foreach ( (array) preg_split( '/\R/', $text ) as $line ) {
			$line = trim( (string) $line );
			if ( preg_match( '/^RewriteRule\s+(\S+)\s+-\s+\[([^\]]*)\]/i', $line, $match ) && false !== stripos( $match[1], 'plugins' ) && false !== stripos( $match[1], 'php' ) && preg_match( '/(?:^|,)\s*F\s*(?:,|$)/i', $match[2] ) ) {
				/* translators: %s: a line of an .htaccess file, quoted. */
				return sprintf( __( '“%s”', 'simple-storage' ), $line );
			}
		}

		return '';
	}

	/**
	 * Put a probe file with a space and Cyrillic in its name into the storage, run $check with its
	 * uploads URL and contents, and always remove it again.
	 *
	 * @return mixed|WP_Error Whatever $check returns.
	 */
	private static function with_probe_file( string $label, string $extension, callable $check ) {
		$client = Simple_Storage_Client::create();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$relative = '0000/00/' . $label . ' ' . strtolower( wp_generate_password( 6, false, false ) ) . ' тест' . ( '' !== $extension ? '.' . $extension : '' );
		$remote   = Simple_Storage_Paths::remote( $relative );
		$body     = 'Simple Storage ' . $label . ' ' . wp_generate_password( 24, false, false );
		$local    = wp_tempnam( 'simple-storage-probe' );
		file_put_contents( $local, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		try {
			$ready    = $client->ensure_dir( dirname( $remote ) );
			$uploaded = is_wp_error( $ready ) ? $ready : $client->upload( $remote, $local, true );
			if ( is_wp_error( $uploaded ) ) {
				return $uploaded;
			}

			return $check( Simple_Storage_Paths::uploads()['baseurl'] . '/' . Simple_Storage_Paths::encode( $relative ), $body );
		} finally {
			@unlink( $local ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			$client->delete_dir( Simple_Storage_Paths::remote_root() . '/0000' );
		}
	}

	/** @return array<string, mixed>|WP_Error A request for a probe file without cookies or credentials. */
	private static function probe_request( string $url, int $redirection ) {
		return wp_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => $redirection,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter for loopback requests.
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			)
		);
	}

	/** @return array<string, mixed> */
	public static function proxy_config(): array {
		/**
		 * Cache-Control sent with files served by the transparent proxy.
		 *
		 * @param string $value Header value.
		 */
		$cache_control = (string) apply_filters( 'simple_storage_proxy_cache_control', 'public, max-age=604800' );

		return array(
			'version'       => Simple_Storage_Proxy::CONFIG_VERSION,
			'mode'          => Simple_Storage_Settings::delivery_mode(),
			'base'          => Simple_Storage_Settings::public_base(),
			'prefix'        => Simple_Storage_Settings::prefix(),
			'uploads_path'  => Simple_Storage_Paths::uploads_url_path(),
			'uploads_dir'   => Simple_Storage_Paths::uploads()['basedir'],
			'cache_control' => $cache_control,
		);
	}

	/** @return bool|WP_Error */
	public static function write_config() {
		$file   = Simple_Storage_Proxy::config_file();
		$source = Simple_Storage_Proxy::config_source( self::proxy_config() );
		if ( is_file( $file ) && file_get_contents( $file ) === $source ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			return true;
		}

		$temporary = $file . '.' . wp_generate_password( 8, false ) . '.tmp';
		if ( false === file_put_contents( $temporary, $source, LOCK_EX ) || ! @rename( $temporary, $file ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			@unlink( $temporary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink

			return new WP_Error(
				'simple_storage_config',
				/* translators: %s: file path. */
				sprintf( __( 'Could not write %s; the transparent proxy cannot work without it.', 'simple-storage' ), $file )
			);
		}

		if ( function_exists( 'wp_opcache_invalidate' ) ) {
			wp_opcache_invalidate( $file, true );
		}

		return true;
	}

	public static function remove_config(): void {
		$file = Simple_Storage_Proxy::config_file();
		if ( ! is_file( $file ) ) {
			return;
		}

		$head = (string) file_get_contents( $file, false, null, 0, 256 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( str_contains( $head, Simple_Storage_Proxy::CONFIG_MARKER ) ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			if ( function_exists( 'wp_opcache_invalidate' ) ) {
				wp_opcache_invalidate( $file, true );
			}
		}
	}

	public static function htaccess_file(): string {
		return Simple_Storage_Paths::uploads()['basedir'] . '/.htaccess';
	}

	/**
	 * The proxy engine found by the last probe for the current storage address and folder:
	 * "apache-https" (mod_proxy through RewriteRule [P], no PHP), or "php". A plain HTTP connection
	 * to the storage is not an option: proxy.php verifies the storage, Apache would not.
	 */
	public static function proxy_engine(): string {
		$probe = Simple_Storage_Settings::state()['proxy'];
		if ( ( $probe['signature'] ?? '' ) !== self::proxy_signature() ) {
			return 'php';
		}

		return 'apache-https' === ( $probe['engine'] ?? '' ) ? 'apache-https' : 'php';
	}

	/** What a probe result depends on; a change makes the result stale. */
	private static function proxy_signature(): string {
		return md5( Simple_Storage_Settings::public_base() . '|' . Simple_Storage_Settings::prefix() . '|' . Simple_Storage_Paths::uploads()['baseurl'] );
	}

	/** @return array<int, string> */
	public static function htaccess_rules( ?string $engine = null ): array {
		$rules = array( '<IfModule mod_rewrite.c>', 'RewriteEngine On' );

		if ( 'proxy' === Simple_Storage_Settings::delivery_mode() ) {
			$engine = $engine ?? self::proxy_engine();
			if ( 'php' !== $engine ) {
				// Apache streams the file itself, but never with the visitor's cookies or credentials,
				// and it passes on neither cookies of the storage nor a type browsers would guess.
				// Without mod_headers to do that (or without mod_proxy) the PHP rule below takes over,
				// as it does for what that rule refuses: other methods and files that could run.
				$rules[] = '<IfModule mod_proxy_http.c>';
				$rules[] = '<IfModule mod_headers.c>';
				$rules[] = 'RequestHeader unset Cookie';
				$rules[] = 'RequestHeader unset Authorization';
				$rules[] = 'Header unset Set-Cookie';
				$rules[] = 'Header always unset Set-Cookie';
				$rules[] = 'Header always set X-Content-Type-Options nosniff';
				$rules[] = 'RewriteCond %{REQUEST_METHOD} ^(?:GET|HEAD)$';
				$rules[] = 'RewriteCond %{REQUEST_URI} !\.(?:' . implode( '|', Simple_Storage_Proxy::CODE_EXTENSIONS ) . ')(?:\.|$) [NC]';
				$rules[] = 'RewriteCond %{REQUEST_FILENAME} !-f';
				$rules[] = 'RewriteRule ^([0-9]{4}/[0-9]{2}/.+)$ ' . Simple_Storage_Paths::public_prefix() . '$1 [P,L]';
				$rules[] = '</IfModule>';
				$rules[] = '</IfModule>';
			}
			$rules[] = 'RewriteCond %{REQUEST_FILENAME} !-f';
			$rules[] = 'RewriteRule ^[0-9]{4}/[0-9]{2}/.+$ ' . wp_parse_url( SIMPLE_STORAGE_URL . 'proxy.php', PHP_URL_PATH ) . ' [L]';
		} else {
			$rules[] = 'RewriteCond %{REQUEST_FILENAME} !-f';
			$rules[] = 'RewriteRule ^([0-9]{4}/[0-9]{2}/.+)$ ' . Simple_Storage_Paths::public_prefix() . '$1 [R=' . Simple_Storage_Settings::redirect_status() . ',L]';
		}

		$rules[] = '</IfModule>';

		return $rules;
	}

	/**
	 * Find out whether Apache can proxy to the storage itself (RewriteRule [P]), so that no PHP
	 * process is spent on media. A probe file is put into the storage and requested through the
	 * site address under that rule: the engine counts only when the bytes match and the response
	 * did not come from the PHP proxy. Anything else — no mod_proxy or mod_headers, no SSL
	 * proxying, a failed loopback — falls back to proxy.php.
	 *
	 * @return array<string, mixed>
	 */
	public static function probe_proxy(): array {
		$report = array(
			'engine'    => 'php',
			'time'      => time(),
			'tried'     => array(),
			'signature' => self::proxy_signature(),
		);

		if ( ! self::active() || 'proxy' !== Simple_Storage_Settings::delivery_mode() ) {
			return self::save_probe( $report );
		}

		$result = self::with_probe_file(
			'simple storage probe',
			'txt',
			static function ( string $url, string $body ) use ( &$report ) {
				$engine  = 'apache-https';
				$written = self::write_htaccess( $engine );
				if ( is_wp_error( $written ) ) {
					$report['tried'][] = array(
						'engine' => $engine,
						'result' => $written->get_error_message(),
					);

					return true;
				}

				$response = self::probe_request( $url, 0 );
				if ( is_wp_error( $response ) ) {
					$result = $response->get_error_message();
				} elseif ( '' !== (string) wp_remote_retrieve_header( $response, 'x-simple-storage' ) ) {
					$result = 'php';
				} else {
					$result = (string) wp_remote_retrieve_response_code( $response );
					if ( '200' === $result && wp_remote_retrieve_body( $response ) !== $body ) {
						$result = 'body mismatch';
					}
				}

				$report['tried'][] = array(
					'engine' => $engine,
					'result' => $result,
				);
				if ( '200' === $result ) {
					$report['engine'] = $engine;
				}

				return true;
			}
		);
		if ( is_wp_error( $result ) ) {
			$report['tried'][] = array(
				'engine' => 'upload',
				'result' => $result->get_error_message(),
			);
		}

		return self::save_probe( $report );
	}

	/**
	 * @param array<string, mixed> $report
	 * @return array<string, mixed>
	 */
	private static function save_probe( array $report ): array {
		Simple_Storage_Settings::update_state( array( 'proxy' => $report ) );
		$written = self::write_htaccess();
		if ( is_wp_error( $written ) ) {
			Simple_Storage_Log::warning( $written->get_error_message() );
		}

		Simple_Storage_Log::info(
			'php' === $report['engine']
				? __( 'Transparent proxy: Apache cannot proxy to the storage here, so proxy.php serves the files.', 'simple-storage' )
				/* translators: %s: engine name. */
				: sprintf( __( 'Transparent proxy: Apache serves the files itself (%s), no PHP is involved.', 'simple-storage' ), $report['engine'] )
		);

		return $report;
	}

	/** Whether the stored probe result is still valid for the current settings. */
	public static function probe_is_current(): bool {
		return ( Simple_Storage_Settings::state()['proxy']['signature'] ?? '' ) === self::proxy_signature();
	}

	/** @return bool|WP_Error */
	public static function write_htaccess( ?string $engine = null ) {
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		$file = self::htaccess_file();
		if ( ! insert_with_markers( $file, self::MARKER, self::htaccess_rules( $engine ) ) ) {
			return new WP_Error(
				'simple_storage_htaccess',
				/* translators: %s: file path. */
				sprintf( __( 'Could not write the rules to %s. Missing files are then served only where the server passes such requests to WordPress.', 'simple-storage' ), $file )
			);
		}

		return true;
	}

	public static function remove_htaccess(): void {
		$file = self::htaccess_file();
		if ( ! is_file( $file ) || ! is_writable( $file ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			return;
		}

		$contents = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$pattern  = '/^# BEGIN ' . preg_quote( self::MARKER, '/' ) . '\R.*?^# END ' . preg_quote( self::MARKER, '/' ) . '\R?/ms';
		$updated  = (string) preg_replace( $pattern, '', $contents );
		if ( $updated === $contents ) {
			return;
		}

		if ( '' === trim( $updated ) ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		} else {
			file_put_contents( $file, ltrim( $updated ), LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	/** "ok", "missing" or "stale" for the rules currently in uploads/.htaccess. */
	public static function htaccess_status(): string {
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		$lines = extract_from_markers( self::htaccess_file(), self::MARKER );
		if ( empty( $lines ) ) {
			return 'missing';
		}

		return $lines === self::htaccess_rules() ? 'ok' : 'stale';
	}
}
