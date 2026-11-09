<?php
/**
 * Overview tab. The dynamic parts are filled in by assets/js/admin.js from the page state.
 *
 * Top to bottom: where the files are and how the site serves them, the one suggested next step,
 * the current job, the move itself (step by step, or in one go), then the storage connection and
 * serving details, recent events and files with errors.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$simple_storage_settings_url = Simple_Storage_Admin::url( 'settings' );
?>
<div class="ss-notice-area" data-ss="notices"></div>

<section class="ss-card ss-status">
	<div class="ss-facts">
		<div class="ss-fact">
			<p class="ss-fact-label"><?php esc_html_e( 'The site serves media from', 'simple-storage' ); ?></p>
			<p class="ss-fact-value" data-ss="fact-source"></p>
			<p class="ss-fact-note" data-ss="fact-source-note"></p>
		</div>
		<div class="ss-fact">
			<p class="ss-fact-label"><?php esc_html_e( 'New uploads', 'simple-storage' ); ?></p>
			<p class="ss-fact-value" data-ss="fact-uploads"></p>
		</div>
		<div class="ss-fact">
			<p class="ss-fact-label"><?php esc_html_e( 'All media files', 'simple-storage' ); ?></p>
			<p class="ss-fact-value"><span data-ss="stat-total-files"></span> · <span data-ss="stat-total-bytes"></span></p>
		</div>
	</div>

	<div class="ss-spread" data-ss="spread" aria-hidden="true">
		<span class="ss-spread-local" data-ss="spread-local_only"></span>
		<span class="ss-spread-listed" data-ss="spread-listed"></span>
		<span class="ss-spread-both" data-ss="spread-both"></span>
		<span class="ss-spread-remote" data-ss="spread-remote_only"></span>
	</div>

	<table class="ss-stats">
		<tbody>
			<tr>
				<th scope="row"><span class="ss-dot ss-dot-local"></span><?php esc_html_e( 'Only in WordPress', 'simple-storage' ); ?></th>
				<td data-ss="stat-local_only-files"></td>
				<td data-ss="stat-local_only-bytes"></td>
			</tr>
			<tr class="ss-stats-sub" data-ss="stat-listed-row" hidden>
				<th scope="row"><span class="ss-dot ss-dot-listed"></span><?php esc_html_e( 'of them, the storage has a file of the same name and size, not checked yet', 'simple-storage' ); ?></th>
				<td data-ss="stat-listed-files"></td>
				<td data-ss="stat-listed-bytes"></td>
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
	<p class="description" data-ss="stat-listed-note" hidden><?php esc_html_e( 'A file of the same name in the storage counts as the copy only once its contents are checked: the "Check the copies in the storage" button does that and nothing else. "Copy everything to the storage", "Switch to the storage" and "Delete the copies in WordPress" check them too.', 'simple-storage' ); ?></p>

	<div class="ss-status-foot">
		<p class="ss-meta">
			<?php esc_html_e( 'Last indexed:', 'simple-storage' ); ?> <strong data-ss="indexed"></strong>
			<span data-ss="errors-link" hidden> · <a href="#ss-errors"><?php esc_html_e( 'Errors:', 'simple-storage' ); ?> <span data-ss="stat-errors"></span></a></span>
		</p>
		<p class="ss-actions">
			<button type="button" class="button" data-ss-start="index"><?php esc_html_e( 'Index media files', 'simple-storage' ); ?></button>
			<button type="button" class="button" data-ss-start="verify_remote"><?php esc_html_e( 'Check the copies in the storage', 'simple-storage' ); ?></button>
		</p>
	</div>
	<p class="description"><?php esc_html_e( 'Files in the year and month folders of uploads, the ones added through the Media Library. Other folders in wp-content are not touched.', 'simple-storage' ); ?></p>
	<p class="description"><?php esc_html_e( 'Indexing lists what is in WordPress and in the storage. The check also compares the contents of the files the storage holds under the same names (uploaded by hand, for example) and counts them as copies; it uploads, deletes and switches nothing.', 'simple-storage' ); ?></p>
</section>

<section class="ss-card ss-next" data-ss="next" hidden>
	<p class="ss-next-label"><?php esc_html_e( 'Next step', 'simple-storage' ); ?></p>
	<p class="ss-next-text" data-ss="next-text"></p>
	<p class="ss-next-action" data-ss="next-action"></p>
</section>

<section class="ss-card ss-job" data-ss="job" hidden>
	<div class="ss-job-head">
		<h2 data-ss="job-title"></h2>
		<span class="ss-badge" data-ss="job-status"></span>
	</div>
	<p class="ss-job-meta" data-ss="job-meta"></p>
	<details class="ss-phases-wrap" data-ss="job-phases-wrap" open>
		<summary><?php esc_html_e( 'Stages of the job', 'simple-storage' ); ?></summary>
		<ol class="ss-phases" data-ss="job-phases"></ol>
	</details>
	<p class="ss-job-current" data-ss="job-current"></p>
	<div data-ss="job-message"></div>
	<div class="ss-report" data-ss="job-report"></div>
	<ul class="ss-warnings" data-ss="job-warnings"></ul>
	<p class="ss-job-buttons" data-ss="job-buttons"></p>
</section>

<section class="ss-card ss-move">
	<div class="ss-move-head">
		<h2><?php esc_html_e( 'Move', 'simple-storage' ); ?></h2>
		<div class="ss-segmented" role="group" aria-label="<?php esc_attr_e( 'How to move', 'simple-storage' ); ?>">
			<button type="button" class="button" data-ss-view="steps" aria-pressed="true"><?php esc_html_e( 'Step by step', 'simple-storage' ); ?></button>
			<button type="button" class="button" data-ss-view="oneclick" aria-pressed="false"><?php esc_html_e( 'In one go', 'simple-storage' ); ?></button>
		</div>
	</div>

	<div data-ss-pane="steps">
		<p class="description"><?php esc_html_e( 'For a move done by hand or in stages, and for a site whose files are partly in WordPress and partly in the storage. Copying never deletes anything. Switching copies nothing: it checks the files and changes where the site serves them from. Deleting happens only when every file has a verified copy on the other side; otherwise nothing is deleted and the job names what is missing.', 'simple-storage' ); ?></p>
		<div class="ss-lanes">
			<div class="ss-lane">
				<h3><?php esc_html_e( 'To the storage', 'simple-storage' ); ?></h3>
				<ol class="ss-lane-steps">
					<li>
						<div class="ss-step-row">
							<button type="button" class="button" data-ss-start="copy_remote"><?php esc_html_e( 'Copy everything to the storage', 'simple-storage' ); ?></button>
							<span class="ss-chip" data-ss-chip="copy_remote"></span>
						</div>
						<p class="description"><?php esc_html_e( 'Uploads what is only in WordPress and verifies every copy; files already in the storage (uploaded by hand) are verified, not uploaded again. Nothing is deleted.', 'simple-storage' ); ?></p>
					</li>
					<li>
						<div class="ss-step-row">
							<button type="button" class="button" data-ss-start="adopt"><?php esc_html_e( 'Switch to the storage', 'simple-storage' ); ?></button>
							<span class="ss-chip" data-ss-chip="adopt"></span>
						</div>
						<p class="description"><?php esc_html_e( 'Compares the storage with the Media Library and, when nothing is missing, turns on serving from the storage; new uploads follow there. Nothing is copied or deleted.', 'simple-storage' ); ?></p>
					</li>
					<li>
						<div class="ss-step-row">
							<button type="button" class="button ss-button-danger" data-ss-start="clean_local"><?php esc_html_e( 'Delete the copies in WordPress', 'simple-storage' ); ?></button>
							<span class="ss-chip" data-ss-chip="clean_local"></span>
						</div>
						<p class="description"><?php esc_html_e( 'Frees the hosting disk. Only if every local file has a verified copy in the storage: then serving from the storage is turned on and checked, and the local copies are deleted.', 'simple-storage' ); ?></p>
					</li>
				</ol>
			</div>
			<div class="ss-lane">
				<h3><?php esc_html_e( 'To WordPress', 'simple-storage' ); ?></h3>
				<ol class="ss-lane-steps">
					<li>
						<div class="ss-step-row">
							<button type="button" class="button" data-ss-start="copy_local"><?php esc_html_e( 'Copy everything to WordPress', 'simple-storage' ); ?></button>
							<span class="ss-chip" data-ss-chip="copy_local"></span>
						</div>
						<p class="description"><?php esc_html_e( 'Downloads what is only in the storage and verifies every file; new uploads stay in WordPress from now on. Nothing is deleted.', 'simple-storage' ); ?></p>
					</li>
					<li>
						<div class="ss-step-row">
							<button type="button" class="button" data-ss-start="switch_local"><?php esc_html_e( 'Switch to WordPress', 'simple-storage' ); ?></button>
							<span class="ss-chip" data-ss-chip="switch_local"></span>
						</div>
						<p class="description"><?php esc_html_e( 'For files brought back by hand: checks that every file in the storage and every file of the Media Library is in WordPress, then turns serving from the storage off. Nothing is copied or deleted.', 'simple-storage' ); ?></p>
					</li>
					<li>
						<div class="ss-step-row">
							<button type="button" class="button ss-button-danger" data-ss-start="clean_remote"><?php esc_html_e( 'Delete the copies in the storage', 'simple-storage' ); ?></button>
							<span class="ss-chip" data-ss-chip="clean_remote"></span>
						</div>
						<p class="description"><?php esc_html_e( 'Frees the storage. Only if every file in the storage is in WordPress too, with the same contents: then the copies in the storage are deleted and serving from it is turned off.', 'simple-storage' ); ?></p>
					</li>
				</ol>
			</div>
		</div>
		<p class="description">
			<?php
			printf(
				/* translators: 1: folder in the storage, 2: example path. */
				esc_html__( 'Step 1 can also be done by hand (FTP), for example for media that do not fit on the hosting: copy the year and month folders of uploads to %1$s (as in %2$s), or from there back into uploads, then continue with step 2.', 'simple-storage' ),
				'<code>' . esc_html( Simple_Storage_Paths::remote_root() ) . '</code>',
				'<code>' . esc_html( Simple_Storage_Paths::remote_root() . '/2024/05/photo.jpg' ) . '</code>'
			);
			?>
		</p>
	</div>

	<div data-ss-pane="oneclick" hidden>
		<div class="ss-transfer-actions">
			<div>
				<button type="button" class="button button-hero" data-ss-start="push"><?php esc_html_e( 'Move everything to the storage', 'simple-storage' ); ?></button>
				<p class="description"><?php esc_html_e( 'Copy → verify → enable serving from the storage → delete local copies. A local file is deleted only after its copy in the storage has been verified.', 'simple-storage' ); ?></p>
			</div>
			<div>
				<button type="button" class="button button-hero" data-ss-start="pull"><?php esc_html_e( 'Return everything to WordPress', 'simple-storage' ); ?></button>
				<p class="description"><?php esc_html_e( 'Download → verify → delete from the storage → disable serving from the storage. The result is a regular WordPress with all media in uploads.', 'simple-storage' ); ?></p>
			</div>
		</div>
	</div>
