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

	/**
	 * The tables and views a backup holds, as ai1wm picks them: those with the site prefix, plus
	 * the tables of plugins that ignore it (Dump_Format::OUTSIDE_PREFIX).
	 *
	 * @param string $prefix Site prefix.
	 * @return array tables (base tables then views), views.
	 */
	public function export_tables( $prefix ) {
		$fold   = $this->folds_table_names();
		$starts = array_merge( array( $prefix ), Dump_Format::OUTSIDE_PREFIX );
		$picked = array(
			'BASE TABLE' => array(),
			'VIEW'       => array(),
		);
		foreach ( $this->fetch_rows( 'SHOW FULL TABLES' ) as $row ) {
			if ( ! isset( $row[1], $picked[ $row[1] ] ) ) {
				continue;
			}
			foreach ( $starts as $start ) {
				if ( '' === $start || 0 === strpos( $fold ? strtolower( $row[0] ) : $row[0], $fold ? strtolower( $start ) : $start ) ) {
					$picked[ $row[1] ][] = $row[0];
					break;
				}
			}
		}
		return array(
			'tables' => array_merge( $picked['BASE TABLE'], $picked['VIEW'] ),
			'views'  => $picked['VIEW'],
		);
	}

	/**
	 * SHOW CREATE TABLE or SHOW CREATE VIEW.
	 *
	 * @param string $name Table or view.
	 * @param bool   $view Whether it is a view.
	 * @return string
	 * @throws \RuntimeException When the server does not answer.
	 */
	public function create_statement( $name, $view ) {
		$rows = $this->fetch_rows( ( $view ? 'SHOW CREATE VIEW `' : 'SHOW CREATE TABLE `' ) . str_replace( '`', '``', $name ) . '`' );
		if ( ! isset( $rows[0][1] ) ) {
			throw new \RuntimeException( sprintf( 'The database server does not describe %s: %s', $name, $this->error ) );
		}
		return $rows[0][1];
	}

	/**
	 * Column types in column order, generated columns typed Dump_Format::GENERATED.
	 *
	 * @param string $table Table.
	 * @return string[] Column name => type.
	 */
	public function columns( $table ) {
		$columns = array();
		foreach ( $this->fetch_rows( 'SHOW COLUMNS FROM `' . str_replace( '`', '``', $table ) . '`' ) as $row ) {
			$columns[ $row[0] ] = Dump_Format::column_type( $row[1], isset( $row[5] ) ? $row[5] : '' );
		}
		return $columns;
	}

	/**
	 * Primary key columns, in key order.
	 *
	 * @param string $table Table.
	 * @return string[]
	 */
	public function primary_key( $table ) {
		$key = array();
		foreach ( $this->fetch_rows( 'SHOW KEYS FROM `' . str_replace( '`', '``', $table ) . "` WHERE Key_name = 'PRIMARY'" ) as $row ) {
			$key[ (int) $row[3] ] = $row[4];
		}
		ksort( $key );
		return array_values( $key );
	}

	/**
	 * Rows as lists of raw values, NULL kept, bytes untouched.
	 *
	 * @param string $sql Query.
	 * @return array[]
	 * @throws \RuntimeException When the query fails.
	 */
	public function fetch_rows( $sql ) {
		$this->error = '';
		if ( null === $this->mysqli ) {
			$suppress = $this->wpdb->suppress_errors( true );
			$rows     = $this->wpdb->get_results( $sql, ARRAY_N );
			$this->wpdb->suppress_errors( $suppress );
			$this->error = $this->wpdb->last_error;
			if ( '' !== $this->error ) {
				throw new \RuntimeException( $this->error );
			}
			return is_array( $rows ) ? $rows : array();
		}
		try {
			$result = mysqli_query( $this->mysqli, $sql );
		} catch ( \mysqli_sql_exception $e ) {
			$result      = false;
			$this->error = $e->getMessage();
		}
		if ( ! $result instanceof \mysqli_result ) {
			$this->error = $this->error ?: mysqli_error( $this->mysqli );
			throw new \RuntimeException( $this->error );
		}
		$rows = array();
		while ( null !== ( $row = mysqli_fetch_row( $result ) ) ) {
			$rows[] = $row;
		}
		mysqli_free_result( $result );
		return $rows;
	}

	/** @return bool Whether the server compares table names without case (lower_case_table_names 1 or 2). */
	public function folds_table_names() {
		return (int) $this->value( 'SELECT @@lower_case_table_names' ) > 0;
	}

	/**
	 * Whether this site talks to its database the way the restore needs: core wpdb over mysqli.
	 * Drop-ins (HyperDB, LudicrousDB, SQLite) route queries their own way, possibly to replicas.
	 *
	 * @return bool
	 */
	public static function is_supported() {
		global $wpdb;
		return $wpdb instanceof \wpdb && 'wpdb' === get_class( $wpdb ) && $wpdb->dbh instanceof \mysqli;
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
