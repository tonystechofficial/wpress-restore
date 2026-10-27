<?php
/**
 * The must-use plugin that isolates the restore's own requests.
 *
 * @package WPressRestore
 */

namespace WPressRestore;

/**
 * Installs and removes a must-use plugin that keeps other plugins and the theme from loading in
 * the restore's requests while their files are being replaced: half-written plugin code must not
 * break the requests that finish writing it. It touches no other request.
 */
final class Guard {

	const FILE = 'wpress-restore-guard.php';

	const TEMPLATE = <<<'PHP'
<?php
/**
 * Plugin Name: WPress Restore guard
 * Description: Present only while WPress Restore restores a backup: its own requests then load no other plugin and no theme, since their files are being replaced. Deleted when the restore ends.
 */
if (
	( defined( 'DOING_AJAX' ) && DOING_AJAX && isset( $_REQUEST['action'] ) && 'wpress_restore' === $_REQUEST['action'] )
	|| ( isset( $_GET['page'], $_SERVER['SCRIPT_NAME'] ) && 'wpress-restore' === $_GET['page'] && '/wp-admin/tools.php' === substr( $_SERVER['SCRIPT_NAME'], -19 ) )
) {
	add_filter( 'option_active_plugins', function () { return array( __BASENAME__ ); }, PHP_INT_MAX );
	add_filter( 'template', function () { return 'wpress-restore-no-theme'; }, PHP_INT_MAX );
	add_filter( 'stylesheet', function () { return 'wpress-restore-no-theme'; }, PHP_INT_MAX );
	add_filter( 'validate_current_theme', '__return_false' );
}

PHP;

	/** @return string */
	public static function path() {
		return WPMU_PLUGIN_DIR . '/' . self::FILE;
	}

	/**
	 * Installs the guard.
	 *
	 * @return bool Whether it is in place; a restore can run without it.
	 */
	public static function install() {
		if ( ! wp_mkdir_p( WPMU_PLUGIN_DIR ) ) {
			return false;
		}
		$code = str_replace( '__BASENAME__', var_export( Site::basename(), true ), self::TEMPLATE ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions -- writes a PHP literal.
		return false !== @file_put_contents( self::path(), $code ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- failure is returned.
	}

	/** Removes the guard. */
	public static function remove() {
		if ( is_file( self::path() ) ) {
			@unlink( self::path() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}
}
