<?php
/**
 * Serves media files that live in the storage under their original uploads addresses.
 *
 * uploads/.htaccess rewrites requests for missing media files here (without a redirect) when the
 * "transparent proxy" mode is on. WordPress is not loaded; see Simple_Storage_Proxy.
 */

if ( ! defined( 'SIMPLE_STORAGE_PROXY' ) ) {
	define( 'SIMPLE_STORAGE_PROXY', true );
}

require_once __DIR__ . '/includes/class-simple-storage-proxy.php';

Simple_Storage_Proxy::handle_request();
