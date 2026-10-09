<?php
/**
 * End-to-end test against the fake storage, in an isolated temporary uploads folder that a fake
 * site (tests/fake-site/router.php, which emulates the uploads/.htaccess rules) serves over HTTP.
 *
 *   FAKE_STORAGE_ROOT=/tmp/fake php -S 127.0.0.1:8899 tests/fake-storage/router.php &
 *   FAKE_STORAGE_URL=http://127.0.0.1:8899 FAKE_STORAGE_ROOT=/tmp/fake wp eval-file tests/integration.php
 *
 * The plugin must not be active. The test starts the fake site itself (port 8898, override with
 * FAKE_SITE_PORT and the PHP binary with FAKE_SITE_PHP), creates the index table and the options,
 * runs index → push → both serving modes → image editing → automatic offload → attachment
 * deletion → conflicts and faults → pull, and removes everything it created at the end. Real
 * media of the site are never touched.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$ss_fake_url  = getenv( 'FAKE_STORAGE_URL' ) ?: 'http://127.0.0.1:8899';
$ss_fake_root = (string) getenv( 'FAKE_STORAGE_ROOT' );
if ( '' === $ss_fake_root || ! is_dir( $ss_fake_root ) ) {
	fwrite( STDERR, "FAKE_STORAGE_ROOT must point to the fake storage folder.\n" );
	exit( 1 );
}
if ( class_exists( 'Simple_Storage_Plugin' ) ) {
	fwrite( STDERR, "Deactivate Simple Storage before running the test: it uses the plugin's own table and options.\n" );
	exit( 1 );
}

$ss_port    = (int) ( getenv( 'FAKE_SITE_PORT' ) ?: 8898 );
$ss_site    = 'http://127.0.0.1:' . $ss_port;
$ss_tmp     = sys_get_temp_dir() . '/ss-it-' . wp_generate_password( 6, false, false );
$ss_uploads = $ss_tmp . '/site/wp-content/uploads';
$ss_baseurl = $ss_site . '/wp-content/uploads';
$ss_prefix  = 'it-' . strtolower( wp_generate_password( 6, false, false ) );
mkdir( $ss_uploads, 0777, true );

add_filter(
	'upload_dir',
	static function ( array $dir ) use ( $ss_uploads, $ss_baseurl ): array {
		$dir['basedir'] = $ss_uploads;
		$dir['baseurl'] = $ss_baseurl;
		$dir['path']    = $dir['basedir'] . $dir['subdir'];
		$dir['url']     = $dir['baseurl'] . $dir['subdir'];

		return $dir;
	},
	999
);

require dirname( __DIR__ ) . '/simple-storage.php';

// eval-file runs this file inside a method, so shared state lives in $GLOBALS explicitly.
$GLOBALS['ss_test'] = array(
	'failures'  => 0,
	'checks'    => 0,
	'fake_root' => $ss_fake_root,
	'prefix'    => $ss_prefix,
);

function ss_check( bool $condition, string $label, $detail = '' ): void {
	++$GLOBALS['ss_test']['checks'];
	if ( $condition ) {
		WP_CLI::log( '  ok    ' . $label );
	} else {
		++$GLOBALS['ss_test']['failures'];
		WP_CLI::log( '  FAIL  ' . $label . ( '' !== $detail ? ' — ' . ( is_string( $detail ) ? $detail : wp_json_encode( $detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) : '' ) );
	}
}

function ss_section( string $title ): void {
	WP_CLI::log( "\n== " . $title );
}

function ss_run_job( string $type ): array {
	$job = Simple_Storage_Jobs::start( $type );
	if ( is_wp_error( $job ) ) {
		return array(
			'status'  => 'start-error',
			'message' => $job->get_error_message(),
		);
	}
	for ( $i = 0; $i < 500; $i++ ) {
		$job = Simple_Storage_Jobs::step( 5.0 );
		if ( is_wp_error( $job ) ) {
			return array(
				'status'  => 'step-error',
				'message' => $job->get_error_message(),
			);
		}
		if ( 'running' !== $job['status'] ) {
			break;
		}
	}

	return $job;
}

function ss_failed( array $job ): int {
	$failed = 0;
	foreach ( (array) ( $job['progress'] ?? array() ) as $progress ) {
		$failed += (int) ( $progress['failed'] ?? 0 );
	}

	return $failed;
}

function ss_write( string $path, string $contents, ?int $mtime = null ): void {
	wp_mkdir_p( dirname( $path ) );
	file_put_contents( $path, $contents );
	touch( $path, $mtime ?? time() - 600 );
}

function ss_image( string $path, int $width, int $height, string $type ): void {
	wp_mkdir_p( dirname( $path ) );
	$image = imagecreatetruecolor( $width, $height );
	imagefilledrectangle( $image, 0, 0, $width, $height, imagecolorallocate( $image, random_int( 0, 255 ), random_int( 0, 255 ), random_int( 0, 255 ) ) );
	imagefilledrectangle( $image, 0, 0, (int) ( $width / 3 ), (int) ( $height / 3 ), imagecolorallocate( $image, 255, 255, 255 ) );
	'png' === $type ? imagepng( $image, $path ) : ( 'webp' === $type ? imagewebp( $image, $path ) : imagejpeg( $image, $path, 90 ) );
	touch( $path, time() - 600 );
}

function ss_remote( string $relative ): string {
	return $GLOBALS['ss_test']['fake_root'] . '/' . $GLOBALS['ss_test']['prefix'] . '/' . $relative;
}

/** Move settled files of a folder out the way the automatic offload does, after aging them. */
function ss_offload( string $dir, array $paths ): void {
	foreach ( $paths as $path ) {
		if ( is_file( Simple_Storage_Paths::local( $path ) ) ) {
			touch( Simple_Storage_Paths::local( $path ), time() - 600 );
		}
	}
	wp_unschedule_hook( Simple_Storage_Media::OFFLOAD_HOOK );
	Simple_Storage_Media::offload_dir( $dir );
	wp_unschedule_hook( Simple_Storage_Media::OFFLOAD_HOOK );
}

/** Whether a file lives only in the storage, intact in the index. */
function ss_remote_only( string $path ): bool {
	$row = Simple_Storage_Index::get( $path );

	return ! file_exists( Simple_Storage_Paths::local( $path ) ) && is_file( ss_remote( $path ) ) && null !== $row && 1 === $row['remote'] && 0 === $row['local'] && 0 === $row['conflict'];
}

/** Forget a local copy, as if the offload had removed it. */
function ss_drop_local( string $path ): void {
	@unlink( Simple_Storage_Paths::local( $path ) );
	$row = Simple_Storage_Index::get( $path );
	if ( null !== $row ) {
		Simple_Storage_Index::update( (int) $row['id'], array( 'local' => 0 ) );
	}
}

function ss_set_mode( string $mode ): void {
	$settings                  = Simple_Storage_Settings::get();
	$settings['delivery_mode'] = $mode;
	update_option( Simple_Storage_Settings::OPTION, $settings );
	Simple_Storage_Settings::flush_cache();
	Simple_Storage_Delivery::sync();
}

$ss_server = proc_open(
	array( getenv( 'FAKE_SITE_PHP' ) ?: PHP_BINARY, '-S', '127.0.0.1:' . $ss_port, '-t', $ss_tmp . '/site', __DIR__ . '/fake-site/router.php' ),
	array(
		0 => array( 'file', '/dev/null', 'r' ),
		1 => array( 'file', $ss_tmp . '/site.log', 'w' ),
		2 => array( 'file', $ss_tmp . '/site.log', 'a' ),
	),
	$ss_pipes
);
usleep( 800000 );

