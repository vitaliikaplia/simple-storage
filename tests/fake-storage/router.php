<?php
/**
 * Fake Hosting Ukraine storage for local testing, run with the PHP built-in server:
 *
 *   FAKE_STORAGE_ROOT=/tmp/fake-storage php -S 127.0.0.1:8899 tests/fake-storage/router.php
 *
 * It implements the documented content API (https://www.ukraine.com.ua/wiki/storage/api/) and
 * direct public links. Where the documentation is silent it is deliberately strict: uploads and
 * new directories need an existing parent, and an existing directory cannot be created again.
 *
 * Environment: FAKE_STORAGE_ROOT (files), FAKE_STORAGE_LOGIN / FAKE_STORAGE_PASSWORD (default
 * test / test), FAKE_STORAGE_PUBLIC=0 to switch public access off. Faults for tests live in
 * {root}/.fake/faults.json: {"corrupt": "<regex>", "fail": "<regex>"} — matching uploads are
 * stored truncated or answered with 503.
 */

// A test helper for the PHP built-in server only; never act as a web-reachable script.
if ( 'cli-server' !== PHP_SAPI ) {
	http_response_code( 404 );
	exit;
}

$root = rtrim( (string) ( getenv( 'FAKE_STORAGE_ROOT' ) ?: sys_get_temp_dir() . '/fake-storage' ), '/' );
$meta = $root . '/.fake';
@mkdir( $meta, 0777, true );

$login    = (string) ( getenv( 'FAKE_STORAGE_LOGIN' ) ?: 'test' );
$password = (string) ( getenv( 'FAKE_STORAGE_PASSWORD' ) ?: 'test' );
$public   = '0' !== getenv( 'FAKE_STORAGE_PUBLIC' );
$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri_path = rawurldecode( (string) parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH ) );

function respond( int $status, $data = null, array $headers = array() ): void {
	http_response_code( $status );
	header( 'Content-Type: application/json' );
	foreach ( $headers as $name => $value ) {
		header( $name . ': ' . $value );
	}
	if ( null !== $data && 'HEAD' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		echo json_encode( $data );
	}
	exit;
}

function fail( int $status, string $message ): void {
	respond( $status, array( 'status' => 'error', 'error' => $message ) );
}

/** multipart/form-data fields of a PUT body, which PHP does not parse itself. */
function form_fields(): array {
	if ( ! empty( $_POST ) ) {
		return $_POST;
	}
	$type = (string) ( $_SERVER['CONTENT_TYPE'] ?? '' );
	if ( ! preg_match( '/boundary=(?:"([^"]+)"|([^;]+))/', $type, $match ) ) {
		parse_str( (string) file_get_contents( 'php://input' ), $fields );

		return $fields;
	}
	$boundary = '' !== $match[1] ? $match[1] : $match[2];
	$fields   = array();
	foreach ( explode( '--' . $boundary, (string) file_get_contents( 'php://input' ) ) as $part ) {
		if ( ! preg_match( '/name="([^"]+)"\r\n(?:[^\r\n]+\r\n)*\r\n(.*)\r\n$/s', $part, $field ) ) {
			continue;
		}
		$fields[ $field[1] ] = $field[2];
	}

	return $fields;
}

/** Absolute path inside the root for a storage path, or an error for unsafe paths. */
function local_path( string $root, string $path ): string {
	if ( '' === $path || '/' !== $path[0] || str_contains( $path, "\0" ) || preg_match( '#(^|/)\.\.(/|$)#', $path ) ) {
		fail( 400, 'Invalid path' );
	}

	return $root . rtrim( $path, '/' );
}

function tokens( string $meta ): array {
	$tokens = json_decode( (string) @file_get_contents( $meta . '/tokens.json' ), true );

	return is_array( $tokens ) ? $tokens : array();
}

function require_auth( string $meta, string $login ): void {
	$header = (string) ( $_SERVER['HTTP_AUTHORIZATION'] ?? '' );
	$token  = preg_match( '/^Bearer\s+(\S+)$/', $header, $match ) ? $match[1] : '';
	$tokens = tokens( $meta );
	if ( '' === $token || ! isset( $tokens[ $token ] ) || $tokens[ $token ] < time() ) {
		fail( 401, 'Unauthorized' );
	}
	header( 'x-user-login: ' . $login );
	header( 'x-user-write: 1' );
	header( 'x-user-till: ' . $tokens[ $token ] );
}

function faults( string $meta ): array {
	$faults = json_decode( (string) @file_get_contents( $meta . '/faults.json' ), true );

	return is_array( $faults ) ? $faults : array();
}

