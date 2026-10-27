<?php

use PHPUnit\Framework\TestCase;
use WPressRestore\Upload;

final class UploadTest extends TestCase {

	private $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/wpr-upload-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir );
	}

	protected function tearDown(): void {
		foreach ( glob( $this->dir . '/{,.}*', GLOB_BRACE ) as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		rmdir( $this->dir );
	}

	private function chunk( $bytes ) {
		$file = tempnam( sys_get_temp_dir(), 'wprc' );
		file_put_contents( $file, $bytes );
		return $file;
	}

	public function test_assembles_a_file_from_its_chunks() {
		$upload = new Upload( $this->dir );
		$data   = random_bytes( 2500 );

		$this->assertSame( 0, $upload->begin( 'site.wpress', 2500, false ) );
		$this->assertSame( array( 'offset' => 1000, 'done' => false ), $upload->append( 'site.wpress', 2500, 0, $this->chunk( substr( $data, 0, 1000 ) ) ) );
		$upload->append( 'site.wpress', 2500, 1000, $this->chunk( substr( $data, 1000, 1000 ) ) );
		$this->assertSame( array( 'offset' => 2500, 'done' => true ), $upload->append( 'site.wpress', 2500, 2000, $this->chunk( substr( $data, 2000 ) ) ) );

		$this->assertSame( $data, file_get_contents( $this->dir . '/site.wpress' ) );
		$this->assertFileDoesNotExist( $this->dir . '/site.wpress.part' );
	}

	public function test_a_chunk_for_the_wrong_offset_is_not_written() {
		$upload = new Upload( $this->dir );
		$upload->begin( 'site.wpress', 100, false );
		$upload->append( 'site.wpress', 100, 0, $this->chunk( str_repeat( 'a', 40 ) ) );

		$this->assertSame( array( 'offset' => 40, 'done' => false ), $upload->append( 'site.wpress', 100, 80, $this->chunk( str_repeat( 'b', 20 ) ) ) );
		$this->assertSame( 40, filesize( $this->dir . '/site.wpress.part' ) );
	}

	public function test_an_interrupted_upload_resumes_where_it_stopped() {
		$upload = new Upload( $this->dir );
		$upload->begin( 'site.wpress', 100, false );
		$upload->append( 'site.wpress', 100, 0, $this->chunk( str_repeat( 'a', 30 ) ) );

		$this->assertSame( 30, ( new Upload( $this->dir ) )->begin( 'site.wpress', 100, false ) );
	}

	public function test_a_leftover_that_is_already_complete_becomes_the_backup() {
		file_put_contents( $this->dir . '/site.wpress.part', str_repeat( 'x', 100 ) );

		$this->assertSame( 100, ( new Upload( $this->dir ) )->begin( 'site.wpress', 100, false ) );
		$this->assertFileExists( $this->dir . '/site.wpress' );
		$this->assertFileDoesNotExist( $this->dir . '/site.wpress.part' );
	}

	public function test_a_leftover_larger_than_the_new_file_starts_over() {
		file_put_contents( $this->dir . '/site.wpress.part', str_repeat( 'x', 500 ) );

		$this->assertSame( 0, ( new Upload( $this->dir ) )->begin( 'site.wpress', 100, false ) );
		clearstatcache();
		$this->assertSame( 0, filesize( $this->dir . '/site.wpress.part' ) );
	}

	public function test_does_not_replace_an_existing_backup_unless_asked() {
		file_put_contents( $this->dir . '/site.wpress', 'old' );
		$upload = new Upload( $this->dir );

		try {
			$upload->begin( 'site.wpress', 3, false );
			$this->fail( 'An existing backup was about to be replaced.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'exists', $e->getMessage() );
		}

		$upload->begin( 'site.wpress', 3, true );
		$upload->append( 'site.wpress', 3, 0, $this->chunk( 'new' ) );
		$this->assertSame( 'new', file_get_contents( $this->dir . '/site.wpress' ) );
	}

	public function test_refuses_a_chunk_that_goes_past_the_declared_size() {
		$upload = new Upload( $this->dir );
		$upload->begin( 'site.wpress', 10, false );

		$this->expectException( RuntimeException::class );

		$upload->append( 'site.wpress', 10, 0, $this->chunk( str_repeat( 'a', 11 ) ) );
	}

	/**
	 * @dataProvider names
	 */
	public function test_turns_names_into_safe_backup_names( $name, $expected ) {
		$this->assertSame( $expected, Upload::safe_name( $name ) );
	}

	public function names() {
		return array(
			'ordinary'          => array( 'example-com-20261027-101500-ab12cd.wpress', 'example-com-20261027-101500-ab12cd.wpress' ),
			'path is dropped'   => array( '../../wp-config.wpress', 'wp-config.wpress' ),
			'windows path'      => array( 'C:\\Users\\me\\site.wpress', 'site.wpress' ),
			'odd characters'    => array( 'my site (copy) é.WPRESS', 'my-site-copy-.wpress' ),
			'hidden file'       => array( '.htaccess.wpress', 'htaccess.wpress' ),
			'not a backup'      => array( 'shell.php', null ),
			'double extension'  => array( 'shell.php.txt', null ),
			'only an extension' => array( '.wpress', null ),
			'empty'             => array( '', null ),
		);
	}
}
