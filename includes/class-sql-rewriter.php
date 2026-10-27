<?php
/**
 * Turning dump statements into statements for this server.
 *
 * @package WPressRestore
 */

namespace WPressRestore;

/**
 * Decides what happens to each statement of an ai1wm dump, and rewrites it.
 *
 * Tables are created under a temporary prefix, so the live site keeps working until the final
 * swap; the dump's SERVMASK_PREFIX_ placeholder becomes this site's prefix everywhere else (it
 * marks option names and user meta keys). Only statements on the backup's own tables run: the
 * rest is refused or, for session statements, skipped. CREATE TABLE is adapted to the server:
 * collations, engines, options it may not know, and foreign keys, which ai1wm also drops.
 */
final class Sql_Rewriter {

	const SOURCE_PREFIX = 'SERVMASK_PREFIX_';

	const KINDS = array(
		'drop_table'   => '/^DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?`([^`]+)`\s*$/i',
		'create_table' => '/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`([^`]+)`\s*\(/i',
		'insert'       => '/^INSERT\s+(?:IGNORE\s+)?INTO\s+`([^`]+)`\s*(?:\([^)]*\)\s*)?VALUES\s*\(/i',
		'drop_view'    => '/^DROP\s+VIEW\s+(?:IF\s+EXISTS\s+)?`([^`]+)`\s*$/i',
		'create_view'  => '/^CREATE\s+(?:OR\s+REPLACE\s+)?(?:ALGORITHM\s*=\s*\w+\s+)?(?:DEFINER\s*=\s*\S+\s+)?(?:SQL\s+SECURITY\s+\w+\s+)?VIEW\s+`([^`]+)`\s+AS\s/i',
	);

	/** Rows ai1wm leaves out on import: caches and sessions. */
	const CACHE_ROWS = "/^INSERT\s+(?:IGNORE\s+)?INTO\s+`[^`]+`\s*VALUES\s*\(\s*-?\d+\s*,\s*'(?:_transient_|_site_transient_|_wc_session_|_wpallimport_session_)/i";

	/**
	 * Temporary table prefix.
	 *
	 * @var string
	 */
	private $temp;

	/**
	 * This site's table prefix.
	 *
	 * @var string
	 */
	private $final;

	/**
	 * Tables the backup holds under their own name.
	 *
	 * @var string[]
	 */
	private $included;

	/**
	 * Address replacement, or null.
	 *
	 * @var Serialized_Replacer|null
	 */
	private $replacer;

	/**
	 * Collations this server supports, or null to keep them.
	 *
	 * @var Collation_Map|null
	 */
	private $collations;

	/**
	 * Storage engines this server supports, lower case.
	 *
	 * @var string[]
	 */
	private $engines;

	/**
	 * Escaped strings whose presence means a literal may change.
	 *
	 * @var string[]
	 */
	private $needles = array();

	/**
	 * @param array $options temp_prefix, final_prefix, included_tables, replacer, collations, engines.
	 */
	public function __construct( array $options ) {
		$this->temp       = $options['temp_prefix'];
		$this->final      = $options['final_prefix'];
		$this->included   = (array) $options['included_tables'];
		$this->replacer   = $options['replacer'];
		$this->collations = $options['collations'];
		$this->engines    = array_map( 'strtolower', (array) $options['engines'] );

		if ( $this->replacer && $this->replacer->has_changes() ) {
			$needles = array_map( array( Sql_String::class, 'escape' ), $this->replacer->changing_needles() );
			usort(
				$needles,
				static function ( $a, $b ) {
					return strlen( $a ) - strlen( $b );
				}
			);
			// A string containing a shorter needle is found through that needle.
			foreach ( $needles as $needle ) {
				foreach ( $this->needles as $kept ) {
					if ( false !== strpos( $needle, $kept ) ) {
						continue 2;
					}
				}
				$this->needles[] = $needle;
			}
		}
	}

