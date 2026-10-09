<?php
/**
 * Plugin Name: Simple Storage
 * Description: Moves WordPress media files to the Hosting Ukraine storage and back, with verification and serving from the storage.
 * Version: 0.2.0
 * Author: Vitalii Kaplia
 * Author URI: https://kaplia.pro/
 * Update URI: https://github.com/vitaliikaplia/simple-storage
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 * Text Domain: simple-storage
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// WordPress refuses to activate or update the plugin on PHP older than 8.1, but PHP can be downgraded
// after activation. Show a notice and load nothing instead of a fatal error; this file parses on PHP 7.
if ( PHP_VERSION_ID < 80100 ) {
	$simple_storage_old_php = static function () {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>Simple Storage requires PHP 8.1 or newer (current: ',
			esc_html( PHP_VERSION ), '). The plugin was not loaded.</p></div>';
	};
	add_action( 'admin_notices', $simple_storage_old_php );
	unset( $simple_storage_old_php );
	return;
}

define( 'SIMPLE_STORAGE_VERSION', '0.2.0' );
define( 'SIMPLE_STORAGE_FILE', __FILE__ );
define( 'SIMPLE_STORAGE_DIR', plugin_dir_path( __FILE__ ) );
define( 'SIMPLE_STORAGE_URL', plugin_dir_url( __FILE__ ) );
define( 'SIMPLE_STORAGE_BASENAME', plugin_basename( __FILE__ ) );

require_once SIMPLE_STORAGE_DIR . 'includes/class-simple-storage-settings.php';
require_once SIMPLE_STORAGE_DIR . 'includes/class-simple-storage-paths.php';
require_once SIMPLE_STORAGE_DIR . 'includes/class-simple-storage-log.php';
require_once SIMPLE_STORAGE_DIR . 'includes/class-simple-storage-index.php';
require_once SIMPLE_STORAGE_DIR . 'includes/class-simple-storage-client.php';
require_once SIMPLE_STORAGE_DIR . 'includes/class-simple-storage-transfer.php';
require_once SIMPLE_STORAGE_DIR . 'includes/class-simple-storage-tester.php';
require_once SIMPLE_STORAGE_DIR . 'includes/class-simple-storage-proxy.php';
require_once SIMPLE_STORAGE_DIR . 'includes/class-simple-storage-delivery.php';
require_once SIMPLE_STORAGE_DIR . 'includes/class-simple-storage-jobs.php';
require_once SIMPLE_STORAGE_DIR . 'includes/class-simple-storage-media.php';
require_once SIMPLE_STORAGE_DIR . 'includes/class-simple-storage-timber.php';
require_once SIMPLE_STORAGE_DIR . 'includes/class-simple-storage-admin.php';
require_once SIMPLE_STORAGE_DIR . 'includes/class-simple-storage-cli.php';
require_once SIMPLE_STORAGE_DIR . 'includes/class-simple-storage-github-updater.php';
require_once SIMPLE_STORAGE_DIR . 'includes/class-simple-storage-plugin.php';
require_once SIMPLE_STORAGE_DIR . 'includes/functions.php';

register_activation_hook( __FILE__, array( 'Simple_Storage_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Simple_Storage_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Simple_Storage_Plugin', 'init' ) );