try {
	ss_section( 'Unit checks' );

	ss_check( Simple_Storage_Paths::is_media_path( '2024/05/photo.jpg' ), 'media path accepted' );
	ss_check( Simple_Storage_Paths::is_media_path( '2024/05/sub/Фото тест.png' ), 'nested unicode media path accepted' );
	foreach ( array( '2024/5/a.jpg', 'elementor/a.css', '2024/05/../x.jpg', '2024/05/.htaccess', '2024/05/a.jpg' . Simple_Storage_Paths::PART_SUFFIX, '2024/05/', '2024/05//a.jpg', "2024/05/a\0.jpg" ) as $ss_bad ) {
		ss_check( ! Simple_Storage_Paths::is_media_path( $ss_bad ), 'rejected: ' . addslashes( $ss_bad ) );
	}
	ss_check( 'https://abc.cdn.express' === Simple_Storage_Settings::sanitize_host( 'abc.cdn.express/' ), 'host normalized' );
	ss_check( 'https://abc.cdn.express' === Simple_Storage_Settings::sanitize_host( 'http://abc.cdn.express' ), 'plain http refused for public hosts' );
	ss_check( 'http://127.0.0.1:8899' === Simple_Storage_Settings::sanitize_host( 'http://127.0.0.1:8899' ), 'http allowed for loopback' );
	ss_check( 'site.example/media' === Simple_Storage_Settings::sanitize_prefix( '/Site.Example//media/' ), 'prefix sanitized' );
	ss_check( 'секрет' === Simple_Storage_Settings::decrypt( Simple_Storage_Settings::encrypt( 'секрет' ) ), 'password encryption round trip' );
	ss_check( '' === Simple_Storage_Settings::decrypt( 'ss1:broken' ), 'broken secret decrypts to empty' );

	$ss_listing = Simple_Storage_Client::parse_listing( '{"data":[{"path":"/a/b.jpg","size":3,"isDirectory":false,"uri":"/a/b.jpg"}]}' );
	ss_check( is_array( $ss_listing ) && '/a/b.jpg' === $ss_listing[0]['path'], 'listing with data envelope' );
	$ss_listing = Simple_Storage_Client::parse_listing( "{\"path\":\"/a/1.jpg\",\"size\":1}\n{\"path\":\"/a/2.jpg\",\"size\":2}\n" );
	ss_check( is_array( $ss_listing ) && 2 === count( $ss_listing ), 'listing in iterator format' );
	ss_check( '2024/05/Фото тест.png' === Simple_Storage_Proxy::relative_from_request( '/wp-content/uploads/2024/05/%D0%A4%D0%BE%D1%82%D0%BE%20%D1%82%D0%B5%D1%81%D1%82.png?x=1', '/wp-content/uploads' ), 'proxy maps a request to a media path' );
	ss_check( null === Simple_Storage_Proxy::relative_from_request( '/wp-content/uploads/2024/05/../../wp-config.php', '/wp-content/uploads' ), 'proxy refuses traversal' );
	ss_check( null === Simple_Storage_Proxy::relative_from_request( '/wp-content/plugins/simple-storage/proxy.php', '/wp-content/uploads' ), 'proxy refuses its own address' );

	ss_section( 'Setup' );

	Simple_Storage_Index::install();
	update_option(
		Simple_Storage_Settings::OPTION,
		Simple_Storage_Settings::sanitize(
			array(
				'host'          => $ss_fake_url,
				'login'         => 'test',
				'password'      => 'test',
				'prefix'        => $ss_prefix,
				'delivery_mode' => 'proxy',
				'strict_verify' => '1',
				'auto_offload'  => '1',
			)
		)
	);
	Simple_Storage_Settings::flush_cache();
	Simple_Storage_Paths::reset();
	Simple_Storage_Media::init();
	Simple_Storage_Timber::init();
	ss_check( Simple_Storage_Settings::is_configured(), 'connection configured' );
	ss_check( str_starts_with( (string) Simple_Storage_Settings::get()['password'], 'ss1:' ), 'password stored encrypted' );
	ss_check( '404' === (string) wp_remote_retrieve_response_code( wp_remote_get( $ss_baseurl . '/2024/05/nothing.jpg' ) ), 'fake site answers' );

	ss_image( $ss_uploads . '/2024/05/photo.jpg', 800, 600, 'jpg' );
	ss_image( $ss_uploads . '/2024/05/photo-300x225.jpg', 300, 225, 'jpg' );
	ss_image( $ss_uploads . '/2024/05/photo-150x150.jpg', 150, 150, 'jpg' );
	ss_image( $ss_uploads . '/2024/05/photo.webp', 800, 600, 'webp' );
	ss_image( $ss_uploads . '/2024/05/Фото тест.png', 64, 64, 'png' );
	ss_image( $ss_uploads . '/2024/05/знімок.jpg', 20, 20, 'jpg' );
	ss_write( $ss_uploads . '/2024/05/anim.gif', 'GIF89a' . random_bytes( 200 ) );
	ss_write( $ss_uploads . '/2024/05/anim-video.mp4', random_bytes( 2000 ) );
	ss_image( $ss_uploads . '/2024/05/anim-poster.jpg', 30, 30, 'jpg' );
	ss_image( $ss_uploads . '/2024/05/anim-thumb.jpg', 15, 15, 'jpg' );
	ss_write( $ss_uploads . '/2024/05/anim-source.heic', random_bytes( 1500 ) );
	ss_image( $ss_uploads . '/2024/05/edit-me.jpg', 400, 300, 'jpg' );
	ss_image( $ss_uploads . '/2024/05/edit-me-150x150.jpg', 150, 150, 'jpg' );
	ss_write( $ss_uploads . '/2024/05/doc.pdf', random_bytes( 300000 ) );
	ss_write( $ss_uploads . '/2024/05/sub/nested.txt', 'nested' );
	ss_write( $ss_uploads . '/2025/01/video.mp4', random_bytes( 3 * 1024 * 1024 ) );
	ss_write( $ss_uploads . '/2025/01/empty.txt', '' );
	ss_write( $ss_uploads . '/2024/05/.DS_Store', 'hidden' );
	ss_write( $ss_uploads . '/elementor/css/post-1.css', 'body{}' );
	ss_write( $ss_uploads . '/2024/xx/not-a-month.txt', 'x' );

	$ss_expected = array();
	foreach ( array( '2024/05/photo.jpg', '2024/05/photo-300x225.jpg', '2024/05/photo-150x150.jpg', '2024/05/photo.webp', '2024/05/Фото тест.png', '2024/05/знімок.jpg', '2024/05/anim.gif', '2024/05/anim-video.mp4', '2024/05/anim-poster.jpg', '2024/05/anim-thumb.jpg', '2024/05/anim-source.heic', '2024/05/edit-me.jpg', '2024/05/edit-me-150x150.jpg', '2024/05/doc.pdf', '2024/05/sub/nested.txt', '2025/01/video.mp4', '2025/01/empty.txt' ) as $ss_path ) {
		$ss_expected[ $ss_path ] = hash_file( 'sha256', $ss_uploads . '/' . $ss_path );
	}

	ss_section( 'Connection test' );
	$ss_report = Simple_Storage_Tester::run();
	ss_check( $ss_report['ok'], 'connection test passes', $ss_report['steps'] );
	ss_check( ! is_dir( $ss_fake_root . '/' . $ss_prefix . '/' . Simple_Storage_Tester::TEST_DIR ), 'test folder removed' );
	$ss_token = get_option( 'simple_storage_token' );
	ss_check( is_array( $ss_token ) && str_starts_with( (string) ( $ss_token['token'] ?? '' ), 'ss1:' ) && hash_hmac( 'sha256', $ss_fake_url . "\ntest\ntest", wp_salt( 'auth' ) ) === ( $ss_token['key'] ?? '' ), 'cached token encrypted and keyed with the salts, not a plain hash of the password' );

	ss_section( 'Index' );
	$ss_job   = ss_run_job( 'index' );
	$ss_stats = Simple_Storage_Index::stats();
	ss_check( 'done' === $ss_job['status'], 'index job finished', $ss_job['message'] ?? '' );
	ss_check( count( $ss_expected ) === $ss_stats['total']['files'], 'all media files indexed, nothing else', $ss_stats['total'] );
	ss_check( array_sum( array_map( static fn( $p ) => filesize( $ss_uploads . '/' . $p ), array_keys( $ss_expected ) ) ) === $ss_stats['total']['bytes'], 'total size matches' );
	ss_check( count( $ss_expected ) === $ss_stats['local_only']['files'], 'everything is local only' );

	ss_section( 'Busy lock' );
	ss_check( ! is_wp_error( Simple_Storage_Jobs::start( 'index' ) ), 'a job starts' );
	$ss_lock = Simple_Storage_Jobs::lock();
	$ss_busy = Simple_Storage_Jobs::step( 5.0 );
	ss_check( is_wp_error( $ss_busy ) && 'simple_storage_job_locked' === $ss_busy->get_error_code(), 'a step reports a held lock as busy' );
	Simple_Storage_Jobs::unlock( (string) $ss_lock );
	Simple_Storage_Jobs::cancel();
	ss_check( 'cancelled' === Simple_Storage_Jobs::current()['status'], 'job cancelled' );
	wp_unschedule_hook( Simple_Storage_Media::OFFLOAD_HOOK );

	ss_section( 'Delivery check refuses to delete when serving is broken' );
	$ss_not_found = array( 'headers' => array(), 'body' => 'Not Found', 'response' => array( 'code' => 404, 'message' => 'Not Found' ), 'cookies' => array(), 'filename' => null );
	$ss_break     = static fn( $pre, $args, $url ) => str_contains( $url, '/wp-content/uploads/0000/00/' ) ? $ss_not_found : $pre;
	add_filter( 'pre_http_request', $ss_break, 10, 3 );
	$ss_job = ss_run_job( 'push' );
	remove_filter( 'pre_http_request', $ss_break, 10 );
	ss_check( 'failed' === ( $ss_job['status'] ?? '' ) && 'check_delivery' === $ss_job['phases'][ $ss_job['phase'] ], 'push stops at the delivery check', array( $ss_job['status'] ?? '', $ss_job['message'] ?? '' ) );
	ss_check( count( $ss_expected ) === Simple_Storage_Index::totals( array( 'local' => 1 ) )['files'] && is_file( $ss_uploads . '/2024/05/photo.jpg' ), 'every local file kept' );
	ss_check( ! file_exists( $ss_fake_root . '/' . $ss_prefix . '/0000' ), 'delivery probe removed from the storage' );
	ss_check( 'local' === Simple_Storage_Settings::state()['mode'] && ! Simple_Storage_Delivery::delivery_verified() && ! Simple_Storage_Media::auto_enabled(), 'a failed check never switches the site to the remote mode' );
	Simple_Storage_Jobs::cancel();
	Simple_Storage_Media::offload_dir( '2024/05' );
	ss_check( count( $ss_expected ) === Simple_Storage_Index::totals( array( 'local' => 1 ) )['files'] && is_file( $ss_uploads . '/2024/05/photo.jpg' ), 'after the push is cancelled the automatic offload deletes nothing' );

	ss_section( 'Push' );
	Simple_Storage_Jobs::start( 'push' );
	add_filter( 'pre_http_request', $ss_break, 10, 3 );
	for ( $ss_i = 0; $ss_i < 200 && 'running' === ( Simple_Storage_Jobs::current()['status'] ?? '' ); $ss_i++ ) {
		Simple_Storage_Jobs::step( 5.0 );
	}
	remove_filter( 'pre_http_request', $ss_break, 10 );
	// Serving works for every extension but .pdf: those files must stay local.
	$ss_break_pdf = static fn( $pre, $args, $url ) => str_contains( $url, '/wp-content/uploads/0000/00/' ) && str_ends_with( (string) wp_parse_url( $url, PHP_URL_PATH ), '.pdf' ) ? $ss_not_found : $pre;
	add_filter( 'pre_http_request', $ss_break_pdf, 10, 3 );
	$ss_job = Simple_Storage_Jobs::resume();
	for ( $ss_i = 0; $ss_i < 500 && 'running' === ( $ss_job['status'] ?? '' ); $ss_i++ ) {
		$ss_job = Simple_Storage_Jobs::step( 5.0 );
	}
	remove_filter( 'pre_http_request', $ss_break_pdf, 10 );
	$ss_row = Simple_Storage_Index::get( '2024/05/doc.pdf' );
	ss_check( 'done' === $ss_job['status'] && 1 === ss_failed( $ss_job ), 'resumed push finished, only the .pdf file kept', array( $ss_job['status'], ss_failed( $ss_job ), $ss_job['message'] ?? '' ) );
	ss_check( is_file( $ss_uploads . '/2024/05/doc.pdf' ) && null !== $ss_row && 1 === $ss_row['local'] && ! empty( $ss_row['error'] ), 'a .pdf file stays local while serving .pdf is not verified', $ss_row );
	ss_check( in_array( 'jpg', Simple_Storage_Delivery::verified_extensions(), true ) && ! in_array( 'pdf', Simple_Storage_Delivery::verified_extensions(), true ), 'serving is verified per extension', Simple_Storage_Delivery::verified_extensions() );
	ss_check( (bool) preg_grep( '/\.pdf/', (array) $ss_job['warnings'] ), 'the push warns about the .pdf files', $ss_job['warnings'] );
	ss_check( ! is_file( $ss_uploads . '/2024/05/photo.jpg' ) && 'remote' === Simple_Storage_Settings::state()['mode'] && Simple_Storage_Media::auto_enabled(), 'other files moved, mode remote after a passed check' );
	$ss_job = ss_run_job( 'push' );
	$ss_stats = Simple_Storage_Index::stats();
	ss_check( 'done' === $ss_job['status'] && 0 === ss_failed( $ss_job ), 'the next push moves the .pdf file once serving it works', $ss_job['message'] ?? '' );
	ss_check( count( $ss_expected ) === $ss_stats['remote_only']['files'], 'everything is remote only', $ss_stats );
	foreach ( $ss_expected as $ss_path => $ss_hash ) {
		ss_check( ! file_exists( $ss_uploads . '/' . $ss_path ) && is_file( ss_remote( $ss_path ) ) && hash_file( 'sha256', ss_remote( $ss_path ) ) === $ss_hash, 'moved intact: ' . $ss_path );
	}
	ss_check( is_file( $ss_uploads . '/2024/05/.DS_Store' ) && is_file( $ss_uploads . '/elementor/css/post-1.css' ) && is_file( $ss_uploads . '/2024/xx/not-a-month.txt' ), 'files outside the scope stayed' );
	ss_check( Simple_Storage_Delivery::active() && 'remote' === Simple_Storage_Settings::state()['mode'], 'serving enabled, mode remote' );
	ss_check( 'ok' === Simple_Storage_Delivery::htaccess_status(), '.htaccess rules written' );
	$ss_htaccess = (string) file_get_contents( $ss_uploads . '/.htaccess' );
	ss_check( str_contains( $ss_htaccess, 'simple-storage/proxy.php [L]' ) && ! str_contains( $ss_htaccess, '[R=' ), 'proxy mode rewrites internally, no redirect' );
	$ss_config = Simple_Storage_Proxy::load_config();
	ss_check( is_array( $ss_config ) && 'proxy' === $ss_config['mode'] && $ss_prefix === $ss_config['prefix'] && $ss_uploads === $ss_config['uploads_dir'], 'proxy configuration written', $ss_config );
	ss_check( ! str_contains( (string) file_get_contents( Simple_Storage_Proxy::config_file() ), "'test'" ), 'proxy configuration holds no credentials' );
	$ss_response = wp_remote_get( $ss_baseurl . '/2024/05/photo.jpg' );
	ss_check( 200 === wp_remote_retrieve_response_code( $ss_response ) && hash( 'sha256', wp_remote_retrieve_body( $ss_response ) ) === $ss_expected['2024/05/photo.jpg'], 'remote-only file served at its uploads address' );
	$ss_url = $ss_baseurl . '/2024/05/photo.jpg';
	$ss_proxy_path = wp_parse_url( SIMPLE_STORAGE_URL . 'proxy.php', PHP_URL_PATH );
	ss_check(
		array(
			'<IfModule mod_rewrite.c>',
			'RewriteEngine On',
			'<IfModule mod_proxy_http.c>',
			'<IfModule mod_headers.c>',
			'RequestHeader unset Cookie',
			'RequestHeader unset Authorization',
			'RewriteCond %{REQUEST_FILENAME} !-f',
			'RewriteRule ^([0-9]{4}/[0-9]{2}/.+)$ ' . $ss_fake_url . '/' . $ss_prefix . '/$1 [P,L]',
			'</IfModule>',
			'</IfModule>',
			'RewriteCond %{REQUEST_FILENAME} !-f',
			'RewriteRule ^[0-9]{4}/[0-9]{2}/.+$ ' . $ss_proxy_path . ' [L]',
			'</IfModule>',
		) === Simple_Storage_Delivery::htaccess_rules( 'apache-https' ),
		'Apache [P] rules have the shape tests/apache-proxy.sh verifies'
	);
	$ss_probe = Simple_Storage_Delivery::probe_proxy();
	ss_check( 'php' === $ss_probe['engine'] && ! empty( $ss_probe['tried'] ), 'probe without an Apache proxy falls back to proxy.php', $ss_probe );
	ss_check( 'php' === Simple_Storage_Delivery::proxy_engine() && ! str_contains( (string) file_get_contents( $ss_uploads . '/.htaccess' ), '[P' ), 'rules written for the PHP engine' );
	ss_check( ! file_exists( $ss_fake_root . '/' . $ss_prefix . '/0000' ), 'probe file removed from the storage' );
	$ss_report = Simple_Storage_Delivery::verify_delivery( array( 'jpg', 'PNG', 'pdf', 'jpg' ) );
	ss_check( ! is_wp_error( $ss_report ) && array( 'jpg', 'png', 'pdf' ) === $ss_report['ok'] && empty( $ss_report['failed'] ), 'delivery check passes in proxy mode, once per extension', $ss_report );

	ss_section( 'Direct mode' );
	ss_set_mode( 'direct' );
	$ss_public = $ss_fake_url . '/' . $ss_prefix . '/';
	ss_check( ! Simple_Storage_Delivery::delivery_verified() && ! Simple_Storage_Media::auto_enabled(), 'a mode switch makes the earlier verification stale' );
	ss_check( str_contains( (string) file_get_contents( $ss_uploads . '/.htaccess' ), 'RewriteRule ^([0-9]{4}/[0-9]{2}/.+)$ ' . $ss_public . '$1 [R=302,L]' ), 'direct mode redirects with 302' );
	$ss_report = Simple_Storage_Delivery::verify_delivery( array( 'jpg', 'txt' ) );
	ss_check( ! is_wp_error( $ss_report ) && array( 'jpg', 'txt' ) === $ss_report['ok'], 'delivery check follows the redirect in direct mode', $ss_report );
	$ss_break_target = static fn( $pre, $args, $url ) => str_contains( $url, '/' . $ss_prefix . '/0000/00/' ) ? $ss_not_found : $pre;
	add_filter( 'pre_http_request', $ss_break_target, 10, 3 );
	$ss_report = Simple_Storage_Delivery::verify_delivery( array( 'txt' ) );
	remove_filter( 'pre_http_request', $ss_break_target, 10 );
	ss_check( ! is_wp_error( $ss_report ) && empty( $ss_report['ok'] ) && isset( $ss_report['failed']['txt'] ) && ! in_array( 'txt', Simple_Storage_Delivery::verified_extensions(), true ), 'a redirect to an address that does not serve the file fails the check', $ss_report );
	ss_check( $ss_public . '2024/05/photo.jpg?ver=2' === Simple_Storage_Delivery::url_for( $ss_url . '?ver=2' ), 'URL of a remote file points to the storage' );
	ss_image( $ss_uploads . '/2024/05/local-only.jpg', 10, 10, 'jpg' );
	ss_image( $ss_uploads . '/2024/05/фото-local.jpg', 10, 10, 'jpg' );
	ss_write( $ss_uploads . '/2024/05/a&b.jpg', 'x' );
	$ss_host   = 'http://127.0.0.1:' . $ss_port . '/wp-content/uploads';
	$ss_jhost  = str_replace( '/', '\\/', $ss_host );
	$ss_html   = '<img src="' . $ss_host . '/2024/05/photo.jpg?ver=1" srcset="' . $ss_host . '/2024/05/photo-300x225.jpg 300w, //127.0.0.1:' . $ss_port . '/wp-content/uploads/2024/05/photo.jpg 800w">'
		. '<script>{"u":"' . $ss_jhost . '\\/2024\\/05\\/photo.webp","l":"' . $ss_jhost . '\\/2024\\/05\\/\\u0444\\u043e\\u0442\\u043e-local.jpg"}</script>'
		. '<a href="' . $ss_host . '/2024/05/%D0%A4%D0%BE%D1%82%D0%BE%20%D1%82%D0%B5%D1%81%D1%82.png">x</a>'
		. '<img src="' . $ss_host . '/2024/05/local-only.jpg"><link href="' . $ss_host . '/elementor/css/post-1.css">'
		. '<img src="' . $ss_host . '/2024/05/a&amp;b.jpg">'
		. '<div data-settings="{&quot;url&quot;:&quot;' . $ss_jhost . '\\/2024\\/05\\/photo.jpg&quot;}"></div>'
		. '<textarea name="content">' . $ss_host . '/2024/05/photo.jpg</textarea>'
		. '<script>var d={"n":"' . $ss_host . '/2024/05/\\u0437\\u043d\\u0456\\u043c\\u043e\\u043a.jpg","m":"' . $ss_host . '/2024/05/\\u0444\\u043e\\u0442\\u043e-local.jpg"};</script>';
	$ss_result         = Simple_Storage_Delivery::rewrite_html( $ss_html );
	$ss_escaped_public = str_replace( '/', '\\/', $ss_public );
	ss_check( str_contains( $ss_result, 'src="' . $ss_public . '2024/05/photo.jpg?ver=1"' ), 'src rewritten, query kept' );
	ss_check( str_contains( $ss_result, 'srcset="' . $ss_public . '2024/05/photo-300x225.jpg 300w, ' . $ss_public . '2024/05/photo.jpg 800w"' ), 'srcset rewritten, protocol-relative too' );
	ss_check( str_contains( $ss_result, '"u":"' . $ss_escaped_public . '2024\\/05\\/photo.webp"' ), 'JSON-escaped URL rewritten' );
	ss_check( str_contains( $ss_result, '"l":"' . $ss_jhost . '\\/2024\\/05\\/\\u0444\\u043e\\u0442\\u043e-local.jpg"' ), 'JSON \\u-escaped name of a local file is not rewritten' );
	ss_check( str_contains( $ss_result, 'href="' . $ss_public . '2024/05/%D0%A4%D0%BE%D1%82%D0%BE%20%D1%82%D0%B5%D1%81%D1%82.png"' ), 'percent-encoded unicode URL rewritten' );
	ss_check( str_contains( $ss_result, $ss_host . '/2024/05/local-only.jpg' ), 'local file is not rewritten' );
	ss_check( str_contains( $ss_result, $ss_host . '/elementor/css/post-1.css' ), 'non-media file is not rewritten' );
	ss_check( str_contains( $ss_result, $ss_host . '/2024/05/a&amp;b.jpg' ), 'a name cut by an entity is left alone' );
	ss_check( str_contains( $ss_result, '&quot;url&quot;:&quot;' . $ss_escaped_public . '2024\\/05\\/photo.jpg&quot;' ), 'URL inside an entity-encoded attribute rewritten' );
	ss_check( str_contains( $ss_result, '<textarea name="content">' . $ss_host . '/2024/05/photo.jpg</textarea>' ), 'textarea contents are never rewritten' );
	ss_check( str_contains( $ss_result, '"n":"' . $ss_public . '2024/05/\\u0437\\u043d\\u0456\\u043c\\u043e\\u043a.jpg"' ), 'JSON with plain slashes and a \\u-escaped name rewritten' );
	ss_check( str_contains( $ss_result, '"m":"' . $ss_host . '/2024/05/\\u0444\\u043e\\u0442\\u043e-local.jpg"' ), 'such JSON naming a local file is left alone' );
	foreach ( array( 'local-only.jpg', 'фото-local.jpg', 'a&b.jpg' ) as $ss_name ) {
		unlink( $ss_uploads . '/2024/05/' . $ss_name );
	}
	ss_set_mode( 'proxy' );
	ss_check( str_contains( (string) file_get_contents( $ss_uploads . '/.htaccess' ), 'proxy.php [L]' ) && 'proxy' === Simple_Storage_Proxy::load_config()['mode'], 'switched back to proxy mode' );
	ss_check( ! Simple_Storage_Media::auto_enabled(), 'the direct-mode verification does not count for the proxy mode' );
	Simple_Storage_Delivery::verify_delivery( array( 'jpg' ) );
	ss_check( Simple_Storage_Media::auto_enabled(), 'a new verification turns the automatic offload back on' );

	ss_section( 'Unique file names' );
	$ss_dir = $ss_uploads . '/2024/05';
	ss_check( 'photo-1.jpg' === Simple_Storage_Media::unique_filename( 'photo.jpg', '.jpg', $ss_dir ), 'name of a remote-only file is not reused' );
	ss_check( 'Photo-1.JPG' === Simple_Storage_Media::unique_filename( 'Photo.JPG', '.JPG', $ss_dir ), 'comparison ignores case' );
	ss_check( 'fresh.jpg' === Simple_Storage_Media::unique_filename( 'fresh.jpg', '.jpg', $ss_dir ), 'free name kept' );
	ss_check( 'doc-1.pdf' === Simple_Storage_Media::unique_filename( 'doc.pdf', '.pdf', $ss_dir ), 'non-image name protected too' );
	$ss_row = Simple_Storage_Index::touch_local( '2024/05/shade-jpg.webp', 10, 1 );
	Simple_Storage_Index::update( (int) $ss_row['id'], array( 'local' => 0, 'remote' => 1 ) );
	ss_check( 'shade-1.jpg' === Simple_Storage_Media::unique_filename( 'shade.jpg', '.jpg', $ss_dir ) && 'shade-new.jpg' === Simple_Storage_Media::unique_filename( 'shade-new.jpg', '.jpg', $ss_dir ), 'the name of an image whose WebP copy is in the storage is not reused' );
	Simple_Storage_Index::delete( (int) $ss_row['id'] );

	ss_section( 'Image editing' );
	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/image-edit.php';
	$ss_admins = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		)
	);
	wp_set_current_user( (int) ( $ss_admins[0] ?? 0 ) );
	$ss_edit = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/jpeg',
			'post_title'     => 'Simple Storage edit test',
			'post_status'    => 'inherit',
		),
		'2024/05/edit-me.jpg'
	);
	update_post_meta( $ss_edit, '_wp_attached_file', '2024/05/edit-me.jpg' );
	update_post_meta(
		$ss_edit,
		'_wp_attachment_metadata',
		array(
			'width'    => 400,
			'height'   => 300,
			'filesize' => 20000,
			'file'     => '2024/05/edit-me.jpg',
			'sizes'  => array(
				'thumbnail' => array( 'file' => 'edit-me-150x150.jpg', 'width' => 150, 'height' => 150, 'mime-type' => 'image/jpeg' ),
			),
		)
	);
	ss_check( ! file_exists( $ss_uploads . '/2024/05/edit-me.jpg' ), 'image to edit lives only in the storage' );
	$ss_edit_path = _load_image_to_edit_path( $ss_edit );
	ss_check( $ss_uploads . '/2024/05/edit-me.jpg' === $ss_edit_path && hash_file( 'sha256', $ss_edit_path ) === $ss_expected['2024/05/edit-me.jpg'], 'the editor gets the original back from the storage, verified' );
	ss_check( false !== wp_next_scheduled( Simple_Storage_Media::OFFLOAD_HOOK, array( '2024/05' ) ), 'its folder is scheduled to go out again' );
	wp_unschedule_hook( Simple_Storage_Media::OFFLOAD_HOOK );
	$ss_row = Simple_Storage_Index::get( '2024/05/edit-me.jpg' );
	ss_check( null !== $ss_row && 1 === $ss_row['local'] && 1 === $ss_row['remote'], 'restored file is indexed as local and remote' );
	unlink( $ss_edit_path );
	Simple_Storage_Index::update( (int) $ss_row['id'], array( 'local' => 0 ) );
	$ss_request = new WP_REST_Request( 'POST', '/wp/v2/media/' . $ss_edit . '/edit' );
	$ss_request->set_param( 'src', wp_get_attachment_url( $ss_edit ) );
	$ss_request->set_param( 'rotation', 90 );
	$ss_response = rest_do_request( $ss_request );
	$ss_new      = (int) ( $ss_response->get_data()['id'] ?? 0 );
	ss_check( 201 === $ss_response->get_status() && $ss_new > 0, 'block editor rotation works on an image that lived only in the storage', $ss_response->get_data() );
	$ss_new_file = $ss_new ? (string) get_attached_file( $ss_new ) : '';
	ss_check( '' !== $ss_new_file && is_file( $ss_new_file ), 'the edited copy is a new local file, ready for the automatic offload', $ss_new_file );
	if ( $ss_new ) {
		wp_delete_attachment( $ss_new, true );
	}

	// The classic image editor: rotate and save, then restore the original.
	foreach ( array( '2024/05/edit-me.jpg' ) as $ss_path ) {
		if ( is_file( $ss_uploads . '/' . $ss_path ) ) {
			unlink( $ss_uploads . '/' . $ss_path );
		}
		$ss_row = Simple_Storage_Index::get( $ss_path );
		Simple_Storage_Index::update( (int) $ss_row['id'], array( 'local' => 0 ) );
	}
	$_REQUEST['history'] = wp_slash( '[{"r":90}]' );
	$_REQUEST['target']  = 'all';
	$_REQUEST['context'] = '';
	$_REQUEST['do']      = 'save';
	$ss_saved            = wp_save_image( $ss_edit );
	$ss_edited_file      = (string) get_post_meta( $ss_edit, '_wp_attached_file', true );
	ss_check( empty( $ss_saved->error ) && preg_match( '#^2024/05/edit-me-e[0-9]{13}\.jpg$#', $ss_edited_file ) && is_file( $ss_uploads . '/' . $ss_edited_file ), 'classic editor saves an edit of an image that lived only in the storage', array( $ss_saved, $ss_edited_file ) );
	$ss_restored = wp_restore_image( $ss_edit );
	ss_check( empty( $ss_restored->error ) && '2024/05/edit-me.jpg' === get_post_meta( $ss_edit, '_wp_attached_file', true ), '"Restore original image" switches back to the original', $ss_restored );
	unset( $_REQUEST['history'], $_REQUEST['target'], $_REQUEST['context'], $_REQUEST['do'] );
	foreach ( glob( $ss_uploads . '/2024/05/edit-me-e*' ) as $ss_file ) {
		unlink( $ss_file );
	}
	delete_post_meta( $ss_edit, '_wp_attachment_backup_sizes' );
	wp_set_current_user( 0 );

	ss_section( 'Automatic offload' );
	ss_check( Simple_Storage_Media::auto_enabled(), 'automatic offload is on after the push' );
	ss_image( $ss_uploads . '/2025/01/new-upload.jpg', 40, 40, 'jpg' );
	$ss_new_hash = hash_file( 'sha256', $ss_uploads . '/2025/01/new-upload.jpg' );
	ss_write( $ss_uploads . '/2025/01/still-writing.jpg', 'x', time() );
	Simple_Storage_Jobs::start( 'index' );
	Simple_Storage_Media::offload_dir( '2025/01' );
	ss_check( is_file( $ss_uploads . '/2025/01/new-upload.jpg' ), 'automatic offload stays away while a job exists' );
	Simple_Storage_Jobs::cancel();
	wp_unschedule_hook( Simple_Storage_Media::OFFLOAD_HOOK );
	ss_write( $ss_uploads . '/2025/01/theme-package.zip', 'PK' . random_bytes( 300 ) );
	$ss_package = wp_insert_attachment(
		array(
			'post_mime_type' => 'application/zip',
			'post_title'     => 'theme-package.zip',
			'post_status'    => 'private',
			'context'        => 'upgrader',
		),
		'2025/01/theme-package.zip'
	);
	update_post_meta( $ss_package, '_wp_attached_file', '2025/01/theme-package.zip' );
	Simple_Storage_Media::schedule_for_attachment( $ss_package );
	ss_check( false === wp_next_scheduled( Simple_Storage_Media::OFFLOAD_HOOK, array( '2025/01' ) ), 'an uploaded theme or plugin package schedules nothing' );
	Simple_Storage_Media::offload_dir( '2025/01' );
	ss_check( ! file_exists( $ss_uploads . '/2025/01/new-upload.jpg' ) && hash_file( 'sha256', ss_remote( '2025/01/new-upload.jpg' ) ) === $ss_new_hash, 'settled new file moved' );
	ss_check( is_file( $ss_uploads . '/2025/01/theme-package.zip' ) && ! file_exists( ss_remote( '2025/01/theme-package.zip' ) ), 'the package stays local for the upgrader' );
	wp_delete_attachment( $ss_package, true );
	ss_check( ! file_exists( $ss_uploads . '/2025/01/theme-package.zip' ), 'the package is deleted as usual' );
	ss_check( is_file( $ss_uploads . '/2025/01/still-writing.jpg' ) && ! file_exists( ss_remote( '2025/01/still-writing.jpg' ) ), 'fresh file left for later' );
	ss_check( false !== wp_next_scheduled( Simple_Storage_Media::OFFLOAD_HOOK, array( '2025/01' ) ), 'another run scheduled for the fresh file' );
	unlink( $ss_uploads . '/2025/01/still-writing.jpg' );
	Simple_Storage_Media::offload_dir( '2024/05' );
	ss_check( ! file_exists( $ss_uploads . '/2024/05/edit-me.jpg' ), 'a file brought back for editing goes out again' );
	wp_unschedule_hook( Simple_Storage_Media::OFFLOAD_HOOK );
	$ss_expected['2025/01/new-upload.jpg'] = $ss_new_hash;

	ss_section( 'Remote copy re-checked before a local delete' );
	ss_write( $ss_uploads . '/2024/05/stale.bin', random_bytes( 2000 ) );
	$ss_row = Simple_Storage_Index::touch_local( '2024/05/stale.bin', 2000, (int) filemtime( $ss_uploads . '/2024/05/stale.bin' ) );
	Simple_Storage_Index::update(
		(int) $ss_row['id'],
		array(
			'remote'   => 1,
			'verified' => 1,
		)
	);
	$ss_client  = Simple_Storage_Client::create();
	$ss_removed = Simple_Storage_Transfer::remove_local( $ss_client, Simple_Storage_Index::get( '2024/05/stale.bin' ) );
	$ss_row     = Simple_Storage_Index::get( '2024/05/stale.bin' );
	ss_check( is_wp_error( $ss_removed ) && is_file( $ss_uploads . '/2024/05/stale.bin' ), 'a stale "verified" flag does not delete a file the storage lacks' );
	ss_check( 0 === $ss_row['remote'] && 0 === $ss_row['verified'], 'the row is queued for another upload' );
	$ss_expected['2024/05/stale.bin'] = hash_file( 'sha256', $ss_uploads . '/2024/05/stale.bin' );

	ss_section( 'Attachment deletion' );
	$ss_attachment = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/jpeg',
			'post_title'     => 'Simple Storage test',
			'post_status'    => 'inherit',
		),
		'2024/05/photo.jpg'
	);
	update_post_meta( $ss_attachment, '_wp_attached_file', '2024/05/photo.jpg' );
	update_post_meta(
		$ss_attachment,
		'_wp_attachment_metadata',
		array(
			'width'  => 800,
			'height' => 600,
			'file'   => '2024/05/photo.jpg',
			'sizes'  => array(
				'medium'    => array( 'file' => 'photo-300x225.jpg', 'width' => 300, 'height' => 225, 'mime-type' => 'image/jpeg' ),
				'thumbnail' => array( 'file' => 'photo-150x150.jpg', 'width' => 150, 'height' => 150, 'mime-type' => 'image/jpeg' ),
			),
		)
	);
	ss_check( array( '2024/05/photo.jpg', '2024/05/photo-300x225.jpg', '2024/05/photo-150x150.jpg' ) === Simple_Storage_Media::attachment_files( $ss_attachment ), 'attachment files resolved' );
	wp_delete_attachment( $ss_attachment, true );
	foreach ( array( '2024/05/photo.jpg', '2024/05/photo-300x225.jpg', '2024/05/photo-150x150.jpg' ) as $ss_path ) {
		ss_check( ! file_exists( ss_remote( $ss_path ) ) && null === Simple_Storage_Index::get( $ss_path ), 'deleted from the storage and the index: ' . $ss_path );
		unset( $ss_expected[ $ss_path ] );
	}
	ss_check( is_file( ss_remote( '2024/05/photo.webp' ) ), 'a file WordPress does not know stays' );

	$ss_anim = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/gif',
			'post_title'     => 'Simple Storage companions',
			'post_status'    => 'inherit',
		),
		'2024/05/anim.gif'
	);
	update_post_meta( $ss_anim, '_wp_attached_file', '2024/05/anim.gif' );
	update_post_meta(
		$ss_anim,
		'_wp_attachment_metadata',
		array(
			'width'                 => 30,
			'height'                => 30,
			'file'                  => '2024/05/anim.gif',
			'sizes'                 => array(),
			'source_image'          => 'anim-source.heic',
			'animated_video'        => 'anim-video.mp4',
			'animated_video_poster' => 'anim-poster.jpg',
			'thumb'                 => 'anim-thumb.jpg',
		)
	);
	$ss_companions = array( '2024/05/anim.gif', '2024/05/anim-source.heic', '2024/05/anim-video.mp4', '2024/05/anim-poster.jpg', '2024/05/anim-thumb.jpg' );
	ss_check( $ss_companions === Simple_Storage_Media::attachment_files( $ss_anim ), 'companion files resolved', Simple_Storage_Media::attachment_files( $ss_anim ) );
	wp_delete_attachment( $ss_anim, true );
	foreach ( $ss_companions as $ss_path ) {
		ss_check( ! file_exists( ss_remote( $ss_path ) ) && null === Simple_Storage_Index::get( $ss_path ), 'companion deleted from the storage: ' . $ss_path );
		unset( $ss_expected[ $ss_path ] );
	}

	$ss_veto = static fn( $file ) => is_string( $file ) && str_ends_with( $file, '/2024/05/photo.webp' ) ? '' : $file;
	add_filter( 'wp_delete_file', $ss_veto );
	$ss_webp = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/webp',
			'post_title'     => 'Simple Storage veto',
			'post_status'    => 'inherit',
		),
		'2024/05/photo.webp'
	);
	update_post_meta( $ss_webp, '_wp_attached_file', '2024/05/photo.webp' );
	wp_delete_attachment( $ss_webp, true );
	remove_filter( 'wp_delete_file', $ss_veto );
	ss_check( is_file( ss_remote( '2024/05/photo.webp' ) ) && null !== Simple_Storage_Index::get( '2024/05/photo.webp' ), 'a wp_delete_file filter that keeps the file keeps the remote copy too' );

	$ss_shared = array();
	foreach ( array( 'en', 'uk' ) as $ss_lang ) {
		$ss_shared[ $ss_lang ] = wp_insert_attachment(
			array(
				'post_mime_type' => 'video/mp4',
				'post_title'     => 'Simple Storage shared ' . $ss_lang,
				'post_status'    => 'inherit',
			),
			'2025/01/video.mp4'
		);
		update_post_meta( $ss_shared[ $ss_lang ], '_wp_attached_file', '2025/01/video.mp4' );
	}
	wp_delete_attachment( $ss_shared['en'], true );
	ss_check( is_file( ss_remote( '2025/01/video.mp4' ) ) && null !== Simple_Storage_Index::get( '2025/01/video.mp4' ), 'a file another attachment still uses stays in the storage' );
	wp_delete_attachment( $ss_shared['uk'], true );
	ss_check( ! file_exists( ss_remote( '2025/01/video.mp4' ) ) && null === Simple_Storage_Index::get( '2025/01/video.mp4' ), 'deleting the last attachment that uses it deletes it' );
	unset( $ss_expected['2025/01/video.mp4'] );
	wp_delete_attachment( $ss_edit, true );
	foreach ( array( '2024/05/edit-me.jpg', '2024/05/edit-me-150x150.jpg' ) as $ss_path ) {
		unset( $ss_expected[ $ss_path ] );
	}

	ss_section( 'Thumbnail regeneration' );
	wp_set_current_user( (int) ( $ss_admins[0] ?? 0 ) );
	ss_image( $ss_uploads . '/2024/05/regen.jpg', 640, 480, 'jpg' );
	$ss_regen = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/jpeg',
			'post_title'     => 'Simple Storage regeneration',
			'post_status'    => 'inherit',
		),
		$ss_uploads . '/2024/05/regen.jpg'
	);
	$ss_meta = wp_generate_attachment_metadata( $ss_regen, $ss_uploads . '/2024/05/regen.jpg' );
	ss_image( $ss_uploads . '/2024/05/regen-100x75.jpg', 100, 75, 'jpg' );
	$ss_meta['sizes']['legacy'] = array( 'file' => 'regen-100x75.jpg', 'width' => 100, 'height' => 75, 'mime-type' => 'image/jpeg' );
	wp_update_attachment_metadata( $ss_regen, $ss_meta );
	$ss_regen_files = Simple_Storage_Media::attachment_files( $ss_regen );
	$ss_thumb       = '2024/05/' . $ss_meta['sizes']['thumbnail']['file'];
	ss_offload( '2024/05', $ss_regen_files );
	ss_check( count( $ss_regen_files ) >= 3 && count( $ss_regen_files ) === count( array_filter( $ss_regen_files, 'ss_remote_only' ) ), 'the attachment lives only in the storage', $ss_regen_files );
	$ss_old_thumb = hash_file( 'sha256', ss_remote( $ss_thumb ) );

	// What `wp media regenerate` does, with a different JPEG quality so the new sizes differ.
	$ss_quality = static fn() => 35;
	add_filter( 'wp_editor_set_quality', $ss_quality );
	Simple_Storage_Media::start_regeneration();
	$ss_path = wp_get_original_image_path( $ss_regen );
	ss_check( is_string( $ss_path ) && is_file( $ss_path ), '`wp media regenerate` finds the original, brought back from the storage' );
	wp_update_attachment_metadata( $ss_regen, wp_generate_attachment_metadata( $ss_regen, (string) $ss_path ) );
	Simple_Storage_Media::finish_regeneration();
	remove_filter( 'wp_editor_set_quality', $ss_quality );
	$ss_row       = Simple_Storage_Index::get( $ss_thumb );
	$ss_new_thumb = is_file( $ss_uploads . '/' . $ss_thumb ) ? hash_file( 'sha256', $ss_uploads . '/' . $ss_thumb ) : '';
	ss_check( null !== $ss_row && 1 === $ss_row['local'] && 0 === $ss_row['remote'] && 0 === $ss_row['conflict'] && '' !== $ss_new_thumb && $ss_new_thumb !== $ss_old_thumb, 'a regenerated size takes over its name instead of becoming a conflict', $ss_row );
	ss_check( ! file_exists( ss_remote( '2024/05/regen-100x75.jpg' ) ) && null === Simple_Storage_Index::get( '2024/05/regen-100x75.jpg' ), 'a size that is no longer generated is deleted from the storage too' );
	ss_check( false !== wp_next_scheduled( Simple_Storage_Media::OFFLOAD_HOOK, array( '2024/05' ) ), 'the folder is scheduled to go out' );
	$ss_regen_files = Simple_Storage_Media::attachment_files( $ss_regen );
	ss_offload( '2024/05', $ss_regen_files );
	ss_check( ss_remote_only( $ss_thumb ) && hash_file( 'sha256', ss_remote( $ss_thumb ) ) === $ss_new_thumb, 'the regenerated size replaced the old one in the storage' );
	ss_check( count( $ss_regen_files ) === count( array_filter( $ss_regen_files, 'ss_remote_only' ) ), 'the whole attachment lives only in the storage again' );

	$_REQUEST['id'] = (string) $ss_regen;
	Simple_Storage_Media::before_regenerate_ajax();
	unset( $_REQUEST['id'] );
	ss_check( is_file( $ss_uploads . '/2024/05/regen.jpg' ), 'Regenerate Thumbnails 2.x gets the original back first' );
	ss_drop_local( '2024/05/regen.jpg' );
	Simple_Storage_Media::before_rest_edit( null, null, new WP_REST_Request( 'POST', '/regenerate-thumbnails/v1/regenerate/' . $ss_regen ) );
	ss_check( is_file( $ss_uploads . '/2024/05/regen.jpg' ), 'Regenerate Thumbnails 3.x too' );
	ss_drop_local( '2024/05/regen.jpg' );

	ss_check( simple_storage_file_exists( $ss_baseurl . '/' . $ss_thumb ) && simple_storage_file_exists( $ss_uploads . '/' . $ss_thumb ) && simple_storage_file_exists( $ss_thumb ), 'simple_storage_file_exists() knows a file in the storage by URL, path and media path' );
	ss_check( ! simple_storage_file_exists( $ss_baseurl . '/2024/05/nothing.jpg' ) && ! simple_storage_file_exists( '' ), 'and a missing one' );
	ss_check( simple_storage_ensure_local( $ss_regen ) && is_file( $ss_uploads . '/2024/05/regen.jpg' ), 'simple_storage_ensure_local() brings an attachment back' );
	ss_drop_local( '2024/05/regen.jpg' );
	ss_check( simple_storage_ensure_local( $ss_baseurl . '/2024/05/regen.jpg' ) && is_file( $ss_uploads . '/2024/05/regen.jpg' ), 'and a file by its URL' );
	wp_delete_attachment( $ss_regen, true );
	ss_check( ! array_filter( $ss_regen_files, static fn( $p ) => file_exists( ss_remote( $p ) ) || null !== Simple_Storage_Index::get( $p ) ), 'deleting the regenerated attachment deletes its files in the storage' );
	wp_unschedule_hook( Simple_Storage_Media::OFFLOAD_HOOK );

	$ss_new_image = static function ( string $name, int $width, int $height ) use ( $ss_uploads ): int {
		ss_image( $ss_uploads . '/2024/05/' . $name, $width, $height, str_ends_with( $name, '.png' ) ? 'png' : 'jpg' );
		$id = wp_insert_attachment(
			array(
				'post_mime_type' => str_ends_with( $name, '.png' ) ? 'image/png' : 'image/jpeg',
				'post_title'     => 'Simple Storage ' . $name,
				'post_status'    => 'inherit',
			),
			$ss_uploads . '/2024/05/' . $name
		);
		update_post_meta( $id, '_wp_attached_file', '2024/05/' . $name );

		return (int) $id;
	};

	// A scaled image regenerated after the threshold went up: the new metadata has no
	// original_image, so the full-size original is no longer among the attachment's files.
	$ss_threshold = static fn() => 1000;
	add_filter( 'big_image_size_threshold', $ss_threshold );
	$ss_big = $ss_new_image( 'big.jpg', 1200, 900 );
	wp_update_attachment_metadata( $ss_big, wp_generate_attachment_metadata( $ss_big, $ss_uploads . '/2024/05/big.jpg' ) );
	remove_filter( 'big_image_size_threshold', $ss_threshold );
	$ss_big_files = Simple_Storage_Media::attachment_files( $ss_big );
	ss_offload( '2024/05', $ss_big_files );
	ss_check( in_array( '2024/05/big-scaled.jpg', $ss_big_files, true ) && ss_remote_only( '2024/05/big.jpg' ), 'a scaled image and its original live only in the storage', $ss_big_files );
	$ss_big_hash = hash_file( 'sha256', ss_remote( '2024/05/big.jpg' ) );
	Simple_Storage_Media::start_regeneration();
	wp_update_attachment_metadata( $ss_big, wp_generate_attachment_metadata( $ss_big, (string) wp_get_original_image_path( $ss_big ) ) );
	ss_offload( '2024/05', array( '2024/05/big.jpg' ) );
	ss_check( is_file( $ss_uploads . '/2024/05/big.jpg' ), 'the automatic offload waits while a regeneration runs' );
	$ss_row = Simple_Storage_Index::get( '2024/05/' . wp_get_attachment_metadata( $ss_big )['sizes']['thumbnail']['file'] );
	ss_check( null !== $ss_row && 1 === $ss_row['local'] && 0 === $ss_row['remote'] && 0 === $ss_row['conflict'], 'sizes regenerated without original_image are still taken over', $ss_row );
	// Even if the original went out meanwhile (a stale pause), it is not an old size.
	delete_option( Simple_Storage_Media::REGENERATING_OPTION );
	ss_offload( '2024/05', array( '2024/05/big.jpg' ) );
	ss_check( ss_remote_only( '2024/05/big.jpg' ), 'the original went out during the run' );
	Simple_Storage_Media::finish_regeneration();
	ss_check( is_file( ss_remote( '2024/05/big.jpg' ) ) && hash_file( 'sha256', ss_remote( '2024/05/big.jpg' ) ) === $ss_big_hash, 'the original that dropped out of the metadata is never deleted from the storage' );
	ss_check( false !== wp_next_scheduled( Simple_Storage_Media::OFFLOAD_HOOK, array( '2024/05' ) ), 'the folder goes out after the run' );
	wp_delete_attachment( $ss_big, true );
	foreach ( array( '2024/05/big.jpg', '2024/05/big-scaled.jpg' ) as $ss_path ) {
		@unlink( $ss_uploads . '/' . $ss_path );
		@unlink( ss_remote( $ss_path ) );
		Simple_Storage_Index::delete_path( $ss_path );
	}
	wp_unschedule_hook( Simple_Storage_Media::OFFLOAD_HOOK );

	// A file in the storage that no attachment records, under a name a new size of this one takes.
	$ss_hero = $ss_new_image( 'hero.jpg', 1000, 600 );
	wp_update_attachment_metadata( $ss_hero, wp_generate_attachment_metadata( $ss_hero, $ss_uploads . '/2024/05/hero.jpg' ) );
	ss_image( $ss_uploads . '/2024/05/hero-500x300.jpg', 500, 300, 'jpg' );
	ss_offload( '2024/05', array_merge( Simple_Storage_Media::attachment_files( $ss_hero ), array( '2024/05/hero-500x300.jpg' ) ) );
	$ss_other_hash = hash_file( 'sha256', ss_remote( '2024/05/hero-500x300.jpg' ) );
	add_image_size( 'ss_half', 500, 300, true );
	Simple_Storage_Media::start_regeneration();
	wp_update_attachment_metadata( $ss_hero, wp_generate_attachment_metadata( $ss_hero, (string) wp_get_original_image_path( $ss_hero ) ) );
	Simple_Storage_Media::finish_regeneration();
	$ss_row = Simple_Storage_Index::get( '2024/05/hero-500x300.jpg' );
	ss_check( null !== $ss_row && 1 === $ss_row['remote'] && 0 === $ss_row['local'], 'a new size never takes over a name that was not the attachment\'s before', $ss_row );
	ss_offload( '2024/05', array_unique( array_merge( Simple_Storage_Media::attachment_files( $ss_hero ), array( '2024/05/hero-500x300.jpg' ) ) ) );
	ss_check( hash_file( 'sha256', ss_remote( '2024/05/hero-500x300.jpg' ) ) === $ss_other_hash && 1 === ( Simple_Storage_Index::get( '2024/05/hero-500x300.jpg' )['conflict'] ?? 0 ), 'the file in the storage stays intact, the new size is a conflict' );
	@unlink( $ss_uploads . '/2024/05/hero-500x300.jpg' );

	// A name both in this attachment's metadata and another attachment's own file (a collision
	// that existed before): the other attachment's file is never taken over either.
	$ss_hero2 = $ss_new_image( 'hero2.jpg', 1000, 600 );
	$ss_meta  = wp_generate_attachment_metadata( $ss_hero2, $ss_uploads . '/2024/05/hero2.jpg' );
	$ss_meta['sizes']['ss_half'] = array( 'file' => 'hero2-500x300.jpg', 'width' => 500, 'height' => 300, 'mime-type' => 'image/jpeg' );
	wp_update_attachment_metadata( $ss_hero2, $ss_meta );
	$ss_other = $ss_new_image( 'hero2-500x300.jpg', 500, 300 );
	update_post_meta( $ss_other, '_wp_attachment_metadata', array( 'width' => 500, 'height' => 300, 'file' => '2024/05/hero2-500x300.jpg', 'sizes' => array() ) );
	ss_offload( '2024/05', array_merge( Simple_Storage_Media::attachment_files( $ss_hero2 ), array( '2024/05/hero2-500x300.jpg' ) ) );
	$ss_other_hash = hash_file( 'sha256', ss_remote( '2024/05/hero2-500x300.jpg' ) );
	Simple_Storage_Media::start_regeneration();
	wp_update_attachment_metadata( $ss_hero2, wp_generate_attachment_metadata( $ss_hero2, (string) wp_get_original_image_path( $ss_hero2 ) ) );
	Simple_Storage_Media::finish_regeneration();
	remove_image_size( 'ss_half' );
	$ss_row = Simple_Storage_Index::get( '2024/05/hero2-500x300.jpg' );
	ss_check( null !== $ss_row && 1 === $ss_row['remote'] && 0 === $ss_row['local'], "another attachment's file is never taken over", $ss_row );
	ss_offload( '2024/05', array( '2024/05/hero2-500x300.jpg' ) );
	ss_check( hash_file( 'sha256', ss_remote( '2024/05/hero2-500x300.jpg' ) ) === $ss_other_hash, "the other attachment's file stays intact in the storage" );
	@unlink( $ss_uploads . '/2024/05/hero2-500x300.jpg' );
	wp_delete_attachment( $ss_hero2, true );
	wp_delete_attachment( $ss_other, true );
	foreach ( array( '2024/05/hero-500x300.jpg', '2024/05/hero2-500x300.jpg' ) as $ss_path ) {
		@unlink( ss_remote( $ss_path ) );
		Simple_Storage_Index::delete_path( $ss_path );
	}
	ss_run_job( 'index' );
	wp_unschedule_hook( Simple_Storage_Media::OFFLOAD_HOOK );

	// --skip-delete, also as a default in wp-cli.yml: old sizes stay in the storage.
	$ss_meta = wp_get_attachment_metadata( $ss_hero );
	ss_image( $ss_uploads . '/2024/05/hero-100x60.jpg', 100, 60, 'jpg' );
	$ss_meta['sizes']['legacy'] = array( 'file' => 'hero-100x60.jpg', 'width' => 100, 'height' => 60, 'mime-type' => 'image/jpeg' );
	wp_update_attachment_metadata( $ss_hero, $ss_meta );
	ss_offload( '2024/05', array( '2024/05/hero-100x60.jpg' ) );
	Simple_Storage_Media::remember_cli_command( array( 'media', 'regenerate' ), array( 'skip-delete' => true, 'yes' => true ) );
	Simple_Storage_Media::start_regeneration();
	wp_update_attachment_metadata( $ss_hero, wp_generate_attachment_metadata( $ss_hero, (string) wp_get_original_image_path( $ss_hero ) ) );
	Simple_Storage_Media::finish_regeneration();
	Simple_Storage_Media::remember_cli_command( array( 'media', 'regenerate' ), array() );
	ss_check( ss_remote_only( '2024/05/hero-100x60.jpg' ), '`--skip-delete` keeps old sizes in the storage' );
	wp_delete_attachment( $ss_hero, true );
	foreach ( array( '2024/05/hero-100x60.jpg' ) as $ss_path ) {
		@unlink( ss_remote( $ss_path ) );
		Simple_Storage_Index::delete_path( $ss_path );
	}
	wp_unschedule_hook( Simple_Storage_Media::OFFLOAD_HOOK );

	$ss_timber_autoload = (string) getenv( 'TIMBER_AUTOLOAD' );
	if ( '' !== $ss_timber_autoload && is_file( $ss_timber_autoload ) ) {
		ss_section( 'Timber resizing' );
		// The class maps of a Composer vendor folder, without its platform check (a theme's vendor
		// folder may demand a newer PHP than the one under test).
		$ss_vendor = dirname( $ss_timber_autoload );
		if ( ! class_exists( '\Composer\Autoload\ClassLoader', false ) ) {
			require_once $ss_vendor . '/composer/ClassLoader.php';
		}
		$ss_loader = new \Composer\Autoload\ClassLoader( $ss_vendor );
		foreach ( require $ss_vendor . '/composer/autoload_psr4.php' as $ss_ns => $ss_dirs ) {
			$ss_loader->setPsr4( $ss_ns, $ss_dirs );
		}
		$ss_loader->addClassMap( require $ss_vendor . '/composer/autoload_classmap.php' );
		$ss_loader->register();
		$ss_t_url = $ss_baseurl . '/2024/05/timber.jpg';
		$ss_t_300 = $ss_baseurl . '/2024/05/timber-300x0-c-default.jpg';
		$ss_t_200 = $ss_baseurl . '/2024/05/timber-200x0-c-default.jpg';
		ss_image( $ss_uploads . '/2024/05/timber.jpg', 800, 600, 'jpg' );
		ss_image( $ss_uploads . '/2024/05/timber-jpg.webp', 800, 600, 'webp' );
		ss_image( $ss_uploads . '/2024/05/timber-50x50-c-default.jpg', 50, 50, 'jpg' );
		$ss_timber_ids = array();
		foreach ( array( 'timber.jpg', 'timber-50x50-c-default.jpg' ) as $ss_name ) {
			$ss_timber_ids[ $ss_name ] = wp_insert_attachment(
				array(
					'post_mime_type' => 'image/jpeg',
					'post_title'     => 'Simple Storage ' . $ss_name,
					'post_status'    => 'inherit',
				),
				'2024/05/' . $ss_name
			);
			update_post_meta( $ss_timber_ids[ $ss_name ], '_wp_attached_file', '2024/05/' . $ss_name );
			update_post_meta( $ss_timber_ids[ $ss_name ], '_wp_attachment_metadata', array( 'width' => 800, 'height' => 600, 'file' => '2024/05/' . $ss_name, 'sizes' => array() ) );
		}
		$ss_filters = apply_filters( 'timber/twig/filters', array( 'resize' => array( 'callable' => array( 'Timber\ImageHelper', 'resize' ) ) ) );
		ss_check( array( 'Simple_Storage_Timber', 'resize' ) === $ss_filters['resize']['callable'], 'the Twig resize filter is taken over' );
		$ss_own     = static fn( $src ) => $src;
		$ss_filters = apply_filters( 'timber/twig/filters', array( 'resize' => array( 'callable' => $ss_own ) ) );
		ss_check( $ss_own === $ss_filters['resize']['callable'], "a theme's own resize filter is left alone" );
		ss_check( $ss_t_300 === Simple_Storage_Timber::resize( $ss_t_url, 300 ) && is_file( $ss_uploads . '/2024/05/timber-300x0-c-default.jpg' ), 'with a local original Timber cuts the size as usual' );
		Simple_Storage_Timber::resize( $ss_baseurl . '/2024/05/timber-jpg.webp', 300 );
		$ss_timber_files = array( '2024/05/timber.jpg', '2024/05/timber-300x0-c-default.jpg', '2024/05/timber-jpg.webp', '2024/05/timber-jpg-300x0-c-default.webp', '2024/05/timber-50x50-c-default.jpg' );
		ss_offload( '2024/05', $ss_timber_files );
		Simple_Storage_Timber::reset();
		ss_check( count( $ss_timber_files ) === count( array_filter( $ss_timber_files, 'ss_remote_only' ) ), 'the image, its sizes and its WebP copy live only in the storage' );
		ss_check( $ss_t_url === \Timber\ImageHelper::resize( $ss_t_url, 300 ), 'Timber alone now falls back to the full-size original' );
		ss_check( $ss_t_300 === Simple_Storage_Timber::resize( $ss_t_url, 300 ), 'a size in the storage is used without the original' );
		ss_check( $ss_t_url === Simple_Storage_Timber::resize( $ss_t_url, 200 ) && false !== wp_next_scheduled( Simple_Storage_Timber::GENERATE_HOOK, array( '2024/05/timber.jpg', 200, 0, 'default' ) ), 'a missing size falls back to the original for now and is cut in the background' );
		wp_unschedule_hook( Simple_Storage_Timber::GENERATE_HOOK );
		Simple_Storage_Timber::generate( '2024/05/timber.jpg', 200, 0, 'default' );
		ss_check( is_file( $ss_uploads . '/2024/05/timber-200x0-c-default.jpg' ) && false !== wp_next_scheduled( Simple_Storage_Media::OFFLOAD_HOOK, array( '2024/05' ) ), 'the background run cuts it from the original brought back, and the folder goes out again' );
		Simple_Storage_Timber::reset();
		ss_check( $ss_t_200 === Simple_Storage_Timber::resize( $ss_t_url, 200 ), 'the new size is used' );

		$ss_t_result = \Timber\ImageHelper::resize( $ss_t_url, 300 );
		$ss_row      = Simple_Storage_Index::get( '2024/05/timber-300x0-c-default.jpg' );
		ss_check( $ss_t_300 === $ss_t_result && null !== $ss_row && 1 === $ss_row['local'] && 1 === $ss_row['remote'] && 0 === $ss_row['conflict'], 'a direct Timber call with a local original fetches the stored size instead of cutting a conflicting copy', array( $ss_t_result, $ss_row ) );
		\Timber\ImageHelper::resize( $ss_t_url, 50, 50 );
		ss_check( 0 === ( Simple_Storage_Index::get( '2024/05/timber-50x50-c-default.jpg' )['local'] ?? -1 ), "but never another attachment's file that only looks like a Timber size" );
		@unlink( $ss_uploads . '/2024/05/timber-50x50-c-default.jpg' );
		Simple_Storage_Timber::forget_copies( array(), $ss_timber_ids['timber.jpg'] );
		ss_check( ! file_exists( ss_remote( '2024/05/timber-300x0-c-default.jpg' ) ) && is_file( ss_remote( '2024/05/timber.jpg' ) ) && is_file( ss_remote( '2024/05/timber-jpg-300x0-c-default.webp' ) ) && is_file( ss_remote( '2024/05/timber-50x50-c-default.jpg' ) ), 'new metadata deletes Timber sizes of the image in the storage, as Timber does on disk' );
		$ss_timber_files[] = '2024/05/timber-200x0-c-default.jpg';
		ss_offload( '2024/05', $ss_timber_files );
		wp_delete_attachment( $ss_timber_ids['timber.jpg'], true );
		ss_check( ! array_filter( array( '2024/05/timber.jpg', '2024/05/timber-300x0-c-default.jpg', '2024/05/timber-200x0-c-default.jpg', '2024/05/timber-jpg.webp', '2024/05/timber-jpg-300x0-c-default.webp' ), static fn( $p ) => file_exists( ss_remote( $p ) ) || null !== Simple_Storage_Index::get( $p ) ), 'deleting the attachment deletes its Timber sizes and WebP copies in the storage' );
		ss_check( ss_remote_only( '2024/05/timber-50x50-c-default.jpg' ), 'an attachment that only looks like a Timber size stays' );
		wp_delete_attachment( $ss_timber_ids['timber-50x50-c-default.jpg'], true );
		wp_unschedule_hook( Simple_Storage_Media::OFFLOAD_HOOK );
		wp_unschedule_hook( Simple_Storage_Timber::GENERATE_HOOK );

		// crop=false is Timber's "-c-f", also when cut in the background.
		ss_image( $ss_uploads . '/2024/05/cf.jpg', 600, 400, 'jpg' );
		ss_offload( '2024/05', array( '2024/05/cf.jpg' ) );
		Simple_Storage_Timber::reset();
		wp_unschedule_hook( Simple_Storage_Timber::GENERATE_HOOK );
		Simple_Storage_Timber::resize( $ss_baseurl . '/2024/05/cf.jpg', 300, 200, false );
		ss_check( false !== wp_next_scheduled( Simple_Storage_Timber::GENERATE_HOOK, array( '2024/05/cf.jpg', 300, 200, false ) ), 'crop=false is kept for the background run' );
		wp_unschedule_hook( Simple_Storage_Timber::GENERATE_HOOK );
		Simple_Storage_Timber::generate( '2024/05/cf.jpg', 300, 200, false );
		ss_check( is_file( $ss_uploads . '/2024/05/cf-300x200-c-f.jpg' ), 'and the requested name is cut', glob( $ss_uploads . '/2024/05/cf-300x200*' ) );

		// A registered size name resolves like Timber resolves it.
		ss_offload( '2024/05', array( '2024/05/cf.jpg', '2024/05/cf-300x200-c-f.jpg' ) );
		Simple_Storage_Timber::reset();
		Simple_Storage_Timber::resize( $ss_baseurl . '/2024/05/cf.jpg', 'thumbnail' );
		ss_check( false !== wp_next_scheduled( Simple_Storage_Timber::GENERATE_HOOK, array( '2024/05/cf.jpg', 150, 150, 'default' ) ), 'a size name is resolved to its dimensions' );
		wp_unschedule_hook( Simple_Storage_Timber::GENERATE_HOOK );

		// An image no editor can read is tried once, then left alone for a day; SVG never.
		ss_write( $ss_uploads . '/2024/05/broken.jpg', random_bytes( 3000 ) );
		ss_write( $ss_uploads . '/2024/05/vector.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"/>' );
		ss_offload( '2024/05', array( '2024/05/broken.jpg', '2024/05/vector.svg' ) );
		Simple_Storage_Timber::reset();
		Simple_Storage_Timber::resize( $ss_baseurl . '/2024/05/vector.svg', 300 );
		ss_check( false === wp_next_scheduled( Simple_Storage_Timber::GENERATE_HOOK, array( '2024/05/vector.svg', 300, 0, 'default' ) ), 'an SVG is never cut in the background' );
		Simple_Storage_Timber::resize( $ss_baseurl . '/2024/05/broken.jpg', 300 );
		wp_unschedule_hook( Simple_Storage_Timber::GENERATE_HOOK );
		Simple_Storage_Timber::generate( '2024/05/broken.jpg', 300, 0, 'default' );
		ss_drop_local( '2024/05/broken.jpg' );
		Simple_Storage_Timber::reset();
		Simple_Storage_Timber::resize( $ss_baseurl . '/2024/05/broken.jpg', 300 );
		ss_check( false === wp_next_scheduled( Simple_Storage_Timber::GENERATE_HOOK, array( '2024/05/broken.jpg', 300, 0, 'default' ) ), 'a size that could not be cut is not tried again at once' );
		foreach ( array( '2024/05/cf.jpg', '2024/05/cf-300x200-c-f.jpg', '2024/05/broken.jpg', '2024/05/vector.svg' ) as $ss_path ) {
			@unlink( $ss_uploads . '/' . $ss_path );
			@unlink( ss_remote( $ss_path ) );
			Simple_Storage_Index::delete_path( $ss_path );
		}
		wp_unschedule_hook( Simple_Storage_Media::OFFLOAD_HOOK );

		// Retina and other operations write names that may be other attachments' files.
		ss_image( $ss_uploads . '/2024/05/logo@2x.png', 400, 200, 'png' );
		ss_offload( '2024/05', array( '2024/05/logo@2x.png' ) );
		$ss_logo2x_hash = hash_file( 'sha256', ss_remote( '2024/05/logo@2x.png' ) );
		$ss_logo        = $ss_new_image( 'logo.png', 200, 100 );
		touch( $ss_uploads . '/2024/05/logo.png', time() - 120 ); // Newer than the file in the storage.
		Simple_Storage_Timber::reset();
		\Timber\ImageHelper::retina_resize( $ss_baseurl . '/2024/05/logo.png', 2 );
		ss_offload( '2024/05', array( '2024/05/logo@2x.png' ) );
		ss_check( hash_file( 'sha256', ss_remote( '2024/05/logo@2x.png' ) ) === $ss_logo2x_hash, 'a retina copy never replaces a file of that name in the storage' );
		wp_delete_attachment( $ss_logo, true );
		foreach ( array( '2024/05/logo.png', '2024/05/logo@2x.png' ) as $ss_path ) {
			@unlink( $ss_uploads . '/' . $ss_path );
			@unlink( ss_remote( $ss_path ) );
			Simple_Storage_Index::delete_path( $ss_path );
		}
		wp_unschedule_hook( Simple_Storage_Media::OFFLOAD_HOOK );
		wp_unschedule_hook( Simple_Storage_Timber::GENERATE_HOOK );
	} else {
		WP_CLI::log( "\n== Timber resizing: skipped, set TIMBER_AUTOLOAD to the vendor/autoload.php of a Composer install with Timber 2" );
	}
	wp_set_current_user( 0 );

	ss_section( 'Name conflict' );
	$ss_original      = hash_file( 'sha256', ss_remote( '2024/05/doc.pdf' ) );
	$ss_original_size = filesize( ss_remote( '2024/05/doc.pdf' ) );
	$ss_foreign_file  = static function ( string $contents ) use ( $ss_uploads ): int {
		ss_write( $ss_uploads . '/2024/05/doc.pdf', $contents );
		$id = wp_insert_attachment(
			array(
				'post_mime_type' => 'application/pdf',
				'post_title'     => 'Simple Storage foreign document',
				'post_status'    => 'inherit',
			),
			'2024/05/doc.pdf'
		);
		update_post_meta( $id, '_wp_attached_file', '2024/05/doc.pdf' );

		return $id;
	};
	$ss_foreign = $ss_foreign_file( 'a different document with the same name' );
	$ss_job     = ss_run_job( 'push' );
	$ss_row     = Simple_Storage_Index::get( '2024/05/doc.pdf' );
	ss_check( 'done' === $ss_job['status'], 'push finishes', $ss_job['message'] ?? '' );
	ss_check( null !== $ss_row && 1 === $ss_row['conflict'] && 1 === $ss_row['remote'] && ! empty( $ss_row['error'] ), 'the conflict is recorded', $ss_row );
	ss_check( hash_file( 'sha256', ss_remote( '2024/05/doc.pdf' ) ) === $ss_original, 'the original in the storage is not overwritten' );
	ss_check( is_file( $ss_uploads . '/2024/05/doc.pdf' ), 'the conflicting local file is kept' );
	ss_run_job( 'index' );
	$ss_row = Simple_Storage_Index::get( '2024/05/doc.pdf' );
	ss_check( null !== $ss_row && 1 === $ss_row['conflict'] && 1 === $ss_row['remote'] && $ss_original_size === $ss_row['size'], 'indexing again keeps the conflict and the size of the remote original', $ss_row );
	wp_delete_attachment( $ss_foreign, true );
	$ss_row = Simple_Storage_Index::get( '2024/05/doc.pdf' );
	ss_check( ! file_exists( $ss_uploads . '/2024/05/doc.pdf' ) && hash_file( 'sha256', ss_remote( '2024/05/doc.pdf' ) ) === $ss_original, 'deleting the attachment of the conflicting file keeps the remote original' );
	ss_check( null !== $ss_row && 0 === $ss_row['conflict'] && 1 === $ss_row['remote'] && 0 === $ss_row['local'] && null === $ss_row['error'], 'the original lives only in the storage again', $ss_row );

	$ss_foreign = $ss_foreign_file( 'a newcomer the index has not seen' );
	wp_delete_attachment( $ss_foreign, true );
	$ss_row = Simple_Storage_Index::get( '2024/05/doc.pdf' );
	ss_check( ! file_exists( $ss_uploads . '/2024/05/doc.pdf' ) && hash_file( 'sha256', ss_remote( '2024/05/doc.pdf' ) ) === $ss_original && null !== $ss_row && 1 === $ss_row['remote'], 'deleting an unindexed newcomer keeps the remote original', $ss_row );

	ss_write( $ss_uploads . '/2024/05/doc.pdf', 'a third different document' );
	ss_run_job( 'index' );
	ss_check( 1 === ( Simple_Storage_Index::get( '2024/05/doc.pdf' )['conflict'] ?? 0 ), 'indexing finds the conflict too' );
	unlink( $ss_uploads . '/2024/05/doc.pdf' );
	ss_run_job( 'index' );
	$ss_row = Simple_Storage_Index::get( '2024/05/doc.pdf' );
	ss_check( null !== $ss_row && 0 === $ss_row['conflict'] && 1 === $ss_row['remote'] && 0 === $ss_row['local'] && null === $ss_row['error'] && $ss_original_size === $ss_row['size'], 'removing the conflicting file resolves the conflict', $ss_row );

	ss_section( 'An equal size is never taken for a copy' );
	ss_write( $ss_uploads . '/2024/05/same-size.bin', random_bytes( 1000 ) );
	wp_mkdir_p( dirname( ss_remote( '2024/05/same-size.bin' ) ) );
	file_put_contents( ss_remote( '2024/05/same-size.bin' ), random_bytes( 1000 ) );
	ss_run_job( 'index' );
	$ss_row = Simple_Storage_Index::get( '2024/05/same-size.bin' );
	ss_check( null !== $ss_row && 1 === $ss_row['local'] && 0 === $ss_row['remote'] && 0 === $ss_row['verified'], 'a remote file of the same size does not count as the copy of a local one', $ss_row );
	$ss_expected['2024/05/same-size.bin'] = hash_file( 'sha256', $ss_uploads . '/2024/05/same-size.bin' );

	ss_section( 'Faults' );
	file_put_contents( $ss_fake_root . '/.fake/faults.json', wp_json_encode( array( 'corrupt' => '#corrupt-me#', 'fail' => '#fail-me#' ) ) );
	ss_write( $ss_uploads . '/2024/05/corrupt-me.bin', random_bytes( 5000 ) );
	ss_write( $ss_uploads . '/2024/05/fail-me.bin', random_bytes( 500 ) );
	$ss_expected['2024/05/corrupt-me.bin'] = hash_file( 'sha256', $ss_uploads . '/2024/05/corrupt-me.bin' );
	$ss_expected['2024/05/fail-me.bin']    = hash_file( 'sha256', $ss_uploads . '/2024/05/fail-me.bin' );
	$ss_job                                = ss_run_job( 'push' );
	ss_check( 'done' === $ss_job['status'] && 2 === ss_failed( $ss_job ), 'push reports both faulty files', array( $ss_job['status'], ss_failed( $ss_job ) ) );
	ss_check( is_file( $ss_uploads . '/2024/05/corrupt-me.bin' ), 'corrupted copy caught: local file kept' );
	ss_check( is_file( $ss_uploads . '/2024/05/fail-me.bin' ), 'failed upload: local file kept' );
	$ss_row = Simple_Storage_Index::get( '2024/05/corrupt-me.bin' );
	ss_check( null !== $ss_row && 0 === $ss_row['remote'] && ! empty( $ss_row['error'] ), 'corrupted copy marked for another upload', $ss_row );
	unlink( $ss_fake_root . '/.fake/faults.json' );
	$ss_job = ss_run_job( 'push' );
	ss_check( 'done' === $ss_job['status'] && 0 === ss_failed( $ss_job ), 'second push succeeds', $ss_job['message'] ?? '' );
	ss_check( ! file_exists( $ss_uploads . '/2024/05/corrupt-me.bin' ) && hash_file( 'sha256', ss_remote( '2024/05/corrupt-me.bin' ) ) === $ss_expected['2024/05/corrupt-me.bin'], 'file moved intact after retry' );
	ss_check( ! file_exists( $ss_uploads . '/2024/05/stale.bin' ) && hash_file( 'sha256', ss_remote( '2024/05/stale.bin' ) ) === $ss_expected['2024/05/stale.bin'], 'the re-queued file moved too' );
	ss_check( ! file_exists( $ss_uploads . '/2024/05/same-size.bin' ) && hash_file( 'sha256', ss_remote( '2024/05/same-size.bin' ) ) === $ss_expected['2024/05/same-size.bin'], 'the same-size file was uploaded over the stranger in the storage' );
	ss_check( 0 === Simple_Storage_Index::stats()['errors'], 'errors cleared after success' );

	ss_section( 'Pull' );
	$ss_job   = ss_run_job( 'pull' );
	$ss_stats = Simple_Storage_Index::stats();
	ss_check( 'done' === $ss_job['status'] && 0 === ss_failed( $ss_job ), 'pull finished without failures', $ss_job['message'] ?? '' );
	foreach ( $ss_expected as $ss_path => $ss_hash ) {
		ss_check( is_file( $ss_uploads . '/' . $ss_path ) && hash_file( 'sha256', $ss_uploads . '/' . $ss_path ) === $ss_hash, 'returned intact: ' . $ss_path );
	}
	ss_check( is_file( $ss_uploads . '/2024/05/photo.webp' ) && is_file( $ss_uploads . '/2024/05/doc.pdf' ), 'files WordPress does not know returned too' );
	ss_check( 0 === $ss_stats['remote_only']['files'] && 0 === $ss_stats['both']['files'], 'nothing left in the storage', $ss_stats );
	$ss_left = is_dir( $ss_fake_root . '/' . $ss_prefix ) ? array_diff( scandir( $ss_fake_root . '/' . $ss_prefix ), array( '.', '..' ) ) : array();
	ss_check( empty( $ss_left ), 'empty storage folders pruned', array_values( $ss_left ) );
	ss_check( ! Simple_Storage_Delivery::active() && 'local' === Simple_Storage_Settings::state()['mode'], 'serving disabled, mode local' );
	ss_check( ! is_file( $ss_uploads . '/.htaccess' ) && ! is_file( Simple_Storage_Proxy::config_file() ), '.htaccess block and proxy configuration removed' );
	ss_check( ! glob( $ss_uploads . '/*/*/*' . Simple_Storage_Paths::PART_SUFFIX ), 'no temporary files left' );
} finally {
	ss_section( 'Cleanup' );
	Simple_Storage_Delivery::remove_config();
	Simple_Storage_Index::drop();
	foreach ( array( 'simple_storage_settings', 'simple_storage_state', 'simple_storage_job', 'simple_storage_lock', 'simple_storage_log', 'simple_storage_token', 'simple_storage_scan_seq', 'simple_storage_regenerating' ) as $ss_option ) {
		delete_option( $ss_option );
	}
	wp_unschedule_hook( Simple_Storage_Media::OFFLOAD_HOOK );
	wp_unschedule_hook( Simple_Storage_Media::DELETE_HOOK );
	wp_unschedule_hook( Simple_Storage_Timber::GENERATE_HOOK );
	if ( is_resource( $ss_server ) ) {
		proc_terminate( $ss_server );
		proc_close( $ss_server );
	}
	exec( 'rm -rf ' . escapeshellarg( $ss_tmp ) . ' ' . escapeshellarg( $ss_fake_root . '/' . $ss_prefix ) );
	@unlink( $ss_fake_root . '/.fake/faults.json' );
	WP_CLI::log( '  temporary data removed' );
}

WP_CLI::log( '' );
if ( $GLOBALS['ss_test']['failures'] > 0 ) {
	WP_CLI::error( sprintf( '%d of %d checks failed.', $GLOBALS['ss_test']['failures'], $GLOBALS['ss_test']['checks'] ) );
}
WP_CLI::success( sprintf( 'All %d checks passed.', $GLOBALS['ss_test']['checks'] ) );
