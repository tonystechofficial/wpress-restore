<?php

use PHPUnit\Framework\TestCase;
use WPressRestore\Archive_Exception;
use WPressRestore\Codec;

final class CodecTest extends TestCase {

	public function test_decrypts_a_chunk_encrypted_the_ai1wm_way() {
		$codec = new Codec( 'secret', null );

		$this->assertSame( 'hello world', $codec->decode( Ai1wm::encrypt( 'hello world', 'secret' ) ) );
	}

	public function test_a_wrong_password_cannot_decrypt() {
		$this->expectException( Archive_Exception::class );

		// A fixed IV keeps the outcome stable: about 1 wrong key in 256 still yields valid padding.
		( new Codec( 'wrong', null ) )->decode( Ai1wm::encrypt( 'hello world', 'secret', str_repeat( 'i', 16 ) ) );
	}

	public function test_a_codec_rebuilt_from_its_stored_key_decrypts_without_the_password() {
		$stored = ( new Codec( 'secret', 'gzip' ) )->key();

		$this->assertNotContains( 'secret', array( $stored ) );
		$this->assertSame( 32, strlen( $stored ) );
		$this->assertSame( 'kept', Codec::from_key( $stored, 'gzip' )->decode( Ai1wm::encrypt( gzcompress( 'kept' ), 'secret' ) ) );
	}

	public function test_without_a_password_there_is_no_key() {
		$this->assertNull( ( new Codec( null, null ) )->key() );
		$this->assertTrue( Codec::from_key( null, null )->is_plain() );
	}

	public function test_an_encrypted_full_chunk_has_the_length_ai1wm_reads() {
		$stored = ( new Codec( 'pw', null ) )->encode( str_repeat( 'a', Codec::CHUNK ) );

		$this->assertSame( Codec::CHUNK + 2 * Codec::IV_LENGTH, strlen( $stored ) );
		$this->assertSame( Codec::encrypted_chunk_length( 5 * Codec::CHUNK ), strlen( $stored ) );
	}

	public function test_a_compressed_chunk_carries_its_length_in_front() {
		$stored = ( new Codec( null, 'gzip' ) )->encode( str_repeat( 'abc', 1000 ) );

		$this->assertSame( strlen( $stored ) - 4, unpack( 'N', substr( $stored, 0, 4 ) )[1] );
		$this->assertSame( str_repeat( 'abc', 1000 ), gzuncompress( substr( $stored, 4 ) ) );
	}

	public function test_plain_chunks_are_stored_as_they_are() {
		$this->assertSame( 'as is', ( new Codec( null, null ) )->encode( 'as is' ) );
	}

	public function test_signs_a_password_the_way_ai1wm_checks_it() {
		$signature = Codec::signature( 'S3cret pass!' );
		$raw       = base64_decode( $signature );

		$this->assertSame( Ai1wm::SIGN_TEXT, openssl_decrypt( substr( $raw, 16 ), 'AES-256-CBC', substr( sha1( 'S3cret pass!', true ), 0, 16 ), OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) ) );
		$this->assertTrue( Codec::password_matches( $signature, 'S3cret pass!' ) );
	}

	public function test_checks_a_password_against_the_package_signature() {
		$signature = base64_encode( Ai1wm::encrypt( Ai1wm::SIGN_TEXT, 'secret' ) );

		$this->assertTrue( Codec::password_matches( $signature, 'secret' ) );
		$this->assertFalse( Codec::password_matches( $signature, 'Secret' ) );
		$this->assertFalse( Codec::password_matches( 'not base64 !', 'secret' ) );
	}

	/**
	 * @dataProvider encrypted_lengths
	 */
	public function test_reads_encrypted_entries_in_ai1wm_sized_chunks( $remaining, $expected ) {
		$this->assertSame( $expected, Codec::encrypted_chunk_length( $remaining ) );
	}

	public function encrypted_lengths() {
		return array(
			'many chunks left'            => array( 2000000, 512032 ),
			'exactly one full chunk'      => array( 512032, 512032 ),
			'last chunk above 512000'     => array( 512016, 512016 ),
			'last chunk of exactly 512000' => array( 512000, 512000 ),
			'small last chunk'            => array( 48, 48 ),
		);
	}

	public function test_decompresses_gzip_chunks() {
		$this->assertSame( 'abcabcabc', ( new Codec( null, 'gzip' ) )->decode( gzcompress( 'abcabcabc', 9 ) ) );
	}

	public function test_decompresses_bzip2_chunks() {
		if ( ! function_exists( 'bzcompress' ) ) {
			$this->markTestSkipped( 'The bz2 extension is not loaded.' );
		}

		$this->assertSame( 'abcabcabc', ( new Codec( null, 'bzip2' ) )->decode( bzcompress( 'abcabcabc', 9 ) ) );
	}

	public function test_decrypts_before_decompressing() {
		$stored = Ai1wm::encrypt( gzcompress( 'both at once', 9 ), 'pw' );

		$this->assertSame( 'both at once', ( new Codec( 'pw', 'gzip' ) )->decode( $stored ) );
	}

	public function test_damaged_compressed_data_is_an_error() {
		$this->expectException( Archive_Exception::class );

		( new Codec( null, 'gzip' ) )->decode( 'not zlib data' );
	}

	public function test_rejects_a_compression_it_does_not_know() {
		$this->expectException( Archive_Exception::class );

		new Codec( null, 'zstd' );
	}

	public function test_only_the_root_config_files_are_stored_plain() {
		$codec = new Codec( 'pw', 'gzip' );

		$this->assertTrue( $codec->for_entry( 'package.json' )->is_plain() );
		$this->assertTrue( $codec->for_entry( 'multisite.json' )->is_plain() );
		$this->assertFalse( $codec->for_entry( 'plugins/x/package.json' )->is_plain() );
		$this->assertFalse( $codec->for_entry( 'database.sql' )->is_plain() );
	}
}
