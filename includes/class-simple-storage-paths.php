<?php
/**
 * Mapping between uploads-relative media paths, local files, remote paths and public URLs.
 *
 * A media path is relative to the uploads directory and always starts with a year and a month
 * folder, e.g. "2024/05/photo-300x200.jpg". The storage mirrors it under the site prefix:
 * "/{prefix}/2024/05/photo-300x200.jpg".
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Simple_Storage_Paths {
	/** Suffix of partially downloaded files; such files are never indexed. */
	public const PART_SUFFIX = '.simple-storage-part';

	private const MEDIA_PATTERN = '#^[0-9]{4}/[0-9]{2}/.+#';

	/** @var array{basedir: string, baseurl: string}|null */
	private static ?array $uploads = null;

	/** @return array{basedir: string, baseurl: string} */
	public static function uploads(): array {
		if ( null === self::$uploads ) {
			$dir           = wp_get_upload_dir();
			self::$uploads = array(
				'basedir' => untrailingslashit( wp_normalize_path( (string) $dir['basedir'] ) ),
				'baseurl' => untrailingslashit( (string) $dir['baseurl'] ),
			);
		}

		return self::$uploads;
	}

	public static function reset(): void {
		self::$uploads = null;
	}

	/** True for a safe uploads-relative path inside a year/month folder. */
	public static function is_media_path( string $relative ): bool {
		if ( '' === $relative || ! preg_match( self::MEDIA_PATTERN, $relative ) ) {
			return false;
		}
		if ( str_contains( $relative, "\0" ) || str_contains( $relative, '\\' ) || str_contains( $relative, '//' ) ) {
			return false;
		}
		if ( str_ends_with( $relative, '/' ) || str_ends_with( $relative, self::PART_SUFFIX ) ) {
			return false;
		}

		foreach ( explode( '/', $relative ) as $segment ) {
			if ( '' === $segment || '.' === $segment[0] ) {
				return false;
			}
		}

		return true;
	}

	/** True for a "YYYY/MM" folder name. */
	public static function is_month_dir( string $relative ): bool {
		return 1 === preg_match( '#^[0-9]{4}/[0-9]{2}$#', $relative );
	}

	/** The "YYYY/MM" folder a media path belongs to, subfolders included. */
	public static function month_dir( string $relative ): string {
		return implode( '/', array_slice( explode( '/', $relative ), 0, 2 ) );
	}

	/** Lowercase file extension of a media path, or "" when the name has none. */
	public static function extension( string $relative ): string {
		return strtolower( (string) pathinfo( $relative, PATHINFO_EXTENSION ) );
	}

	public static function local( string $relative ): string {
		return self::uploads()['basedir'] . '/' . $relative;
	}

	/** Uploads-relative path for an absolute local path, or null outside the media folders. */
	public static function relative_from_local( string $absolute ): ?string {
		$absolute = wp_normalize_path( $absolute );
		$base     = self::uploads()['basedir'] . '/';
		if ( ! str_starts_with( $absolute, $base ) ) {
			return null;
		}

		$relative = substr( $absolute, strlen( $base ) );

		return self::is_media_path( $relative ) ? $relative : null;
	}

	public static function remote_root(): string {
		return '/' . Simple_Storage_Settings::prefix();
	}

	public static function remote( string $relative ): string {
		return self::remote_root() . '/' . $relative;
	}

	/** Uploads-relative path for a remote path under the site prefix, or null. */
	public static function relative_from_remote( string $remote ): ?string {
		$root = self::remote_root() . '/';
		if ( ! str_starts_with( $remote, $root ) ) {
			return null;
		}

		$relative = substr( $remote, strlen( $root ) );

		return self::is_media_path( $relative ) ? $relative : null;
	}

	public static function encode( string $path ): string {
		return implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );
	}

	/** Public URL of a media file in the storage. */
	public static function public_url( string $relative ): string {
		return Simple_Storage_Settings::public_base() . '/' . self::encode( Simple_Storage_Settings::prefix() ) . '/' . self::encode( $relative );
	}

	/** Public URL prefix that replaces "{uploads baseurl}/" in rewritten URLs. */
	public static function public_prefix(): string {
		return Simple_Storage_Settings::public_base() . '/' . self::encode( Simple_Storage_Settings::prefix() ) . '/';
	}

	/**
	 * Uploads-relative path for a URL inside the uploads base URL, ignoring scheme, query and fragment.
	 */
	public static function relative_from_url( string $url ): ?string {
		$base = self::strip_scheme( self::uploads()['baseurl'] ) . '/';
		$url  = self::strip_scheme( $url );
		if ( ! str_starts_with( $url, $base ) ) {
			return null;
		}

		$relative = substr( $url, strlen( $base ) );
		$relative = (string) preg_replace( '/[?#].*$/', '', $relative );
		$relative = rawurldecode( $relative );

		return self::is_media_path( $relative ) ? $relative : null;
	}

	private static function strip_scheme( string $url ): string {
		return (string) preg_replace( '#^(?:https?:)?//#i', '//', $url );
	}

	/** Path part of the uploads base URL, e.g. "/wp-content/uploads". */
	public static function uploads_url_path(): string {
		return untrailingslashit( (string) wp_parse_url( self::uploads()['baseurl'], PHP_URL_PATH ) );
	}
}
