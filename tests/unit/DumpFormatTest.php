<?php

use PHPUnit\Framework\TestCase;
use WPressRestore\Dump_Format;
use WPressRestore\Sql_Reader;
use WPressRestore\Sql_String;

final class DumpFormatTest extends TestCase {

	/**
	 * @dataProvider values
	 */
	public function test_writes_each_column_type_as_ai1wm_does( $value, $type, $expected ) {
		$this->assertSame( $expected, Dump_Format::value( $value, $type ) );
	}

	public function values() {
		return array(
			'null'               => array( null, 'varchar(255)', 'NULL' ),
			'integer'            => array( '42', 'bigint(20) unsigned', '42' ),
			'decimal'            => array( '-1.50', 'decimal(10,2)', '-1.50' ),
			'float'              => array( '1.5e-7', 'double', '1.5e-7' ),
			'text with quotes'   => array( "it's \"quoted\"\n\\", 'longtext', "'it\\'s \\\"quoted\\\"\\n\\\\'" ),
			'date'               => array( '2026-10-27 12:00:00', 'datetime', "'2026-10-27 12:00:00'" ),
			'binary'             => array( "\x00\xff", 'varbinary(16)', '0x00ff' ),
			'blob'               => array( 'ab', 'longblob', '0x6162' ),
			'empty binary'       => array( '', 'blob', "''" ),
			'bit'                => array( "\x01", 'bit(1)', '0x01' ),
			'geometry'           => array( "\x00\x00\x00\x00\x01", 'point', '0x0000000001' ),
			'type in upper case' => array( '7', 'INT(11)', '7' ),
		);
	}

	public function test_an_insert_is_one_line_that_reads_back_as_one_statement() {
		$insert = Dump_Format::insert( 'SERVMASK_PREFIX_posts', array( '1', "a;b\nc", null ), array( 'bigint(20)', 'longtext', 'varchar(20)' ) );

		$this->assertSame( "INSERT INTO `SERVMASK_PREFIX_posts` VALUES (1,'a;b\\nc',NULL);\n", $insert );
		$handle = fopen( 'php://memory', 'w+b' );
		fwrite( $handle, $insert . $insert );
		$reader = new Sql_Reader( $handle, 0, 1048576 );
		$this->assertSame( rtrim( $insert, ";\n" ), $reader->next()['sql'] );
		$this->assertSame( "a;b\nc", Sql_String::unescape( "a;b\\nc" ) );
	}

	/**
	 * @dataProvider table_names
	 */
	public function test_marks_the_site_prefix_in_table_names( $name, $prefix, $expected ) {
		$this->assertSame( $expected, Dump_Format::table_name( $name, $prefix ) );
	}

	public function table_names() {
		return array(
			'core table'                 => array( 'wp_options', 'wp_', 'SERVMASK_PREFIX_options' ),
			'other prefix'               => array( 'wpb_posts', 'wpb_', 'SERVMASK_PREFIX_posts' ),
			'table ai1wm exports as is'  => array( 'wbk_bookings', 'wp_', 'wbk_bookings' ),
			'site without a prefix'      => array( 'options', '', 'SERVMASK_PREFIX_options' ),
		);
	}

	/**
	 * @dataProvider columns
	 */
	public function test_types_generated_columns_apart_from_columns_with_computed_defaults( $type, $extra, $expected ) {
		$this->assertSame( $expected, Dump_Format::column_type( $type, $extra ) );
	}

	public function columns() {
		return array(
			'stored generated (MySQL, MariaDB)' => array( 'decimal(10,2)', 'STORED GENERATED', Dump_Format::GENERATED ),
			'virtual generated'                 => array( 'varchar(30)', 'VIRTUAL GENERATED', Dump_Format::GENERATED ),
			'persistent generated (MariaDB)'    => array( 'int(11)', 'PERSISTENT GENERATED', Dump_Format::GENERATED ),
			'default CURRENT_TIMESTAMP, MySQL 8' => array( 'datetime', 'DEFAULT_GENERATED', 'datetime' ),
			'default and on update'             => array( 'timestamp', 'DEFAULT_GENERATED on update CURRENT_TIMESTAMP', 'timestamp' ),
			'auto increment'                    => array( 'bigint unsigned', 'auto_increment', 'bigint unsigned' ),
			'plain'                             => array( 'longtext', '', 'longtext' ),
		);
	}

