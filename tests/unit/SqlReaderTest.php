<?php

use PHPUnit\Framework\TestCase;
use WPressRestore\Sql_Reader;

final class SqlReaderTest extends TestCase {

	const DUMP = <<<'SQL'
-- All-in-One WP Migration SQL Dump
-- https://servmask.com/
--
-- Host: db
-- Database: wordpress
-- Class: Ai1wm_Database_Mysqli
--

DROP TABLE IF EXISTS `SERVMASK_PREFIX_options`;
CREATE TABLE `SERVMASK_PREFIX_options` (
  `option_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `option_name` varchar(191) NOT NULL DEFAULT '',
  PRIMARY KEY (`option_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

START TRANSACTION;
INSERT INTO `SERVMASK_PREFIX_options` VALUES (1,'siteurl','https://old.example','yes');
INSERT INTO `SERVMASK_PREFIX_options` VALUES (2,'blogname','It\'s; here \\','yes');
COMMIT;

SQL;

	private $files = array();

	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			@unlink( $file );
		}
	}

	private function handle( $sql ) {
		$file          = tempnam( sys_get_temp_dir(), 'wpr' );
		$this->files[] = $file;
		file_put_contents( $file, $sql );
		return fopen( $file, 'rb' );
	}

	private function read_all( $sql, $offset = 0, $max = 1048576, $read_size = 1048576 ) {
		$reader = new Sql_Reader( $this->handle( $sql ), $offset, $max, $read_size );
		$all    = array();
		while ( null !== ( $statement = $reader->next() ) ) {
			$all[] = $statement;
		}
		return $all;
	}

	private static function texts( array $statements ) {
		return array_map(
			function ( $s ) {
				return $s['sql'];
			},
			$statements
		);
	}

	public function test_splits_an_ai1wm_dump_into_its_statements() {
		$texts = self::texts( $this->read_all( self::DUMP ) );

		$this->assertCount( 6, $texts );
		$this->assertStringEndsWith( 'DROP TABLE IF EXISTS `SERVMASK_PREFIX_options`', $texts[0] );
		$this->assertStringStartsWith( 'CREATE TABLE `SERVMASK_PREFIX_options` (', $texts[1] );
		$this->assertStringEndsWith( 'COLLATE=utf8mb4_unicode_520_ci', $texts[1] );
		$this->assertSame( 'START TRANSACTION', $texts[2] );
		$this->assertSame( "INSERT INTO `SERVMASK_PREFIX_options` VALUES (2,'blogname','It\\'s; here \\\\','yes')", $texts[4] );
		$this->assertSame( 'COMMIT', $texts[5] );
	}

	public function test_semicolons_inside_quotes_do_not_end_a_statement() {
		$sql = <<<'SQL'
INSERT INTO `t` VALUES ('a;b','C:\\','x\';y','it''s;ok',"d;q",`we;ird`);
INSERT INTO `t` VALUES (2);
SQL;

		$texts = self::texts( $this->read_all( $sql ) );

		$this->assertCount( 2, $texts );
		$this->assertSame( 'INSERT INTO `t` VALUES (2)', $texts[1] );
	}

	public function test_semicolons_inside_comments_do_not_end_a_statement() {
		$texts = self::texts( $this->read_all( "-- a; comment\n# hash; comment\n/* block; */ SELECT 1;\nSELECT 2;" ) );

		$this->assertCount( 2, $texts );
		$this->assertStringEndsWith( 'SELECT 1', $texts[0] );
	}

	public function test_a_double_dash_without_a_space_is_not_a_comment() {
		$this->assertSame( array( 'SELECT 5--1', 'SELECT 2' ), self::texts( $this->read_all( "SELECT 5--1;\nSELECT 2;" ) ) );
	}

	public function test_resumes_from_the_end_offset_of_any_statement() {
		$all    = $this->read_all( self::DUMP );
		$resume = $this->read_all( self::DUMP, $all[2]['end'] );

		$this->assertSame( self::texts( array_slice( $all, 3 ) ), self::texts( $resume ) );
		$this->assertSame( strlen( rtrim( self::DUMP ) ), end( $all )['end'] );
	}

	public function test_gives_the_same_statements_whatever_the_read_size() {
		$sql = self::DUMP . "INSERT INTO `t` VALUES ('\\\\','\\'','''','a\\\\\\';b');\n-- trailing; comment\n/* x; */";

		$expected = self::texts( $this->read_all( $sql ) );
		foreach ( range( 1, 13 ) as $read_size ) {
			$this->assertSame( $expected, self::texts( $this->read_all( $sql, 0, 1048576, $read_size ) ), "read size $read_size" );
		}
	}

	public function test_reports_a_statement_too_large_to_run_without_holding_it() {
		$big = 'INSERT INTO `t` VALUES (\'' . str_repeat( 'x;', 300 ) . '\')';
		$all = $this->read_all( "SELECT 1;\n$big;\nSELECT 2;", 0, 100 );

		$this->assertCount( 3, $all );
		$this->assertTrue( $all[1]['too_large'] );
		$this->assertNull( $all[1]['sql'] );
		$this->assertSame( 'SELECT 2', $all[2]['sql'] );
	}

	public function test_flags_text_left_without_a_closing_semicolon() {
		$all = $this->read_all( "SELECT 1;\nSELECT 2" );

		$this->assertTrue( $all[0]['complete'] );
		$this->assertSame( 'SELECT 2', $all[1]['sql'] );
		$this->assertFalse( $all[1]['complete'] );
	}

	public function test_trailing_whitespace_and_comments_are_not_a_statement() {
		$this->assertCount( 1, $this->read_all( "SELECT 1;\n\n-- end\n/* done */\n" ) );
	}
}
