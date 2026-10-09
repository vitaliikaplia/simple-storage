<?php
/**
 * Background work without WP-Cron.
 *
 * Many sites turn WP-Cron off (DISABLE_WP_CRON) and some never run wp-cron.php from the server
 * either, so the plugin's scheduled events would wait forever. This runner does the work at the
 * end of ordinary requests instead, after the response has been sent where the server allows it
 * (PHP-FPM, LiteSpeed), so nobody waits for it:
 * - files a request wrote go to the storage at its end: the files of an attachment it saved (the
 *   original, its sizes, a theme's WebP/AVIF copies), a size Timber cut on a page view, a file a
 *   theme announced with simple_storage_queue_offload(). Exactly these paths and nothing else of
 *   the folder: other requests may still be writing or using their files;
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

	/** @var array<string, bool> Media paths written in this request. */
	private static array $paths = array();

	public static function init(): void {
		add_action( 'shutdown', array( self::class, 'shutdown' ), PHP_INT_MAX );
	}

	/** An upload or a metadata change: the attachment's files go out at the end of this request. */
	public static function queue_attachment( int $attachment_id ): void {
		self::$attachments[ $attachment_id ] = true;
	}

	/** A media file this request wrote goes out at the end of it. */
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
	}

	public static function shutdown(): void {
		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || wp_installing() ) {
			return;
		}

		// Nobody may wait for this but the person who caused it: without a way to finish the
		// response first, only requests of a logged-in user who can upload run it (their uploads,
		// edits and admin pages). Visitors' requests run it only after their response is sent.
		$can_finish = function_exists( 'fastcgi_finish_request' ) || function_exists( 'litespeed_finish_request' );
		$uploader   = is_user_logged_in() && current_user_can( 'upload_files' );
		if ( ! $can_finish && ! $uploader ) {
			return;
		}

		// A request that died half-way may have left an upload without all its sizes; WordPress
		// finishes them in a follow-up request from the original, which must still be local then.
		// The folder's scheduled event takes the files later.
		$error = error_get_last();
		if ( is_array( $error ) && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
			return;
		}

		if ( empty( self::$attachments ) && empty( self::$paths ) && empty( self::due_events( self::cron_disabled() ) ) ) {
			return;
		}

		if ( PHP_SESSION_ACTIVE === session_status() ) {
			session_write_close();
		}
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}

		self::run( self::cron_disabled(), (float) ( $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime( true ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	/**
	 * Move the files this request wrote to the storage, then run the plugin's due events.
	 *
	 * @param float $request_start Only files changed since then count as written by this request.
	 */
	public static function run( bool $cron_disabled, float $request_start ): void {
		$started = microtime( true );
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		self::refresh_cron_cache();

		$paths = array_keys( self::$paths );
		foreach ( array_keys( self::$attachments ) as $attachment_id ) {
			$paths = array_merge( $paths, Simple_Storage_Media::upload_files( (int) $attachment_id ) );
		}
		self::$paths       = array();
		self::$attachments = array();

		$by_dir = array();
		foreach ( array_unique( $paths ) as $relative ) {
			if ( Simple_Storage_Paths::is_media_path( $relative ) ) {
				$by_dir[ dirname( $relative ) ][] = $relative;
			}
		}
		foreach ( $by_dir as $dir => $list ) {
			Simple_Storage_Media::offload_paths_now( (string) $dir, $list, $request_start );
		}

		self::refresh_cron_cache();
		$due = self::due_events( $cron_disabled );
		if ( empty( $due ) || get_transient( self::BUSY ) ) {
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

	/**
	 * The plugin's events that are due and that WP-Cron is not going to run: all due ones when it
	 * is off, otherwise only those it has let wait past the grace period.
	 *
	 * @return array<int, array{time: int, hook: string, args: array<int, mixed>}>
	 */
	public static function due_events( bool $cron_disabled ): array {
		$hooks = array( Simple_Storage_Media::OFFLOAD_HOOK, Simple_Storage_Media::DELETE_HOOK, Simple_Storage_Timber::GENERATE_HOOK );
		$limit = $cron_disabled ? time() : time() - self::GRACE;
		$due   = array();

		foreach ( (array) _get_cron_array() as $time => $events ) {
			if ( (int) $time > $limit ) {
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
