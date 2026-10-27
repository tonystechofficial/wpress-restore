<?php
/**
 * Reading one file out of an archive.
 *
 * @package WPressRestore
 */

namespace WPressRestore;

/**
 * Reads the content of one archive entry piece by piece, decoded.
 *
 * Between two reads the position is always at a chunk boundary, so the work can stop after any
 * piece and a new stream, created at stored_offset(), carries on in a later request.
 */
final class Entry_Stream {

	/** Read size for plain entries. */
	const PLAIN_READ = 1048576;

	/**
	 * Archive handle; shared, so each read seeks first.
	 *
	 * @var resource
	 */
	private $handle;

	/**
	 * Archive offset of the entry's first stored byte.
	 *
	 * @var int
	 */
	private $data_start;

	/**
	 * Stored size, from the header.
	 *
	 * @var int
	 */
	private $stored_size;

	/**
	 * Stored bytes consumed so far.
	 *
	 * @var int
	 */
	private $offset;

	/**
	 * Decoder for this entry.
	 *
	 * @var Codec
	 */
	private $codec;

	/**
	 * @param resource $handle        Archive opened for reading.
	 * @param int      $data_start    Offset of the entry's content in the archive.
	 * @param int      $stored_size   Stored size of the entry.
	 * @param int      $stored_offset Where to resume, as returned by stored_offset().
	 * @param Codec    $codec         Decoder for this entry.
	 */
	public function __construct( $handle, $data_start, $stored_size, $stored_offset, Codec $codec ) {
		$this->handle      = $handle;
		$this->data_start  = $data_start;
		$this->stored_size = $stored_size;
		$this->offset      = $stored_offset;
		$this->codec       = $codec;
	}

	/**
	 * Stored bytes consumed: where a later stream resumes.
	 *
	 * @return int
	 */
	public function stored_offset() {
		return $this->offset;
	}

	/**
	 * Next piece of content.
	 *
	 * @return string|null Decoded bytes, or null once the entry is consumed.
	 * @throws Archive_Exception When the archive is cut short or a chunk cannot be decoded.
	 */
	public function read() {
		$remaining = $this->stored_size - $this->offset;
		if ( $remaining <= 0 ) {
			return null;
		}
		if ( fseek( $this->handle, $this->data_start + $this->offset ) !== 0 ) {
			throw new Archive_Exception( 'Cannot move inside the backup file.' );
		}

		if ( $this->codec->is_compressed() ) {
			$length = $remaining >= 4 ? unpack( 'N', $this->exactly( 4 ) )[1] : PHP_INT_MAX;
			if ( $length > $remaining - 4 ) {
				throw new Archive_Exception( 'A compressed part runs past the end of its file: the backup file is damaged.' );
			}
			$stored        = $this->exactly( $length );
			$this->offset += 4 + $length;
			return $this->codec->decode( $stored );
		}
		if ( $this->codec->is_encrypted() ) {
			$stored        = $this->exactly( Codec::encrypted_chunk_length( $remaining ) );
			$this->offset += strlen( $stored );
			return $this->codec->decode( $stored );
		}
		$stored        = $this->exactly( min( self::PLAIN_READ, $remaining ) );
		$this->offset += strlen( $stored );
		return $stored;
	}

	/**
	 * Reads exactly $length bytes.
	 *
	 * @param int $length Byte count.
	 * @return string
	 * @throws Archive_Exception When the archive ends first.
	 */
	private function exactly( $length ) {
		$data = '';
		while ( strlen( $data ) < $length ) {
			$piece = fread( $this->handle, $length - strlen( $data ) );
			if ( false === $piece || '' === $piece ) {
				throw new Archive_Exception( 'The backup file ends in the middle of a file: it is incomplete.' );
			}
			$data .= $piece;
		}
		return $data;
	}
}
