=== Simple Storage ===
Contributors: vitaliikaplia
Tags: storage, media, offload, hosting ukraine, uploads
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Moves WordPress media files to the Hosting Ukraine storage and back, with verification and serving from the storage.

== Description ==

Simple Storage frees the disk of a WordPress hosting by moving the media files to a Hosting Ukraine storage (https://www.ukraine.com.ua/storage/) through its content API, and brings them back when needed. The Media Library, attachment IDs and metadata stay exactly as they are.

* Media files are every file in the year and month folders of uploads (`uploads/YYYY/MM/…`, including subfolders): originals, thumbnails, `-scaled` images and the WebP/AVIF files of optimizers. Other folders of uploads and wp-content are not touched.
* The storage mirrors that structure under a folder of the site, `/{site folder}/YYYY/MM/…`, so several sites can share one storage.
* Move everything to the storage: copy → verify → enable serving from the storage → check serving end to end → delete local copies. A local file is deleted only after its remote copy has been verified (the size through the API and, in strict mode, the default, the SHA-256 of a download from the public address), after a probe file with the same extension that exists only in the storage was actually served at its uploads address, and after the storage confirmed the copy once more right before the delete. Files of an extension that fails the check stay local.
* Return everything to WordPress: download into a temporary file → verify → move into place with the original modification time → delete from the storage → disable serving from the storage.
* The jobs run in short AJAX steps with live progress and can be paused and continued; a closed tab only pauses them. The same jobs are available through WP-CLI.
* New uploads go to the storage at the end of the upload request, with their sizes and the WebP/AVIF copies a theme makes; sizes that Timber or a theme cuts on a page view follow at the end of that request. This never runs while a job is unfinished. Deleting an attachment deletes its files in the storage too, and new uploads never take the name of a file that already lives only in the storage. A different local file that appears at such a name anyway is reported as a conflict and never overwrites the remote original.
* Image editing keeps working for files that live only in the storage: crop, rotate, flip, scale and "Restore original image" in the classic editor, the Customizer crop and the crop of the Image block bring the original back first, and the edited files then follow into the storage.
* Thumbnail regeneration too: `wp media regenerate` and Regenerate Thumbnails get the original back; the new sizes replace the old ones in the storage, and old sizes that are no longer generated are deleted there as well (unless `--skip-delete`), never an original or another attachment's file. The automatic offload waits while a regeneration runs. With `--only-missing`, sizes that live only in the storage count as missing for WP-CLI, so such attachments are regenerated in full.
* Timber's on-the-fly resizing (`|resize` in Twig) uses a size from the storage without the original; a size that does not exist yet is cut in the background from the original brought back, and both go out again. Deleting an attachment deletes Timber's sizes and WebP/AVIF copies kept next to the image (`photo-jpg.webp`) in the storage too.

Two serving modes, switchable at any time without moving files:

* Transparent proxy (default): addresses stay `/wp-content/uploads/…`. A rule in `uploads/.htaccess` hands requests for missing files to Apache mod_proxy when the server allows it (the plugin probes this itself), or to `proxy.php`, a small script that streams the file from the storage without loading WordPress (Range, conditional requests and caching headers supported). Every uncached request costs the hosting a request and traffic, so this mode works best behind Cloudflare or another CDN.
* Directly from the storage: pages link to the storage or your own subdomain of it, and old addresses get a 302 redirect (301 optional).

In both modes a file is served from the storage only when it is missing locally, so every file stays reachable while it moves in either direction. On servers that ignore `.htaccess` but pass missing files to WordPress, the plugin does the same from WordPress.

== Installation ==

1. Install and activate Simple Storage. PHP needs the cURL extension.
2. In the hosting control panel, open the storage and the "Users and API" tab. Add a user with the Read, Write and Content rights, and give the "Public access" user only the Read right, so files open by direct links while the file list stays hidden.
3. Open Media > Simple Storage > Settings, enter the storage address, login and password, and use Test the connection.
4. On the Overview tab, index the media files to see their number and size, then use Move everything to the storage.

The login, password and storage address can also be defined as the `SIMPLE_STORAGE_LOGIN`, `SIMPLE_STORAGE_PASSWORD` and `SIMPLE_STORAGE_HOST` constants in `wp-config.php`; a password saved in the settings is stored encrypted with the WordPress salts.

WP-CLI: `wp simple-storage status`, `test`, `index`, `push`, `pull`, `resume`, `cancel`, `delivery on|off`.

For themes and plugins that work with media files themselves: `simple_storage_ensure_local( $attachment_id_or_path_or_url )` brings a file back from the storage, `simple_storage_file_exists( $path_or_url )` replaces `file_exists()` and knows files in the storage, `simple_storage_queue_offload( $path )` sends a file the code has just written to the storage at the end of the request, `simple_storage_timber_resize()` is `Timber\ImageHelper::resize()` for direct PHP calls, and the `simple_storage_attachment_files` filter adds files a theme keeps for an attachment outside its metadata.

== Frequently Asked Questions ==

= Is the storage a backup? =

No. Files that exist only in the storage exist only there. Hosting Ukraine blocks a storage when the balance runs out and deletes it irreversibly ten days later, so keep the balance positive and keep your own backups.

= What about code that reads image files from disk? =

WordPress image editing, thumbnail regeneration (WP-CLI and Regenerate Thumbnails) and Timber's resizing are handled. Other code that checks or reads the local file itself (generators of WebP copies, inline SVG, some optimizers) does not see files that live only in the storage; it can call `simple_storage_ensure_local()` or `simple_storage_file_exists()`, or the files can be returned to WordPress before such work.

= What if serving from the storage does not work on my server? =

Before any local file is deleted, the transfer puts a probe file into the storage and requests it through the site. If the server neither applies the `uploads/.htaccess` rules nor passes missing files to WordPress, the transfer stops with every local file in place.

= What happens when the plugin is deactivated or deleted? =

Deactivation keeps serving: the `.htaccess` rules and the proxy configuration stay in place. Deleting the plugin never deletes files in the storage; while some files exist only there, the rules are kept and turned into a redirect to the storage.

== Changelog ==

= 0.3.0 =
* Works without WP-Cron (DISABLE_WP_CRON with no server cron): new uploads go to the storage at the end of the upload request, sizes cut on page views (Timber, or a theme through simple_storage_queue_offload()) at the end of that request, and the plugin's delayed work (folder offloads, retried remote deletions, background Timber sizes) runs at the end of ordinary requests, after the response where the server allows it.

= 0.2.0 =
* First working release: indexing of the media folders, transfer to the storage and back with SHA-256 verification, serving through a transparent proxy (Apache mod_proxy or proxy.php) or directly from the storage, an end-to-end serving check per extension before local copies are deleted, automatic transfer of new uploads, deletion of remote copies with their attachments, protection against name conflicts, image editing and thumbnail regeneration (WP-CLI, Regenerate Thumbnails, Timber resizing) for files that live only in the storage, functions for themes, WP-CLI commands, updates from GitHub and a Ukrainian translation.

= 0.1.0 =
* Initial plugin skeleton.