	/**
	 * What to do with one statement.
	 *
	 * @param string $statement Statement from the dump, without its semicolon.
	 * @return array action (run, skip, defer, refuse), kind, sql, table (final name), temp_table, reason.
	 */
	public function rewrite( $statement ) {
		$sql = self::strip_leading_comments( $statement );
		if ( '' === $sql ) {
			return self::result( 'skip', 'empty' );
		}
		if ( preg_match( '/^(START\s+TRANSACTION|BEGIN|COMMIT|ROLLBACK|SET\s|LOCK\s+TABLES|UNLOCK\s+TABLES)/i', $sql ) ) {
			return self::result( 'skip', 'session' );
		}

		foreach ( self::KINDS as $kind => $pattern ) {
			if ( preg_match( $pattern, $sql, $match, PREG_OFFSET_CAPTURE ) ) {
				return $this->rewrite_kind( $kind, $sql, $match[1][0], $match[1][1] );
			}
		}
		return self::result( 'refuse', 'other', null, null, null, 'not a statement a backup restores' );
	}

	/**
	 * Rewrites a statement whose kind and table are known.
	 *
	 * @param string $kind   Kind.
	 * @param string $sql    Statement.
	 * @param string $name   Table or view name in the dump.
	 * @param int    $offset Offset of the name in $sql.
	 * @return array
	 */
	private function rewrite_kind( $kind, $sql, $name, $offset ) {
		$names = $this->map_table( $name );
		if ( null === $names ) {
			return self::result( 'refuse', $kind, null, null, null, sprintf( 'table %s is not part of the backup', $name ) );
		}
		list( $temp, $final ) = $names;

		if ( 'drop_view' === $kind || 'create_view' === $kind ) {
			return self::result( 'defer', $kind, $this->with_final_prefix( $sql ), $final );
		}
		if ( 'create_table' === $kind && self::reaches_beyond( $sql ) ) {
			return self::result( 'refuse', $kind, null, $final, $temp, 'the table would be filled from, or stored in, other tables or servers' );
		}
		if ( 'insert' === $kind && ( ( $final === $this->final . 'options' && preg_match( self::CACHE_ROWS, $sql ) ) || $final === $this->final . 'eum_logs' ) ) {
			return self::result( 'skip', $kind, null, $final, $temp, 'cache row' );
		}

		$sql = substr( $sql, 0, $offset ) . $temp . $this->with_final_prefix( substr( $sql, $offset + strlen( $name ) ) );
		if ( 'create_table' === $kind ) {
			$sql = $this->fit_table( $sql );
		} elseif ( 'insert' === $kind && $this->needles ) {
			$sql = Sql_String::map_literals( $sql, array( $this, 'replace_literal' ) );
		}
		return self::result( 'run', $kind, $sql, $final, $temp );
	}

	/**
	 * Replaces addresses in one literal, keeping it byte for byte when nothing changes.
	 *
	 * @param string $content Escaped literal content.
	 * @return string
	 */
	public function replace_literal( $content ) {
		foreach ( $this->needles as $needle ) {
			if ( false !== strpos( $content, $needle ) ) {
				$value    = Sql_String::unescape( $content );
				$replaced = $this->replacer->replace( $value );
				return $replaced === $value ? $content : Sql_String::escape( $replaced );
			}
		}
		return $content;
	}

	/**
	 * Temporary and final names of a dump table, or null when the backup does not own it.
	 *
	 * @param string $name Name in the dump.
	 * @return string[]|null
	 */
	private function map_table( $name ) {
		if ( 0 === stripos( $name, self::SOURCE_PREFIX ) && strlen( $name ) > strlen( self::SOURCE_PREFIX ) ) {
			$suffix = substr( $name, strlen( self::SOURCE_PREFIX ) );
			return array( $this->temp . $suffix, $this->final . $suffix );
		}
		if ( in_array( $name, $this->included, true ) ) {
			return array( $this->temp . $name, $name );
		}
		foreach ( Dump_Format::OUTSIDE_PREFIX as $outside ) {
			if ( 0 === strpos( $name, $outside ) ) {
				return array( $this->temp . $name, $name );
			}
		}
		return null;
	}

