<?php
/**
 * The .wpress block layout.
 *
 * @package WPressRestore
 */

namespace WPressRestore;

/**
 * Reads and writes the 4377-byte blocks of a .wpress archive.
 *
 * Each file is a header followed by its stored content. Version 7 packs the header as
 * name (255) | size (14) | mtime (12) | path (4088) | crc32 (8). Older versions had a 4096-byte
 * path and no CRC; they read the same, the last path bytes being NUL. The archive ends with a
 * block that is all NUL (old) or carries the size and CRC of everything before it.
 */
final class Archive_Format {

	const HEADER_SIZE = 4377;

	/**
	 * Decodes one block.
	 *
	 * @param string $block HEADER_SIZE bytes.
	 * @return array Either type "file" with name (relative, "/" separated), size, mtime, crc32 ("" when absent),
	 *               or type "eof" with archive_size and crc32 (both null for the old format).
	 * @throws Archive_Exception When the block is not a header.
	 */
	public static function parse_header( $block ) {
		if ( strlen( $block ) !== self::HEADER_SIZE ) {
			throw new Archive_Exception( 'The backup file ends in the middle of a file header: it is incomplete.' );
		}
		if ( strspn( $block, "\0" ) === self::HEADER_SIZE ) {
			return array(
				'type'         => 'eof',
				'archive_size' => null,
				'crc32'        => null,
			);
		}

		$name  = rtrim( substr( $block, 0, 255 ), "\0" );
		$size  = trim( substr( $block, 255, 14 ), "\0 " );
		$mtime = trim( substr( $block, 269, 12 ), "\0 " );
		$path  = rtrim( substr( $block, 281, 4088 ), "\0" );
		$crc   = trim( substr( $block, 4369, 8 ), "\0 " );

		if ( '' === $name ) {
			if ( '' === $mtime && '' === $path && ctype_digit( $size ) && self::is_crc( $crc ) ) {
				return array(
					'type'         => 'eof',
					'archive_size' => (int) $size,
					'crc32'        => strtolower( $crc ),
				);
			}
			throw new Archive_Exception( 'A file header has no file name: the backup file is damaged.' );
		}
		if ( ! ctype_digit( $size ) || ( '' !== $mtime && ! ctype_digit( $mtime ) ) || '' === $path || ( '' !== $crc && ! self::is_crc( $crc ) ) ) {
			throw new Archive_Exception( 'A file header is unreadable: the backup file is damaged.' );
		}

		return array(
			'type'  => 'file',
			'name'  => '.' === $path ? $name : $path . '/' . $name,
			'size'  => (int) $size,
			'mtime' => (int) $mtime,
			'crc32' => strtolower( $crc ),
		);
	}

	/**
	 * Encodes a file header the way version 7 writes it.
	 *
	 * @param string $name  Relative name, "/" separated.
	 * @param int    $size  Stored size.
	 * @param int    $mtime Modification time.
	 * @param string $crc   CRC32 of the original content, 8 hex digits, or "".
	 * @return string
	 */
	public static function build_header( $name, $size, $mtime, $crc = '' ) {
		$slash = strrpos( $name, '/' );
		$path  = false === $slash ? '.' : substr( $name, 0, $slash );
		$base  = false === $slash ? $name : substr( $name, $slash + 1 );
		return pack( 'a255a14a12a4088a8', $base, $size, $mtime, $path, $crc );
	}

	/**
	 * Encodes the end block: all NUL without arguments, with the archive size and CRC otherwise.
	 *
	 * @param int|null    $archive_size Bytes before the end block.
	 * @param string|null $crc          CRC32 of those bytes.
	 * @return string
	 */
	public static function build_eof( $archive_size = null, $crc = null ) {
		if ( null === $archive_size ) {
			return str_repeat( "\0", self::HEADER_SIZE );
		}
		return pack( 'a255a14a4100a8', '', $archive_size, '', $crc );
	}

	/**
	 * Whether a string is a CRC32 written as 8 hex digits.
	 *
	 * @param string $value Field value.
	 * @return bool
	 */
	private static function is_crc( $value ) {
		return 8 === strlen( $value ) && ctype_xdigit( $value );
	}
}
