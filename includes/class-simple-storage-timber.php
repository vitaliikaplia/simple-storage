<?php
/**
 * Timber's on-the-fly image resizing for files that live only in the storage.
 *
 * Timber cuts a size on the first page view ("photo-768x0-c-default.jpg" next to the original)
 * and only from a local original: once the original lives only in the storage it falls back to
 * the full-size URL, and the sizes it cut before are never used again. The Twig "resize" filter
 * is taken over here so that
 * - a size that exists (locally or in the storage) is used without the original;
 * - a size that exists nowhere is cut in the background (a scheduled event) from the original
 *   brought back from the storage, and both go out again with the automatic offload; until then
 *   the page gets the full-size URL, as Timber itself would give it.
 * Code that calls Timber\ImageHelper::resize() directly can use simple_storage_timber_resize().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_Storage_Timber {
	public const GENERATE_HOOK = 'simple_storage_timber_resize';

	/** URL of the image Timber is working on, from its analyze_url filter. */
	private static ?string $source_url = null;

	/** @var array<string, bool> Whether a media path has a usable remote copy, per request. */
	private static array $in_storage = array();

	/** Whether the index knows any remote file, per request. */
	private static ?bool $has_remote = null;

	public static function init(): void {
		add_filter( 'timber/twig/filters', array( self::class, 'twig_filters' ) );
		add_filter( 'timber/image_helper/analyze_url', array( self::class, 'remember_source' ), 10, 2 );
		add_filter( 'timber/image/new_path', array( self::class, 'prepare_destination' ) );
		add_filter( 'wp_generate_attachment_metadata', array( self::class, 'forget_copies' ), 20, 2 );
		add_action( self::GENERATE_HOOK, array( self::class, 'generate' ), 10, 4 );
	}

	private static function available(): bool {
		return class_exists( '\Timber\ImageHelper' ) && method_exists( '\Timber\ImageHelper', 'get_resize_file_url' ) && method_exists( '\Timber\ImageHelper', 'get_resize_file_path' );
	}

	/**
	 * @param mixed $filters
	 * @return mixed
	 */
	public static function twig_filters( $filters ) {
		$callable = is_array( $filters ) ? ( $filters['resize']['callable'] ?? null ) : null;
		// Only Timber's own filter: a theme that put its own resize there keeps it.
		if ( is_array( $callable ) && 2 === count( $callable ) && is_string( $callable[0] ) && 'Timber\ImageHelper' === ltrim( $callable[0], '\\' ) && 'resize' === $callable[1] ) {
			$filters['resize']['callable'] = array( self::class, 'resize' );
		}

		return $filters;
	}

	/**
	 * Timber's resize with the storage in mind (see the class description).
	 *
	 * @param mixed      $src
	 * @param int|string $w
	 * @param int        $h
	 * @param mixed      $crop
	 * @param bool       $force
	 */
	public static function resize( $src, $w, $h = 0, $crop = 'default', $force = false ): string {
		if ( ! self::available() ) {
			return (string) $src;
		}

		$timber = static fn(): string => (string) \Timber\ImageHelper::resize( $src, $w, $h, $crop, $force );
		if ( ! is_numeric( $w ) && is_string( $w ) ) {
			// A registered size name, resolved the way Timber does it.
			$size = self::named_size( $w );
			if ( null === $size ) {
				return $timber();
			}
			list( $w, $h ) = $size;
		}
		if ( $force || ! is_string( $src ) || '' === $src || ! is_numeric( $w ) || ! is_numeric( $h ) || ! self::has_remote() ) {
			return $timber();
		}

		// Timber reads a root-relative URL against the home URL.
		$absolute = str_starts_with( $src, '/' ) && ! str_starts_with( $src, '//' ) ? untrailingslashit( network_home_url() ) . $src : $src;
		$source   = Simple_Storage_Paths::relative_from_url( $absolute );
		if ( null === $source ) {
			return $timber();
		}

		$target = Simple_Storage_Paths::relative_from_local( (string) \Timber\ImageHelper::get_resize_file_path( $src, $w, $h, $crop ) );
		if ( null === $target ) {
			return $timber();
		}
		$target_url = static fn(): string => (string) apply_filters( 'timber/image/new_url', \Timber\ImageHelper::get_resize_file_url( $src, $w, $h, $crop ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Timber's own filter.

		$target_local = is_file( Simple_Storage_Paths::local( $target ) );
		if ( is_file( Simple_Storage_Paths::local( $source ) ) ) {
			// Timber would cut a size that exists only in the storage again, under the same name.
			return ! $target_local && self::in_storage( $target ) ? $target_url() : $timber();
		}

		// The original lives only in the storage: Timber would fall back to it at full size.
		if ( $target_local || self::in_storage( $target ) ) {
			return $target_url();
		}
		if ( self::in_storage( $source ) ) {
			self::schedule( $source, $target, (int) $w, (int) $h, $crop );
		}

		return $timber();
	}

	/** @return array{0: int, 1: int}|null Width and height of a registered image size. */
	private static function named_size( string $name ): ?array {
		$sizes = wp_get_registered_image_subsizes();
		if ( empty( $sizes[ $name ] ) || ( empty( $sizes[ $name ]['width'] ) && empty( $sizes[ $name ]['height'] ) ) ) {
			return null;
		}

		return array( (int) $sizes[ $name ]['width'], (int) $sizes[ $name ]['height'] );
	}

	private static function has_remote(): bool {
		if ( null === self::$has_remote ) {
			self::$has_remote = Simple_Storage_Index::has_remote_files();
		}

		return self::$has_remote;
	}

	/**
	 * Whether a path is one of Timber's resized or letterboxed copies of a source file.
	 */
	private static function is_copy_of( string $target, string $source ): bool {
		$extension = pathinfo( $source, PATHINFO_EXTENSION );
		if ( '' === $extension || dirname( $target ) !== dirname( $source ) ) {
			return false;
		}

		$stem = preg_quote( dirname( $source ) . '/' . pathinfo( $source, PATHINFO_FILENAME ), '#' );
		$ext  = preg_quote( $extension, '#' );

		return 1 === preg_match( '#^' . $stem . '-(?:[0-9]+x[0-9]+-c-[a-z]+(?:-[a-z]+)?|lbox-[0-9]+x[0-9]+-[a-zA-Z0-9]+)\.' . $ext . '$#i', $target );
	}

	/**
	 * @param mixed $result
	 * @param mixed $url
	 * @return mixed
	 */
	public static function remember_source( $result, $url ) {
		self::$source_url = is_string( $url ) ? $url : null;

		return $result;
	}

	/**
	 * Timber cuts a size again whenever the file is missing locally. With a local original and the
	 * size in the storage (the original was brought back meanwhile), the stored size is fetched
	 * instead: a new copy under the same name would be a name conflict.
	 *
	 * @param mixed $path
	 * @return mixed
	 */
	public static function prepare_destination( $path ) {
		$source_url       = self::$source_url;
		self::$source_url = null;
		if ( ! is_string( $path ) || null === $source_url || is_file( $path ) ) {
			return $path;
		}

		// Timber may cut this file on a page view; if it does, the file goes to the storage at the
		// end of the request. Only a candidate here: Timber gives up when the source is missing.
		$written = Simple_Storage_Paths::relative_from_local( $path );
		if ( null !== $written && Simple_Storage_Media::auto_enabled() ) {
			Simple_Storage_Runner::queue_path( $written );
		}

		if ( ! self::has_remote() ) {
			return $path;
		}

		$source = Simple_Storage_Paths::relative_from_url( $source_url );
		$target = Simple_Storage_Paths::relative_from_local( $path );
		if ( null !== $source && is_file( Simple_Storage_Paths::local( $source ) ) && self::in_storage( $source ) ) {
			// An original brought back from the storage that Timber is about to read: keep it. Timber
			// asks for this path right before every read, so this covers direct calls too.
			Simple_Storage_Media::keep_local( $source );
		}
		// Only Timber's own resized copies: other operations (retina "@2x", tojpg, towebp) write
		// names that can be other attachments' files, which must stay protected conflicts.
		if ( null !== $source && null !== $target && self::is_copy_of( $target, $source ) && is_file( Simple_Storage_Paths::local( $source ) )
			&& self::in_storage( $target ) && ! Simple_Storage_Media::used_by_other_attachment( $target, '' ) ) {
			Simple_Storage_Media::ensure_local( $target );
		}

		return $path;
	}

	/**
	 * @param mixed $metadata
	 * @param mixed $attachment_id
	 * @return mixed
	 */
	public static function forget_copies( $metadata, $attachment_id ) {
		return self::available() ? Simple_Storage_Media::forget_timber_copies( $metadata, $attachment_id ) : $metadata;
	}

	private static function schedule( string $source, string $target, int $w, int $h, $crop ): void {
		if ( ! Simple_Storage_Media::auto_enabled() || get_transient( self::failed_key( $target ) ) ) {
			// Without the automatic offload the original would stay local for good; a size that
			// could not be cut is not tried again for a day.
			return;
		}

		$type = wp_check_filetype( $source );
		if ( empty( $type['type'] ) || 'image/svg+xml' === $type['type'] || ! wp_image_editor_supports( array( 'mime_type' => $type['type'] ) ) ) {
			return;
		}

		Simple_Storage_Runner::schedule_event( time(), self::GENERATE_HOOK, array( $source, $w, $h, $crop ) );
	}

	private static function failed_key( string $target ): string {
		return 'simple_storage_timber_failed_' . md5( $target );
	}

	/**
	 * Cron: cut one size of an image that lives only in the storage.
	 *
	 * @param mixed $source
	 * @param mixed $w
	 * @param mixed $h
	 * @param mixed $crop Timber crop mode; false stays false (Timber names it "f").
	 */
	public static function generate( $source, $w, $h, $crop ): void {
		$source = (string) $source;
		if ( ! self::available() || ! Simple_Storage_Media::auto_enabled() || ! Simple_Storage_Paths::is_media_path( $source ) ) {
			return;
		}

		$args = array( $source, (int) $w, (int) $h, $crop );
		$lock = Simple_Storage_Jobs::is_active() ? null : Simple_Storage_Jobs::lock();
		if ( null === $lock ) {
			// A job or an offload is changing files right now.
			Simple_Storage_Runner::schedule_event( time() + 5 * MINUTE_IN_SECONDS, self::GENERATE_HOOK, $args );

			return;
		}

		try {
			$url    = Simple_Storage_Paths::uploads()['baseurl'] . '/' . $source;
			$target = Simple_Storage_Paths::relative_from_local( (string) \Timber\ImageHelper::get_resize_file_path( $url, $args[1], $args[2], $crop ) );
			if ( null === $target || is_file( Simple_Storage_Paths::local( $target ) ) || self::in_storage( $target ) ) {
				return;
			}

			// ensure_local() also schedules the folder, so the original and the new size go out.
			if ( Simple_Storage_Media::ensure_local( $source ) ) {
				\Timber\ImageHelper::resize( $url, $args[1], $args[2], $crop );
			}
			if ( ! is_file( Simple_Storage_Paths::local( $target ) ) ) {
				set_transient( self::failed_key( $target ), 1, DAY_IN_SECONDS );
			}
		} finally {
			Simple_Storage_Jobs::unlock( $lock );
		}
	}

	private static function in_storage( string $relative ): bool {
		if ( ! isset( self::$in_storage[ $relative ] ) ) {
			$row                             = Simple_Storage_Index::get( $relative );
			self::$in_storage[ $relative ] = null !== $row && $row['remote'] && ! $row['conflict'];
		}

		return self::$in_storage[ $relative ];
	}

	/** Forget per-request answers (tests, long-running processes). */
	public static function reset(): void {
		self::$in_storage = array();
		self::$source_url = null;
		self::$has_remote = null;
	}
}
