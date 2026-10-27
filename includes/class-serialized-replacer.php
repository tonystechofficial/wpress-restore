<?php
/**
 * Search and replace that keeps serialized PHP data valid.
 *
 * @package WPressRestore
 */

namespace WPressRestore;

/**
 * Replaces strings in database values, rewriting the byte lengths of serialized strings.
 *
 * A value that parses as serialized data is walked token by token: each string is replaced (or,
 * when it holds serialized data itself, walked in turn) and written back with its new length.
 * Nothing is unserialized, so no class is loaded. A value that does not parse fully gets a plain
 * replacement, as All-in-One WP Migration does. Replacement is strtr(): longest match first,
 * nothing replaced twice.
 */
final class Serialized_Replacer {

	/**
	 * Search => replace.
	 *
	 * @var string[]
	 */
	private $pairs = array();

	/**
	 * Whether some pair changes its text.
	 *
	 * @var bool
	 */
	private $changes = false;

	/**
	 * @param string[] $pairs Search => replace. Empty searches are ignored.
	 */
	public function __construct( array $pairs ) {
		foreach ( $pairs as $from => $to ) {
			$from = (string) $from;
			if ( '' === $from ) {
				continue;
			}
			$this->pairs[ $from ] = (string) $to;
			if ( $from !== (string) $to ) {
				$this->changes = true;
			}
		}
	}

	/**
	 * Whether replacing could change anything.
	 *
	 * @return bool
	 */
	public function has_changes() {
		return $this->changes;
	}

	/**
	 * The searched strings whose replacement differs: a value holding none of them cannot change.
	 *
	 * @return string[]
	 */
	public function changing_needles() {
		$needles = array();
		foreach ( $this->pairs as $from => $to ) {
			if ( (string) $from !== $to ) {
				$needles[] = (string) $from;
			}
		}
		return $needles;
	}

	/**
	 * Replaces in one value.
	 *
	 * @param string $value Database value.
	 * @return string
	 */
	public function replace( $value ) {
		if ( ! $this->changes || '' === $value ) {
			return $value;
		}
		$pos    = 0;
		$parsed = $this->parse( $value, $pos );
		if ( null !== $parsed && strlen( $value ) === $pos ) {
			return $parsed;
		}
		return strtr( $value, $this->pairs );
	}

	/**
	 * Rewrites the serialized token at $pos and moves $pos past it.
	 *
	 * @param string $data Serialized data.
	 * @param int    $pos  Current offset.
	 * @return string|null The rewritten token, or null when $data is not serialized there.
	 */
	private function parse( $data, &$pos ) {
		if ( ! isset( $data[ $pos ] ) ) {
			return null;
		}
		switch ( $data[ $pos ] ) {
			case 's':
				$string = $this->quoted( $data, $pos, 's', '";' );
				if ( null === $string ) {
					return null;
				}
				$string = $this->replace_in_string( $string );
				return 's:' . strlen( $string ) . ':"' . $string . '";';

			case 'E':
				$start = $pos;
				return null === $this->quoted( $data, $pos, 'E', '";' ) ? null : substr( $data, $start, $pos - $start );

			case 'i':
			case 'd':
			case 'b':
				return $this->token( '/\G[idb]:[^;:{}"]*;/', $data, $pos );

			case 'N':
				return $this->token( '/\GN;/', $data, $pos );

			case 'R':
			case 'r':
				return $this->token( '/\G[Rr]:\d+;/', $data, $pos );

			case 'a':
				$head = $this->token( '/\Ga:(\d+):\{/', $data, $pos, $count );
				return null === $head ? null : $this->members( $data, $pos, $head, 2 * $count );

			case 'O':
				$start = $pos;
				$class = $this->quoted( $data, $pos, 'O', '":' );
				if ( null === $class ) {
					return null;
				}
				$head = $this->token( '/\G(\d+):\{/', $data, $pos, $count );
				return null === $head ? null : $this->members( $data, $pos, substr( $data, $start, $pos - $start ), 2 * $count );

			case 'C':
				$start = $pos;
				if ( null === $this->quoted( $data, $pos, 'C', '":' ) || null === $this->token( '/\G(\d+):\{/', $data, $pos, $length ) ) {
					return null;
				}
				if ( '}' !== substr( $data, $pos + $length, 1 ) ) {
					return null;
				}
				$pos += $length + 1;
				return substr( $data, $start, $pos - $start );
		}
		return null;
	}

	/**
	 * Reads type:length:"bytes" followed by $closing, and returns the bytes.
	 *
	 * @param string $data    Serialized data.
	 * @param int    $pos     Current offset, moved past the closing.
	 * @param string $type    Type letter.
	 * @param string $closing What follows the closing quote.
	 * @return string|null
	 */
	private function quoted( $data, &$pos, $type, $closing ) {
		if ( ! preg_match( '/\G' . $type . ':(\d+):"/', $data, $match, 0, $pos ) ) {
			return null;
		}
		$start  = $pos + strlen( $match[0] );
		$length = (int) $match[1];
		if ( substr( $data, $start + $length, strlen( $closing ) ) !== $closing ) {
			return null;
		}
		$pos = $start + $length + strlen( $closing );
		return (string) substr( $data, $start, $length );
	}

	/**
	 * Matches a fixed token at $pos.
	 *
	 * @param string   $pattern Anchored pattern; its first group, if any, is a count.
	 * @param string   $data    Serialized data.
	 * @param int      $pos     Current offset, moved past the token.
	 * @param int|null $count   Receives the first group as an integer.
	 * @return string|null
	 */
	private function token( $pattern, $data, &$pos, &$count = null ) {
		if ( ! preg_match( $pattern, $data, $match, 0, $pos ) ) {
			return null;
		}
		$pos  += strlen( $match[0] );
		$count = isset( $match[1] ) ? (int) $match[1] : null;
		return $match[0];
	}

	/**
	 * Rewrites $count members and the closing brace of an array or object.
	 *
	 * @param string $data  Serialized data.
	 * @param int    $pos   Offset of the first member.
	 * @param string $head  Already rewritten opening.
	 * @param int    $count Keys plus values.
	 * @return string|null
	 */
	private function members( $data, &$pos, $head, $count ) {
		$out = $head;
		for ( $i = 0; $i < $count; $i++ ) {
			$member = $this->parse( $data, $pos );
			if ( null === $member ) {
				return null;
			}
			$out .= $member;
		}
		if ( '}' !== substr( $data, $pos, 1 ) ) {
			return null;
		}
		++$pos;
		return $out . '}';
	}

	/**
	 * Replaces inside a serialized string, which may hold serialized data itself.
	 *
	 * @param string $string String content.
	 * @return string
	 */
	private function replace_in_string( $string ) {
		if ( strlen( $string ) > 1 ) {
			$pos    = 0;
			$parsed = $this->parse( $string, $pos );
			if ( null !== $parsed && strlen( $string ) === $pos ) {
				return $parsed;
			}
		}
		return strtr( $string, $this->pairs );
	}
}
