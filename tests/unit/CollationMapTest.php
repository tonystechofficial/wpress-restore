<?php

use PHPUnit\Framework\TestCase;
use WPressRestore\Collation_Map;

final class CollationMapTest extends TestCase {

	/** Builds a map from collation names, as SHOW COLLATION lists them. */
	private static function server( array $collations ) {
		$by_name = array();
		foreach ( $collations as $collation ) {
			$by_name[ $collation ] = strstr( $collation, '_', true );
		}
		return new Collation_Map( $by_name );
	}

	private static function mariadb_10_6() {
		return self::server( array( 'utf8mb4_general_ci', 'utf8mb4_bin', 'utf8mb4_unicode_ci', 'utf8mb4_unicode_520_ci', 'utf8mb3_general_ci', 'utf8mb3_bin', 'utf8mb3_unicode_ci', 'latin1_swedish_ci', 'latin1_bin' ) );
	}

	private static function mysql_5_7() {
		return self::server( array( 'utf8mb4_general_ci', 'utf8mb4_bin', 'utf8mb4_unicode_ci', 'utf8mb4_unicode_520_ci', 'utf8_general_ci', 'utf8_bin', 'utf8_unicode_ci', 'latin1_swedish_ci' ) );
	}

	public function test_keeps_what_the_server_supports() {
		$this->assertSame( 'utf8mb4_unicode_520_ci', self::mariadb_10_6()->collation( 'utf8mb4_unicode_520_ci' ) );
		$this->assertSame( 'latin1', self::mariadb_10_6()->charset( 'latin1' ) );
	}

	/**
	 * @dataProvider mysql8_onto_mariadb
	 */
	public function test_brings_a_mysql_8_export_onto_mariadb( $from, $to ) {
		$this->assertSame( $to, self::mariadb_10_6()->collation( $from ) );
	}

	public function mysql8_onto_mariadb() {
		return array(
			'default collation'           => array( 'utf8mb4_0900_ai_ci', 'utf8mb4_unicode_520_ci' ),
			'accent-sensitive'            => array( 'utf8mb4_0900_as_ci', 'utf8mb4_unicode_520_ci' ),
			'case-sensitive stays strict' => array( 'utf8mb4_0900_as_cs', 'utf8mb4_bin' ),
			'binary stays binary'         => array( 'utf8mb4_0900_bin', 'utf8mb4_bin' ),
			'old utf8 name'               => array( 'utf8_general_ci', 'utf8mb3_general_ci' ),
			'mixed case'                  => array( 'UTF8MB4_0900_AI_CI', 'utf8mb4_unicode_520_ci' ),
		);
	}

	public function test_brings_a_mariadb_11_export_onto_mysql() {
		$this->assertSame( 'utf8mb4_unicode_520_ci', self::mysql_5_7()->collation( 'utf8mb4_uca1400_ai_ci' ) );
		$this->assertSame( 'utf8_unicode_ci', self::mysql_5_7()->collation( 'utf8mb3_unicode_ci' ) );
		$this->assertSame( 'utf8', self::mysql_5_7()->charset( 'utf8mb3' ) );
		$this->assertSame( 'utf8mb3', self::mariadb_10_6()->charset( 'utf8' ) );
	}

	public function test_falls_back_on_servers_without_newer_collations() {
		$old = self::server( array( 'utf8mb4_general_ci', 'utf8mb4_bin', 'utf8mb4_unicode_ci', 'utf8_general_ci' ) );

		$this->assertSame( 'utf8mb4_unicode_ci', $old->collation( 'utf8mb4_unicode_520_ci' ) );
	}

	public function test_falls_back_to_utf8_on_servers_without_utf8mb4() {
		$ancient = self::server( array( 'utf8_general_ci', 'utf8_bin', 'utf8_unicode_ci', 'latin1_swedish_ci' ) );

		$this->assertSame( 'utf8_unicode_ci', $ancient->collation( 'utf8mb4_unicode_ci' ) );
		$this->assertSame( 'utf8', $ancient->charset( 'utf8mb4' ) );
	}

	public function test_an_unknown_charset_becomes_utf8mb4() {
		$this->assertSame( 'utf8mb4', self::mariadb_10_6()->charset( 'gb18030' ) );
		$this->assertSame( 'utf8mb4_unicode_520_ci', self::mariadb_10_6()->collation( 'gb18030_chinese_ci' ) );
	}
}
