<?php
/**
 * Texts of the browser script.
 *
 * @package WPressRestore
 */

namespace WPressRestore;

/**
 * Translatable texts the admin script shows. Placeholders follow sprintf: %s, %1$s, %d.
 */
final class Strings {

	/** @return array */
	public static function all() {
		return array(
			'restore'        => __( 'Restore', 'wpress-restore' ),
			'delete'         => __( 'Delete', 'wpress-restore' ),
			'noBackups'      => __( 'No backup yet: upload one by FTP into the folder above, or with the uploader.', 'wpress-restore' ),
			'partial'        => __( 'Unfinished upload: choose the same file again to resume it.', 'wpress-restore' ),
			'confirmDelete'  => __( 'Delete %s from the server?', 'wpress-restore' ),
			'uploading'      => __( 'Uploading %1$s: %2$s of %3$s', 'wpress-restore' ),
			'uploadDone'     => __( '%s is on the server.', 'wpress-restore' ),
			'uploadFailed'   => __( 'The upload stopped: %s', 'wpress-restore' ),
			'replaceBackup'  => __( 'A backup named %s is already on the server. Replace it?', 'wpress-restore' ),
			'notWpress'      => __( 'Choose a .wpress file.', 'wpress-restore' ),
			'network'        => __( 'The server could not be reached.', 'wpress-restore' ),
			'retrying'       => __( 'No answer from the server. Trying again in %d seconds…', 'wpress-restore' ),
			'serverStopped'  => __( 'The server stopped answering during this step (HTTP %s). The progress is saved: retry when ready.', 'wpress-restore' ),
			'phases'         => array(
				'scanning'         => __( 'Reading the backup', 'wpress-restore' ),
				'verifying'        => __( 'Checking the backup is intact', 'wpress-restore' ),
				'extracting_db'    => __( 'Unpacking the database', 'wpress-restore' ),
				'importing_db'     => __( 'Importing the database', 'wpress-restore' ),
				'extracting_files' => __( 'Restoring files', 'wpress-restore' ),
				'finalizing'       => __( 'Switching the site to the restored database', 'wpress-restore' ),
			),
			'of'             => __( '%1$s of %2$s', 'wpress-restore' ),
			'restoring'      => __( 'Restoring %s', 'wpress-restore' ),
			'cancel'         => __( 'Cancel', 'wpress-restore' ),
			'retry'          => __( 'Retry', 'wpress-restore' ),
			'resume'         => __( 'Resume', 'wpress-restore' ),
			'showLog'        => __( 'Show the log', 'wpress-restore' ),
			'paused'         => __( 'This restore was interrupted: the page was closed or the connection dropped. Resume it or cancel it.', 'wpress-restore' ),
			'confirmCancel'  => __( 'Cancel this restore? The database stays as it is; files already restored stay too.', 'wpress-restore' ),
			'leaveWarning'   => __( 'A restore is running: leaving this page pauses it.', 'wpress-restore' ),
			'failedTitle'    => __( 'The restore stopped.', 'wpress-restore' ),
			'failedSafe'     => __( 'The site still runs on its own database: the restored one is only switched in at the very end.', 'wpress-restore' ),
			'summaryTitle'   => __( 'Check before restoring', 'wpress-restore' ),
			'from'           => __( 'Backup of', 'wpress-restore' ),
			'madeWith'       => __( 'Made with', 'wpress-restore' ),
			'madeWithValue'  => __( 'All-in-One WP Migration %1$s, WordPress %2$s, PHP %3$s', 'wpress-restore' ),
			'content'        => __( 'Files', 'wpress-restore' ),
			'filesCount'     => __( '%1$s files, %2$s', 'wpress-restore' ),
			'database'       => __( 'Database', 'wpress-restore' ),
			'none'           => __( 'none in this backup', 'wpress-restore' ),
			'plugins'        => __( 'Active plugins', 'wpress-restore' ),
			'theme'          => __( 'Theme', 'wpress-restore' ),
			'protection'     => __( 'Protection', 'wpress-restore' ),
			'encryptedNote'  => __( 'encrypted with a password', 'wpress-restore' ),
			'compressedNote' => __( 'compressed (%s)', 'wpress-restore' ),
			'password'       => __( 'Backup password', 'wpress-restore' ),
			'addressChange'  => __( 'Addresses in the content will move from %1$s to %2$s.', 'wpress-restore' ),
			'sameSite'       => __( 'This backup was made on this same site.', 'wpress-restore' ),
			'warnWordpress'  => __( 'The backup comes from WordPress %1$s and this site runs %2$s: update WordPress after the restore if something misbehaves.', 'wpress-restore' ),
			'warnPhp'        => __( 'The backup comes from PHP %1$s and this server runs PHP %2$s: some plugins may need the newer version.', 'wpress-restore' ),
			'warnDisk'       => __( 'The restore needs about %1$s of disk space and only %2$s seems free.', 'wpress-restore' ),
			'warnNoDatabase' => __( 'This backup has no database: only its files will be restored.', 'wpress-restore' ),
			'whatHappens'    => __( 'This replaces the site\'s database with the backup\'s, and overwrites plugins, themes and uploads with the backup\'s files. Files that exist only on this site are kept.', 'wpress-restore' ),
			'loginAfter'     => __( 'At the end you will be logged out: log in with a username and password from the backup.', 'wpress-restore' ),
			'understand'     => __( 'I understand: replace this site with the backup', 'wpress-restore' ),
			'restoreNow'     => __( 'Restore now', 'wpress-restore' ),
			'doneTitle'      => __( 'The site is restored.', 'wpress-restore' ),
			'doneLogin'      => __( 'Log in again with a username and password from the backup.', 'wpress-restore' ),
			'logIn'          => __( 'Log in', 'wpress-restore' ),
			'doneFiles'      => __( '%s files restored.', 'wpress-restore' ),
			'doneRows'       => __( '%s database rows imported.', 'wpress-restore' ),
			'failedFiles'    => __( '%s files could not be written: see the log.', 'wpress-restore' ),
			'failedRows'     => __( '%s database rows were refused by the server: see the log.', 'wpress-restore' ),
			'refused'        => __( '%s statements of the dump were left out because they did not belong to the backup\'s tables: see the log.', 'wpress-restore' ),
			'deactivated'    => __( 'Turned off so you can log in and reach the site: %s. Turn them back on once you have checked it.', 'wpress-restore' ),
			'missing'        => __( 'Active in the backup but not in it, so left off: %s.', 'wpress-restore' ),
			'permalinks'     => __( 'If some pages answer "not found", open Settings → Permalinks and click Save.', 'wpress-restore' ),
			'restoredOn'     => __( 'Last restore: %1$s, finished on %2$s.', 'wpress-restore' ),
			'deleteBackup'   => __( 'Delete the backup file', 'wpress-restore' ),
			'dismiss'        => __( 'Close', 'wpress-restore' ),
			'busy'           => __( 'Another tab is running this restore.', 'wpress-restore' ),
		);
	}
}
