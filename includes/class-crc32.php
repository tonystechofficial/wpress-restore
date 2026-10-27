<?php
/**
 * CRC32 arithmetic.
 *
 * @package WPressRestore
 */

namespace WPressRestore;

/**
 * Combines CRC32 values, so a checksum computed over many requests matches hash( 'crc32b' ) of the whole.
 *
 * This is zlib's crc32_combine(): it appends len2 zero bytes to crc1 with GF(2) matrix powers,
 * then folds in crc2. It needs 64-bit integers.
 */
final class Crc32 {

	/**
	 * CRC32 of A followed by B, from the CRC of each and the length of B.
	 *
	 * @param string|null $crc1 CRC of A as 8 hex digits, null when there is no A yet.
	 * @param string      $crc2 CRC of B as 8 hex digits.
	 * @param int         $len2 Length of B in bytes.
	 * @return string 8 hex digits.
	 */
	public static function combine( $crc1, $crc2, $len2 ) {
		if ( null === $crc1 || '' === $crc1 ) {
			return strtolower( $crc2 );
		}
		if ( $len2 <= 0 ) {
			return strtolower( $crc1 );
		}

		$value = hexdec( $crc1 );
		$odd   = array( 0xEDB88320 );
		$row   = 1;
		for ( $n = 1; $n < 32; $n++ ) {
			$odd[] = $row;
			$row <<= 1;
		}
		$even = self::square( $odd );
		$odd  = self::square( $even );

		do {
			$even = self::square( $odd );
			if ( $len2 & 1 ) {
				$value = self::times( $even, $value );
			}
			$len2 >>= 1;
			if ( 0 === $len2 ) {
				break;
			}
			$odd = self::square( $even );
			if ( $len2 & 1 ) {
				$value = self::times( $odd, $value );
			}
			$len2 >>= 1;
		} while ( 0 !== $len2 );

		return sprintf( '%08x', ( $value ^ hexdec( $crc2 ) ) & 0xFFFFFFFF );
	}

	/**
	 * Matrix times vector over GF(2).
	 *
	 * @param int[] $matrix 32 rows.
	 * @param int   $vector 32-bit value.
	 * @return int
	 */
	private static function times( array $matrix, $vector ) {
		$sum = 0;
		for ( $i = 0; $vector; $i++, $vector >>= 1 ) {
			if ( $vector & 1 ) {
				$sum ^= $matrix[ $i ];
			}
		}
		return $sum;
	}

	/**
	 * Matrix squared over GF(2).
	 *
	 * @param int[] $matrix 32 rows.
	 * @return int[]
	 */
	private static function square( array $matrix ) {
		$square = array();
		for ( $n = 0; $n < 32; $n++ ) {
			$square[] = self::times( $matrix, $matrix[ $n ] );
		}
		return $square;
	}
}