function item( string $root, string $absolute, bool $public ): array {
	$path = substr( $absolute, strlen( $root ) );
	$dir  = is_dir( $absolute );

	return array(
		'name'        => basename( $absolute ),
		'path'        => $path,
		'isDirectory' => $dir,
		'size'        => $dir ? 4096 : filesize( $absolute ),
		'mtime'       => filemtime( $absolute ),
		'ctime'       => filectime( $absolute ),
		'birthtime'   => filemtime( $absolute ),
		'ext'         => $dir ? null : pathinfo( $absolute, PATHINFO_EXTENSION ),
		'share'       => null,
		'uri'         => $public ? $path : '/~/secure/' . md5( $path ) . $path,
	);
}

function walk( string $root, string $dir, bool $recursive, bool $public ): array {
	$items = array();
	foreach ( scandir( $dir ) as $name ) {
		if ( '.' === $name || '..' === $name || ( $dir === $root && '.fake' === $name ) ) {
			continue;
		}
		$absolute = $dir . '/' . $name;
		$items[]  = item( $root, $absolute, $public );
		if ( $recursive && is_dir( $absolute ) ) {
			$items = array_merge( $items, walk( $root, $absolute, true, $public ) );
		}
	}

	return $items;
}

function remove_tree( string $path ): void {
	if ( is_dir( $path ) && ! is_link( $path ) ) {
		foreach ( scandir( $path ) as $name ) {
			if ( '.' !== $name && '..' !== $name ) {
				remove_tree( $path . '/' . $name );
			}
		}
		rmdir( $path );
	} else {
		unlink( $path );
	}
}

// Public direct links.
if ( ! str_starts_with( $uri_path, '/~/' ) ) {
	if ( 'GET' !== $method && 'HEAD' !== $method ) {
		fail( 405, 'Method not allowed' );
	}
	if ( ! $public ) {
		fail( 403, 'Forbidden' );
	}
	$file = local_path( $root, $uri_path );
	if ( str_starts_with( $file, $meta ) || ! is_file( $file ) ) {
		fail( 404, 'Not found' );
	}
	// Conditional and range requests as the documentation describes them for downloads.
	$size  = filesize( $file );
	$mtime = filemtime( $file );
	$etag  = 'W/"' . dechex( $mtime ) . '-' . dechex( $size ) . '"';
	header( 'ETag: ' . $etag );
	header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT' );
	header( 'Accept-Ranges: bytes' );
	header( 'Content-Type: application/octet-stream' );
	// Lets tests/apache-proxy.sh see whether a proxy forwarded the visitor's credentials.
	if ( isset( $_SERVER['HTTP_COOKIE'] ) ) {
		header( 'X-Fake-Cookie: received' );
	}
	if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
		header( 'X-Fake-Authorization: received' );
	}
	if ( ( $_SERVER['HTTP_IF_NONE_MATCH'] ?? '' ) === $etag ) {
		http_response_code( 304 );
		exit;
	}
	$start = 0;
	$end   = $size - 1;
	if ( preg_match( '/^bytes=(\d*)-(\d*)$/', (string) ( $_SERVER['HTTP_RANGE'] ?? '' ), $range ) ) {
		$start = '' === $range[1] ? max( 0, $size - (int) $range[2] ) : (int) $range[1];
		$end   = '' === $range[1] || '' === $range[2] ? $size - 1 : min( (int) $range[2], $size - 1 );
		if ( $start > $end || $start >= $size ) {
			http_response_code( 416 );
			header( 'Content-Range: bytes */' . $size );
			exit;
		}
		http_response_code( 206 );
		header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
	} else {
		http_response_code( 200 );
	}
	header( 'Content-Length: ' . ( $end - $start + 1 ) );
	if ( 'HEAD' !== $method && $size > 0 ) {
		$handle = fopen( $file, 'rb' );
		fseek( $handle, $start );
		echo fread( $handle, $end - $start + 1 );
		fclose( $handle );
	}
	exit;
}

$endpoint = substr( $uri_path, strlen( '/~/api/' ) );

if ( 'auth' === $endpoint ) {
	if ( 'PUT' === $method ) {
		$fields = form_fields();
		if ( ( $fields['login'] ?? '' ) !== $login || ( $fields['password'] ?? '' ) !== $password ) {
			fail( 401, "Invalid credential 'login' or 'password'" );
		}
		$till            = time() + ( '1' === ( $fields['remember'] ?? '0' ) ? 7 * 86400 : 6 * 3600 );
		$token           = bin2hex( random_bytes( 16 ) );
		$tokens          = tokens( $meta );
		$tokens[ $token ] = $till;
		file_put_contents( $meta . '/tokens.json', json_encode( $tokens ) );
		respond( 200, array( 'status' => 'success', 'data' => array( 'token' => $token, 'till' => $till ) ) );
	}
	if ( 'DELETE' === $method ) {
		require_auth( $meta, $login );
		respond( 200, array( 'status' => 'success' ) );
	}
	fail( 405, 'Method not allowed' );
}

