<?php

use PHPUnit\Framework\TestCase;
use WPressRestore\Entry_Path;

final class EntryPathTest extends TestCase {

	/**
	 * @dataProvider safe_names
	 */
	public function test_keeps_names_that_stay_inside_wp_content( $name, $expected ) {
		$this->assertSame( $expected, Entry_Path::normalize( $name ) );
	}

	public function safe_names() {
		return array(
			'plugin file'          => array( 'plugins/akismet/akismet.php', 'plugins/akismet/akismet.php' ),
			'doubled separators'   => array( 'uploads//2024/./01/a.jpg', 'uploads/2024/01/a.jpg' ),
			'windows separators'   => array( 'uploads\\2024\\a.jpg', 'uploads/2024/a.jpg' ),
			'dots inside a name'   => array( 'uploads/my..file.txt', 'uploads/my..file.txt' ),
			'root file'            => array( 'database.sql', 'database.sql' ),
			'spaces and accents'   => array( 'uploads/Été 2024/photo 1.jpg', 'uploads/Été 2024/photo 1.jpg' ),
		);
	}

	/**
	 * @dataProvider unsafe_names
	 */
	public function test_refuses_names_that_could_escape( $name ) {
		$this->assertNull( Entry_Path::normalize( $name ) );
	}

	public function unsafe_names() {
		return array(
			'parent directory'          => array( '../wp-config.php' ),
			'parent in the middle'      => array( 'uploads/../../wp-config.php' ),
			'parent with backslashes'   => array( 'uploads\\..\\..\\wp-config.php' ),
			'absolute unix path'        => array( '/etc/passwd' ),
			'drive letter'              => array( 'C:/Windows/win.ini' ),
			'drive letter, backslashes' => array( 'C:\\Windows\\win.ini' ),
			'network share'             => array( '\\\\server\\share\\x' ),
			'stream wrapper'            => array( 'php://filter/resource=x' ),
			'nul byte'                  => array( "uploads/a\0b.php" ),
			'empty'                     => array( '' ),
			'only a dot'                => array( '.' ),
			'only a parent'             => array( '..' ),
			'ends with a parent'        => array( 'uploads/..' ),
		);
	}
}
