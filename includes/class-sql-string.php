<?php
/**
 * MySQL string literals.
 *
 * @package WPressRestore
 */

namespace WPressRestore;

/**
 * Escapes, unescapes and walks MySQL string literals the way the dump writes them.
 */
final class Sql_String {

	/** What mysqli_real_escape_string() escapes, as ai1wm's dump writes it. */
	const ESCAPES = array(
		'\\'   => '\\\\',
		"\0"   => '\\0',
		"\n"   => '\\n',
		"\r"   => '\\r',
		"'"    => "\\'",
		'"'    => '\\"',
		"\x1a" => '\\Z',
	);

	/** Escapes MySQL reads in a quoted string; \% and \_ keep their backslash, as in MySQL. */
	const UNESCAPES = array(
		'\\0'  => "\0",
		"\\'"  => "'",
		'\\"'  => '"',
		'\\b'  => "\x08",
		'\\n'  => "\n",
		'\\r'  => "\r",
		'\\t'  => "\t",
		'\\Z'  => "\x1a",
		'\\\\' => '\\',
		"''"   => "'",
	);

	/**
	 * @param string $value Raw value.
	 * @return string Content for a single-quoted literal.
	 */
	public static function escape( $value ) {
		return strtr( $value, self::ESCAPES );
	}

	/**
	 * @param string $content Content of a single-quoted literal.
	 * @return string Raw value.
	 */
	public static function unescape( $content ) {
		return strtr( $content, self::UNESCAPES );
	}

	/**
	 * Rebuilds a statement, passing the content of each single-quoted literal through $callback.
	 * Backtick identifiers and double-quoted strings are copied as they are.
	 *
	 * @param string   $sql      Statement.
	 * @param callable $callback Receives the escaped content, returns the escaped content to write.
	 * @return string
	 */
	public static function map_literals( $sql, callable $callback ) {
		return self::walk(
			$sql,
			static function ( $code ) {
				return $code;
			},
			static function ( $quoted, $quote ) use ( $callback ) {
				return "'" === $quote ? "'" . $callback( substr( $quoted, 1, -1 ) ) . "'" : $quoted;
			}
		);
	}

	/**
	 * Rebuilds a statement, passing each stretch of text outside quotes and backticks through $callback.
	 *
	 * @param string   $sql      Statement.
	 * @param callable $callback Receives and returns unquoted text.
	 * @return string
	 */
	public static function map_code( $sql, callable $callback ) {
		return self::walk(
			$sql,
			$callback,
			static function ( $quoted ) {
				return $quoted;
			}
		);
	}

	/**
	 * Removes /* *\/ comments outside quotes, whatever they contain.
	 *
	 * @param string $sql Statement.
	 * @return string
	 */
	public static function strip_block_comments( $sql ) {
		$out    = '';
		$length = strlen( $sql );
		$pos    = 0;
		while ( $pos < $length ) {
			$plain = strcspn( $sql, "'\"`/", $pos );
			$out  .= substr( $sql, $pos, $plain );
			$pos  += $plain;
			if ( $pos >= $length ) {
				break;
			}
			if ( '/' === $sql[ $pos ] ) {
				if ( '*' !== substr( $sql, $pos + 1, 1 ) ) {
					$out .= '/';
					++$pos;
					continue;
				}
				$close = strpos( $sql, '*/', $pos + 2 );
				$pos   = false === $close ? $length : $close + 2;
				if ( '' !== $out && ctype_space( substr( $out, -1 ) ) ) {
					$pos += strspn( $sql, " \t", $pos );
				}
				continue;
			}
			$end = self::closing_quote( $sql, $pos );
			if ( null === $end ) {
				$out .= substr( $sql, $pos );
				break;
			}
			$out .= substr( $sql, $pos, $end - $pos + 1 );
			$pos  = $end + 1;
		}
		return $out;
	}

	/**
	 * Splits a statement into unquoted text and quoted runs (quotes included).
	 *
	 * @param string   $sql    Statement.
	 * @param callable $code   Maps unquoted text.
	 * @param callable $quoted Maps a quoted run; receives the run and its quote character.
	 * @return string
	 */
	private static function walk( $sql, callable $code, callable $quoted ) {
		$out    = '';
		$length = strlen( $sql );
		$pos    = 0;
		while ( $pos < $length ) {
			$plain = strcspn( $sql, "'\"`", $pos );
			if ( $plain > 0 ) {
				$out .= $code( substr( $sql, $pos, $plain ) );
				$pos += $plain;
			}
			if ( $pos >= $length ) {
				break;
			}
			$end = self::closing_quote( $sql, $pos );
			if ( null === $end ) {
				$out .= substr( $sql, $pos );
				break;
			}
			$out .= $quoted( substr( $sql, $pos, $end - $pos + 1 ), $sql[ $pos ] );
			$pos  = $end + 1;
		}
		return $out;
	}

	/**
	 * Offset of the quote closing the run that opens at $start, or null when the run never closes.
	 *
	 * @param string $sql   Statement.
	 * @param int    $start Offset of the opening quote.
	 * @return int|null
	 */
	private static function closing_quote( $sql, $start ) {
		$quote  = $sql[ $start ];
		$stops  = '`' === $quote ? '`' : $quote . '\\';
		$length = strlen( $sql );
		$pos    = $start + 1;
		while ( true ) {
			$pos += strcspn( $sql, $stops, $pos );
			if ( $pos >= $length ) {
				return null;
			}
			if ( '\\' === $sql[ $pos ] ) {
				$pos += 2;
			} elseif ( $pos + 1 < $length && $sql[ $pos + 1 ] === $quote ) {
				$pos += 2;
			} else {
				return $pos;
			}
		}
	}
}
