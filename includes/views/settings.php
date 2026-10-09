<?php
/**
 * Settings tab: storage connection and transfer options.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$simple_storage_values    = Simple_Storage_Settings::get();
$simple_storage_overrides = Simple_Storage_Settings::constant_overrides();
$simple_storage_name      = Simple_Storage_Settings::OPTION;
$simple_storage_locked    = Simple_Storage_Index::has_remote_files();
$simple_storage_constant  = static function ( string $constant ): void {
	printf(
		'<p class="description">%s</p>',
		/* translators: %s: constant name. */
		esc_html( sprintf( __( 'Set by the %s constant in wp-config.php.', 'simple-storage' ), $constant ) )
	);
};
?>
<div class="ss-settings">
	<form method="post" action="options.php" class="ss-card">
		<?php settings_fields( 'simple_storage' ); ?>
		<h2><?php esc_html_e( 'Storage connection', 'simple-storage' ); ?></h2>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="ss-host"><?php esc_html_e( 'Storage address', 'simple-storage' ); ?></label></th>
					<td>
						<?php if ( $simple_storage_overrides['host'] ) : ?>
							<code><?php echo esc_html( Simple_Storage_Settings::connection()['host'] ); ?></code>
							<?php $simple_storage_constant( 'SIMPLE_STORAGE_HOST' ); ?>
						<?php else : ?>
							<input type="text" id="ss-host" class="regular-text code" name="<?php echo esc_attr( $simple_storage_name ); ?>[host]" value="<?php echo esc_attr( (string) $simple_storage_values['host'] ); ?>" placeholder="a1b2c3d4e5f6g7h8.cdn.express" autocomplete="off">
							<p class="description"><?php esc_html_e( 'Shown at the top of the storage section in the hosting control panel.', 'simple-storage' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ss-login"><?php esc_html_e( 'Login', 'simple-storage' ); ?></label></th>
					<td>
						<?php if ( $simple_storage_overrides['login'] ) : ?>
							<code><?php echo esc_html( Simple_Storage_Settings::connection()['login'] ); ?></code>
							<?php $simple_storage_constant( 'SIMPLE_STORAGE_LOGIN' ); ?>
						<?php else : ?>
							<input type="text" id="ss-login" class="regular-text" name="<?php echo esc_attr( $simple_storage_name ); ?>[login]" value="<?php echo esc_attr( (string) $simple_storage_values['login'] ); ?>" autocomplete="off">
							<p class="description"><?php esc_html_e( 'A web access user of the storage ("Users and API" tab) with the Read, Write and Content rights.', 'simple-storage' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ss-password"><?php esc_html_e( 'Password', 'simple-storage' ); ?></label></th>
					<td>
						<?php if ( $simple_storage_overrides['password'] ) : ?>
							<?php $simple_storage_constant( 'SIMPLE_STORAGE_PASSWORD' ); ?>
						<?php else : ?>
							<input type="password" id="ss-password" class="regular-text" name="<?php echo esc_attr( $simple_storage_name ); ?>[password]" value="" autocomplete="new-password" placeholder="<?php echo Simple_Storage_Settings::has_stored_password() ? esc_attr__( 'Saved — leave empty to keep it', 'simple-storage' ) : ''; ?>">
							<p class="description"><?php esc_html_e( 'Stored encrypted with the WordPress salts. You can also define it as the SIMPLE_STORAGE_PASSWORD constant in wp-config.php.', 'simple-storage' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ss-public"><?php esc_html_e( 'Public address', 'simple-storage' ); ?></label></th>
					<td>
						<input type="text" id="ss-public" class="regular-text code" name="<?php echo esc_attr( $simple_storage_name ); ?>[public_url]" value="<?php echo esc_attr( (string) $simple_storage_values['public_url'] ); ?>" placeholder="https://media.example.com">
						<p class="description"><?php esc_html_e( 'Optional: your own domain connected to the storage. Empty means the storage address. Do not use the address of the site itself.', 'simple-storage' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ss-prefix"><?php esc_html_e( 'Site folder in the storage', 'simple-storage' ); ?></label></th>
					<td>
						<input type="text" id="ss-prefix" class="regular-text code" name="<?php echo esc_attr( $simple_storage_name ); ?>[prefix]" value="<?php echo esc_attr( Simple_Storage_Settings::prefix() ); ?>" <?php disabled( $simple_storage_locked ); ?>>
						<?php if ( $simple_storage_locked ) : ?>
							<input type="hidden" name="<?php echo esc_attr( $simple_storage_name ); ?>[prefix]" value="<?php echo esc_attr( Simple_Storage_Settings::prefix() ); ?>">
							<p class="description"><?php esc_html_e( 'Locked while the storage holds files of this site. Return them to WordPress to change it.', 'simple-storage' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Each site keeps its media in its own folder, so several sites can share one storage. The media keep their year/month structure inside it.', 'simple-storage' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>

		<h2><?php esc_html_e( 'Transfer and serving', 'simple-storage' ); ?></h2>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'How files are served', 'simple-storage' ); ?></th>
					<td>
						<fieldset class="ss-modes">
							<label>
								<input type="radio" name="<?php echo esc_attr( $simple_storage_name ); ?>[delivery_mode]" value="proxy" <?php checked( 'proxy', Simple_Storage_Settings::delivery_mode() ); ?>>
								<strong><?php esc_html_e( 'Transparent proxy', 'simple-storage' ); ?></strong>
							</label>
							<p class="description"><?php esc_html_e( 'Addresses stay exactly as they were (/wp-content/uploads/…). A small PHP script without WordPress streams each missing file from the storage. Every request that is not cached takes a PHP process and traffic of the site hosting, so it works best behind Cloudflare or another CDN.', 'simple-storage' ); ?></p>
							<label>
								<input type="radio" name="<?php echo esc_attr( $simple_storage_name ); ?>[delivery_mode]" value="direct" <?php checked( 'direct', Simple_Storage_Settings::delivery_mode() ); ?>>
								<strong><?php esc_html_e( 'Directly from the storage', 'simple-storage' ); ?></strong>
							</label>
							<p class="description"><?php esc_html_e( 'Pages link straight to the storage (or your subdomain of it) and old addresses are redirected there. The fastest option: the site does no work for media at all.', 'simple-storage' ); ?></p>
						</fieldset>
						<p class="description"><?php esc_html_e( 'The mode can be switched at any time; files do not move.', 'simple-storage' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Verification', 'simple-storage' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $simple_storage_name ); ?>[strict_verify]" value="1" <?php checked( (bool) $simple_storage_values['strict_verify'] ); ?>> <?php esc_html_e( 'Strict: download every copy from its public address and compare its SHA-256 with the local file', 'simple-storage' ); ?></label>
						<p class="description"><?php esc_html_e( 'Without it, the size in the storage and the size served at the public address are compared. Local files are deleted only after verification in both modes.', 'simple-storage' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'New uploads', 'simple-storage' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $simple_storage_name ); ?>[auto_offload]" value="1" <?php checked( (bool) $simple_storage_values['auto_offload'] ); ?>> <?php esc_html_e( 'Move new uploads to the storage automatically after everything has been moved', 'simple-storage' ); ?></label>
						<p class="description"><?php esc_html_e( 'A few minutes after an upload, once WordPress has created the thumbnails, the new files are copied, verified and deleted locally. If the storage is unavailable they stay in WordPress.', 'simple-storage' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ss-redirect"><?php esc_html_e( 'Redirect for old addresses', 'simple-storage' ); ?></label></th>
					<td>
						<select id="ss-redirect" name="<?php echo esc_attr( $simple_storage_name ); ?>[redirect_status]">
							<option value="302" <?php selected( 302, (int) $simple_storage_values['redirect_status'] ); ?>><?php esc_html_e( '302 — temporary (recommended)', 'simple-storage' ); ?></option>
							<option value="301" <?php selected( 301, (int) $simple_storage_values['redirect_status'] ); ?>><?php esc_html_e( '301 — permanent', 'simple-storage' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Only for the "Directly from the storage" mode: pages link to the storage, and the redirect catches old /wp-content/uploads/ links. Browsers remember a 301 for a long time, which breaks images for those visitors after the files are returned to WordPress.', 'simple-storage' ); ?></p>
					</td>
				</tr>
			</tbody>
		</table>
		<?php submit_button(); ?>
	</form>

	<aside class="ss-card ss-help">
		<h2><?php esc_html_e( 'Connection test', 'simple-storage' ); ?></h2>
		<p><?php esc_html_e( 'Uploads a small file to the site folder, reads it back through the public address and deletes it. Real media are not touched.', 'simple-storage' ); ?></p>
		<p><button type="button" class="button button-secondary" data-ss="test" <?php disabled( ! Simple_Storage_Settings::is_configured() ); ?>><?php esc_html_e( 'Test the connection', 'simple-storage' ); ?></button></p>
		<ol class="ss-test-steps" data-ss="test-steps"></ol>

		<h2><?php esc_html_e( 'Preparing the storage', 'simple-storage' ); ?></h2>
		<ol>
			<li><?php esc_html_e( 'In the hosting control panel, open the storage and the "Users and API" tab.', 'simple-storage' ); ?></li>
			<li><?php esc_html_e( 'Add a user with the Read, Write and Content rights and enter its login and password here.', 'simple-storage' ); ?></li>
			<li><?php esc_html_e( 'For the "Public access" user, enable only "Read": files open by direct links while the file list stays hidden.', 'simple-storage' ); ?></li>
			<li><?php esc_html_e( 'Optionally, on the "Settings" tab of the storage, set browser caching and the HTTP to HTTPS redirect, and connect your own subdomain for the public address.', 'simple-storage' ); ?></li>
		</ol>
	</aside>
</div>
