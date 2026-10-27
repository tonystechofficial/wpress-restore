<?php
/**
 * Names of the backups this plugin makes.
 *
 * @package WPressRestore
 */

namespace WPressRestore;

/**
 * Names a backup the way All-in-One WP Migration does: host, folder, date, time, random part.
 */
final class Backup_Name {

	/**
	 * @param string $site_url Site address.
	 * @param string $datetime Date and time, as Ymd-His.
	 * @param string $random   Random part.
	 * @return string
	 */
	public static function for_site( $site_url, $datetime, $random ) {
		$parts = array();
		$host  = explode( '.', (string) parse_url( $site_url, PHP_URL_HOST ) );
		$path  = explode( '/', (string) parse_url( $site_url, PHP_URL_PATH ) );
		foreach ( array_merge( $host, $path ) as $part ) {
			$part = strtolower( (string) preg_replace( '/[^A-Za-z0-9\-]/', '', $part ) );
			if ( '' !== $part ) {
				$parts[] = $part;
			}
		}
		$parts[] = $datetime;
		$parts[] = $random;
		return implode( '-', $parts ) . '.wpress';
	}

	/** @return string 12 random lowercase letters and digits. */
	public static function random() {
		$alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
		$random   = '';
		for ( $i = 0; $i < 12; $i++ ) {
			$random .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
		}
		return $random;
	}
}
