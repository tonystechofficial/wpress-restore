<?php
/**
 * Where archive entries go.
 *
 * @package WPressRestore
 */

namespace WPressRestore;

/**
 * Maps archive entries to this site's folders, and leaves out what must not be restored.
 *
 * Entries are relative to wp-content. plugins/, themes/, uploads/ and mu-plugins/ go wherever
 * this site keeps them, which may be outside wp-content. The left-out list follows
 * All-in-One WP Migration's import: config files and the dump, drop-ins tied to the old server,
 * hosting companies' must-use plugins, regenerated caches, plus this plugin and its working folder.
 */
final class Destination_Map {

	/** Root-level entries: config files, the dump, and drop-ins. */
	const SKIPPED_FILES = array(
		'package.json',
		'multisite.json',
		'database.sql',
		'advanced-cache.php',
		'blog-deleted.php',
		'blog-inactive.php',
		'blog-suspended.php',
		'db-error.php',
		'db.php',
		'fatal-error-handler.php',
		'install.php',
		'maintenance.php',
		'object-cache.php',
		'php-error.php',
		'sunrise.php',
	);

	/** Entries skipped with everything below them. */
	const SKIPPED_TREES = array(
		'ai1wm-backups',
		'themes/functions.php',
		'uploads/elementor/css',
		'uploads/civicrm/civicrm.settings.php',
		'uploads/civicrm/templates_c',
		'uploads/civicrm/ConfigAndLog',
		'uploads/civicrm/upload',
		'uploads/civicrm/dynamic',
		'mu-plugins/endurance-page-cache.php',
		'mu-plugins/endurance-php-edge.php',
		'mu-plugins/endurance-browser-cache.php',
		'mu-plugins/gd-system-plugin.php',
		'mu-plugins/wp-stack-cache.php',
		'mu-plugins/wpcomsh-loader.php',
		'mu-plugins/wpcomsh',
		'mu-plugins/mu-plugin.php',
		'mu-plugins/wpe-wp-sign-on-plugin.php',
		'mu-plugins/wpengine-security-auditor.php',
		'mu-plugins/aaa-wp-cerber.php',
		'mu-plugins/sqlite-database-integration',
		'mu-plugins/0-sqlite.php',
		'mu-plugins/eos-deactivate-plugins.php',
		'mu-plugins/wpress-restore-guard.php',
	);

	const SKIPPED_SUFFIXES = array( '.less.cache', '.sqlite' );

	/**
	 * wp-content, no trailing slash.
	 *
	 * @var string
	 */
	private $content_dir;

	/**
	 * Top-level folder name => absolute folder.
	 *
	 * @var string[]
	 */
	private $roots;

	/**
	 * Trees owned by this plugin.
	 *
	 * @var string[]
	 */
	private $own_trees;

	/**
	 * @param string   $content_dir wp-content.
	 * @param string[] $roots       Absolute folders for plugins, themes, uploads and mu-plugins.
	 * @param string   $own_slug    This plugin's folder name, also the name of its working folder.
	 */
	public function __construct( $content_dir, array $roots, $own_slug ) {
		$this->content_dir = rtrim( $content_dir, '/\\' );
		$this->roots       = array();
		foreach ( $roots as $top => $dir ) {
			$this->roots[ $top ] = rtrim( $dir, '/\\' );
		}
		$this->own_trees = array( 'plugins/' . $own_slug, $own_slug );
	}

	/**
	 * Absolute destination of an entry, or null when it is unsafe or left out.
	 *
	 * @param string $name Entry name.
	 * @return string|null
	 */
	public function resolve( $name ) {
		$relative = Entry_Path::normalize( $name );
		if ( null === $relative || $this->is_skipped( $relative ) ) {
			return null;
		}
		$parts = explode( '/', $relative, 2 );
		if ( isset( $this->roots[ $parts[0] ] ) ) {
			return isset( $parts[1] ) ? $this->roots[ $parts[0] ] . '/' . $parts[1] : null;
		}
		return $this->content_dir . '/' . $relative;
	}

	/**
	 * Whether a normalized entry is left out.
	 *
	 * @param string $relative Normalized entry name.
	 * @return bool
	 */
	private function is_skipped( $relative ) {
		if ( in_array( $relative, self::SKIPPED_FILES, true ) ) {
			return true;
		}
		foreach ( array_merge( self::SKIPPED_TREES, $this->own_trees ) as $tree ) {
			if ( $relative === $tree || 0 === strpos( $relative, $tree . '/' ) ) {
				return true;
			}
		}
		foreach ( self::SKIPPED_SUFFIXES as $suffix ) {
			if ( substr( $relative, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}
		return false;
	}
}