require_auth( $meta, $login );

if ( 'directory' === $endpoint ) {
	if ( 'GET' === $method ) {
		$dir = local_path( $root, (string) ( $_GET['path'] ?? '' ) );
		if ( '/' === ( $_GET['path'] ?? '' ) ) {
			$dir = $root;
		}
		if ( ! is_dir( $dir ) ) {
			fail( 404, 'Not found' );
		}
		respond( 200, array( 'status' => 'success', 'data' => walk( $root, $dir, '1' === ( $_GET['recursive'] ?? '0' ), $public ) ) );
	}
	if ( 'PUT' === $method ) {
		$fields = form_fields();
		$dir    = local_path( $root, (string) ( $fields['path'] ?? '' ) );
		if ( file_exists( $dir ) ) {
			fail( 400, 'Directory exists' );
		}
		if ( ! is_dir( dirname( $dir ) ) ) {
			fail( 400, 'Parent directory not found' );
		}
		mkdir( $dir );
		respond( 200, array( 'status' => 'success' ) );
	}
	if ( 'DELETE' === $method ) {
		$dir = local_path( $root, (string) ( $_GET['path'] ?? '' ) );
		if ( $dir === $root || ! is_dir( $dir ) ) {
			fail( 404, 'Not found' );
		}
		remove_tree( $dir );
		respond( 200, array( 'status' => 'success' ) );
	}
	fail( 405, 'Method not allowed' );
}

if ( 'file' === $endpoint ) {
	$path = (string) ( $_GET['path'] ?? '' );
	$file = local_path( $root, $path );

	if ( 'HEAD' === $method ) {
		if ( ! file_exists( $file ) ) {
			respond( 404 );
		}
		respond(
			200,
			null,
			array(
				'x-stat-directory' => is_dir( $file ) ? 'true' : 'false',
				'x-stat-size'      => is_dir( $file ) ? 4096 : filesize( $file ),
				'x-stat-mtime'     => filemtime( $file ),
			)
		);
	}
	if ( 'PUT' === $method ) {
		$faults = faults( $meta );
		if ( ! empty( $faults['fail'] ) && preg_match( $faults['fail'], $path ) ) {
			fail( 503, 'Service unavailable' );
		}
		if ( ! is_dir( dirname( $file ) ) ) {
			fail( 400, 'Path not found' );
		}
		if ( file_exists( $file ) && '1' !== ( $_GET['overwrite'] ?? '0' ) ) {
			fail( 400, 'File exists' );
		}
		$in  = fopen( 'php://input', 'rb' );
		$out = fopen( $file, 'wb' );
		stream_copy_to_stream( $in, $out );
		fclose( $out );
		if ( ! empty( $faults['corrupt'] ) && preg_match( $faults['corrupt'], $path ) ) {
			// Same size, different bytes: only a checksum comparison can catch it.
			$bytes = (string) file_get_contents( $file );
			if ( '' !== $bytes ) {
				$bytes[0] = chr( ord( $bytes[0] ) ^ 0xFF );
				file_put_contents( $file, $bytes );
			}
		}
		respond( 200, array( 'status' => 'success' ) );
	}
	if ( 'POST' === $method ) {
		$fields = form_fields();
		$from   = local_path( $root, (string) ( $fields['path'] ?? '' ) );
		$to     = local_path( $root, (string) ( $fields['dest'] ?? '' ) );
		if ( ! is_file( $from ) ) {
			fail( 404, 'Not found' );
		}
		if ( file_exists( $to ) && '1' !== ( $fields['overwrite'] ?? '0' ) ) {
			fail( 400, 'File exists' );
		}
		rename( $from, $to );
		respond( 200, array( 'status' => 'success' ) );
	}
	if ( 'DELETE' === $method ) {
		if ( ! file_exists( $file ) ) {
			fail( 404, 'Not found' );
		}
		if ( is_dir( $file ) ) {
			fail( 400, 'Is a directory' );
		}
		unlink( $file );
		respond( 200, array( 'status' => 'success' ) );
	}
	fail( 405, 'Method not allowed' );
}

fail( 404, 'Unknown method' );
