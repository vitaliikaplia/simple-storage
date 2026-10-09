<?php
/**
 * Functions for themes and plugins that work with media files themselves.
 *
 * A media file may live only in the storage: it is served at its usual address, but it is not on
 * the disk. Code that reads, checks or rewrites the file can ask for it with these functions.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Uploads-relative media path ("2024/05/photo.jpg") of an absolute path, an uploads URL or an
 * uploads-relative path; null for anything outside the year and month folders of uploads.
 *
 * @param string $target
 */
function simple_storage_media_path( $target ): ?string {
	$target = (string) $target;
	if ( '' === $target ) {
		return null;
	}
	if ( str_contains( $target, '//' ) && preg_match( '#^(?:https?:)?//#i', $target ) ) {
		return Simple_Storage_Paths::relative_from_url( $target );
	}

	$normalized = wp_normalize_path( $target );
	if ( str_starts_with( $normalized, Simple_Storage_Paths::uploads()['basedir'] . '/' ) ) {
		return Simple_Storage_Paths::relative_from_local( $normalized );
	}

	return Simple_Storage_Paths::is_media_path( $target ) ? $target : null;
}

/**
 * Make sure a media file is on the disk, downloading it from the storage when it lives only there
 * (verified like any transfer). The automatic offload moves it out again later.
 *
 * @param int|string $target Attachment ID (its file and original image), absolute path, uploads
 *                           URL or uploads-relative path.
 * @return bool Whether the file (every file of the attachment) is local now.
 */
function simple_storage_ensure_local( $target ): bool {
	if ( is_int( $target ) || ( is_string( $target ) && ctype_digit( $target ) ) ) {
		$attachment_id = (int) $target;
		$attached      = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( '' === $attached ) {
			return false;
		}

		$files = array( $attached );
		$meta  = wp_get_attachment_metadata( $attachment_id );
		if ( is_array( $meta ) && ! empty( $meta['original_image'] ) ) {
			$files[] = dirname( $attached ) . '/' . wp_basename( (string) $meta['original_image'] );
		}

		$local = true;
		foreach ( array_unique( $files ) as $file ) {
			$local = ( Simple_Storage_Paths::is_media_path( $file ) ? Simple_Storage_Media::ensure_local( $file ) : file_exists( Simple_Storage_Paths::local( $file ) ) ) && $local;
		}

		return $local;
	}

	$relative = simple_storage_media_path( (string) $target );

	return null !== $relative && Simple_Storage_Media::ensure_local( $relative );
}

/**
 * Whether a media file exists, locally or as a copy in the storage — the check to use instead of
 * file_exists() before linking to a file.
 *
 * @param string $target Absolute path, uploads URL or uploads-relative path.
 */
function simple_storage_file_exists( $target ): bool {
	$relative = simple_storage_media_path( (string) $target );
	if ( null === $relative ) {
		$target = (string) $target;

		return '' !== $target && ! preg_match( '#^(?:https?:)?//#i', $target ) && file_exists( $target );
	}
	if ( file_exists( Simple_Storage_Paths::local( $relative ) ) ) {
		return true;
	}

	$row = Simple_Storage_Index::has_remote_files() ? Simple_Storage_Index::get( $relative ) : null;

	return null !== $row && $row['remote'] && ! $row['conflict'];
}

/**
 * Tell the plugin that code has just written a media file (a size cut on a page view, a WebP
 * copy): it goes to the storage at the end of the request, verified, and leaves the disk (later,
 * with the folder's scheduled run, where an image optimizer works in the background); that run
 * also takes the file should the request not get to it. Does nothing unless new files follow
 * into the storage.
 *
 * @param string $target Absolute path, uploads URL or uploads-relative path of the new file.
 */
function simple_storage_queue_offload( $target ): void {
	$relative = simple_storage_media_path( (string) $target );
	if ( null === $relative || ! Simple_Storage_Media::auto_enabled() ) {
		return;
	}

	Simple_Storage_Runner::queue_path( $relative );
}

/**
 * Timber\ImageHelper::resize() that also works for images living only in the storage: an existing
 * size is used without the original, a missing one is cut in the background.
 *
 * @param string $src  URL of the image.
 * @param int    $w    Width.
 * @param int    $h    Height, 0 to keep the ratio.
 * @param string $crop Timber crop mode.
 */
function simple_storage_timber_resize( $src, $w, $h = 0, $crop = 'default' ): string {
	return Simple_Storage_Timber::resize( $src, $w, $h, $crop );
}