</section>

<div class="ss-grid">
	<section class="ss-card ss-storage">
		<div class="ss-card-head">
			<h2><?php esc_html_e( 'Storage and serving', 'simple-storage' ); ?></h2>
			<a href="<?php echo esc_url( $simple_storage_settings_url ); ?>"><?php esc_html_e( 'Settings', 'simple-storage' ); ?></a>
		</div>
		<?php if ( ! Simple_Storage_Settings::is_configured() ) : ?>
			<div class="notice notice-info inline">
				<p>
					<?php esc_html_e( 'The storage is not connected yet. You can already index the media files; transfers need the connection.', 'simple-storage' ); ?>
					<a href="<?php echo esc_url( $simple_storage_settings_url ); ?>"><?php esc_html_e( 'Connect the storage', 'simple-storage' ); ?></a>
				</p>
			</div>
		<?php endif; ?>
		<table class="ss-props">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Connection', 'simple-storage' ); ?></th>
					<td data-ss="connection"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Files are served from', 'simple-storage' ); ?></th>
					<td><code><?php echo esc_html( Simple_Storage_Settings::is_configured() ? Simple_Storage_Paths::public_prefix() : '—' ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Serving from the storage', 'simple-storage' ); ?></th>
					<td data-ss="delivery"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'New uploads', 'simple-storage' ); ?></th>
					<td data-ss="auto"></td>
				</tr>
			</tbody>
		</table>
		<details class="ss-advanced">
			<summary><?php esc_html_e( 'Advanced', 'simple-storage' ); ?></summary>
			<p class="description" data-ss="engine"></p>
			<p class="description" data-ss="verified"></p>
			<p class="description" data-ss="htaccess"></p>
			<p class="ss-checks" data-ss="checks" hidden>
				<button type="button" class="button button-small" data-ss="verify"></button>
				<button type="button" class="button button-small" data-ss="probe"></button>
			</p>
			<p class="description"><?php esc_html_e( 'Turning serving on or off by hand skips the checks the steps make: once it is off, files that exist only in the storage are not shown on the site.', 'simple-storage' ); ?></p>
			<p><button type="button" class="button" data-ss="delivery-toggle"></button></p>
		</details>
	</section>

	<section class="ss-card ss-recent">
		<div class="ss-card-head">
			<h2><?php esc_html_e( 'Recent events', 'simple-storage' ); ?></h2>
			<a href="<?php echo esc_url( Simple_Storage_Admin::url( 'log' ) ); ?>"><?php esc_html_e( 'Full log', 'simple-storage' ); ?></a>
		</div>
		<div data-ss="log"></div>
	</section>
</div>

<section class="ss-card" id="ss-errors" data-ss="errors-card" hidden>
	<h2><?php esc_html_e( 'Files with errors', 'simple-storage' ); ?></h2>
	<div data-ss="errors"></div>
	<p><button type="button" class="button button-small" data-ss-clear="errors"><?php esc_html_e( 'Clear the error list', 'simple-storage' ); ?></button></p>
	<p class="description"><?php esc_html_e( 'A name conflict stays on the list until one of the two files is renamed or removed and the files are indexed again.', 'simple-storage' ); ?></p>
</section>
