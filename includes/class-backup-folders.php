<?php
/**
 * The folders a backup holds.
 *
 * @package WPressRestore
 */

namespace WPressRestore;

/**
 * Says which folders go into a backup, under which archive names, and what stays out of each,
 * as All-in-One WP Migration lists them: wp-content, then uploads, plugins and themes wherever
 * they live. Backups never go into a backup, nor do the migration plugins, which each importer
 * brings itself.
 */
final class Backup_Folders {

	/**
	 * @param array $site    content, uploads, plugins, themes (folders), backups, work, guard (paths),
	 *                       own_plugin (this plugin's folder name), plugin_dirs (names in the plugins folder).
	 * @param array $options no_media, no_cache.
	 * @return array[] path, prefix (of the archive names), excluded (absolute paths).
	 */
	public static function for_site( array $site, array $options ) {
		$always  = array( $site['backups'], $site['work'], $site['content'] . '/ai1wm-backups' );
		$content = array_merge(
			$always,
			array( $site['uploads'], $site['plugins'] ),
			$site['themes'],
			array(
				// Importers read these at the archive root: a site's own copy must not pass for them.
				$site['content'] . '/package.json',
				$site['content'] . '/multisite.json',
				$site['content'] . '/database.sql',
				$site['content'] . '/w3tc-config/master.php',
				$site['guard'],
			)
		);
		if ( ! empty( $options['no_cache'] ) ) {
			$content[] = $site['content'] . '/cache';
		}
		$folders = array( self::folder( $site['content'], '', $content ) );
		if ( empty( $options['no_media'] ) ) {
			$folders[] = self::folder( $site['uploads'], 'uploads/', $always );
		}
		$plugins = $always;
		foreach ( $site['plugin_dirs'] as $name ) {
			if ( $name === $site['own_plugin'] || 'sqlite-database-integration' === $name || 0 === strpos( $name, 'all-in-one-wp-migration' ) ) {
				$plugins[] = $site['plugins'] . '/' . $name;
			}
		}
		$folders[] = self::folder( $site['plugins'], 'plugins/', $plugins );
		foreach ( $site['themes'] as $themes ) {
			$folders[] = self::folder( $themes, 'themes/', $always );
		}
		return $folders;
	}

	/**
	 * @param string   $path     Folder.
	 * @param string   $prefix   Archive name prefix.
	 * @param string[] $excluded Paths left out.
	 * @return array
	 */
	private static function folder( $path, $prefix, array $excluded ) {
		return array(
			'path'     => $path,
			'prefix'   => $prefix,
			'excluded' => $excluded,
		);
	}
}
