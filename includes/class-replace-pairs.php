<?php
/**
 * What to replace when a backup lands on another address or server.
 *
 * @package WPressRestore
 */

namespace WPressRestore;

/**
 * Builds the search => replace pairs that All-in-One WP Migration applies on import.
 *
 * Every old address is moved in the spellings sites store it in: http, https and scheme-less,
 * with and without www, URL-encoded and JSON-escaped; plus email domains (unless the backup says
 * not to), server paths, and the replacements saved in the backup. The first pair for a given
 * search wins, as in ai1wm.
 */
final class Replace_Pairs {

	/**
	 * @param array $old site_url, home_url, internal_site_url, internal_home_url, uploads_url, absolute,
	 *                   content (from package.json), custom (list of [old, new]), replace_email (bool).
	 * @param array $new site_url, home_url, uploads_url, uploads_path (/relative/ to the site root, or ""),
	 *                   absolute, content (this site).
	 * @return string[] Search => replace.
	 */
	public static function build( array $old, array $new ) {
		$pairs = array();

		if ( ! empty( $new['uploads_path'] ) ) {
			self::add_encoded( $pairs, '/wp-content/uploads/', $new['uploads_path'] );
		}
		foreach ( array( 'site', 'home' ) as $kind ) {
			$urls = array( $old[ $kind . '_url' ] );
			if ( self::is_absolute_url( $old[ 'internal_' . $kind . '_url' ] ) ) {
				$urls[] = $old[ 'internal_' . $kind . '_url' ];
			}
			foreach ( array_filter( $urls ) as $url ) {
				foreach ( array( $url, self::www_inversion( $url ) ) as $variant ) {
					self::add_site_url( $pairs, $variant, $new[ $kind . '_url' ], ! empty( $old['replace_email'] ) );
				}
			}
		}
		if ( ! empty( $old['uploads_url'] ) ) {
			foreach ( array( $old['uploads_url'], self::www_inversion( $old['uploads_url'] ) ) as $variant ) {
				self::add_uploads_url( $pairs, $variant, $new['uploads_url'] );
			}
		}
		foreach ( array( 'absolute', 'content' ) as $key ) {
			if ( ! empty( $old[ $key ] ) ) {
				self::add_encoded( $pairs, $old[ $key ], $new[ $key ] );
			}
		}
		foreach ( (array) $old['custom'] as $pair ) {
			if ( isset( $pair[0], $pair[1] ) && '' !== (string) $pair[0] && '' !== (string) $pair[1] ) {
				self::add_encoded( $pairs, (string) $pair[0], (string) $pair[1] );
			}
		}
		return $pairs;
	}

	/**
	 * Pairs for one old site or home address.
	 *
	 * @param string[] $pairs         Pairs so far.
	 * @param string   $url           Old address.
	 * @param string   $target        New address.
	 * @param bool     $replace_email Whether email domains move too.
	 */
	private static function add_site_url( array &$pairs, $url, $target, $replace_email ) {
		$old_domain = (string) parse_url( $url, PHP_URL_HOST );
		$new_domain = (string) parse_url( $target, PHP_URL_HOST );
		$old_path   = rtrim( (string) parse_url( $url, PHP_URL_PATH ), '/' );
		$new_path   = rtrim( (string) parse_url( $target, PHP_URL_PATH ), '/' );
		$new_scheme = (string) parse_url( $target, PHP_URL_SCHEME );

		self::add( $pairs, "='" . $old_domain . $old_path, "='" . $new_domain . $new_path );
		self::add( $pairs, '="' . $old_domain . $old_path, '="' . $new_domain . $new_path );
		foreach ( array( 'http' => $new_scheme, 'https' => $new_scheme, '' => '' ) as $old_scheme => $new_scheme_for ) {
			self::add_encoded( $pairs, self::with_scheme( rtrim( $url, '/' ), $old_scheme ), self::with_scheme( rtrim( $target, '/' ), $new_scheme_for ) );
		}
		if ( $replace_email ) {
			self::add( $pairs, '@' . $old_domain, str_ireplace( '@www.', '@', '@' . $new_domain ) );
		}
	}

	/**
	 * Pairs for the old uploads address.
	 *
	 * @param string[] $pairs  Pairs so far.
	 * @param string   $url    Old uploads address, with its trailing slash.
	 * @param string   $target New uploads address.
	 */
	private static function add_uploads_url( array &$pairs, $url, $target ) {
		$old_path   = (string) parse_url( $url, PHP_URL_PATH );
		$new_path   = (string) parse_url( $target, PHP_URL_PATH );
		$new_scheme = (string) parse_url( $target, PHP_URL_SCHEME );

		if ( '' !== trim( $old_path, '/' ) ) {
			self::add( $pairs, "='" . rtrim( $old_path, '/' ) . '/', "='" . rtrim( $new_path, '/' ) . '/' );
			self::add( $pairs, '="' . rtrim( $old_path, '/' ) . '/', '="' . rtrim( $new_path, '/' ) . '/' );
		}
		foreach ( array( 'http' => $new_scheme, 'https' => $new_scheme, '' => '' ) as $old_scheme => $new_scheme_for ) {
			self::add_encoded( $pairs, self::with_scheme( $url, $old_scheme ), self::with_scheme( $target, $new_scheme_for ) );
		}
	}

	/**
	 * A pair, plus its URL-encoded, raw URL-encoded and JSON-escaped spellings.
	 *
	 * @param string[] $pairs Pairs so far.
	 * @param string   $from  Search.
	 * @param string   $to    Replace.
	 */
	private static function add_encoded( array &$pairs, $from, $to ) {
		self::add( $pairs, $from, $to );
		self::add( $pairs, urlencode( $from ), urlencode( $to ) );
		self::add( $pairs, rawurlencode( $from ), rawurlencode( $to ) );
		self::add( $pairs, addcslashes( $from, '/' ), addcslashes( $to, '/' ) );
	}

	/**
	 * Adds a pair unless its search is empty or already taken.
	 *
	 * @param string[] $pairs Pairs so far.
	 * @param string   $from  Search.
	 * @param string   $to    Replace.
	 */
	private static function add( array &$pairs, $from, $to ) {
		if ( '' !== $from && ! array_key_exists( $from, $pairs ) ) {
			$pairs[ $from ] = $to;
		}
	}

	/**
	 * The same address with its scheme replaced, or scheme-less ("//host") when $scheme is "".
	 *
	 * @param string $url    Address.
	 * @param string $scheme Scheme.
	 * @return string
	 */
	private static function with_scheme( $url, $scheme ) {
		return (string) preg_replace( '#^\w+://#', '' === $scheme ? '//' : $scheme . '://', $url );
	}

	/**
	 * The address with www added, or removed when it has one.
	 *
	 * @param string $url Address.
	 * @return string
	 */
	private static function www_inversion( $url ) {
		return false !== stripos( $url, '//www.' ) ? str_ireplace( '//www.', '//', $url ) : str_ireplace( '//', '//www.', $url );
	}

	/**
	 * Whether a value is an address with a scheme and a host.
	 *
	 * @param mixed $url Value.
	 * @return bool
	 */
	private static function is_absolute_url( $url ) {
		return is_string( $url ) && parse_url( $url, PHP_URL_SCHEME ) && parse_url( $url, PHP_URL_HOST );
	}
}
