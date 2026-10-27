<?php
/**
 * Database access for the import.
 *
 * @package WPressRestore
 */

namespace WPressRestore;

/**
 * Runs statements on WordPress's own connection.
 *
 * Statements go straight to mysqli when WordPress uses it, byte for byte: wpdb::query() would
 * reject a dump row whose bytes do not fit the column charset. Errors are returned, never shown.
 */
final class Database {

	/**
	 * WordPress database.
	 *
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * Native connection, or null when WordPress uses another driver.
	 *
	 * @var \mysqli|null
	 */
	private $mysqli;

	/**
	 * Last error number.
	 *
	 * @var int
	 */
	private $errno = 0;

	/**
	 * Last error message.
	 *
	 * @var string
	 */
	private $error = '';

	public function __construct() {
		global $wpdb;
		$this->wpdb   = $wpdb;
		$this->mysqli = $wpdb->dbh instanceof \mysqli ? $wpdb->dbh : null;
	}

	/** @return string This site's table prefix. */
	public function prefix() {
		return $this->wpdb->prefix;
	}

	/**
	 * Runs one statement.
	 *
	 * @param string $sql Statement.
	 * @return bool Success.
	 */
	public function query( $sql ) {
		$this->errno = 0;
		$this->error = '';
		if ( null === $this->mysqli ) {
			$suppress = $this->wpdb->suppress_errors( true );
			$result   = $this->wpdb->query( $sql );
			$this->wpdb->suppress_errors( $suppress );
			if ( false === $result ) {
				$this->errno = 1;
				$this->error = $this->wpdb->last_error;
				return false;
			}
			return true;
		}
		try {
			$result = mysqli_query( $this->mysqli, $sql );
		} catch ( \mysqli_sql_exception $e ) {
			$this->errno = (int) $e->getCode();
			$this->error = $e->getMessage();
			return false;
		}
		if ( false === $result ) {
			$this->errno = mysqli_errno( $this->mysqli );
			$this->error = mysqli_error( $this->mysqli );
			return false;
		}
		if ( $result instanceof \mysqli_result ) {
			mysqli_free_result( $result );
		}
		return true;
	}

	/** @return int MySQL error number of the last statement, 0 on success. */
	public function errno() {
		return $this->errno;
	}

	/** @return string MySQL error message of the last statement. */
	public function error() {
		return $this->error;
	}

	/**
	 * Rows of a query.
	 *
	 * @param string $sql Query.
	 * @return array[]
	 */
	public function rows( $sql ) {
		$suppress = $this->wpdb->suppress_errors( true );
		$rows     = $this->wpdb->get_results( $sql, ARRAY_A );
		$this->wpdb->suppress_errors( $suppress );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * First value of a query.
	 *
	 * @param string $sql Query.
	 * @return string|null
	 */
	public function value( $sql ) {
		$suppress = $this->wpdb->suppress_errors( true );
		$value    = $this->wpdb->get_var( $sql );
		$this->wpdb->suppress_errors( $suppress );
		return $value;
	}

	/**
	 * Quotes a value for a statement.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public function quote( $value ) {
		return "'" . Sql_String::escape( (string) $value ) . "'";
	}

	/**
	 * Tables whose name starts with $prefix (all tables for "").
	 *
	 * @param string $prefix Prefix.
	 * @return string[]
	 */
	public function tables( $prefix = '' ) {
		$sql = '' === $prefix ? 'SHOW TABLES' : 'SHOW TABLES LIKE ' . $this->quote( $this->wpdb->esc_like( $prefix ) . '%' );
		return array_map( 'current', $this->rows( $sql ) );
	}

	/** @return string[] Collation => character set, as this server lists them. */
	public function collations() {
		$collations = array();
		foreach ( $this->rows( 'SHOW COLLATION' ) as $row ) {
			if ( isset( $row['Collation'], $row['Charset'] ) ) {
				$collations[ $row['Collation'] ] = $row['Charset'];
			}
		}
		return $collations;
	}

	/** @return string[] Storage engines this server can use, lower case. */
	public function engines() {
		$engines = array();
		foreach ( $this->rows( 'SHOW ENGINES' ) as $row ) {
			if ( isset( $row['Engine'], $row['Support'] ) && in_array( strtoupper( $row['Support'] ), array( 'YES', 'DEFAULT' ), true ) ) {
				$engines[] = strtolower( $row['Engine'] );
			}
		}
		return $engines ? $engines : array( 'innodb', 'myisam' );
	}

	/** @return int Largest statement the server accepts. */
	public function max_allowed_packet() {
		$value = (int) $this->value( 'SELECT @@SESSION.max_allowed_packet' );
		return $value > 0 ? $value : 1048576;
	}

	/** Session settings for importing a dump: no strict mode, no foreign key checks, explicit zero ids kept. */
	public function prepare_import() {
		$this->query( "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'" );
		$this->query( 'SET SESSION foreign_key_checks = 0' );
		$this->query( 'SET SESSION innodb_strict_mode = 0' );
	}
}
