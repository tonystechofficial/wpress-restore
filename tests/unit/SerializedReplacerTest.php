<?php

use PHPUnit\Framework\TestCase;
use WPressRestore\Serialized_Replacer;

final class SerializedReplacerTest extends TestCase {

	private function replacer() {
		return new Serialized_Replacer( array( 'http://old.com' => 'https://new.org' ) );
	}

	public function test_replaces_in_plain_text() {
		$this->assertSame( 'Visit https://new.org/page', $this->replacer()->replace( 'Visit http://old.com/page' ) );
	}

	public function test_fixes_string_lengths_in_serialized_arrays() {
		$out = $this->replacer()->replace( serialize( array( 'url' => 'http://old.com/x', 'n' => 5, 'f' => 1.5, 'b' => true, 'z' => null ) ) );

		$this->assertSame( array( 'url' => 'https://new.org/x', 'n' => 5, 'f' => 1.5, 'b' => true, 'z' => null ), unserialize( $out ) );
	}

	public function test_handles_serialized_data_inside_serialized_data() {
		$out = $this->replacer()->replace( serialize( array( 'inner' => serialize( array( 'u' => 'http://old.com' ) ) ) ) );

		$this->assertSame( array( 'u' => 'https://new.org' ), unserialize( unserialize( $out )['inner'] ) );
	}

	public function test_handles_objects_without_loading_their_class() {
		$out = $this->replacer()->replace( 'O:7:"Unknown":1:{s:1:"u";s:14:"http://old.com";}' );

		$this->assertSame( 'O:7:"Unknown":1:{s:1:"u";s:15:"https://new.org";}', $out );
	}

	public function test_keeps_private_and_protected_property_names() {
		$value = serialize( new SerializedReplacerFixture( 'http://old.com/a' ) );

		$this->assertEquals( new SerializedReplacerFixture( 'https://new.org/a' ), unserialize( $this->replacer()->replace( $value ) ) );
	}

	public function test_keeps_references() {
		$out = $this->replacer()->replace( 'a:2:{i:0;s:14:"http://old.com";i:1;R:2;}' );

		$this->assertSame( 'a:2:{i:0;s:15:"https://new.org";i:1;R:2;}', $out );
	}

	public function test_leaves_custom_serialized_payloads_and_enums_as_they_are() {
		$value = 'a:3:{i:0;C:11:"ArrayObject":21:{x:i:0;a:0:{};m:a:0:{}}i:1;E:11:"Suit:Hearts";i:2;s:14:"http://old.com";}';

		$this->assertSame(
			'a:3:{i:0;C:11:"ArrayObject":21:{x:i:0;a:0:{};m:a:0:{}}i:1;E:11:"Suit:Hearts";i:2;s:15:"https://new.org";}',
			$this->replacer()->replace( $value )
		);
	}

	public function test_counts_lengths_in_bytes() {
		$out = $this->replacer()->replace( serialize( array( 't' => 'Café — http://old.com' ) ) );

		$this->assertSame( array( 't' => 'Café — https://new.org' ), unserialize( $out ) );
	}

	public function test_damaged_serialized_data_falls_back_to_plain_replacement() {
		$this->assertSame( 's:5:"https://new.org";', $this->replacer()->replace( 's:5:"http://old.com";' ) );
		$this->assertSame( 'a:2:{oops https://new.org', $this->replacer()->replace( 'a:2:{oops http://old.com' ) );
	}

	public function test_the_longest_match_wins_and_nothing_is_replaced_twice() {
		$replacer = new Serialized_Replacer(
			array(
				'http://old.com'    => 'http://new.com',
				'http://old.com/wp' => 'http://old.com/wp',
				'a'                 => 'b',
				'b'                 => 'c',
			)
		);

		$this->assertSame( 'http://old.com/wp/x http://new.com/y', $replacer->replace( 'http://old.com/wp/x http://old.com/y' ) );
		$this->assertSame( 'bc', $replacer->replace( 'ab' ) );
	}

	public function test_knows_when_nothing_would_change() {
		$this->assertFalse( ( new Serialized_Replacer( array( 'x' => 'x' ) ) )->has_changes() );
		$this->assertTrue( $this->replacer()->has_changes() );
	}
}

final class SerializedReplacerFixture {
	private $secret;
	protected $kept;
	public $open = 1;

	public function __construct( $url ) {
		$this->secret = $url;
		$this->kept   = array( $url );
	}
}
