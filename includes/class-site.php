<?php
/**
 * Facts about this WordPress site.
 *
 * @package WPressRestore
 */

namespace WPressRestore;

/**
 * Where things live on this site, and what the restored data must point to.
 */
final class Site {

	/** Name of the working folder in wp-content. */
	const WORK_FOLDER = 'wpress-restore';

	/**
	 * The folder backups are read from: All-in-One WP Migration's, so its own backups show up too.
	 *
	 * @return string
	 */
	public static function backups_dir() {
		$dir = defined( 'AI1WM_BACKUPS_PATH' ) ? AI1WM_BACKUPS_PATH : WP_CONTENT_DIR . '/ai1wm-backups';
		return untrailingslashit( (string) apply_filters( 'wpress_restore_backups_dir', $dir ) );
	}

	/** @return string Folder holding the restore in progress. */
	public static function work_dir() {
		return WP_CONTENT_DIR . '/' . self::WORK_FOLDER;
	}

	/** @return string This plugin's basename, as listed in active_plugins. */
	public static function basename() {
		return plugin_basename( WPRESS_RESTORE_FILE );
	}

	/**
	 * Where archive entries are written.
	 *
	 * @return Destination_Map
	 */
	public static function destination_map() {
		return new Destination_Map(
			WP_CONTENT_DIR,
			array(
				'plugins'    => WP_PLUGIN_DIR,
				'themes'     => get_theme_root(),
				'uploads'    => wp_get_upload_dir()['basedir'],
				'mu-plugins' => WPMU_PLUGIN_DIR,
			),
			dirname( self::basename() )
		);
	}

	/**
	 * This site's addresses and paths, in the shape Replace_Pairs::build() takes.
	 *
	 * @return array
	 */
	public static function new_site() {
		$uploads      = wp_get_upload_dir();
		$basedir      = wp_normalize_path( $uploads['basedir'] );
		$root         = wp_normalize_path( ABSPATH );
		$uploads_path = 0 === strpos( $basedir, $root ) ? '/' . trim( substr( $basedir, strlen( $root ) ), '/' ) . '/' : '';
		return array(
			'site_url'     => site_url(),
			'home_url'     => home_url(),
			'uploads_url'  => trailingslashit( $uploads['baseurl'] ),
			'uploads_path' => $uploads_path,
			'absolute'     => ABSPATH,
			'content'      => WP_CONTENT_DIR,
		);
	}

	/** @return bool Whether visitors reach this site over HTTPS. */
	public static function uses_https() {
		return is_ssl() || 0 === strpos( home_url(), 'https://' );
	}

	/**
	 * Writes the files that keep a folder from being listed or, with $deny, read over the web.
	 *
	 * @param string $dir  Folder.
	 * @param bool   $deny Whether to refuse all web access (working folder) or only listings (backups folder).
	 */
	public static function protect( $dir, $deny ) {
		$files = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'.htaccess'  => $deny
				? "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n"
				: "<IfModule mod_mime.c>\n\tAddType application/octet-stream .wpress\n</IfModule>\n<IfModule mod_dir.c>\n\tDirectoryIndex index.php\n</IfModule>\n<IfModule mod_autoindex.c>\n\tOptions -Indexes\n</IfModule>\n",
			'web.config' => $deny
				? "<configuration>\n<system.webServer>\n<authorization>\n<deny users=\"*\" />\n</authorization>\n</system.webServer>\n</configuration>\n"
				: "<configuration>\n<system.webServer>\n<staticContent>\n<mimeMap fileExtension=\".wpress\" mimeType=\"application/octet-stream\" />\n</staticContent>\n<defaultDocument>\n<files>\n<add value=\"index.php\" />\n</files>\n</defaultDocument>\n<directoryBrowse enabled=\"false\" />\n</system.webServer>\n</configuration>\n",
		);
		wp_mkdir_p( $dir );
		foreach ( $files as $name => $content ) {
			if ( ! file_exists( $dir . '/' . $name ) ) {
				@file_put_contents( $dir . '/' . $name, $content ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a read-only folder is reported elsewhere.
			}
		}
	}
}