	/**
	 * Puts this site's prefix in place of the placeholder.
	 *
	 * @param string $sql Statement part.
	 * @return string
	 */
	private function with_final_prefix( $sql ) {
		return str_ireplace( self::SOURCE_PREFIX, $this->final, $sql );
	}

	/**
	 * Adapts a CREATE TABLE to this server.
	 *
	 * @param string $sql CREATE TABLE statement.
	 * @return string
	 */
	private function fit_table( $sql ) {
		$sql = Sql_String::map_code( Sql_String::strip_block_comments( $sql ), array( $this, 'fit_charsets' ) );
		$sql = self::map_definitions(
			$sql,
			static function ( $definition ) {
				if ( preg_match( '/^\s*(?:CONSTRAINT\s+(?:`[^`]*`\s+)?)?FOREIGN\s+KEY\b/i', $definition ) ) {
					return null;
				}
				return (string) preg_replace( '/^(\s*)CONSTRAINT\s+(?:`[^`]*`\s+)?(CHECK\b)/i', '$1$2', $definition );
			}
		);
		return self::map_options(
			$sql,
			function ( $options ) {
				$options = (string) preg_replace_callback(
					'/\b(?:ENGINE|TYPE)\s*=\s*(\w+)/i',
					function ( $match ) {
						return 'ENGINE=' . ( in_array( strtolower( $match[1] ), $this->engines, true ) ? $match[1] : 'InnoDB' );
					},
					$options
				);
				$options = (string) preg_replace( '/\s*\b(?:TRANSACTIONAL|PAGE_CHECKSUM|TABLE_CHECKSUM|ROW_FORMAT|PAGE_COMPRESSED|PAGE_COMPRESSION_LEVEL|ENCRYPTED|ENCRYPTION_KEY_ID)\s*=\s*\'?\w+\'?/i', '', $options );
				return (string) preg_replace( '/\s*\b(?:WITH|WITHOUT)\s+SYSTEM\s+VERSIONING\b/i', '', $options );
			}
		);
	}

	/**
	 * Maps character sets and collations in unquoted text.
	 *
	 * @param string $code Unquoted text.
	 * @return string
	 */
	public function fit_charsets( $code ) {
		if ( null === $this->collations ) {
			return $code;
		}
		$code = (string) preg_replace_callback(
			'/\b(COLLATE)(\s*=\s*|\s+)(\w+)/i',
			function ( $match ) {
				return $match[1] . $match[2] . $this->collations->collation( $match[3] );
			},
			$code
		);
		return (string) preg_replace_callback(
			'/\b(CHARSET|CHARACTER\s+SET)(\s*=\s*|\s+)(\w+)/i',
			function ( $match ) {
				return $match[1] . $match[2] . $this->collations->charset( $match[3] );
			},
			$code
		);
	}

	/**
	 * Whether a CREATE TABLE copies another table (… SELECT) or keeps its rows elsewhere
	 * (MERGE over other tables, FEDERATED, CONNECT or SPIDER on other servers).
	 *
	 * @param string $sql CREATE TABLE statement.
	 * @return bool
	 */
	private static function reaches_beyond( $sql ) {
		$bounds  = self::definition_bounds( $sql );
		$options = Sql_String::without_quoted( null === $bounds ? $sql : substr( $sql, $bounds[1] + 1 ) );
		return (bool) preg_match( '/\bSELECT\b|\bUNION\s*=|\bCONNECTION\s*=|\b(?:ENGINE|TYPE)\s*=\s*(?:MRG_MYISAM|MERGE|FEDERATED|FEDERATEDX|CONNECT|SPIDER)\b/i', $options );
	}

