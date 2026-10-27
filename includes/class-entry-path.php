<?php
/**
 * Archive entry names.
 *
 * @package WPressRestore
 */

namespace WPressRestore;

/**
 * Normalizes entry names and refuses any that could land outside wp-content.
 */
final class Entry_Path {

	/**
	 * A relative "/" separated name with no empty, "." or ".." segment, or null when the name is unsafe.
	 *
	 * @param string $name Entry name from the archive header.
	 * @return string|null
	 */
	public static function normalize( $name ) {
		if ( ! is_string( $name ) || '' === $name || false !== strpos( $name, "\0" ) ) {
			return null;
		}
		$name = str_replace( '\\', '/', $name );
		if ( '/' === $name[0] || preg_match( '#^[A-Za-z]:#', $name ) || false !== strpos( $name, '://' ) ) {
			return null;
		}

		$parts = array();
		foreach ( explode( '/', $name ) as $part ) {
			if ( '..' === $part ) {
				return null;
			}
			if ( '' !== $part && '.' !== $part ) {
				$parts[] = $part;
			}
		}
		return $parts ? implode( '/', $parts ) : null;
	}
}
