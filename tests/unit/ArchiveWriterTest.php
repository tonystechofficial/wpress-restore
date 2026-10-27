<?php

use PHPUnit\Framework\TestCase;
use WPressRestore\Archive_Exception;
use WPressRestore\Archive_Format;
use WPressRestore\Archive_Writer;
use WPressRestore\Codec;
use WPressRestore\Entry_Stream;

final class ArchiveWriterTest extends TestCase {

	private $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/wpr-writer-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir );
	}

	protected function tearDown(): void {
		array_map( 'unlink', glob( $this->dir . '/*' ) );
		rmdir( $this->dir );
	}

	private function source( $name, $content ) {
		file_put_contents( $this->dir . '/' . $name, $content );
		touch( $this->dir . '/' . $name, 1700000000 );
		return array(
			'source' => $this->dir . '/' . $name,
			'name'   => 'uploads/2026/' . $name,
			'mtime'  => 1700000000,
		);
	}

	private function archive() {
		return fopen( $this->dir . '/out.wpress', 'c+b' );
	}

	/** Reads the archive back with the restore's own reader: name => [header, content]. */
	private function read_back( Codec $codec ) {
		$handle  = fopen( $this->dir . '/out.wpress', 'rb' );
		$entries = array();
		for ( $offset = 0; ; ) {
			fseek( $handle, $offset );
			$header = Archive_Format::parse_header( fread( $handle, Archive_Format::HEADER_SIZE ) );
			if ( 'eof' === $header['type'] ) {
				$entries['#eof'] = array( $header, $offset );
				break;
			}
			$stream  = new Entry_Stream( $handle, $offset + Archive_Format::HEADER_SIZE, $header['size'], 0, $codec->for_entry( $header['name'] ) );
			$content = '';
			while ( null !== ( $piece = $stream->read() ) ) {
				$content .= $piece;
			}
			$entries[ $header['name'] ] = array( $header, $content );
			$offset                    += Archive_Format::HEADER_SIZE + $header['size'];
		}
		return $entries;
	}

	private static function keep_going() {
		return static function () {
			return false;
		};
	}

	public function codecs() {
		return array(
			'plain'              => array( null, null ),
			'encrypted and gzip' => array( 'pw', 'gzip' ),
			'encrypted'          => array( 'pw', null ),
		);
	}

	/**
	 * @dataProvider codecs
	 */
	public function test_writes_entries_the_restore_reads_back( $password, $compression ) {
		$codec   = new Codec( $password, $compression );
		$content = random_bytes( 1300000 );
		$entry   = $this->source( 'video.mp4', $content );
		$archive = $this->archive();
		$writer  = new Archive_Writer( $archive, $codec );

		$this->assertTrue( $writer->add( $entry, self::keep_going() ) );
		$writer->seal( $writer->checksum( 0, null, self::keep_going() )['crc'] );
		fclose( $archive );

		list( $header, $read ) = $this->read_back( $codec )['uploads/2026/video.mp4'];
		$this->assertSame( $content, $read );
		$this->assertSame( hash( 'crc32b', $content ), $header['crc32'] );
		$this->assertSame( 1700000000, $header['mtime'] );
	}

	public function test_carries_on_a_file_across_steps() {
		$content = random_bytes( 2600000 );
		$entry   = $this->source( 'big.bin', $content );
		$steps   = 0;
		$stop    = static function () {
			return true;
		};
		do {
			$archive = $this->archive();
			$done    = ( new Archive_Writer( $archive, new Codec( null, 'gzip' ) ) )->add( $entry, $stop );
			fclose( $archive );
			++$steps;
		} while ( ! $done );
		$archive = $this->archive();
		$writer  = new Archive_Writer( $archive, new Codec( null, 'gzip' ) );
		$writer->seal( $writer->checksum( 0, null, self::keep_going() )['crc'] );
		fclose( $archive );

		$this->assertGreaterThan( 2, $steps );
		$entries = $this->read_back( new Codec( null, 'gzip' ) );
		$this->assertSame( $content, $entries['uploads/2026/big.bin'][1] );
		$this->assertSame( hash( 'crc32b', $content ), $entries['uploads/2026/big.bin'][0]['crc32'] );
	}

	public function test_the_package_description_stays_readable_in_an_encrypted_backup() {
		$entry         = $this->source( 'package.json', '{"SiteURL":"x"}' );
		$entry['name'] = 'package.json';
		$archive       = $this->archive();

		( new Archive_Writer( $archive, new Codec( 'pw', 'gzip' ) ) )->add( $entry, self::keep_going() );
		fclose( $archive );

		$this->assertStringContainsString( '{"SiteURL":"x"}', file_get_contents( $this->dir . '/out.wpress' ) );
	}

	public function test_an_empty_file_has_a_zero_size_and_the_crc_of_nothing() {
		$entry   = $this->source( 'empty.txt', '' );
		$archive = $this->archive();
		$writer  = new Archive_Writer( $archive, new Codec( null, null ) );
		$writer->add( $entry, self::keep_going() );
		$writer->seal( $writer->checksum( 0, null, self::keep_going() )['crc'] );
		fclose( $archive );

		list( $header, $read ) = $this->read_back( new Codec( null, null ) )['uploads/2026/empty.txt'];
		$this->assertSame( array( 0, '00000000', '' ), array( $header['size'], $header['crc32'], $read ) );
	}

	public function test_the_end_block_holds_the_size_and_crc_of_everything_before_it() {
		$archive = $this->archive();
		$writer  = new Archive_Writer( $archive, new Codec( null, null ) );
		foreach ( array( 'a.txt' => 'first', 'b.txt' => str_repeat( 'second', 100000 ) ) as $name => $content ) {
			$entry = $this->source( $name, $content );
			$writer->add( $entry, self::keep_going() );
		}
		$sum = $writer->checksum( 0, null, self::keep_going() );
		$writer->seal( $sum['crc'] );
		fclose( $archive );

		list( $eof, $offset ) = $this->read_back( new Codec( null, null ) )['#eof'];
		$before               = substr( file_get_contents( $this->dir . '/out.wpress' ), 0, $offset );
		$this->assertSame( strlen( $before ), $eof['archive_size'] );
		$this->assertSame( hash( 'crc32b', $before ), $eof['crc32'] );
		$this->assertSame( strlen( $before ) + Archive_Format::HEADER_SIZE, filesize( $this->dir . '/out.wpress' ) );
	}

	public function test_a_missing_file_is_refused_before_anything_is_written() {
		$archive = $this->archive();
		$entry   = array(
			'source' => $this->dir . '/gone.txt',
			'name'   => 'uploads/gone.txt',
			'mtime'  => 1,
		);

		try {
			( new Archive_Writer( $archive, new Codec( null, null ) ) )->add( $entry, self::keep_going() );
			$this->fail( 'A missing file was added.' );
		} catch ( Archive_Exception $e ) {
			fclose( $archive );
			$this->assertSame( 0, filesize( $this->dir . '/out.wpress' ) );
		}
	}
}
