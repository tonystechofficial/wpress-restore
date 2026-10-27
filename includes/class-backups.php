<?php
/**
 * The backups folder.
 *
 * @package WPressRestore
 */

namespace WPressRestore;

/**
 * Lists and deletes the .wpress files in the backups folder, whatever put them there: FTP,
 * the uploader, or All-in-One WP Migration itself.
 */
final class Backups {

	/**
	 * Backups, newest first; unfinished uploads are flagged as partial.
	 *
	 * @return array[] name, size, mtime, partial, url (to download it, null when the folder has no address).
	 */
	public static function all() {
		$dir  = Site::backups_dir();
		$base = self::base_url();
		$list = array();
		foreach ( is_dir( $dir ) ? (array) scandir( $dir ) : array() as $name ) {
			$partial = (bool) preg_match( '/\.wpress\.part$/i', $name );
			if ( ( ! $partial && ! preg_match( '/\.wpress$/i', $name ) ) || ! is_file( $dir . '/' . $name ) ) {
				continue;
			}
			$list[] = array(
				'name'    => $name,
				'size'    => (int) filesize( $dir . '/' . $name ),
				'mtime'   => (int) filemtime( $dir . '/' . $name ),
				'partial' => $partial,
				'url'     => null === $base || $partial ? null : $base . '/' . rawurlencode( $name ),
			);
		}
		usort(
			$list,
			static function ( $a, $b ) {
				return $b['mtime'] - $a['mtime'];
			}
		);
		return $list;
	}

	/**
	 * The backups folder's web address, as All-in-One WP Migration links its downloads: the web
	 * server sends the file, so no PHP time limit cuts a large download. The folder refuses
	 * listings, and backup names end with a random part.
	 *
	 * @return string|null Null when the folder is outside the site.
	 */
	public static function base_url() {
		$dir = wp_normalize_path( Site::backups_dir() );
		foreach ( array(
			'content_url' => wp_normalize_path( untrailingslashit( WP_CONTENT_DIR ) ),
			'site_url'    => wp_normalize_path( untrailingslashit( ABSPATH ) ),
		) as $url => $root ) {
			if ( 0 === strpos( $dir . '/', $root . '/' ) ) {
				return untrailingslashit( $url( implode( '/', array_map( 'rawurlencode', explode( '/', substr( $dir, strlen( $root ) ) ) ) ) ) );
			}
		}
		return null;
	}

	/**
	 * Checks that a name sent by the browser is a file of the listing.
	 *
	 * @param string $name          Name.
	 * @param bool   $allow_partial Whether an unfinished upload qualifies.
	 * @return string The name.
	 * @throws \OutOfBoundsException When it is not.
	 */
	public static function find( $name, $allow_partial = false ) {
		foreach ( self::all() as $backup ) {
			if ( $backup['name'] === $name && ( $allow_partial || ! $backup['partial'] ) ) {
				return $name;
			}
		}
		throw new \OutOfBoundsException( 'This backup is no longer in the backups folder: reload the page.' );
	}

	/**
	 * Deletes a backup or an unfinished upload.
	 *
	 * @param string $name Name.
	 * @throws \RuntimeException When it cannot be deleted.
	 */
	public static function delete( $name ) {
		$name = self::find( $name, true );
		$job  = Job::current();
		if ( $job && $job->get( 'backup' ) === $name && 'done' !== $job->get( 'status' ) ) {
			throw new \RuntimeException( 'This backup is being restored: cancel the restore first.' );
		}
		if ( ! @unlink( Site::backups_dir() . '/' . $name ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- failure is reported.
			throw new \RuntimeException( 'The file could not be deleted: check its permissions.' );
		}
	}
}
