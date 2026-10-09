<?php
/**
 * Emulates the uploads/.htaccess rules for the PHP built-in server: an existing file is served as
 * is; a missing media file goes to proxy.php in the transparent proxy mode, or gets a 302 to the
 * storage in the direct mode — the mode is read from the generated proxy configuration.
 *
 *   php -S 127.0.0.1:8898 -t <docroot> tests/fake-site/router.php
 *
 * SIMPLE_STORAGE_UPLOADS_PATH (default /wp-content/uploads) is the uploads URL path.
 */

// A test helper for the PHP built-in server only; never act as a web-reachable script.
if ( 'cli-server' !== PHP_SAPI ) {
	http_response_code( 404 );
	exit;
}

$uploads = rtrim( (string) ( getenv( 'SIMPLE_STORAGE_UPLOADS_PATH' ) ?: '/wp-content/uploads' ), '/' );
$path    = rawurldecode( (string) parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH ) );

if ( is_file( $_SERVER['DOCUMENT_ROOT'] . $path ) ) {
	return false;
}

if ( preg_match( '#^' . preg_quote( $uploads, '#' ) . '/([0-9]{4}/[0-9]{2}/.+)$#', $path, $match ) ) {
	define( 'SIMPLE_STORAGE_PROXY', true );
	require_once dirname( __DIR__, 2 ) . '/includes/class-simple-storage-proxy.php';

	$config = Simple_Storage_Proxy::load_config();
	if ( is_array( $config ) && 'direct' === ( $config['mode'] ?? '' ) ) {
		$encode = static fn( string $value ): string => implode( '/', array_map( 'rawurlencode', explode( '/', $value ) ) );
		header( 'Location: ' . rtrim( (string) $config['base'], '/' ) . '/' . $encode( (string) $config['prefix'] ) . '/' . $encode( $match[1] ), true, 302 );
		exit;
	}

	require dirname( __DIR__, 2 ) . '/proxy.php';

	return true;
}

http_response_code( 404 );
echo 'Not Found';
