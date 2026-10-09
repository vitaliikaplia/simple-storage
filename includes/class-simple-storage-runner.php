<?php
/**
 * Background work without WP-Cron.
 *
 * Many sites turn WP-Cron off (DISABLE_WP_CRON) and some never run wp-cron.php from the server
 * either, so the plugin's scheduled events would wait forever. This runner does the work at the
 * end of ordinary requests instead, after the response has been sent where the server allows it
 * (PHP-FPM, LiteSpeed), so nobody waits for it:
 * - files a request wrote are copied to the storage at its end and verified: the files of an
 *   attachment it saved (the original, its sizes, a theme's WebP/AVIF copies), a size Timber cut
 *   on a page view, a file a theme announced with simple_storage_queue_offload(). Exactly these
 *   paths, and only those that exist. Their local copies go with the folder's scheduled run once
 *   they have settled, since an optimizer may still be rewriting them;
 * - due events of the plugin (offload of a folder, a retried remote deletion, a Timber size) run
 *   when WP-Cron is off, or when it has let them wait well past their time.
 * Scheduled events stay the record of pending work, so a site with a working WP-Cron behaves as
 * before, and nothing is lost when a run is cut short.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_Storage_Runner {
	/** A working WP-Cron gets this long to run an event itself before the runner steps in. */
	private const GRACE = 2 * MINUTE_IN_SECONDS;

	/** At most this many due events per request, and no new one after this many seconds. */
	private const MAX_EVENTS = 10;
	private const BUDGET = 30;

	/** Transient that keeps two requests from running the same due events. */
	private const BUSY = 'simple_storage_runner';

	/** @var array<int, bool> Attachments saved in this request. */
	private static array $attachments = array();

	/** @var array<string, bool> Media paths this request may have written. */
	private static array $paths = array();

	/** When the cron cache was last read again from the database in this request. */
	private static float $refreshed = 0.0;

	public static function init(): void {
		add_action( 'shutdown', array( self::class, 'shutdown' ), PHP_INT_MAX );
	}

	/** An upload or a metadata change: the attachment's files go out at the end of this request. */
	public static function queue_attachment( int $attachment_id ): void {
		self::$attachments[ $attachment_id ] = true;
	}

	/** A media file this request wrote (or may have written) goes out at the end of it. */
	public static function queue_path( string $relative ): void {
		self::$paths[ $relative ] = true;
	}

	public static function cron_disabled(): bool {
		return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	}

	/** Read the schedule from the database again: the cached copy may be minutes old. */
	public static function refresh_cron_cache(): void {
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'cron', 'options' );
		self::$refreshed = microtime( true );
	}

	/**
	 * Schedule a single event unless it is already there, deciding on a fresh copy of the schedule:
	 * the copy cached at the start of a request may be stale, and writing it back would drop
	 * events other requests added meanwhile.
	 *
	 * @param array<int, mixed> $args
	 */
	public static function schedule_event( int $timestamp, string $hook, array $args ): void {
		if ( microtime( true ) - self::$refreshed > 1 ) {
			self::refresh_cron_cache();
		}
		if ( false === wp_next_scheduled( $hook, $args ) ) {
			wp_schedule_single_event( $timestamp, $hook, $args );
		}
	}

	public static function shutdown(): void {
		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || wp_installing() ) {
			return;
		}

		self::finish_request(
			function_exists( 'fastcgi_finish_request' ) || function_exists( 'litespeed_finish_request' ),
			is_user_logged_in() && current_user_can( 'upload_files' ),
			is_admin() && ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ),
			(float) ( $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime( true ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		);
	}

	/**
	 * The end of a request.
	 *
	 * @param bool $can_finish Whether the response can be sent before the work (PHP-FPM, LiteSpeed).
	 * @param bool $uploader   Whether a logged-in user who can upload made the request.
	 * @param bool $admin_page Whether it is a plain admin page load (not AJAX or REST).
	 */
	public static function finish_request( bool $can_finish, bool $uploader, bool $admin_page, float $request_start ): void {
		// Files this request worked with stay local for the usual delay counted from now.
		Simple_Storage_Media::release_holds_after_request();

		// Only files that exist count: Timber announces sizes it may never write. Whatever happens
		// next, their folders get a scheduled run as a fallback.
		$written = self::take_written();
		foreach ( array_keys( self::by_month( $written ) ) as $dir ) {
			Simple_Storage_Media::schedule( (string) $dir );
		}

		// Nobody may wait for this but the person who caused it: without a way to finish the
		// response first, only a logged-in uploader's own files are copied, and due events run
		// one at a time on plain admin page loads. Visitors' requests run it only after their
		// response is sent.
		if ( ! $can_finish && ! $uploader ) {
			return;
		}

		// A request that died half-way may have left an upload without all its sizes; WordPress
		// finishes them in a follow-up request from the original. The folder's run takes them later.
		$error = error_get_last();
		if ( is_array( $error ) && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
			return;
		}

		$max_events = $can_finish ? self::MAX_EVENTS : ( $admin_page ? 1 : 0 );
		$has_due    = $max_events > 0 && ! get_transient( self::BUSY ) && self::has_due_events( self::cron_disabled() );
		if ( empty( $written ) && ! $has_due ) {
			return;
		}

		if ( $can_finish ) {
			if ( PHP_SESSION_ACTIVE === session_status() ) {
				session_write_close();
			}
			if ( function_exists( 'fastcgi_finish_request' ) ) {
				fastcgi_finish_request();
			} elseif ( function_exists( 'litespeed_finish_request' ) ) {
				litespeed_finish_request();
			}
		}

		self::copy_written( $written, $request_start );
		if ( $has_due ) {
			self::run_due( self::cron_disabled(), $max_events );
		}
		Simple_Storage_Media::release_holds_after_request();
	}

	/**
	 * Copy the files this request wrote to the storage, then run the plugin's due events.
	 *
	 * @param float $request_start Only files changed since then count as written by this request.
	 */
	public static function run( bool $cron_disabled, float $request_start, int $max_events = self::MAX_EVENTS ): void {
		self::copy_written( self::take_written(), $request_start );
		if ( $max_events > 0 && ! get_transient( self::BUSY ) ) {
			self::run_due( $cron_disabled, $max_events );
		}
		Simple_Storage_Media::release_holds_after_request();
	}

	/** @return array<int, string> Existing media files this request queued, taken off the queue. */
	private static function take_written(): array {
		$paths = array_keys( self::$paths );
		foreach ( array_keys( self::$attachments ) as $attachment_id ) {
			$paths = array_merge( $paths, Simple_Storage_Media::upload_files( (int) $attachment_id ) );
		}
		self::$paths       = array();
		self::$attachments = array();

		return array_values(
			array_filter(
				array_unique( array_map( 'strval', $paths ) ),
				static fn( string $relative ): bool => Simple_Storage_Paths::is_media_path( $relative ) && is_file( Simple_Storage_Paths::local( $relative ) )
			)
		);
	}

	/**
	 * @param array<int, string> $paths
	 * @return array<string, array<int, string>> Paths grouped by their YYYY/MM folder.
	 */
	private static function by_month( array $paths ): array {
		$by_month = array();
		foreach ( $paths as $relative ) {
			$by_month[ Simple_Storage_Paths::month_dir( $relative ) ][] = $relative;
		}

		return $by_month;
	}

	/** @param array<int, string> $written */
	private static function copy_written( array $written, float $request_start ): void {
		if ( empty( $written ) ) {
			return;
		}

		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		foreach ( self::by_month( $written ) as $dir => $list ) {
			Simple_Storage_Media::offload_paths_now( (string) $dir, $list, $request_start );
		}
	}

	private static function run_due( bool $cron_disabled, int $max_events ): void {
		$started = microtime( true );
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		self::refresh_cron_cache();
		$due = array_slice( self::due_events( $cron_disabled ), 0, $max_events );
		if ( empty( $due ) ) {
			return;
		}

		set_transient( self::BUSY, 1, 3 * MINUTE_IN_SECONDS );
		try {
			foreach ( $due as $event ) {
				if ( microtime( true ) - $started > self::BUDGET ) {
					break;
				}
				// Taken off the schedule first, as WP-Cron does: a handler that needs another run
				// schedules one itself.
				self::refresh_cron_cache();
				wp_unschedule_event( $event['time'], $event['hook'], $event['args'] );
				do_action_ref_array( $event['hook'], $event['args'] );
			}
		} finally {
			delete_transient( self::BUSY );
		}
	}

	/** Whether due work exists, read straight from the database without touching shared caches. */
	private static function has_due_events( bool $cron_disabled ): bool {
		global $wpdb;

		$raw  = $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'cron'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$cron = is_string( $raw ) ? maybe_unserialize( $raw ) : array();

		return ! empty( self::due_events( $cron_disabled, is_array( $cron ) ? $cron : array() ) );
	}

	/**
	 * The plugin's events that are due and that WP-Cron is not going to run: all due ones when it
	 * is off, otherwise only those it has let wait past the grace period.
	 *
	 * @param array<int|string, mixed>|null $cron The schedule to look at; the cached one when null.
	 * @return array<int, array{time: int, hook: string, args: array<int, mixed>}>
	 */
	public static function due_events( bool $cron_disabled, ?array $cron = null ): array {
		$hooks = array( Simple_Storage_Media::OFFLOAD_HOOK, Simple_Storage_Media::DELETE_HOOK, Simple_Storage_Timber::GENERATE_HOOK );
		$limit = $cron_disabled ? time() : time() - self::GRACE;
		$due   = array();

		foreach ( (array) ( $cron ?? _get_cron_array() ) as $time => $events ) {
			if ( ! is_numeric( $time ) || (int) $time > $limit || ! is_array( $events ) ) {
				continue;
			}
			foreach ( $hooks as $hook ) {
				foreach ( (array) ( $events[ $hook ] ?? array() ) as $event ) {
					$due[] = array(
						'time' => (int) $time,
						'hook' => $hook,
						'args' => (array) ( $event['args'] ?? array() ),
					);
					if ( count( $due ) >= self::MAX_EVENTS ) {
						return $due;
					}
				}
			}
		}

		return $due;
	}
}
