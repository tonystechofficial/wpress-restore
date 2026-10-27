<?php
/**
 * Writing a .wpress archive.
 *
 * @package WPressRestore
 */

namespace WPressRestore;

/**
 * Appends files to a .wpress archive, as All-in-One WP Migration 7 writes them.
 *
 * Each file gets a header whose size and CRC32 are filled in once its content is written, so a
 * large file can be written over several requests: the entry array records where it stands, and
 * a later call carries on from there after cutting off anything written past that point.
 */
final class Archive_Writer {

	/**
	 * Archive, open for reading and writing.
	 *
	 * @var resource
	 */
	private $archive;

	/**
	 * Encoder for file contents.
	 *
	 * @var Codec
	 */
	private $codec;

	/**
	 * @param resource $archive Archive opened with mode "c+b".
	 * @param Codec    $codec   Encoder.
	 */
	public function __construct( $archive, Codec $codec ) {
		$this->archive = $archive;
		$this->codec   = $codec;
	}

	/**
	 * Adds a file, or carries on adding it.
	 *
	 * @param array    $entry source (path), name (in the archive), mtime; then at, read, stored, crc, kept by
	 *                        this method between calls.
	 * @param callable $stop  Returns true when the work must stop for this request.
	 * @return bool Whether the file is complete.
	 * @throws Archive_Exception When the file cannot be read or the archive written; nothing is left half-written
	 *                           for a file that could not be opened.
	 */
	public function add( array &$entry, callable $stop ) {
		$codec  = $this->codec->for_entry( $entry['name'] );
		$source = @fopen( $entry['source'], 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- failure is reported.
		if ( ! $source ) {
			throw new Archive_Exception( sprintf( 'Cannot read %s.', $entry['source'] ) );
		}
		if ( ! isset( $entry['at'] ) ) {
			fseek( $this->archive, 0, SEEK_END );
			$entry['at']     = ftell( $this->archive );
			$entry['read']   = 0;
			$entry['stored'] = 0;
			$entry['crc']    = null;
			$this->put( Archive_Format::build_header( $entry['name'], 0, $entry['mtime'], '' ) );
		} else {
			// Whatever an interrupted request wrote after the last saved point goes.
			ftruncate( $this->archive, $entry['at'] + Archive_Format::HEADER_SIZE + $entry['stored'] );
			fseek( $this->archive, 0, SEEK_END );
			fseek( $source, $entry['read'] );
		}

		$hash = hash_init( 'crc32b' );
		$read = 0;
		$done = false;
		while ( true ) {
			$chunk = self::read_chunk( $source );
			if ( '' === $chunk ) {
				$done = true;
				break;
			}
			hash_update( $hash, $chunk );
			$read += strlen( $chunk );
			$stored = $codec->encode( $chunk );
			$this->put( $stored );
			$entry['stored'] += strlen( $stored );
			if ( strlen( $chunk ) < Codec::CHUNK ) {
				$done = true;
				break;
			}
			if ( $stop() ) {
				break;
			}
		}
		fclose( $source );
		if ( $read > 0 ) {
			$entry['crc'] = Crc32::combine( $entry['crc'], hash_final( $hash ), $read );
		}
		$entry['read'] += $read;
		if ( ! $done ) {
			return false;
		}

		fseek( $this->archive, $entry['at'] + 255 );
		$this->put( pack( 'a14', $entry['stored'] ) );
		fseek( $this->archive, $entry['at'] + 4369 );
		$this->put( pack( 'a8', null === $entry['crc'] ? '00000000' : $entry['crc'] ) );
		fseek( $this->archive, 0, SEEK_END );
		return true;
	}

	/**
	 * Carries on computing the CRC32 of the archive, from $offset to its current end.
	 *
	 * @param int         $offset Bytes already summed.
	 * @param string|null $crc    Their CRC.
	 * @param callable    $stop   Returns true when the work must stop for this request.
	 * @return array offset, crc, done.
	 */
	public function checksum( $offset, $crc, callable $stop ) {
		fseek( $this->archive, 0, SEEK_END );
		$end = ftell( $this->archive );
		fseek( $this->archive, $offset );
		$hash = hash_init( 'crc32b' );
		$read = 0;
		while ( $offset + $read < $end ) {
			$chunk = fread( $this->archive, (int) min( 4 * 1048576, $end - $offset - $read ) );
			if ( false === $chunk || '' === $chunk ) {
				throw new Archive_Exception( 'The backup being written cannot be read back.' );
			}
			hash_update( $hash, $chunk );
			$read += strlen( $chunk );
			if ( $stop() ) {
				break;
			}
		}
		if ( $read > 0 ) {
			$crc = Crc32::combine( $crc, hash_final( $hash ), $read );
		}
		return array(
			'offset' => $offset + $read,
			'crc'    => $crc,
			'done'   => $offset + $read >= $end,
		);
	}

	/**
	 * Ends the archive with the block giving its size and CRC32.
	 *
	 * @param string $crc CRC32 of everything written so far.
	 */
	public function seal( $crc ) {
		fseek( $this->archive, 0, SEEK_END );
		$this->put( Archive_Format::build_eof( ftell( $this->archive ), null === $crc ? '00000000' : $crc ) );
	}

	/**
	 * Reads one chunk in full: a short read only at the end of the file, as the decoder expects.
	 *
	 * @param resource $source File.
	 * @return string
	 */
	private static function read_chunk( $source ) {
		$chunk = '';
		while ( strlen( $chunk ) < Codec::CHUNK ) {
			$piece = fread( $source, Codec::CHUNK - strlen( $chunk ) );
			if ( false === $piece || '' === $piece ) {
				break;
			}
			$chunk .= $piece;
		}
		return $chunk;
	}

	/**
	 * Writes all of $data.
	 *
	 * @param string $data Bytes.
	 * @throws Archive_Exception When the disk takes less.
	 */
	private function put( $data ) {
		if ( '' !== $data && fwrite( $this->archive, $data ) !== strlen( $data ) ) {
			throw new Archive_Exception( 'Writing the backup failed: the disk is probably full.' );
		}
	}
}
