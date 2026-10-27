<?php
/**
 * Writing a SQL dump the way All-in-One WP Migration does.
 *
 * @package WPressRestore
 */

namespace WPressRestore;

/**
 * Formats the statements of a dump that either importer, ai1wm's or this plugin's, can read.
 *
 * Tables are named with the SERVMASK_PREFIX_ placeholder in place of the site prefix; one INSERT
 * per row on one line; binary columns in hex. Where ai1wm marks every option name that starts
 * with the prefix, this marks only {prefix}user_roles: with the prefix wp_, ai1wm renames options
 * such as wp_mail_smtp or wp_attachment_pages_enabled when a site moves to another prefix.
 */
final class Dump_Format {

	const PLACEHOLDER = 'SERVMASK_PREFIX_';

	/** Plugins whose tables ignore the WordPress prefix; ai1wm exports them under their own names. */
	const OUTSIDE_PREFIX = array( 'wbk_', 'civicrm_' );

	/** User meta keys WordPress builds from the prefix, for sites whose prefix is too short to match on. */
	const PREFIXED_META = array( 'capabilities', 'user_level', 'dashboard_quick_press_last_post_id', 'user-settings', 'user-settings-time', 'persisted_preferences' );

	const NUMERIC = array( 'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'float', 'double', 'real', 'decimal', 'numeric' );

	const BINARY = array( 'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'bit', 'geometry', 'point', 'linestring', 'polygon', 'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection' );

	/** Type given to generated columns, whose value the server computes. */
	const GENERATED = 'generated';

	/**
	 * A column's type for the dump: generated columns apart. MySQL 8 also marks columns whose
	 * default is an expression (DEFAULT CURRENT_TIMESTAMP) as DEFAULT_GENERATED; those keep their values.
	 *
	 * @param string $type  Type, as SHOW COLUMNS lists it.
	 * @param string $extra Extra, as SHOW COLUMNS lists it.
	 * @return string
	 */
	public static function column_type( $type, $extra ) {
		return preg_match( '/\b(?:VIRTUAL|STORED|PERSISTENT)\s+GENERATED\b/i', (string) $extra ) ? self::GENERATED : $type;
	}

	/**
	 * The dump's opening comment.
	 *
	 * @param string $generator Who writes it.
	 * @return string
	 */
	public static function header( $generator ) {
		return "-- All-in-One WP Migration SQL Dump\n-- Written by " . $generator . "\n--\n";
	}

	/**
	 * A column value as a SQL literal.
	 *
	 * @param string|null $value Value as the server returns it.
	 * @param string      $type  Column type, from column_type().
	 * @return string
	 */
	public static function value( $value, $type ) {
		// The server refuses any value for a generated column but DEFAULT, which computes it again.
		if ( self::GENERATED === $type ) {
			return 'DEFAULT';
		}
		if ( null === $value ) {
			return 'NULL';
		}
		$base = strtolower( (string) strtok( (string) $type, '( ' ) );
		if ( in_array( $base, self::NUMERIC, true ) && preg_match( '/^-?[0-9.]+(?:e[-+]?[0-9]+)?$/i', $value ) ) {
			return $value;
		}
		if ( in_array( $base, self::BINARY, true ) ) {
			return '' === $value ? "''" : '0x' . bin2hex( $value );
		}
		return "'" . Sql_String::escape( $value ) . "'";
	}

	/**
	 * One row as one INSERT statement on one line.
	 *
	 * @param string   $table  Table name in the dump.
	 * @param array    $values Values in column order.
	 * @param string[] $types  Column types in the same order.
	 * @return string
	 */
	public static function insert( $table, array $values, array $types ) {
		$literals = array();
		foreach ( array_values( $values ) as $i => $value ) {
			$literals[] = self::value( $value, $types[ $i ] );
		}
		return 'INSERT INTO `' . $table . '` VALUES (' . implode( ',', $literals ) . ");\n";
	}

	/**
	 * A table's name in the dump: the prefix becomes the placeholder.
	 *
	 * @param string $name   Table name.
	 * @param string $prefix Site prefix.
	 * @param bool   $fold   Whether the server compares table names without case.
	 * @return string
	 */
	public static function table_name( $name, $prefix, $fold = false ) {
		if ( '' === $prefix ) {
			return self::PLACEHOLDER . $name;
		}
		$starts = $fold ? 0 === stripos( $name, $prefix ) : 0 === strpos( $name, $prefix );
		return $starts ? self::PLACEHOLDER . substr( $name, strlen( $prefix ) ) : $name;
	}

	/**
	 * A table definition under its dump name.
	 *
	 * @param string $sql      SHOW CREATE TABLE output.
	 * @param string $name     Table name.
	 * @param string $new_name Name in the dump.
	 * @return string
	 */
	public static function create_table( $sql, $name, $new_name ) {
		$needle = '`' . $name . '`';
		$at     = strpos( $sql, $needle );
		return false === $at ? $sql : substr_replace( $sql, '`' . $new_name . '`', $at, strlen( $needle ) );
	}

	/**
	 * A view definition with dump names, and without the definer, which may not exist elsewhere.
	 *
	 * @param string   $sql   SHOW CREATE VIEW output.
	 * @param string[] $names Table and view names => dump names.
	 * @return string
	 */
	public static function create_view( $sql, array $names ) {
		$sql   = (string) preg_replace( '/^CREATE\s.*?\bVIEW\b/is', 'CREATE VIEW', $sql, 1 );
		$pairs = array();
		foreach ( $names as $from => $to ) {
			$pairs[ '`' . $from . '`' ] = '`' . $to . '`';
		}
		return strtr( $sql, $pairs );
	}

	/**
	 * An option name in the dump: only the roles option is named after the prefix.
	 *
	 * @param string $name   Option name.
	 * @param string $prefix Site prefix.
	 * @return string
	 */
	public static function option_name( $name, $prefix ) {
		return $prefix . 'user_roles' === $name ? self::PLACEHOLDER . 'user_roles' : $name;
	}

	/**
	 * A user meta key in the dump: keys built from the prefix get the placeholder.
	 *
	 * @param string $key    Meta key.
	 * @param string $prefix Site prefix.
	 * @return string
	 */
	public static function meta_key( $key, $prefix ) {
		if ( strlen( $prefix ) > 1 ) {
			return 0 === strpos( $key, $prefix ) ? self::PLACEHOLDER . substr( $key, strlen( $prefix ) ) : $key;
		}
		foreach ( self::PREFIXED_META as $suffix ) {
			if ( $prefix . $suffix === $key ) {
				return self::PLACEHOLDER . $suffix;
			}
		}
		return $key;
	}
}
