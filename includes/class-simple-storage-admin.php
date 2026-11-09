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

		foreach ( array( 'state', 'job', 'test', 'delivery', 'probe', 'verify', 'clear' ) as $action ) {
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

		// Serving must be proven again for the new settings before new uploads may leave. The same
		// runs from the "Check serving" button: WordPress calls this hook only for a real change.
		if ( Simple_Storage_Delivery::active() ) {
			Simple_Storage_Delivery::verify_current();
		}
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
				'settingsUrl' => self::url( 'settings' ),
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

		// An index job counts too: while it waits, new uploads stay on the disk.
		$job = Simple_Storage_Jobs::current();
		if ( null === $job || ! Simple_Storage_Jobs::is_active() ) {
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
		// Local files the storage holds under the same name and size, not compared yet.
		$listed   = Simple_Storage_Index::report( 'listed_unchecked', 0, $settings['last_remote_scan'] );
		// Locales such as uk use "&nbsp;" as the thousands separator; the page sets text, not HTML.
		$text     = static fn( string $value ): string => html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
		$format   = static fn( array $item ): array => array(
			/* translators: %s: number of files. */
			'files' => $text( sprintf( _n( '%s file', '%s files', $item['files'], 'simple-storage' ), number_format_i18n( $item['files'] ) ) ),
			'bytes' => $text( size_format( $item['bytes'], 1 ) ?: '0 B' ),
		);

		$situation = self::situation( $settings, $stats );

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
				'listed'      => $format(
					array(
						'files' => $listed['count'],
						'bytes' => $listed['bytes'],
					)
				),
				'errors'      => $text( number_format_i18n( (int) $stats['errors'] ) ),
				'indexed'     => $settings['last_index'] ? wp_date( 'd.m.Y H:i', $settings['last_index'] ) : '',
				'raw'         => array(
					'total'       => $stats['total']['files'],
					'local_only'  => $stats['local_only']['files'],
					'both'        => $stats['both']['files'],
					'remote_only' => $stats['remote_only']['files'],
					'listed'      => $listed['count'],
				),
				// Sizes for the bar of where the files are.
				'bytes'       => array(
					'local_only'  => $stats['local_only']['bytes'],
					'listed'      => $listed['bytes'],
					'both'        => $stats['both']['bytes'],
					'remote_only' => $stats['remote_only']['bytes'],
				),
			),
			'next'        => self::next_step( $situation ),
			'chips'       => self::step_chips( $situation ),
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
			'autoHeld'    => Simple_Storage_Jobs::is_active(),
			'mode'        => $settings['mode'],
			'errors'      => Simple_Storage_Index::errors( 20 ),
			'log'         => $log,
		);
	}

	/**
	 * Where the files are and how the site serves them, in the numbers the next-step suggestion and
	 * the step chips are made of.
	 *
	 * @param array<string, mixed> $settings Simple_Storage_Settings::state().
	 * @param array<string, mixed> $stats    Simple_Storage_Index::stats().
	 * @return array<string, mixed>
	 */
	public static function situation( array $settings, array $stats ): array {
		return array(
			'configured'  => Simple_Storage_Settings::is_configured(),
			'indexed'     => (int) $settings['last_index'] > 0,
			'job_active'  => Simple_Storage_Jobs::is_active(),
			'delivery'    => Simple_Storage_Delivery::active(),
			'mode'        => (string) $settings['mode'],
			'direction'   => (string) $settings['direction'],
			'local_only'  => (int) $stats['local_only']['files'],
			'unverified'  => Simple_Storage_Index::report( 'listed_unchecked', 0, (int) $settings['last_remote_scan'] )['count'],
			'both'        => (int) $stats['both']['files'],
			'both_bytes'  => (int) $stats['both']['bytes'],
			'remote_only' => (int) $stats['remote_only']['files'],
			'conflicts'   => Simple_Storage_Index::report( 'conflict', 0 )['count'],
		);
	}

	/**
	 * The one thing to do next, in the direction the site is moving: a sentence, the job its button
	 * starts ("settings" opens the settings, "" for none) and a tone (info, warning, success).
	 *
	 * @param array<string, mixed> $s self::situation().
	 * @return array{text: string, action: string, tone: string}|null Null while a job is unfinished.
	 */
	public static function next_step( array $s ): ?array {
		$step = static fn( string $text, string $action, string $tone = 'info' ): array => array(
			'text'   => $text,
			'action' => $action,
			'tone'   => $tone,
		);
		$size = static fn( int $bytes ): string => html_entity_decode( size_format( $bytes, 1 ) ?: '0 B', ENT_QUOTES, 'UTF-8' );

		if ( ! empty( $s['job_active'] ) ) {
			return null;
		}
		if ( empty( $s['configured'] ) ) {
			return $step( __( 'Connect the storage first: its address, login and password go in the settings.', 'simple-storage' ), 'settings' );
		}
		if ( empty( $s['indexed'] ) ) {
			return $step( __( 'Index the media files to see where they are.', 'simple-storage' ), 'index' );
		}
		if ( $s['conflicts'] > 0 ) {
			/* translators: %d: number of files. */
			return $step( sprintf( __( '%d files differ from the file at the same path on the other side. Rename or remove one of each pair (the error list names them), then index again.', 'simple-storage' ), $s['conflicts'] ), 'index', 'warning' );
		}
		if ( $s['unverified'] > 0 ) {
			/* translators: %d: number of files. */
			return $step( sprintf( __( 'The storage already holds %d files under the same names. Check them: nothing is uploaded, deleted or switched.', 'simple-storage' ), $s['unverified'] ), 'verify_remote' );
		}

		if ( 'local' === $s['direction'] ) {
			if ( $s['remote_only'] > 0 ) {
				return $s['delivery']
					/* translators: %d: number of files. */
					? $step( sprintf( __( '%d files are only in the storage. Copy them to WordPress.', 'simple-storage' ), $s['remote_only'] ), 'copy_local' )
					/* translators: %d: number of files. */
					: $step( sprintf( __( '%d files are only in the storage, and the site does not serve them. Copy them to WordPress.', 'simple-storage' ), $s['remote_only'] ), 'copy_local', 'warning' );
			}
			if ( $s['delivery'] ) {
				return $step( __( 'Every file in the storage is in WordPress too. Switch the site to WordPress.', 'simple-storage' ), 'switch_local' );
			}
			if ( $s['both'] > 0 ) {
				/* translators: 1: number of files, 2: size. */
				return $step( sprintf( __( 'The site serves its files from WordPress, while %1$d copies (%2$s) still take space in the storage. Free it: delete the copies in the storage.', 'simple-storage' ), $s['both'], $size( $s['both_bytes'] ) ), 'clean_remote' );
			}

			return $step( __( 'Everything is in WordPress, and the storage holds no copies.', 'simple-storage' ), '', 'success' );
		}

		if ( $s['remote_only'] > 0 && ! $s['delivery'] ) {
			/* translators: %d: number of files. */
			return $step( sprintf( __( '%d files are only in the storage, and the site does not serve them yet. Switch the site to the storage.', 'simple-storage' ), $s['remote_only'] ), 'adopt', 'warning' );
		}
		if ( $s['local_only'] > 0 ) {
			/* translators: %d: number of files. */
			return $step( sprintf( __( '%d files are only in WordPress. Copy them to the storage.', 'simple-storage' ), $s['local_only'] ), 'copy_remote' );
		}
		if ( ! $s['delivery'] && $s['both'] > 0 ) {
			return $step( __( 'Every file has a verified copy in the storage. Switch the site to the storage.', 'simple-storage' ), 'adopt' );
		}
		if ( $s['delivery'] && $s['both'] > 0 ) {
			/* translators: 1: number of files, 2: size. */
			return $step( sprintf( __( 'The site serves its files from the storage, while %1$d copies (%2$s) still take space on the hosting. Free it: delete the copies in WordPress.', 'simple-storage' ), $s['both'], $size( $s['both_bytes'] ) ), 'clean_local' );
		}
		if ( $s['delivery'] ) {
			return $step( __( 'Everything is in the storage, and the site serves it from there.', 'simple-storage' ), '', 'success' );
		}

		return $step( __( 'There are no media files yet.', 'simple-storage' ), '', 'success' );
	}

	/**
	 * A short note next to each step-by-step button: what it would do now. Tones: todo (there is
	 * work for it), done (nothing to do), wait (an earlier step comes first). Buttons stay usable:
	 * every job checks for itself.
	 *
	 * @param array<string, mixed> $s self::situation().
	 * @return array<string, array{text: string, tone: string}>
	 */
	public static function step_chips( array $s ): array {
		$chip = static fn( string $text, string $tone ): array => array(
			'text' => $text,
			'tone' => $tone,
		);
		$size = static fn( int $bytes ): string => html_entity_decode( size_format( $bytes, 1 ) ?: '0 B', ENT_QUOTES, 'UTF-8' );
		$num  = static fn( int $number ): string => html_entity_decode( number_format_i18n( $number ), ENT_QUOTES, 'UTF-8' );

		$in_storage = $s['both'] + $s['remote_only'] + $s['unverified'] > 0;
		$on_storage = $s['delivery'] && 'remote' === $s['mode'];
		$on_site    = ! $s['delivery'] && 'local' === $s['mode'];

		if ( $s['unverified'] > 0 ) {
			/* translators: %s: number of files. */
			$copy_remote = $chip( sprintf( __( '%s already there, not checked', 'simple-storage' ), $num( $s['unverified'] ) ), 'todo' );
		} elseif ( $s['local_only'] > 0 ) {
			/* translators: %s: number of files. */
			$copy_remote = $chip( sprintf( __( '%s to copy', 'simple-storage' ), $num( $s['local_only'] ) ), 'todo' );
		} else {
			$copy_remote = $chip( __( 'Nothing to copy', 'simple-storage' ), 'done' );
		}

		return array(
			'copy_remote'  => $copy_remote,
			'adopt'        => $on_storage ? $chip( __( 'Already so', 'simple-storage' ), 'done' ) : ( $in_storage ? $chip( __( 'Ready', 'simple-storage' ), 'todo' ) : $chip( __( 'The storage is empty', 'simple-storage' ), 'wait' ) ),
			/* translators: %s: size. */
			'clean_local'  => $s['local_only'] > 0 ? $chip( __( 'After step 1', 'simple-storage' ), 'wait' ) : ( $s['both'] > 0 ? $chip( sprintf( __( 'Frees %s', 'simple-storage' ), $size( $s['both_bytes'] ) ), 'todo' ) : $chip( __( 'Nothing to delete', 'simple-storage' ), 'done' ) ),
			/* translators: %s: number of files. */
			'copy_local'   => $s['remote_only'] > 0 ? $chip( sprintf( __( '%s to copy', 'simple-storage' ), $num( $s['remote_only'] ) ), 'todo' ) : $chip( __( 'Nothing to copy', 'simple-storage' ), 'done' ),
			'switch_local' => $on_site ? $chip( __( 'Already so', 'simple-storage' ), 'done' ) : ( $s['remote_only'] > 0 ? $chip( __( 'After step 1', 'simple-storage' ), 'wait' ) : $chip( __( 'Ready', 'simple-storage' ), 'todo' ) ),
			/* translators: %s: size. */
			'clean_remote' => $s['remote_only'] > 0 ? $chip( __( 'After step 1', 'simple-storage' ), 'wait' ) : ( $s['both'] > 0 ? $chip( sprintf( __( 'Frees %s', 'simple-storage' ), $size( $s['both_bytes'] ) ), 'todo' ) : $chip( __( 'Nothing to delete', 'simple-storage' ), 'done' ) ),
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
		$failed   = 0;
		$measured = Simple_Storage_Jobs::measured_phases();
		foreach ( $job['phases'] as $index => $phase ) {
			$progress = $job['progress'][ $phase ] ?? array();
			$failed  += (int) ( $progress['failed'] ?? 0 );
			$state    = $index < $job['phase'] ? 'done' : ( $index === $job['phase'] ? 'current' : 'pending' );
			if ( 'done' === $job['status'] ) {
				$state = 'done';
			}
			if ( in_array( $phase, (array) ( $job['skipped_phases'] ?? array() ), true ) ) {
				$state = 'pending';
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
		if ( 'done' === $job['status'] && $failed > 0 ) {
			$statuses['done'] = __( 'Finished with errors', 'simple-storage' );
		}

		return array(
			'type'        => $job['type'],
			'label'       => Simple_Storage_Jobs::type_label( (string) $job['type'] ),
			'status'      => $job['status'],
			'statusLabel' => $statuses[ $job['status'] ] ?? $job['status'],
			'failed'      => $failed,
			'report'      => Simple_Storage_Jobs::report_view( $job ),
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
	private static function respond( $result = null, string $message = '' ): void {
		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
					'state'   => self::state(),
				)
			);
		}

		wp_send_json_success(
			array(
				'state'   => self::state(),
				'message' => $message,
			)
		);
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
				self::respond( Simple_Storage_Jobs::start( $type, array( 'accept_missing' => ! empty( $_POST['accept'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
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
			case 'dismiss':
				Simple_Storage_Jobs::dismiss();
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
			self::respond_with_report( Simple_Storage_Delivery::verify_delivery( Simple_Storage_Delivery::extensions_to_verify() ) );
		} elseif ( 'disable' === $do ) {
			Simple_Storage_Delivery::disable();
			self::respond();
		}

		self::respond( new WP_Error( 'simple_storage_action', __( 'Unknown action.', 'simple-storage' ) ) );
	}

	public static function ajax_verify(): void {
		self::verify_request();
		self::respond_with_report( Simple_Storage_Delivery::verify_current() );
	}

	/**
	 * Report a serving check: nothing served is an error, a part of it a warning for the page.
	 *
	 * @param array{ok: array<int, string>, failed: array<string, string>}|WP_Error $report
	 */
	private static function respond_with_report( $report ): void {
		if ( is_wp_error( $report ) ) {
			self::respond( $report );
		}
		if ( empty( $report['ok'] ) ) {
			self::respond( new WP_Error( 'simple_storage_delivery_check', (string) reset( $report['failed'] ) ) );
		}

		$message = '';
		if ( ! empty( $report['failed'] ) ) {
			/* translators: %s: list of file extensions. */
			$message = sprintf( __( 'Serving does not work for these extensions, so their files stay local: %s. See the log for details.', 'simple-storage' ), implode( ', ', array_map( 'strval', array_keys( $report['failed'] ) ) ) );
		}
		self::respond( null, $message );
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

	/** @return array<string, string|array<string, string>> */
	private static function js_strings(): array {
		return array(
			'confirmPush'      => __( "All media files will be copied to the storage and verified, serving from the storage will be enabled, and then the local copies will be deleted from WordPress.\n\nContinue?", 'simple-storage' ),
			'confirmPull'      => __( "All files will be downloaded from the storage back into WordPress, verified, and then deleted from the storage.\n\nContinue?", 'simple-storage' ),
			'confirmAdopt'     => __( "The files in the storage will be compared with the Media Library. Nothing is copied or downloaded. If nothing is missing, the site starts serving them from the storage.\n\nContinue?", 'simple-storage' ),
			'confirmCopyRemote' => __( "Every file that is only in WordPress will be copied to the storage, and every copy verified. Nothing is deleted, and the site keeps serving its files as before.\n\nContinue?", 'simple-storage' ),
			'confirmCopyLocal' => __( "Every file that is only in the storage will be downloaded into WordPress and verified. Nothing is deleted. New uploads stay in WordPress from now on.\n\nContinue?", 'simple-storage' ),
			'confirmSwitchLocal' => __( "The files in WordPress will be compared with the storage and the Media Library. Nothing is copied or deleted. If every file is in WordPress, serving from the storage is turned off.\n\nContinue?", 'simple-storage' ),
			'confirmCleanLocal' => __( "The local copies will be deleted from WordPress, but only if every local file has a verified copy in the storage; otherwise nothing is deleted. Serving from the storage is turned on and checked first.\n\nContinue?", 'simple-storage' ),
			'confirmCleanRemote' => __( "The copies in the storage will be deleted, but only if every file in the storage is in WordPress too, with the same contents; otherwise nothing is deleted. The site then serves its files from WordPress.\n\nContinue?", 'simple-storage' ),
			'checkAgain'       => __( 'Check again', 'simple-storage' ),
			'factWordPress'    => __( 'WordPress', 'simple-storage' ),
			'factStorage'      => __( 'The storage', 'simple-storage' ),
			'factLocalFirst'   => __( 'a file still in WordPress is served from there', 'simple-storage' ),
			'uploadsLocal'     => __( 'Stay in WordPress', 'simple-storage' ),
			'uploadsStorage'   => __( 'Go to the storage', 'simple-storage' ),
			'uploadsHeld'      => __( 'Wait in WordPress until the job ends', 'simple-storage' ),
			'openSettings'     => __( 'Open the settings', 'simple-storage' ),
			'actions'          => array(
				'index'         => __( 'Index media files', 'simple-storage' ),
				'verify_remote' => __( 'Check the copies in the storage', 'simple-storage' ),
				'copy_remote'   => __( 'Copy everything to the storage', 'simple-storage' ),
				'adopt'         => __( 'Switch to the storage', 'simple-storage' ),
				'clean_local'   => __( 'Delete the copies in WordPress', 'simple-storage' ),
				'copy_local'    => __( 'Copy everything to WordPress', 'simple-storage' ),
				'switch_local'  => __( 'Switch to WordPress', 'simple-storage' ),
				'clean_remote'  => __( 'Delete the copies in the storage', 'simple-storage' ),
			),
			'attachments'      => __( 'attachments', 'simple-storage' ),
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
			'networkLost'      => __( 'The server did not respond, and the job could not be paused. It waits; open this page again when the connection is back.', 'simple-storage' ),
			'keepOpen'         => __( 'Keep this tab open while the job runs. If you close it, the job waits until this page is opened again, and new uploads stay in WordPress until then.', 'simple-storage' ),
			'on'               => __( 'On', 'simple-storage' ),
			'off'              => __( 'Off', 'simple-storage' ),
			'enable'           => __( 'Enable serving from the storage', 'simple-storage' ),
			'disable'          => __( 'Disable serving from the storage', 'simple-storage' ),
			'htaccessOk'       => __( 'The rules are written to uploads/.htaccess.', 'simple-storage' ),
			'htaccessMissing'  => __( 'The rules are not in uploads/.htaccess; missing files are served only if the server passes such requests to WordPress.', 'simple-storage' ),
			'htaccessStale'    => __( 'The rules in uploads/.htaccess are outdated; the "Check serving" button refreshes them.', 'simple-storage' ),
			'modeProxy'        => __( 'transparent proxy, addresses unchanged', 'simple-storage' ),
			'engineApache'     => __( 'Proxy: Apache serves the files itself, without PHP', 'simple-storage' ),
			'enginePhp'        => __( 'Proxy: the proxy.php script (Apache cannot proxy to the storage on this server)', 'simple-storage' ),
			'probe'            => __( 'Check the proxy engine again', 'simple-storage' ),
			'probing'          => __( 'Checking…', 'simple-storage' ),
			'checked'          => __( 'checked', 'simple-storage' ),
			'verifiedFor'      => __( 'Serving verified for:', 'simple-storage' ),
			'notVerified'      => __( 'Serving is not verified for the current settings yet, so new uploads stay in WordPress. Use the "Check serving" button.', 'simple-storage' ),
			'verify'           => __( 'Check serving', 'simple-storage' ),
			'verifyOk'         => __( 'Serving works.', 'simple-storage' ),
			'modeDirect'       => __( 'directly from the storage', 'simple-storage' ),
			'autoOn'           => __( 'New uploads are moved to the storage automatically.', 'simple-storage' ),
			'autoOff'          => __( 'New uploads stay in WordPress.', 'simple-storage' ),
			'autoHeld'         => __( 'New uploads wait in WordPress until the job on this page is finished or cancelled.', 'simple-storage' ),
			'testing'          => __( 'Testing…', 'simple-storage' ),
			'testOk'           => __( 'Connection works.', 'simple-storage' ),
			'testFailed'       => __( 'Connection test failed.', 'simple-storage' ),
			'never'            => __( 'never', 'simple-storage' ),
			'notTested'        => __( 'Not tested yet', 'simple-storage' ),
			'noErrors'         => __( 'No errors.', 'simple-storage' ),
			'leave'            => __( 'A job is running. It stops until this page is opened again.', 'simple-storage' ),
			'busy'             => __( 'The job is being processed in another tab, request or WP-CLI; this page follows its progress.', 'simple-storage' ),
		);
	}
}
