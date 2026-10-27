<?php

use PHPUnit\Framework\TestCase;
use WPressRestore\Archive_Exception;
use WPressRestore\Codec;
use WPressRestore\Entry_Stream;

final class EntryStreamTest extends TestCase {

	private $files = array();

	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			@unlink( $file );
		}
	}

	/** Puts the stored bytes between junk, as an entry sits between other entries in an archive. */
	private function handle_on( $stored ) {
		$file          = tempnam( sys_get_temp_dir(), 'wpr' );
		$this->files[] = $file;
		file_put_contents( $file, 'JUNK!' . $stored . 'MORE JUNK' );
		return fopen( $file, 'rb' );
	}

	private static function drain( Entry_Stream $stream ) {
		$out = '';
		while ( null !== ( $piece = $stream->read() ) ) {
			$out .= $piece;
		}
		return $out;
	}

	public function codecs() {
		$codecs = array(
			'plain'               => array( null, null ),
			'encrypted'           => array( 'pw', null ),
			'gzip'                => array( null, 'gzip' ),
			'gzip and encrypted'  => array( 'pw', 'gzip' ),
		);
		if ( function_exists( 'bzcompress' ) ) {
			$codecs['bzip2 and encrypted'] = array( 'pw', 'bzip2' );
		}
		return $codecs;
	}

	/**
	 * @dataProvider codecs
	 */
	public function test_reads_back_the_original_content( $password, $compression ) {
		$content = random_bytes( 1300000 ) . str_repeat( 'compressible ', 50000 );
		$stored  = Ai1wm::encode( $content, $password, $compression );

		$stream = new Entry_Stream( $this->handle_on( $stored ), 5, strlen( $stored ), 0, new Codec( $password, $compression ) );

		$this->assertSame( $content, self::drain( $stream ) );
		$this->assertSame( strlen( $stored ), $stream->stored_offset() );
	}

	/**
	 * @dataProvider codecs
	 */
	public function test_resumes_from_the_offset_it_reported( $password, $compression ) {
		$content = random_bytes( 1100000 );
		$stored  = Ai1wm::encode( $content, $password, $compression );
		$handle  = $this->handle_on( $stored );
		$codec   = new Codec( $password, $compression );

		$first  = new Entry_Stream( $handle, 5, strlen( $stored ), 0, $codec );
		$start  = $first->read();
		$resume = new Entry_Stream( $handle, 5, strlen( $stored ), $first->stored_offset(), $codec );

		$this->assertSame( $content, $start . self::drain( $resume ) );
	}

	/**
	 * @dataProvider codecs
	 */
	public function test_reads_what_the_codec_writes_chunk_by_chunk( $password, $compression ) {
		$content = random_bytes( 1100000 ) . str_repeat( 'x', 300000 );
		$codec   = new Codec( $password, $compression );
		$stored  = '';
		foreach ( str_split( $content, Codec::CHUNK ) as $chunk ) {
			$stored .= $codec->encode( $chunk );
		}

		$this->assertSame( $content, self::drain( new Entry_Stream( $this->handle_on( $stored ), 5, strlen( $stored ), 0, $codec ) ) );
	}

	public function test_an_empty_entry_yields_nothing() {
		$stream = new Entry_Stream( $this->handle_on( '' ), 5, 0, 0, new Codec( 'pw', 'gzip' ) );

		$this->assertNull( $stream->read() );
	}

	public function test_a_truncated_entry_is_an_error() {
		$stored = Ai1wm::encode( random_bytes( 1000 ) );
		$file   = tempnam( sys_get_temp_dir(), 'wpr' );
		$this->files[] = $file;
		file_put_contents( $file, substr( $stored, 0, 600 ) );

		$this->expectException( Archive_Exception::class );

		self::drain( new Entry_Stream( fopen( $file, 'rb' ), 0, 1000, 0, new Codec( null, null ) ) );
	}

	public function test_a_compressed_chunk_longer_than_its_entry_is_an_error() {
		$stored = pack( 'N', 999999 ) . gzcompress( 'x' );

		$this->expectException( Archive_Exception::class );

		self::drain( new Entry_Stream( $this->handle_on( $stored ), 5, strlen( $stored ), 0, new Codec( null, 'gzip' ) ) );
	}
}
