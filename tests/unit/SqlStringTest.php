<?php

use PHPUnit\Framework\TestCase;
use WPressRestore\Sql_String;

final class SqlStringTest extends TestCase {

	public function test_escapes_like_mysqli_real_escape_string() {
		$this->assertSame( "a\\\\b\\'c\\\"d\\0e\\nf\\rg\\Zh", Sql_String::escape( "a\\b'c\"d\0e\nf\rg\x1Ah" ) );
	}

	public function test_unescaping_reverses_escaping() {
		$value = "C:\\path 'quoted' \"double\" \0 \n \r \x1A tab\there é";

		$this->assertSame( $value, Sql_String::unescape( Sql_String::escape( $value ) ) );
	}

	public function test_unescapes_what_mysql_also_accepts() {
		$this->assertSame( "it's\ttab\x08", Sql_String::unescape( "it''s\\ttab\\b" ) );
		$this->assertSame( '100\% \_x', Sql_String::unescape( '100\% \_x' ) );
	}
}
