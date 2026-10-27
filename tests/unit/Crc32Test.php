<?php

use PHPUnit\Framework\TestCase;
use WPressRestore\Crc32;

final class Crc32Test extends TestCase {

	public function test_combining_two_parts_gives_the_crc_of_the_whole() {
		$a = random_bytes( 1000 );
		$b = random_bytes( 777 );

		$this->assertSame( hash( 'crc32b', $a . $b ), Crc32::combine( hash( 'crc32b', $a ), hash( 'crc32b', $b ), strlen( $b ) ) );
	}

	public function test_chains_over_many_parts_of_uneven_sizes() {
		$data     = random_bytes( 3 * 1024 * 1024 + 17 );
		$combined = null;
		foreach ( str_split( $data, 1048573 ) as $part ) {
			$combined = Crc32::combine( $combined, hash( 'crc32b', $part ), strlen( $part ) );
		}

		$this->assertSame( hash( 'crc32b', $data ), $combined );
	}

	public function test_an_empty_second_part_changes_nothing() {
		$this->assertSame( 'cbf43926', Crc32::combine( 'cbf43926', hash( 'crc32b', '' ), 0 ) );
	}

	public function test_without_a_first_crc_the_second_one_is_returned() {
		$this->assertSame( hash( 'crc32b', 'abc' ), Crc32::combine( null, hash( 'crc32b', 'abc' ), 3 ) );
	}
}
