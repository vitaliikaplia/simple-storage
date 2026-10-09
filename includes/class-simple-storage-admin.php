<?php
/**
 * Admin page under Media: overview with index statistics and transfers, settings, log.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_Storage_Admin {
	public const PAGE = 'simple-storage';
	private const NONCE = 'simple_storage';
	private const CAPABILITY = 'manage_options';

	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'update_option_' . Simple_Storage_Settings::OPTION, array( self::class, 'settings_saved' ), 10, 2 );
		add_action( 'admin_notices', array( self::class, 'notices' ) );
		add_filter( 'plugin_action_links_' . SIMPLE_STORAGE_BASENAME, array( self::class, 'action_links' ) );

		foreach ( array( 'state', 'job', 'test', 'delivery', 'probe', 'clear' ) as $action ) {
			add_action( 'wp_ajax_simple_storage_' . $action, array( self::class, 'ajax_' . $action ) );
		}
	}

	public static function menu(): void {
		add_media_page(
			__( 'Simple Storage', 'simple-storage' ),
			__( 'Simple Storage', 'simple-storage' ),
			self::CAPABILITY,
			self::PAGE,
			array( self::class, 'render' )
		);
	}

	public static function url( string $tab = 'overview' ): string {
		return add_query_arg(
			array(
				'page' => self::PAGE,
				'tab'  => $tab,
			),
			admin_url( 'upload.php' )
		);
	}

	/** @param array<int|string, string> $links */
	public static function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Open', 'simple-storage' ) . '</a>' );

		return $links;
	}

	public static function register_settings(): void {
		register_setting(
			'simple_storage',
			Simple_Storage_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Simple_Storage_Settings::class, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * @param mixed $old
	 * @param mixed $new
	 */
	public static function settings_saved( $old, $new ): void {
		Simple_Storage_Settings::flush_cache();
		Simple_Storage_Paths::reset();

		$old = is_array( $old ) ? $old : array();
		$new = is_array( $new ) ? $new : array();
		foreach ( array( 'host', 'login', 'password' ) as $key ) {
			if ( ( $old[ $key ] ?? '' ) !== ( $new[ $key ] ?? '' ) ) {
				Simple_Storage_Client::forget_token();
				Simple_Storage_Settings::update_state( array( 'last_test' => array() ) );
				break;
			}
		}

		// Another storage address (or public address) means earlier verifications prove nothing
		// about where files are read from now: every copy is verified again before any local
		// file is deleted.
		if ( ( $old['host'] ?? '' ) !== ( $new['host'] ?? '' ) || ( $old['public_url'] ?? '' ) !== ( $new['public_url'] ?? '' ) ) {
			Simple_Storage_Index::reset_verification();
		}

		if ( Simple_Storage_Delivery::active() && 'proxy' === Simple_Storage_Settings::delivery_mode() && ! Simple_Storage_Delivery::probe_is_current() ) {
			// The address, folder or mode changed: find the proxy engine for the new target.
			Simple_Storage_Delivery::sync();
			Simple_Storage_Delivery::probe_proxy();
		} else {
			Simple_Storage_Delivery::sync();
		}

		if ( Simple_Storage_Delivery::active() ) {
			// Serving must be proven again for the new settings before new uploads may leave.
			Simple_Storage_Delivery::verify_delivery( self::extensions_to_verify() );
		}
	}

	/** @return array<int, string> Extensions of the files the site serves (or will serve) from the storage. */
	private static function extensions_to_verify(): array {
		$extensions = Simple_Storage_Index::extensions( array( 'remote' => 1 ), 20 );

		return empty( $extensions ) ? array( 'txt' ) : $extensions;
	}

	public static function assets( string $hook ): void {
		if ( 'media_page_' . self::PAGE !== $hook ) {
			return;
		}

		wp_enqueue_style( 'simple-storage-admin', SIMPLE_STORAGE_URL . 'assets/css/admin.css', array(), SIMPLE_STORAGE_VERSION );
		wp_enqueue_script( 'simple-storage-admin', SIMPLE_STORAGE_URL . 'assets/js/admin.js', array(), SIMPLE_STORAGE_VERSION, true );
		wp_localize_script(
			'simple-storage-admin',
			'SimpleStorage',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'state'   => self::state(),
				'i18n'    => self::js_strings(),
			)
		);
	}

	/** Notice on other admin pages while a transfer waits to be continued. */
	public static function notices(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! current_user_can( self::CAPABILITY ) || ( $screen && 'media_page_' . self::PAGE === $screen->id ) ) {
			return;
		}

		$job = Simple_Storage_Jobs::current();
		if ( null === $job || ! in_array( $job['status'], array( 'running', 'paused', 'failed' ), true ) || 'index' === $job['type'] ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			/* translators: %s: job name. */
			esc_html( sprintf( __( 'Simple Storage: "%s" is not finished.', 'simple-storage' ), Simple_Storage_Jobs::type_label( (string) $job['type'] ) ) ),
			esc_url( self::url() ),
			esc_html__( 'Continue', 'simple-storage' )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'simple-storage' ) );
		}

		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tabs = array(
			'overview' => __( 'Overview', 'simple-storage' ),
			'settings' => __( 'Settings', 'simple-storage' ),
			'log'      => __( 'Log', 'simple-storage' ),
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'overview';
		}

		echo '<div class="wrap simple-storage">';
		echo '<h1>' . esc_html__( 'Simple Storage', 'simple-storage' ) . '</h1>';
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			printf( '<a href="%s" class="nav-tab%s">%s</a>', esc_url( self::url( $key ) ), $key === $tab ? ' nav-tab-active' : '', esc_html( $label ) );
		}
		echo '</nav>';

		if ( is_multisite() ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Simple Storage supports single-site WordPress installations only.', 'simple-storage' ) . '</p></div></div>';

			return;
		}

		if ( 'settings' === $tab ) {
			settings_errors();
		}

		require SIMPLE_STORAGE_DIR . 'includes/views/' . $tab . '.php';
		echo '</div>';
	}

	/** Everything the overview needs, also returned after every AJAX call. @return array<string, mixed> */
	public static function state(): array {
		$settings = Simple_Storage_Settings::state();
		$stats    = Simple_Storage_Index::stats();
		// Locales such as uk use "&nbsp;" as the thousands separator; the page sets text, not HTML.
		$text     = static fn( string $value ): string => html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
		$format   = static fn( array $item ): array => array(
			/* translators: %s: number of files. */
			'files' => $text( sprintf( _n( '%s file', '%s files', $item['files'], 'simple-storage' ), number_format_i18n( $item['files'] ) ) ),
			'bytes' => $text( size_format( $item['bytes'], 1 ) ?: '0 B' ),
		);

		$log = array();
		foreach ( array_reverse( array_slice( Simple_Storage_Log::entries(), -15 ) ) as $entry ) {
			$log[] = array(
				'time'    => wp_date( 'd.m.Y H:i:s', $entry['time'] ),
				'level'   => $entry['level'],
				'message' => $entry['message'],
			);
		}

		return array(
			'job'         => self::job_view( Simple_Storage_Jobs::current() ),
			'stats'       => array(
				'total'       => $format( $stats['total'] ),
				'local_only'  => $format( $stats['local_only'] ),
				'both'        => $format( $stats['both'] ),
				'remote_only' => $format( $stats['remote_only'] ),
				'errors'      => number_format_i18n( (int) $stats['errors'] ),
				'indexed'     => $settings['last_index'] ? wp_date( 'd.m.Y H:i', $settings['last_index'] ) : '',
				'raw'         => array(
					'total'       => $stats['total']['files'],
					'local_only'  => $stats['local_only']['files'],
					'both'        => $stats['both']['files'],
					'remote_only' => $stats['remote_only']['files'],
				),
			),
			'configured'  => Simple_Storage_Settings::is_configured(),
			'test'        => array(
				'ok'   => ! empty( $settings['last_test']['ok'] ),
				'time' => ! empty( $settings['last_test']['time'] ) ? wp_date( 'd.m.Y H:i', (int) $settings['last_test']['time'] ) : '',
			),
			'delivery'    => Simple_Storage_Delivery::active(),
			'deliveryMode' => Simple_Storage_Settings::delivery_mode(),
			'proxyEngine'  => Simple_Storage_Delivery::proxy_engine(),
			'verified'     => Simple_Storage_Delivery::active() ? Simple_Storage_Delivery::verified_extensions() : array(),
			'proxyProbed'  => ! empty( $settings['proxy']['time'] ) && Simple_Storage_Delivery::probe_is_current() ? wp_date( 'd.m.Y H:i', (int) $settings['proxy']['time'] ) : '',
			'htaccess'    => Simple_Storage_Delivery::active() ? Simple_Storage_Delivery::htaccess_status() : '',
			'auto'        => Simple_Storage_Media::auto_enabled(),
			'mode'        => $settings['mode'],
			'errors'      => Simple_Storage_Index::errors( 20 ),
			'log'         => $log,
		);
	}

	/**
	 * @param array<string, mixed>|null $job
	 * @return array<string, mixed>|null
	 */
	private static function job_view( ?array $job ): ?array {
		if ( null === $job ) {
			return null;
		}

		$phases   = array();
		$measured = Simple_Storage_Jobs::measured_phases();
		foreach ( $job['phases'] as $index => $phase ) {
			$progress = $job['progress'][ $phase ] ?? array();
			$state    = $index < $job['phase'] ? 'done' : ( $index === $job['phase'] ? 'current' : 'pending' );
			if ( 'done' === $job['status'] ) {
				$state = 'done';
			}

			$phases[] = array(
				'key'      => $phase,
				'label'    => Simple_Storage_Jobs::phase_label( $phase ),
				'state'    => $state,
				'measured' => in_array( $phase, $measured, true ),
				'total'    => (int) ( $progress['total'] ?? 0 ),
				'done'     => (int) ( $progress['done'] ?? 0 ),
				'failed'   => (int) ( $progress['failed'] ?? 0 ),
				'bytes'    => html_entity_decode( size_format( (int) ( $progress['bytes'] ?? 0 ), 1 ) ?: '0 B', ENT_QUOTES, 'UTF-8' ),
				'total_bytes' => html_entity_decode( size_format( (int) ( $progress['total_bytes'] ?? 0 ), 1 ) ?: '', ENT_QUOTES, 'UTF-8' ),
			);
		}

		$statuses = array(
			'running'   => __( 'Running', 'simple-storage' ),
			'paused'    => __( 'Paused', 'simple-storage' ),
			'failed'    => __( 'Stopped by an error', 'simple-storage' ),
			'done'      => __( 'Finished', 'simple-storage' ),
			'cancelled' => __( 'Cancelled', 'simple-storage' ),
		);

		return array(
			'type'        => $job['type'],
			'label'       => Simple_Storage_Jobs::type_label( (string) $job['type'] ),
			'status'      => $job['status'],
			'statusLabel' => $statuses[ $job['status'] ] ?? $job['status'],
			'phases'      => $phases,
			'current'     => (string) $job['current'],
			'message'     => (string) $job['message'],
			'warnings'    => array_values( (array) $job['warnings'] ),
			'started'     => wp_date( 'd.m.Y H:i', (int) $job['started_at'] ),
			'finished'    => $job['finished_at'] ? wp_date( 'd.m.Y H:i', (int) $job['finished_at'] ) : '',
		);
	}

	private static function verify_request(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Sorry, you are not allowed to do that.', 'simple-storage' ) ), 403 );
		}
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'The page has expired. Reload it and try again.', 'simple-storage' ) ), 403 );
		}
	}

	/** @param mixed $result */
	private static function respond( $result = null ): void {
		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
					'state'   => self::state(),
				)
			);
		}

		wp_send_json_success( array( 'state' => self::state() ) );
	}

	public static function ajax_state(): void {
		self::verify_request();
		self::respond();
	}

	public static function ajax_job(): void {
		self::verify_request();

		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		switch ( $do ) {
			case 'start':
				$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
				self::respond( Simple_Storage_Jobs::start( $type ) );
				break;
			case 'step':
				self::respond( Simple_Storage_Jobs::step( self::budget() ) );
				break;
			case 'resume':
				self::respond( Simple_Storage_Jobs::resume() );
				break;
			case 'pause':
				Simple_Storage_Jobs::pause();
				self::respond();
				break;
			case 'cancel':
				Simple_Storage_Jobs::cancel();
				self::respond();
				break;
		}

		self::respond( new WP_Error( 'simple_storage_action', __( 'Unknown action.', 'simple-storage' ) ) );
	}

	public static function ajax_test(): void {
		self::verify_request();

		$report = Simple_Storage_Tester::run();
		wp_send_json_success(
			array(
				'report' => $report,
				'state'  => self::state(),
			)
		);
	}

	public static function ajax_delivery(): void {
		self::verify_request();

		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( 'enable' === $do ) {
			if ( ! Simple_Storage_Settings::is_configured() ) {
				self::respond( new WP_Error( 'simple_storage_not_configured', __( 'The storage connection is not configured.', 'simple-storage' ) ) );
			}
			$enabled = Simple_Storage_Delivery::enable();
			if ( is_wp_error( $enabled ) ) {
				self::respond( $enabled );
			}
			$report = Simple_Storage_Delivery::verify_delivery( self::extensions_to_verify() );
			if ( ! is_wp_error( $report ) && empty( $report['ok'] ) ) {
				self::respond( new WP_Error( 'simple_storage_delivery_check', (string) reset( $report['failed'] ) ) );
			}
			self::respond( $report );
		}

		Simple_Storage_Delivery::disable();
		self::respond();
	}

	public static function ajax_probe(): void {
		self::verify_request();

		if ( ! Simple_Storage_Delivery::active() || 'proxy' !== Simple_Storage_Settings::delivery_mode() ) {
			self::respond( new WP_Error( 'simple_storage_probe', __( 'The check applies to the transparent proxy while serving from the storage is on.', 'simple-storage' ) ) );
		}

		Simple_Storage_Delivery::probe_proxy();
		self::respond();
	}

	public static function ajax_clear(): void {
		self::verify_request();

		$what = isset( $_POST['what'] ) ? sanitize_key( wp_unslash( $_POST['what'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( 'errors' === $what ) {
			Simple_Storage_Index::clear_errors();
		} elseif ( 'log' === $what ) {
			Simple_Storage_Log::clear();
		}

		self::respond();
	}

	/** Seconds one AJAX step may work, leaving room under max_execution_time. */
	private static function budget(): float {
		$limit = (int) ini_get( 'max_execution_time' );

		return $limit > 0 ? (float) max( 5, min( 20, $limit - 10 ) ) : 20.0;
	}

	/** @return array<string, string> */
	private static function js_strings(): array {
		return array(
			'confirmPush'      => __( "All media files will be copied to the storage and verified, serving from the storage will be enabled, and then the local copies will be deleted from WordPress.\n\nContinue?", 'simple-storage' ),
			'confirmPull'      => __( "All files will be downloaded from the storage back into WordPress, verified, and then deleted from the storage.\n\nContinue?", 'simple-storage' ),
			'confirmCancel'    => __( 'Cancel the job? Files that were already processed stay where they are now.', 'simple-storage' ),
			'confirmDisable'   => __( "Some files exist only in the storage. If serving from the storage is turned off, they will not be shown on the site.\n\nTurn it off anyway?", 'simple-storage' ),
			'pause'            => __( 'Pause', 'simple-storage' ),
			'resume'           => __( 'Continue', 'simple-storage' ),
			'cancel'           => __( 'Cancel', 'simple-storage' ),
			'close'            => __( 'Hide', 'simple-storage' ),
			'files'            => __( 'files', 'simple-storage' ),
			'folders'          => __( 'folders', 'simple-storage' ),
			'failed'           => __( 'failed', 'simple-storage' ),
			'current'          => __( 'Now:', 'simple-storage' ),
			'started'          => __( 'Started:', 'simple-storage' ),
			'finished'         => __( 'Finished:', 'simple-storage' ),
			'networkError'     => __( 'The server did not respond. The job is paused; continue it when the connection is back.', 'simple-storage' ),
			'keepOpen'         => __( 'Keep this tab open while the job runs. If you close it, the job pauses and can be continued later.', 'simple-storage' ),
			'on'               => __( 'On', 'simple-storage' ),
			'off'              => __( 'Off', 'simple-storage' ),
			'enable'           => __( 'Enable serving from the storage', 'simple-storage' ),
			'disable'          => __( 'Disable serving from the storage', 'simple-storage' ),
			'htaccessOk'       => __( 'The rules are written to uploads/.htaccess.', 'simple-storage' ),
			'htaccessMissing'  => __( 'The rules are not in uploads/.htaccess; missing files are served only if the server passes such requests to WordPress.', 'simple-storage' ),
			'htaccessStale'    => __( 'The rules in uploads/.htaccess are outdated; save the settings to refresh them.', 'simple-storage' ),
			'modeProxy'        => __( 'transparent proxy, addresses unchanged', 'simple-storage' ),
			'engineApache'     => __( 'Proxy: Apache serves the files itself, without PHP', 'simple-storage' ),
			'enginePhp'        => __( 'Proxy: the proxy.php script (Apache cannot proxy to the storage on this server)', 'simple-storage' ),
			'probe'            => __( 'Check the proxy engine again', 'simple-storage' ),
			'probing'          => __( 'Checking…', 'simple-storage' ),
			'checked'          => __( 'checked', 'simple-storage' ),
			'verifiedFor'      => __( 'Serving verified for:', 'simple-storage' ),
			'notVerified'      => __( 'Serving is not verified for the current settings yet, so new uploads stay in WordPress. Save the settings or move the files to run the check.', 'simple-storage' ),
			'modeDirect'       => __( 'directly from the storage', 'simple-storage' ),
			'autoOn'           => __( 'New uploads are moved to the storage automatically.', 'simple-storage' ),
			'autoOff'          => __( 'New uploads stay in WordPress.', 'simple-storage' ),
			'testing'          => __( 'Testing…', 'simple-storage' ),
			'testOk'           => __( 'Connection works.', 'simple-storage' ),
			'testFailed'       => __( 'Connection test failed.', 'simple-storage' ),
			'never'            => __( 'never', 'simple-storage' ),
			'notTested'        => __( 'Not tested yet', 'simple-storage' ),
			'noErrors'         => __( 'No errors.', 'simple-storage' ),
			'leave'            => __( 'A job is running. Leaving the page pauses it.', 'simple-storage' ),
			'busy'             => __( 'The job is being processed in another tab, request or WP-CLI; this page follows its progress.', 'simple-storage' ),
		);
	}
}
