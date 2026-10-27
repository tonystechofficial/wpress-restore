<?php

use PHPUnit\Framework\TestCase;
use WPressRestore\File_Walker;

final class FileWalkerTest extends TestCase {

	private $root;
	private $outside;

	protected function setUp(): void {
		$base          = sys_get_temp_dir() . '/wpr-walk-' . bin2hex( random_bytes( 4 ) );
		$this->root    = $base . '/content';
		$this->outside = $base . '/elsewhere';
		foreach ( array( 'plugins/akismet', 'uploads/2026/10', 'cache/pages', 'ai1wm-backups', 'empty' ) as $dir ) {
			mkdir( $this->root . '/' . $dir, 0777, true );
		}
		mkdir( $this->outside, 0777, true );
		file_put_contents( $this->root . '/index.php', '<?php' );
		file_put_contents( $this->root . '/plugins/akismet/akismet.php', 'plugin' );
		file_put_contents( $this->root . '/uploads/2026/10/photo.jpg', str_repeat( 'p', 1000 ) );
		file_put_contents( $this->root . '/cache/pages/index.html', 'cached' );
		file_put_contents( $this->root . '/ai1wm-backups/old.wpress', 'old backup' );
		file_put_contents( $this->root . "/uploads/bad\nname.txt", 'x' );
		file_put_contents( $this->outside . '/linked.txt', 'reached through a link' );
	}

	protected function tearDown(): void {
		exec( 'rm -rf ' . escapeshellarg( dirname( $this->root ) ) );
	}

	private function walk( File_Walker $walker, $stop_each_time ) {
		$list  = fopen( 'php://memory', 'w+b' );
		$state = File_Walker::start();
		$calls = 0;
		do {
			++$calls;
			$done = $walker->walk(
				$state,
				$list,
				static function () use ( $stop_each_time ) {
					return $stop_each_time;
				}
			);
		} while ( ! $done );
		rewind( $list );
		$files = array();
		while ( false !== ( $line = fgets( $list ) ) ) {
			$file                   = File_Walker::read_line( $line );
			$files[ $file['name'] ] = $file;
		}
		ksort( $files );
		return array( $files, $state, $calls );
	}

	public function test_lists_every_file_with_its_archive_name_size_and_date() {
		$walker = new File_Walker( $this->root, 'wp-root/', array( $this->root . '/cache', $this->root . '/ai1wm-backups' ) );

		list( $files, $state ) = $this->walk( $walker, false );

		$this->assertSame( array( 'wp-root/index.php', 'wp-root/plugins/akismet/akismet.php', 'wp-root/uploads/2026/10/photo.jpg' ), array_keys( $files ) );
		$photo = $files['wp-root/uploads/2026/10/photo.jpg'];
		$this->assertSame( array( $this->root . '/uploads/2026/10/photo.jpg', 1000 ), array( $photo['path'], $photo['size'] ) );
		$this->assertSame( filemtime( $this->root . '/index.php' ), $files['wp-root/index.php']['mtime'] );
		$this->assertSame( array( 3, 1000 + 5 + 6 ), array( $state['files'], $state['bytes'] ) );
	}

	public function test_gives_the_same_list_when_stopped_after_every_folder() {
		$walker = new File_Walker( $this->root, '', array() );

		list( $once ) = $this->walk( $walker, false );
		list( $steps, , $calls ) = $this->walk( $walker, true );

		$this->assertSame( array_keys( $once ), array_keys( $steps ) );
		$this->assertGreaterThan( 3, $calls );
	}

	public function test_follows_a_link_to_another_folder_but_never_loops() {
		symlink( $this->outside, $this->root . '/uploads/external' );
		symlink( $this->root, $this->root . '/uploads/loop' );

		list( $files ) = $this->walk( new File_Walker( $this->root, '', array() ), false );

		$this->assertArrayHasKey( 'uploads/external/linked.txt', $files );
		$this->assertArrayNotHasKey( 'uploads/loop/index.php', $files );
	}
}
