<?php
/**
 * The restored site's theme.
 *
 * @package WPressRestore
 */

namespace WPressRestore;

/**
 * Picks the theme the restored site runs.
 *
 * All-in-One WP Migration blanks the theme in its dump and records it in package.json; older or
 * partial backups may not. The first installed choice wins: the package's, the dump's, then the
 * theme this site already runs, so the restored site never points at a theme that is not there.
 */
final class Theme_Choice {

	/**
	 * @param string[] $package   Template and stylesheet named by package.json.
	 * @param string[] $restored  Template and stylesheet in the restored options.
	 * @param string[] $live      Template and stylesheet this site runs now.
	 * @param callable $installed Whether a theme folder exists.
	 * @return string[] Template and stylesheet.
	 */
	public static function pick( array $package, array $restored, array $live, callable $installed ) {
		foreach ( array( $package, $restored ) as $candidate ) {
			list( $template, $stylesheet ) = array_map( 'strval', $candidate );
			if ( '' !== $template && '' !== $stylesheet && $installed( $template ) && $installed( $stylesheet ) ) {
				return array( $template, $stylesheet );
			}
		}
		return array_map( 'strval', $live );
	}
}
