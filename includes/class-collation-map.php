<?php
/**
 * Character sets and collations across MySQL and MariaDB versions.
 *
 * @package WPressRestore
 */

namespace WPressRestore;

/**
 * Picks the closest character set and collation this database server supports.
 *
 * Built from the server's own SHOW COLLATION list rather than version numbers: MySQL 8's
 * utf8mb4_0900_* and MariaDB 11's uca1400 collations each exist on one side only, and
 * utf8 is called utf8mb3 on recent servers.
 */
final class Collation_Map {

	/**
	 * Collation => character set, lower case.
	 *
	 * @var string[]
	 */
	private $collations = array();

	/**
	 * Character set => true.
	 *
	 * @var bool[]
	 */
	private $charsets = array();

	/**
	 * @param string[] $collations Collation => character set, as SHOW COLLATION lists them.
	 */
	public function __construct( array $collations ) {
		foreach ( $collations as $collation => $charset ) {
			$this->collations[ strtolower( $collation ) ] = strtolower( $charset );
			$this->charsets[ strtolower( $charset ) ]     = true;
		}
	}

	/**
	 * The supported collation closest to $name.
	 *
	 * @param string $name Collation from the dump.
	 * @return string
	 */
	public function collation( $name ) {
		$name = strtolower( $name );
		if ( isset( $this->collations[ $name ] ) ) {
			return $name;
		}
		$split   = strpos( $name, '_' );
		$charset = $this->charset( false === $split ? $name : substr( $name, 0, $split ) );
		$suffix  = false === $split ? '' : substr( $name, $split + 1 );

		$candidates = array( $charset . '_' . $suffix );
		if ( preg_match( '/_(bin|cs)$/', $name ) ) {
			$candidates[] = $charset . '_bin';
		}
		$candidates[] = $charset . '_unicode_520_ci';
		$candidates[] = $charset . '_unicode_ci';
		$candidates[] = $charset . '_general_ci';
		foreach ( $candidates as $candidate ) {
			if ( isset( $this->collations[ $candidate ] ) ) {
				return $candidate;
			}
		}
		$any = array_search( $charset, $this->collations, true );
		return false === $any ? $name : $any;
	}

	/**
	 * The supported character set closest to $name.
	 *
	 * @param string $name Character set from the dump.
	 * @return string
	 */
	public function charset( $name ) {
		$name = strtolower( $name );
		if ( isset( $this->charsets[ $name ] ) ) {
			return $name;
		}
		$aliases = array(
			'utf8'    => array( 'utf8mb3' ),
			'utf8mb3' => array( 'utf8' ),
			'utf8mb4' => array( 'utf8', 'utf8mb3' ),
		);
		$candidates = isset( $aliases[ $name ] ) ? $aliases[ $name ] : array();
		foreach ( array_merge( $candidates, array( 'utf8mb4', 'utf8', 'utf8mb3' ) ) as $candidate ) {
			if ( isset( $this->charsets[ $candidate ] ) ) {
				return $candidate;
			}
		}
		return $name;
	}
}
