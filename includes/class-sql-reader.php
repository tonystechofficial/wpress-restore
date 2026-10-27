<?php
/**
 * Reading a SQL dump statement by statement.
 *
 * @package WPressRestore
 */

namespace WPressRestore;

/**
 * Splits a dump into statements, from any byte offset.
 *
 * A statement ends at a semicolon outside quotes, backticks and comments. Each statement comes
 * with the offset just past its semicolon, where a later request resumes. A statement longer
 * than the limit is scanned to its end without being kept in memory, and reported as too large.
 */
final class Sql_Reader {

	const CODE          = 0;
	const SINGLE        = 1;
	const DOUBLE        = 2;
	const BACKTICK      = 3;
	const LINE_COMMENT  = 4;
	const BLOCK_COMMENT = 5;

	/**
	 * Dump handle.
	 *
	 * @var resource
	 */
	private $handle;

	/**
	 * Largest statement kept, in bytes.
	 *
	 * @var int
	 */
	private $max_bytes;

	/**
	 * Bytes per read.
	 *
	 * @var int
	 */
	private $read_size;

	/**
	 * Unconsumed bytes, starting at the next statement.
	 *
	 * @var string
	 */
	private $buffer = '';

	/**
	 * Dump offset of $buffer[0].
	 *
	 * @var int
	 */
	private $buffer_start;

	/**
	 * @param resource $handle    Dump opened for reading.
	 * @param int      $offset    Where to start: 0 or an "end" returned earlier.
	 * @param int      $max_bytes Largest statement to return; longer ones come back with sql = null.
	 * @param int      $read_size Bytes per read.
	 */
	public function __construct( $handle, $offset, $max_bytes, $read_size = 1048576 ) {
		$this->handle       = $handle;
		$this->max_bytes    = $max_bytes;
		$this->read_size    = max( 1, $read_size );
		$this->buffer_start = $offset;
		fseek( $handle, $offset );
	}

	/**
	 * Next statement.
	 *
	 * @return array|null sql (trimmed, without the semicolon; null when too large), start, end (offset after
	 *                    the semicolon), too_large, complete (false for text the dump leaves unterminated);
	 *                    null at the end of the dump.
	 */
	public function next() {
		$start     = $this->buffer_start;
		$state     = self::CODE;
		$pos       = 0;
		$has_code  = false;
		$too_large = false;

		while ( true ) {
			$length = strlen( $this->buffer );
			if ( ! $too_large && $this->buffer_start - $start + $pos > $this->max_bytes ) {
				$too_large = true;
			}
			if ( $too_large && $pos > 0 ) {
				$this->consume( $pos );
				$length = strlen( $this->buffer );
				$pos    = 0;
			}

			if ( $pos >= $length ) {
				if ( $this->fill() ) {
					continue;
				}
				return $this->finish( $start, $has_code, $too_large );
			}

			switch ( $state ) {
				case self::CODE:
					$skip = strcspn( $this->buffer, "'\"`;-#/", $pos );
					if ( $skip > 0 && strspn( $this->buffer, " \t\r\n", $pos, $skip ) < $skip ) {
						$has_code = true;
					}
					$pos += $skip;
					if ( $pos >= $length ) {
						break;
					}
					$char = $this->buffer[ $pos ];
					if ( ';' === $char ) {
						$sql = $too_large ? null : trim( substr( $this->buffer, 0, $pos ) );
						$this->consume( $pos + 1 );
						return array(
							'sql'       => $sql,
							'start'     => $start,
							'end'       => $this->buffer_start,
							'too_large' => $too_large,
							'complete'  => true,
						);
					}
					if ( '-' === $char || '/' === $char ) {
						$need = '-' === $char ? 2 : 1;
						if ( $pos + $need >= $length && $this->fill() ) {
							continue 2;
						}
						$after = substr( $this->buffer, $pos + 1, $need );
						if ( '-' === $char && '-' === substr( $after, 0, 1 ) && ( strlen( $after ) < 2 || ctype_space( $after[1] ) || ctype_cntrl( $after[1] ) ) ) {
							$state = self::LINE_COMMENT;
							$pos  += 2;
						} elseif ( '/' === $char && '*' === $after ) {
							$state = self::BLOCK_COMMENT;
							$pos  += 2;
						} else {
							$has_code = true;
							++$pos;
						}
						break;
					}
					if ( '#' === $char ) {
						$state = self::LINE_COMMENT;
					} else {
						$has_code = true;
						$state    = "'" === $char ? self::SINGLE : ( '"' === $char ? self::DOUBLE : self::BACKTICK );
					}
					++$pos;
					break;

				case self::SINGLE:
				case self::DOUBLE:
				case self::BACKTICK:
					$quote = self::SINGLE === $state ? "'" : ( self::DOUBLE === $state ? '"' : '`' );
					$pos  += strcspn( $this->buffer, self::BACKTICK === $state ? '`' : $quote . '\\', $pos );
					if ( $pos >= $length ) {
						break;
					}
					if ( $pos + 1 >= $length && $this->fill() ) {
						continue 2;
					}
					if ( '\\' === $this->buffer[ $pos ] ) {
						$pos += 2;
					} elseif ( $pos + 1 < strlen( $this->buffer ) && $this->buffer[ $pos + 1 ] === $quote ) {
						$pos += 2;
					} else {
						$state = self::CODE;
						++$pos;
					}
					break;

				case self::LINE_COMMENT:
					$newline = strpos( $this->buffer, "\n", $pos );
					if ( false === $newline ) {
						$pos = $length;
					} else {
						$state = self::CODE;
						$pos   = $newline + 1;
					}
					break;

				case self::BLOCK_COMMENT:
					$close = strpos( $this->buffer, '*/', $pos );
					if ( false === $close ) {
						// Keep a trailing "*" in view: the "/" may come with the next read.
						$pos = max( $pos, $length - 1 );
						if ( $this->fill() ) {
							continue 2;
						}
						$pos = strlen( $this->buffer );
					} else {
						$state = self::CODE;
						$pos   = $close + 2;
					}
					break;
			}
		}
	}

	/**
	 * What is left when the dump ends without a semicolon.
	 *
	 * @param int  $start     Statement start.
	 * @param bool $has_code  Whether anything but whitespace and comments was seen.
	 * @param bool $too_large Whether the text was too large to keep.
	 * @return array|null
	 */
	private function finish( $start, $has_code, $too_large ) {
		$sql = trim( $this->buffer );
		$this->consume( strlen( $this->buffer ) );
		if ( ! $has_code ) {
			return null;
		}
		return array(
			'sql'       => $too_large ? null : $sql,
			'start'     => $start,
			'end'       => $this->buffer_start,
			'too_large' => $too_large,
			'complete'  => false,
		);
	}

	/**
	 * Appends the next read to the buffer.
	 *
	 * @return bool False at the end of the dump.
	 */
	private function fill() {
		$data = fread( $this->handle, $this->read_size );
		if ( false === $data || '' === $data ) {
			return false;
		}
		$this->buffer .= $data;
		return true;
	}

	/**
	 * Drops bytes from the front of the buffer.
	 *
	 * @param int $bytes Count.
	 */
	private function consume( $bytes ) {
		$this->buffer        = (string) substr( $this->buffer, $bytes );
		$this->buffer_start += $bytes;
	}
}
