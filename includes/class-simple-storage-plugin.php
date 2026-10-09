<?php
/**
 * Plugin lifecycle and subsystem orchestration.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_Storage_Plugin {
	private static bool $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		add_action( 'init', array( self::class, 'load_textdomain' ), 0 );
	}

	public static function load_textdomain(): void {
		load_plugin_textdomain( 'simple-storage', false, dirname( SIMPLE_STORAGE_BASENAME ) . '/languages' );
	}

	public static function activate( bool $network_wide = false ): void {
	}

	public static function deactivate( bool $network_wide = false ): void {
	}
}