	public function test_lets_the_server_compute_generated_columns() {
		// MySQL refuses any other value for a generated column; DEFAULT recomputes it.
		$this->assertSame( "INSERT INTO `t` VALUES (1,2.50,DEFAULT,DEFAULT);\n", Dump_Format::insert( 't', array( '1', '2.50', '5.00', null ), array( 'int', 'decimal(8,2)', Dump_Format::GENERATED, Dump_Format::GENERATED ) ) );
	}

	public function test_a_server_that_folds_table_names_matches_the_prefix_in_any_case() {
		// lower_case_table_names=1 stores wp-config's WP_ prefix as wp_.
		$this->assertSame( 'SERVMASK_PREFIX_options', Dump_Format::table_name( 'wp_options', 'WP_', true ) );
		$this->assertSame( 'wp_options', Dump_Format::table_name( 'wp_options', 'WP_' ), 'only where the server folds names' );
	}

	public function test_renames_a_table_definition() {
		$sql = Dump_Format::create_table( "CREATE TABLE `wp_options` (\n  `option_id` bigint\n) ENGINE=InnoDB COMMENT='wp_options'", 'wp_options', 'SERVMASK_PREFIX_options' );

		$this->assertSame( "CREATE TABLE `SERVMASK_PREFIX_options` (\n  `option_id` bigint\n) ENGINE=InnoDB COMMENT='wp_options'", $sql );
	}

	public function test_rewrites_a_view_without_its_definer() {
		$sql = Dump_Format::create_view(
			'CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `wp_recent` AS select `wp_posts`.`ID` AS `ID` from `wp_posts`',
			array(
				'wp_recent' => 'SERVMASK_PREFIX_recent',
				'wp_posts'  => 'SERVMASK_PREFIX_posts',
			)
		);

		$this->assertSame( 'CREATE VIEW `SERVMASK_PREFIX_recent` AS select `SERVMASK_PREFIX_posts`.`ID` AS `ID` from `SERVMASK_PREFIX_posts`', $sql );
	}

	/**
	 * @dataProvider option_names
	 */
	public function test_marks_only_the_options_named_after_the_prefix( $name, $expected ) {
		$this->assertSame( $expected, Dump_Format::option_name( $name, 'wp_' ) );
	}

	public function option_names() {
		return array(
			'roles'                          => array( 'wp_user_roles', 'SERVMASK_PREFIX_user_roles' ),
			'core option that starts alike'  => array( 'wp_attachment_pages_enabled', 'wp_attachment_pages_enabled' ),
			'plugin option that starts alike' => array( 'wp_mail_smtp', 'wp_mail_smtp' ),
			'ordinary option'                => array( 'blogname', 'blogname' ),
		);
	}

	/**
	 * @dataProvider meta_keys
	 */
	public function test_marks_user_meta_keys_that_start_with_the_prefix( $key, $expected ) {
		$this->assertSame( $expected, Dump_Format::meta_key( $key, 'wp_' ) );
	}

	public function meta_keys() {
		return array(
			'capabilities' => array( 'wp_capabilities', 'SERVMASK_PREFIX_capabilities' ),
			'user level'   => array( 'wp_user_level', 'SERVMASK_PREFIX_user_level' ),
			'preferences'  => array( 'wp_persisted_preferences', 'SERVMASK_PREFIX_persisted_preferences' ),
			'other key'    => array( 'nickname', 'nickname' ),
			'prefix later' => array( 'my_wp_thing', 'my_wp_thing' ),
		);
	}
}
