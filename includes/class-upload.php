<?php
/**
 * Uploading a backup in chunks.
 *
 * @package WPressRestore
 */

namespace WPressRestore;

/**
 * Assembles a backup sent in chunks small enough for any PHP upload limit.
 *
 * Chunks are appended to "<name>.part" only at the expected offset, so a lost or repeated chunk
 * cannot corrupt the file: the client is told where to continue. The finished file takes its
 * name only once complete.
 */
final class Upload {

	/**
	 * Backups folder.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * @param string $dir Backups folder.
	 */
	public function __construct( $dir ) {
		$this->dir = rtrim( $dir, '/\\' );
	}

	/**
	 * A safe file name for a backup, or null when the name is not a .wpress file.
	 *
	 * @param string $name Name sent by the browser.
	 * @return string|null
	 */
	public static function safe_name( $name ) {
		$name  = str_replace( '\\', '/', (string) $name );
		$slash = strrpos( $name, '/' );
		$name  = false === $slash ? $name : substr( $name, $slash + 1 );
		if ( ! preg_match( '/^(.*)\.wpress$/i', $name, $match ) ) {
			return null;
		}
		$stem = ltrim( (string) preg_replace( '/[^A-Za-z0-9._-]+/', '-', $match[1] ), '.-' );
		return '' === $stem ? null : substr( $stem, 0, 180 ) . '.wpress';
	}

	/**
	 * Starts or resumes an upload.
	 *
	 * @param string $name      File name.
	 * @param int    $size      Total size.
	 * @param bool   $overwrite Whether an existing backup of that name may be replaced.
	 * @return int Offset to send from.
	 * @throws \RuntimeException "exists" when the backup exists and may not be replaced; other messages on bad input.
	 */
	public function begin( $name, $size, $overwrite ) {
		$final = $this->path( $name );
		if ( $size <= 0 ) {
			throw new \RuntimeException( 'The file is empty.' );
		}
		if ( file_exists( $final ) && ! $overwrite ) {
			throw new \RuntimeException( 'exists' );
		}
		$part = $final . '.part';
		clearstatcache( true, $part );
		if ( is_file( $part ) && filesize( $part ) <= $size ) {
			$have = (int) filesize( $part );
			// Complete but never renamed: the request that wrote the last chunk stopped short.
			if ( $have === $size ) {
				self::finish( $part, $final );
			}
			return $have;
		}
		if ( false === file_put_contents( $part, '' ) ) {
			throw new \RuntimeException( 'The backups folder is not writable.' );
		}
		return 0;
	}

	/**
	 * Appends a chunk sent for $offset.
	 *
	 * @param string $name   File name.
	 * @param int    $size   Total size.
	 * @param int    $offset Offset the chunk was sent for.
	 * @param string $chunk  Path of the uploaded chunk.
	 * @return array offset (where the next chunk goes), done.
	 * @throws \RuntimeException When the chunk does not fit or cannot be written.
	 */
	public function append( $name, $size, $offset, $chunk ) {
		$final = $this->path( $name );
		$part  = $final . '.part';
		clearstatcache( true, $part );
		$have = is_file( $part ) ? (int) filesize( $part ) : 0;
		if ( $offset !== $have ) {
			return array(
				'offset' => $have,
				'done'   => false,
			);
		}
		$length = (int) filesize( $chunk );
		if ( $offset + $length > $size ) {
			throw new \RuntimeException( 'The chunk goes past the end of the file.' );
		}

		$in  = fopen( $chunk, 'rb' );
		$out = fopen( $part, 'ab' );
		if ( ! $in || ! $out ) {
			throw new \RuntimeException( 'The backups folder is not writable.' );
		}
		$copied = stream_copy_to_stream( $in, $out );
		fclose( $in );
		fclose( $out );
		if ( $copied !== $length ) {
			throw new \RuntimeException( 'The chunk could not be written completely: the disk may be full.' );
		}

		$offset += $length;
		if ( $offset === $size ) {
			self::finish( $part, $final );
		}
		return array(
			'offset' => $offset,
			'done'   => $offset === $size,
		);
	}

	/**
	 * Gives a complete upload its name.
	 *
	 * @param string $part  Unfinished file.
	 * @param string $final Backup name.
	 * @throws \RuntimeException When the rename fails.
	 */
	private static function finish( $part, $final ) {
		if ( file_exists( $final ) ) {
			unlink( $final );
		}
		if ( ! rename( $part, $final ) ) {
			throw new \RuntimeException( 'The uploaded file could not be renamed.' );
		}
	}

	/**
	 * Path of a backup in the folder.
	 *
	 * @param string $name File name.
	 * @return string
	 * @throws \RuntimeException When the name is not a backup name.
	 */
	private function path( $name ) {
		$safe = self::safe_name( $name );
		if ( null === $safe || $safe !== $name ) {
			throw new \RuntimeException( 'Only .wpress files can be uploaded.' );
		}
		return $this->dir . '/' . $safe;
	}
}
