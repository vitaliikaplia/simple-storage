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

		if ( is_multisite() ) {
			add_action( 'admin_notices', array( self::class, 'multisite_notice' ) );

			return;
		}

		new Simple_Storage_GitHub_Updater();
		Simple_Storage_Index::maybe_install();
		Simple_Storage_Delivery::init();
		Simple_Storage_Media::init();
		Simple_Storage_Timber::init();

		if ( is_admin() ) {
			Simple_Storage_Admin::init();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'simple-storage', Simple_Storage_CLI::class );
			WP_CLI::add_hook( 'before_run_command', array( Simple_Storage_Media::class, 'remember_cli_command' ) );
			WP_CLI::add_hook( 'before_invoke:media regenerate', array( Simple_Storage_Media::class, 'start_regeneration' ) );
			WP_CLI::add_hook( 'after_invoke:media regenerate', array( Simple_Storage_Media::class, 'finish_regeneration' ) );
		}
	}

	public static function load_textdomain(): void {
		load_plugin_textdomain( 'simple-storage', false, dirname( SIMPLE_STORAGE_BASENAME ) . '/languages' );
	}

	public static function multisite_notice(): void {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Simple Storage supports single-site WordPress installations only and does nothing on this network.', 'simple-storage' ) . '</p></div>';
		}
	}

	public static function activate( bool $network_wide = false ): void {
		self::load_textdomain();

		if ( is_multisite() ) {
			if ( function_exists( 'deactivate_plugins' ) ) {
				deactivate_plugins( SIMPLE_STORAGE_BASENAME, true, $network_wide );
			}

			wp_die(
				esc_html__( 'Simple Storage supports single-site WordPress installations only and was not activated.', 'simple-storage' ),
				esc_html__( 'Plugin activation refused', 'simple-storage' ),
				array( 'back_link' => true )
			);
		}

		Simple_Storage_Index::install();

		if ( false === get_option( Simple_Storage_Settings::OPTION, false ) ) {
			$defaults           = Simple_Storage_Settings::defaults();
			$defaults['prefix'] = Simple_Storage_Settings::default_prefix();
			add_option( Simple_Storage_Settings::OPTION, $defaults, '', true );
		}

		// The rules may have been removed by hand while the plugin was inactive.
		Simple_Storage_Delivery::sync();
	}

	/**
	 * Files already in the storage stay reachable after deactivation: the .htaccess rules work
	 * without PHP, so they are kept. Only background work stops.
	 */
	public static function deactivate( bool $network_wide = false ): void {
		Simple_Storage_Jobs::pause();
		wp_unschedule_hook( Simple_Storage_Media::OFFLOAD_HOOK );
		wp_unschedule_hook( Simple_Storage_Timber::GENERATE_HOOK );
	}
}
