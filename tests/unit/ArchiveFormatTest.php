<?php

use PHPUnit\Framework\TestCase;
use WPressRestore\Archive_Exception;
use WPressRestore\Archive_Format;

final class ArchiveFormatTest extends TestCase {

	/** A file header packed exactly as All-in-One WP Migration 7.x writes it. */
	private static function v2_header( $name, $size, $mtime, $path, $crc ) {
		return pack( 'a255a14a12a4088a8', $name, $size, $mtime, $path, $crc );
	}

	public function test_reads_a_file_header_of_the_current_format() {
		$entry = Archive_Format::parse_header( self::v2_header( 'akismet.php', 12345, 1700000000, 'plugins/akismet', '0a1b2c3d' ) );

		$this->assertSame( 'file', $entry['type'] );
		$this->assertSame( 'plugins/akismet/akismet.php', $entry['name'] );
		$this->assertSame( 12345, $entry['size'] );
		$this->assertSame( 1700000000, $entry['mtime'] );
		$this->assertSame( '0a1b2c3d', $entry['crc32'] );
	}

	public function test_a_root_file_has_a_dot_for_its_path() {
		$entry = Archive_Format::parse_header( self::v2_header( 'package.json', 10, 1, '.', '' ) );

		$this->assertSame( 'package.json', $entry['name'] );
	}

	public function test_reads_a_header_of_the_old_format_which_has_no_crc() {
		$entry = Archive_Format::parse_header( pack( 'a255a14a12a4096', 'style.css', 7, 5, 'themes/x' ) );

		$this->assertSame( 'themes/x/style.css', $entry['name'] );
		$this->assertSame( '', $entry['crc32'] );
	}

	public function test_an_all_zero_block_is_the_old_end_of_archive() {
		$entry = Archive_Format::parse_header( str_repeat( "\0", Archive_Format::HEADER_SIZE ) );

		$this->assertSame( 'eof', $entry['type'] );
		$this->assertNull( $entry['crc32'] );
	}

	public function test_the_current_end_block_carries_the_archive_size_and_crc() {
		$entry = Archive_Format::parse_header( pack( 'a255a14a4100a8', '', 987654321, '', 'deadbeef' ) );

		$this->assertSame( 'eof', $entry['type'] );
		$this->assertSame( 987654321, $entry['archive_size'] );
		$this->assertSame( 'deadbeef', $entry['crc32'] );
	}

	public function test_sizes_beyond_4_gb_stay_exact() {
		$entry = Archive_Format::parse_header( self::v2_header( 'video.mp4', '5368709120', 1, 'uploads/2024/01', '' ) );

		$this->assertSame( 5368709120, $entry['size'] );
	}

	/**
	 * @dataProvider broken_blocks
	 */
	public function test_rejects_a_block_that_is_not_a_header( $block ) {
		$this->expectException( Archive_Exception::class );

		Archive_Format::parse_header( $block );
	}

	public function broken_blocks() {
		return array(
			'truncated block'      => array( str_repeat( "\0", 100 ) ),
			'size is not a number' => array( self::v2_header( 'a.txt', '12a', 1, '.', '' ) ),
			'no file name'         => array( self::v2_header( '', 5, 1, 'uploads', '' ) ),
			'malformed crc'        => array( self::v2_header( 'a.txt', 5, 1, '.', 'zz' ) ),
			'text, not a header'   => array( str_repeat( 'x', Archive_Format::HEADER_SIZE ) ),
		);
	}

	public function test_builds_the_headers_it_reads() {
		$block = Archive_Format::build_header( 'uploads/2024/01/photo.jpg', 42, 1700000000, 'cafebabe' );

		$this->assertSame( self::v2_header( 'photo.jpg', 42, 1700000000, 'uploads/2024/01', 'cafebabe' ), $block );
		$this->assertSame( self::v2_header( 'package.json', 3, 9, '.', '' ), Archive_Format::build_header( 'package.json', 3, 9 ) );
	}

	public function test_builds_end_blocks_it_reads() {
		$this->assertSame( str_repeat( "\0", Archive_Format::HEADER_SIZE ), Archive_Format::build_eof() );
		$this->assertSame( 77, Archive_Format::parse_header( Archive_Format::build_eof( 77, '0000abcd' ) )['archive_size'] );
	}
}
