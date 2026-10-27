<?php
/**
 * Removes what the plugin created: its working folder, the guard, and the temporary tables of an
 * abandoned restore. Backups belong to the owner and stay.
 *
 * @package WPressRestore
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$wpress_restore_work = WP_CONTENT_DIR . '/wpress-restore';
if ( is_dir( $wpress_restore_work ) ) {
	foreach ( (array) glob( $wpress_restore_work . '/job-*/job.json' ) as $wpress_restore_state ) {
		$wpress_restore_job = json_decode( (string) file_get_contents( $wpress_restore_state ), true );
		if ( is_array( $wpress_restore_job ) && ! empty( $wpress_restore_job['temp_prefix'] ) && preg_match( '/^wpr[a-f0-9]{6}_$/', $wpress_restore_job['temp_prefix'] ) ) {
			global $wpdb;
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpress_restore_job['temp_prefix'] ) . '%' ) ) as $wpress_restore_table ) {
				$wpdb->query( "DROP TABLE IF EXISTS `{$wpress_restore_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- names come from SHOW TABLES.
			}
		}
	}
	$wpress_restore_files = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $wpress_restore_work, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $wpress_restore_files as $wpress_restore_file ) {
		$wpress_restore_file->isDir() ? @rmdir( $wpress_restore_file->getPathname() ) : @unlink( $wpress_restore_file->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
	@rmdir( $wpress_restore_work ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
}
if ( is_file( WPMU_PLUGIN_DIR . '/wpress-restore-guard.php' ) ) {
	@unlink( WPMU_PLUGIN_DIR . '/wpress-restore-guard.php' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
}
