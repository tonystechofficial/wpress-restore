<?php
/**
 * Plugins to run on the restored site.
 *
 * @package WPressRestore
 */

namespace WPressRestore;

/**
 * The restored site's active plugins, adjusted as All-in-One WP Migration does after an import.
 *
 * Plugins that force HTTPS are turned off on a site without HTTPS (they would redirect to an
 * address that does not answer), and plugins that move or guard the login page are turned off
 * so the owner can log in at wp-login.php. This plugin stays active.
 */
final class Plugin_List {

	const FORCE_HTTPS = array(
		'really-simple-ssl/rlrsssl-really-simple-ssl.php',
		'wordpress-https/wordpress-https.php',
		'wp-force-ssl/wp-force-ssl.php',
		'force-https-littlebizzy/force-https.php',
	);

	const LOCKOUT = array(
		'invisible-recaptcha/invisible-recaptcha.php',
		'wps-hide-login/wps-hide-login.php',
		'hide-my-wp/index.php',
		'hide-my-wordpress/index.php',
		'mycustomwidget/my_custom_widget.php',
		'lockdown-wp-admin/lockdown-wp-admin.php',
		'rename-wp-login/rename-wp-login.php',
		'wp-simple-firewall/icwp-wpsf.php',
		'join-my-multisite/joinmymultisite.php',
		'multisite-clone-duplicator/multisite-clone-duplicator.php',
		'wordpress-mu-domain-mapping/domain_mapping.php',
		'wordpress-starter/siteground-wizard.php',
		'pro-sites/pro-sites.php',
		'wpide/WPide.php',
		'page-optimize/page-optimize.php',
		'update-services/update-services.php',
	);

	/**
	 * @param mixed         $active       Active plugins in the restored options (possibly damaged; ai1wm empties them).
	 * @param string        $own          This plugin's basename.
	 * @param bool          $https        Whether this site is served over HTTPS.
	 * @param string[]      $from_package Plugins package.json lists as active, where ai1wm records them.
	 * @param callable|null $installed    Whether a plugin's main file is there; null to keep them all.
	 * @return array active, deactivated, missing.
	 */
	public static function for_restored_site( $active, $own, $https, array $from_package = array(), $installed = null ) {
		$kept        = array();
		$deactivated = array();
		$missing     = array();
		foreach ( array_merge( is_array( $active ) ? $active : array(), $from_package ) as $plugin ) {
			if ( ! is_string( $plugin ) || in_array( $plugin, $kept, true ) || in_array( $plugin, $deactivated, true ) || in_array( $plugin, $missing, true ) ) {
				continue;
			}
			if ( self::is_one_of( $plugin, self::LOCKOUT ) || ( ! $https && self::is_one_of( $plugin, self::FORCE_HTTPS ) ) ) {
				$deactivated[] = $plugin;
			} elseif ( $installed && $plugin !== $own && ! $installed( $plugin ) ) {
				$missing[] = $plugin;
			} else {
				$kept[] = $plugin;
			}
		}
		if ( ! in_array( $own, $kept, true ) ) {
			$kept[] = $own;
		}
		return array(
			'active'      => $kept,
			'deactivated' => $deactivated,
			'missing'     => $missing,
		);
	}

	/**
	 * All-in-One WP Migration and its extensions among this site's active plugins. Its backups
	 * leave them out of their plugin list, and ai1wm keeps itself active after an import: so does
	 * this restore, for a site that runs it.
	 *
	 * @param mixed $live This site's active plugins before the restore.
	 * @return string[]
	 */
	public static function servmask( $live ) {
		return array_values(
			array_filter(
				is_array( $live ) ? $live : array(),
				static function ( $plugin ) {
					return is_string( $plugin ) && 0 === strpos( $plugin, 'all-in-one-wp-migration' );
				}
			)
		);
	}

	/**
	 * Whether a plugin is one of $known, even installed under a renamed folder.
	 *
	 * @param string   $plugin Basename.
	 * @param string[] $known  Basenames.
	 * @return bool
	 */
	private static function is_one_of( $plugin, array $known ) {
		$slash  = strrpos( $plugin, '/' );
		$folder = false === $slash ? '' : substr( $plugin, 0, $slash );
		$file   = false === $slash ? $plugin : substr( $plugin, $slash + 1 );
		foreach ( $known as $candidate ) {
			list( $known_folder, $known_file ) = explode( '/', $candidate, 2 );
			if ( $file === $known_file && '' !== $folder && false !== strpos( $folder, $known_folder ) ) {
				return true;
			}
		}
		return false;
	}
}
