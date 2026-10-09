<?php
/**
 * Log tab: operation log and files with errors.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$simple_storage_levels = array(
	'info'    => __( 'Info', 'simple-storage' ),
	'warning' => __( 'Warning', 'simple-storage' ),
	'error'   => __( 'Error', 'simple-storage' ),
);
?>
<section class="ss-card">
	<h2><?php esc_html_e( 'Log', 'simple-storage' ); ?></h2>
	<?php $simple_storage_entries = array_reverse( Simple_Storage_Log::entries() ); ?>
	<?php if ( empty( $simple_storage_entries ) ) : ?>
		<p><?php esc_html_e( 'The log is empty.', 'simple-storage' ); ?></p>
	<?php else : ?>
		<table class="widefat striped ss-log-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Time', 'simple-storage' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Level', 'simple-storage' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Message', 'simple-storage' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $simple_storage_entries as $simple_storage_entry ) : ?>
					<tr class="ss-level-<?php echo esc_attr( $simple_storage_entry['level'] ); ?>">
						<td><?php echo esc_html( wp_date( 'd.m.Y H:i:s', $simple_storage_entry['time'] ) ); ?></td>
						<td><?php echo esc_html( $simple_storage_levels[ $simple_storage_entry['level'] ] ?? $simple_storage_entry['level'] ); ?></td>
						<td><?php echo esc_html( $simple_storage_entry['message'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	<p><button type="button" class="button button-small" data-ss-clear="log"><?php esc_html_e( 'Clear the log', 'simple-storage' ); ?></button></p>
</section>

<section class="ss-card">
	<h2><?php esc_html_e( 'Files with errors', 'simple-storage' ); ?></h2>
	<?php $simple_storage_errors = Simple_Storage_Index::errors( 200 ); ?>
	<?php if ( empty( $simple_storage_errors ) ) : ?>
		<p><?php esc_html_e( 'No errors.', 'simple-storage' ); ?></p>
	<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'File', 'simple-storage' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Error', 'simple-storage' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $simple_storage_errors as $simple_storage_error ) : ?>
					<tr>
						<td><code><?php echo esc_html( $simple_storage_error['path'] ); ?></code></td>
						<td><?php echo esc_html( $simple_storage_error['error'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p><button type="button" class="button button-small" data-ss-clear="errors"><?php esc_html_e( 'Clear the error list', 'simple-storage' ); ?></button></p>
	<?php endif; ?>
</section>