	/**
	 * Retry for "key too long" (1071, 1709): dynamic rows allow longer index keys.
	 *
	 * @param string $sql CREATE TABLE statement.
	 * @return string
	 */
	public static function with_dynamic_rows( $sql ) {
		$count = 0;
		$sql   = (string) preg_replace( '/\b(ENGINE\s*=\s*\w+)/i', '$1 ROW_FORMAT=DYNAMIC', $sql, 1, $count );
		return $count ? $sql : $sql . ' ROW_FORMAT=DYNAMIC';
	}

	/**
	 * Retry for "FULLTEXT not supported" (1214).
	 *
	 * @param string $sql CREATE TABLE statement.
	 * @return string
	 */
	public static function without_fulltext( $sql ) {
		return self::map_definitions(
			$sql,
			static function ( $definition ) {
				return preg_match( '/^\s*FULLTEXT\b/i', $definition ) ? null : $definition;
			}
		);
	}

	/**
	 * Retry for "TEXT/BLOB can't have a default value" (1101).
	 *
	 * @param string $sql CREATE TABLE statement.
	 * @return string
	 */
	public static function without_text_defaults( $sql ) {
		return self::map_definitions(
			$sql,
			static function ( $definition ) {
				if ( ! preg_match( '/^\s*`[^`]+`\s+(?:tiny|medium|long)?(?:text|blob)\b/i', $definition ) ) {
					return $definition;
				}
				return (string) preg_replace( "/\s+DEFAULT\s+(?:'(?:[^'\\\\]|\\\\.|'')*'|\S+)/i", '', $definition );
			}
		);
	}

	/**
	 * Retry for column types only MariaDB knows.
	 *
	 * @param string $sql CREATE TABLE statement.
	 * @return string
	 */
	public static function with_portable_types( $sql ) {
		return (string) preg_replace(
			array( '/(?<!`)\bINET4\b(?!\s*\()/i', '/(?<!`)\bINET6\b(?!\s*\()/i', '/(?<!`)\bUUID\b(?!\s*\()/i', '/(?<!`)\bXMLTYPE\b(?!\s*\()/i', '/(?<!`)\bVECTOR\s*\(\s*\d+\s*\)/i' ),
			array( 'VARCHAR(15)', 'VARCHAR(45)', 'CHAR(36)', 'LONGTEXT', 'BLOB' ),
			$sql
		);
	}

	/**
	 * Rewrites each column and index definition of a CREATE TABLE; a null return drops it.
	 *
	 * @param string   $sql      CREATE TABLE statement.
	 * @param callable $callback Receives a definition with its leading whitespace.
	 * @return string
	 */
	private static function map_definitions( $sql, callable $callback ) {
		$bounds = self::definition_bounds( $sql );
		if ( null === $bounds ) {
			return $sql;
		}
		list( $open, $close ) = $bounds;
		$body                 = substr( $sql, $open + 1, $close - $open - 1 );
		$trailing             = substr( $body, strlen( rtrim( $body ) ) );
		$kept                 = array();
		foreach ( self::split_top_level( rtrim( $body ) ) as $definition ) {
			$definition = $callback( $definition );
			if ( null !== $definition ) {
				$kept[] = $definition;
			}
		}
		return substr( $sql, 0, $open + 1 ) . implode( ',', $kept ) . $trailing . substr( $sql, $close );
	}

	/**
	 * Rewrites the table options that follow the definitions.
	 *
	 * @param string   $sql      CREATE TABLE statement.
	 * @param callable $callback Receives and returns the options.
	 * @return string
	 */
	private static function map_options( $sql, callable $callback ) {
		$bounds = self::definition_bounds( $sql );
		if ( null === $bounds ) {
			return $sql;
		}
		return substr( $sql, 0, $bounds[1] + 1 ) . $callback( substr( $sql, $bounds[1] + 1 ) );
	}

