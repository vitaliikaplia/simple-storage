<?php
/**
 * Overview tab. The dynamic parts are filled in by assets/js/admin.js from the page state.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$simple_storage_settings_url = Simple_Storage_Admin::url( 'settings' );
?>
<div class="ss-notice-area" data-ss="notices"></div>

<?php if ( ! Simple_Storage_Settings::is_configured() ) : ?>
	<div class="notice notice-info inline">
		<p>
			<?php esc_html_e( 'The storage is not connected yet. You can already index the media files; transfers need the connection.', 'simple-storage' ); ?>
			<a href="<?php echo esc_url( $simple_storage_settings_url ); ?>"><?php esc_html_e( 'Connect the storage', 'simple-storage' ); ?></a>
		</p>
	</div>
<?php endif; ?>

<div class="ss-grid">
	<section class="ss-card">
		<h2><?php esc_html_e( 'Media files', 'simple-storage' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Files in the year and month folders of uploads, the ones added through the Media Library. Other folders in wp-content are not touched.', 'simple-storage' ); ?></p>
		<table class="ss-stats">
			<tbody>
				<tr class="ss-stats-total">
					<th scope="row"><?php esc_html_e( 'All media files', 'simple-storage' ); ?></th>
					<td data-ss="stat-total-files"></td>
					<td data-ss="stat-total-bytes"></td>
				</tr>
				<tr>
					<th scope="row"><span class="ss-dot ss-dot-local"></span><?php esc_html_e( 'Only in WordPress', 'simple-storage' ); ?></th>
					<td data-ss="stat-local_only-files"></td>
					<td data-ss="stat-local_only-bytes"></td>
				</tr>
				<tr>
					<th scope="row"><span class="ss-dot ss-dot-both"></span><?php esc_html_e( 'In WordPress and in the storage', 'simple-storage' ); ?></th>
					<td data-ss="stat-both-files"></td>
					<td data-ss="stat-both-bytes"></td>
				</tr>
				<tr>
					<th scope="row"><span class="ss-dot ss-dot-remote"></span><?php esc_html_e( 'Only in the storage', 'simple-storage' ); ?></th>
					<td data-ss="stat-remote_only-files"></td>
					<td data-ss="stat-remote_only-bytes"></td>
				</tr>
			</tbody>
		</table>
		<p class="ss-meta">
			<?php esc_html_e( 'Last indexed:', 'simple-storage' ); ?> <strong data-ss="indexed"></strong>
			<span data-ss="errors-link" hidden> · <a href="#ss-errors"><?php esc_html_e( 'Errors:', 'simple-storage' ); ?> <span data-ss="stat-errors"></span></a></span>
		</p>
		<p><button type="button" class="button" data-ss-start="index"><?php esc_html_e( 'Index media files', 'simple-storage' ); ?></button></p>
	</section>

	<section class="ss-card">
		<h2><?php esc_html_e( 'Storage', 'simple-storage' ); ?></h2>
		<table class="ss-props">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Connection', 'simple-storage' ); ?></th>
					<td data-ss="connection"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Storage address', 'simple-storage' ); ?></th>
					<td><code><?php echo esc_html( Simple_Storage_Settings::connection()['host'] ? Simple_Storage_Settings::connection()['host'] : '—' ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Files are served from', 'simple-storage' ); ?></th>
					<td><code><?php echo esc_html( Simple_Storage_Settings::is_configured() ? Simple_Storage_Paths::public_prefix() : '—' ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Serving from the storage', 'simple-storage' ); ?></th>
					<td>
						<span data-ss="delivery"></span>
						<p class="description" data-ss="engine"></p>
						<p class="description" data-ss="verified"></p>
						<p class="description" data-ss="htaccess"></p>
						<p data-ss="probe-wrap" hidden><button type="button" class="button button-small" data-ss="probe"></button></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'New uploads', 'simple-storage' ); ?></th>
					<td data-ss="auto"></td>
				</tr>
			</tbody>
		</table>
		<p>
			<button type="button" class="button" data-ss="delivery-toggle"></button>
			<a class="button button-link" href="<?php echo esc_url( $simple_storage_settings_url ); ?>"><?php esc_html_e( 'Settings', 'simple-storage' ); ?></a>
		</p>
	</section>
</div>

<section class="ss-card ss-transfer">
	<h2><?php esc_html_e( 'Transfer', 'simple-storage' ); ?></h2>
	<div class="ss-transfer-actions">
		<div>
			<button type="button" class="button button-primary button-hero" data-ss-start="push"><?php esc_html_e( 'Move everything to the storage', 'simple-storage' ); ?></button>
			<p class="description"><?php esc_html_e( 'Copy → verify → enable serving from the storage → delete local copies. A local file is deleted only after its copy in the storage has been verified.', 'simple-storage' ); ?></p>
		</div>
		<div>
			<button type="button" class="button button-hero" data-ss-start="pull"><?php esc_html_e( 'Return everything to WordPress', 'simple-storage' ); ?></button>
			<p class="description"><?php esc_html_e( 'Download → verify → delete from the storage → disable serving from the storage. The result is a regular WordPress with all media in uploads.', 'simple-storage' ); ?></p>
		</div>
	</div>
</section>

<section class="ss-card ss-job" data-ss="job" hidden>
	<div class="ss-job-head">
		<h2 data-ss="job-title"></h2>
		<span class="ss-badge" data-ss="job-status"></span>
	</div>
	<p class="ss-job-meta" data-ss="job-meta"></p>
	<ol class="ss-phases" data-ss="job-phases"></ol>
	<p class="ss-job-current" data-ss="job-current"></p>
	<div data-ss="job-message"></div>
	<ul class="ss-warnings" data-ss="job-warnings"></ul>
	<p class="ss-job-buttons" data-ss="job-buttons"></p>
</section>

<section class="ss-card" id="ss-errors">
	<h2><?php esc_html_e( 'Files with errors', 'simple-storage' ); ?></h2>
	<div data-ss="errors"></div>
	<p><button type="button" class="button button-small" data-ss-clear="errors"><?php esc_html_e( 'Clear the error list', 'simple-storage' ); ?></button></p>
</section>

<section class="ss-card">
	<h2><?php esc_html_e( 'Recent events', 'simple-storage' ); ?></h2>
	<div data-ss="log"></div>
	<p><a href="<?php echo esc_url( Simple_Storage_Admin::url( 'log' ) ); ?>"><?php esc_html_e( 'Full log', 'simple-storage' ); ?></a></p>
</section>