	/**
	 * Offsets of the parentheses around the definitions, or null.
	 *
	 * @param string $sql CREATE TABLE statement.
	 * @return int[]|null
	 */
	private static function definition_bounds( $sql ) {
		$open = null;
		$depth = 0;
		$length = strlen( $sql );
		for ( $pos = 0; $pos < $length; $pos++ ) {
			$char = $sql[ $pos ];
			if ( "'" === $char || '"' === $char || '`' === $char ) {
				$pos = self::skip_quoted( $sql, $pos );
			} elseif ( '(' === $char ) {
				if ( 0 === $depth++ && null === $open ) {
					$open = $pos;
				}
			} elseif ( ')' === $char && $depth > 0 && 0 === --$depth ) {
				return array( $open, $pos );
			}
		}
		return null;
	}

	/**
	 * Splits text on commas outside parentheses and quotes.
	 *
	 * @param string $body Definitions.
	 * @return string[]
	 */
	private static function split_top_level( $body ) {
		$parts  = array();
		$depth  = 0;
		$start  = 0;
		$length = strlen( $body );
		for ( $pos = 0; $pos < $length; $pos++ ) {
			$char = $body[ $pos ];
			if ( "'" === $char || '"' === $char || '`' === $char ) {
				$pos = self::skip_quoted( $body, $pos );
			} elseif ( '(' === $char ) {
				++$depth;
			} elseif ( ')' === $char ) {
				--$depth;
			} elseif ( ',' === $char && 0 === $depth ) {
				$parts[] = substr( $body, $start, $pos - $start );
				$start   = $pos + 1;
			}
		}
		$parts[] = substr( $body, $start );
		return $parts;
	}

	/**
	 * Offset of the quote closing the run opened at $pos (the end of the text when it never closes).
	 *
	 * @param string $sql Text.
	 * @param int    $pos Offset of the opening quote.
	 * @return int
	 */
	private static function skip_quoted( $sql, $pos ) {
		$quote  = $sql[ $pos ];
		$length = strlen( $sql );
		for ( ++$pos; $pos < $length; $pos++ ) {
			if ( '\\' === $sql[ $pos ] && '`' !== $quote ) {
				++$pos;
			} elseif ( $sql[ $pos ] === $quote ) {
				if ( $pos + 1 < $length && $sql[ $pos + 1 ] === $quote ) {
					++$pos;
				} else {
					return $pos;
				}
			}
		}
		return $length;
	}

	/**
	 * Removes whitespace and comments before the first keyword.
	 *
	 * @param string $sql Statement.
	 * @return string
	 */
	private static function strip_leading_comments( $sql ) {
		while ( true ) {
			$sql = ltrim( $sql );
			if ( 0 === strpos( $sql, '--' ) || 0 === strpos( $sql, '#' ) ) {
				$newline = strpos( $sql, "\n" );
				$sql     = false === $newline ? '' : substr( $sql, $newline + 1 );
			} elseif ( 0 === strpos( $sql, '/*' ) ) {
				$close = strpos( $sql, '*/' );
				$sql   = false === $close ? '' : substr( $sql, $close + 2 );
			} else {
				return rtrim( $sql );
			}
		}
	}

	/**
	 * @param string      $action     run, skip, defer or refuse.
	 * @param string      $kind       Statement kind.
	 * @param string|null $sql        Statement to run.
	 * @param string|null $table      Final table name.
	 * @param string|null $temp_table Temporary table name.
	 * @param string|null $reason     Why it is skipped or refused.
	 * @return array
	 */
	private static function result( $action, $kind, $sql = null, $table = null, $temp_table = null, $reason = null ) {
		return array(
			'action'     => $action,
			'kind'       => $kind,
			'sql'        => $sql,
			'table'      => $table,
			'temp_table' => $temp_table,
			'reason'     => $reason,
		);
	}
}
