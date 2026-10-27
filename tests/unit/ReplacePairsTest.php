<?php

use PHPUnit\Framework\TestCase;
use WPressRestore\Replace_Pairs;
use WPressRestore\Serialized_Replacer;

final class ReplacePairsTest extends TestCase {

	private static function old_site( array $overrides = array() ) {
		return array_replace(
			array(
				'site_url'          => 'https://old.example',
				'home_url'          => 'https://old.example',
				'internal_site_url' => 'https://old.example',
				'internal_home_url' => 'https://old.example',
				'uploads_url'       => 'https://old.example/wp-content/uploads/',
				'absolute'          => '/home/old/public_html/',
				'content'           => '/home/old/public_html/wp-content',
				'custom'            => array(),
				'replace_email'     => true,
			),
			$overrides
		);
	}

	private static function new_site( array $overrides = array() ) {
		return array_replace(
			array(
				'site_url'     => 'https://new.example',
				'home_url'     => 'https://new.example',
				'uploads_url'  => 'https://new.example/wp-content/uploads/',
				'uploads_path' => '/wp-content/uploads/',
				'absolute'     => '/var/www/html/',
				'content'      => '/var/www/html/wp-content',
			),
			$overrides
		);
	}

	private static function rewrite( $text, array $old = array(), array $new = array() ) {
		return ( new Serialized_Replacer( Replace_Pairs::build( self::old_site( $old ), self::new_site( $new ) ) ) )->replace( $text );
	}

	public function test_restoring_onto_the_same_site_keeps_its_addresses_and_tidies_old_spellings() {
		$same = array(
			'site_url'    => 'https://old.example',
			'home_url'    => 'https://old.example',
			'uploads_url' => 'https://old.example/wp-content/uploads/',
			'absolute'    => '/home/old/public_html/',
			'content'     => '/home/old/public_html/wp-content',
		);
		$text = 'https://old.example/a /home/old/public_html/wp-content/b contact@old.example';

		$this->assertSame( $text, self::rewrite( $text, array(), $same ) );
		$this->assertSame( 'https://old.example/a https://old.example/b', self::rewrite( 'http://old.example/a https://www.old.example/b', array(), $same ) );
	}

	/**
	 * @dataProvider moved_to_a_new_domain
	 */
	public function test_moves_every_spelling_of_the_old_address( $before, $after ) {
		$this->assertSame( $after, self::rewrite( $before ) );
	}

	public function moved_to_a_new_domain() {
		return array(
			'https link'         => array( 'https://old.example/page', 'https://new.example/page' ),
			'http link'          => array( 'http://old.example/page', 'https://new.example/page' ),
			'scheme-less link'   => array( '//old.example/page', '//new.example/page' ),
			'www spelling'       => array( 'https://www.old.example/', 'https://new.example/' ),
			'json-escaped'       => array( '"https:\/\/old.example\/x"', '"https:\/\/new.example\/x"' ),
			'url-encoded'        => array( 'u=https%3A%2F%2Fold.example%2Fx', 'u=https%3A%2F%2Fnew.example%2Fx' ),
			'raw url-encoded'    => array( 'u=https%3A%2F%2Fold.example', 'u=https%3A%2F%2Fnew.example' ),
			'email domain'       => array( 'contact@old.example', 'contact@new.example' ),
			'server path'        => array( '/home/old/public_html/wp-content/uploads/a.jpg', '/var/www/html/wp-content/uploads/a.jpg' ),
			'json server path'   => array( '\/home\/old\/public_html\/x', '\/var\/www\/html\/x' ),
			'quoted bare domain' => array( '<a href="old.example/x">', '<a href="new.example/x">' ),
			'other domains'      => array( 'https://example.org/old.example', 'https://example.org/old.example' ),
		);
	}

	public function test_keeps_email_domains_when_the_backup_asks_to() {
		$this->assertSame( 'contact@old.example', self::rewrite( 'contact@old.example', array( 'replace_email' => false ) ) );
	}

	public function test_moves_a_site_out_of_a_subfolder() {
		$old = array(
			'site_url'          => 'https://old.example/blog',
			'home_url'          => 'https://old.example/blog',
			'internal_site_url' => 'https://old.example/blog',
			'internal_home_url' => 'https://old.example/blog',
			'uploads_url'       => 'https://old.example/blog/wp-content/uploads/',
		);

		$this->assertSame( 'https://new.example/post', self::rewrite( 'https://old.example/blog/post', $old ) );
		$this->assertSame( 'https://new.example/wp-content/uploads/a.jpg', self::rewrite( 'https://old.example/blog/wp-content/uploads/a.jpg', $old ) );
	}

	public function test_moves_uploads_to_where_this_site_keeps_them() {
		$new = array(
			'uploads_url'  => 'https://new.example/media/',
			'uploads_path' => '/media/',
		);

		$this->assertSame( 'https://new.example/media/2024/a.jpg', self::rewrite( 'https://old.example/wp-content/uploads/2024/a.jpg', array(), $new ) );
		$this->assertSame( '<img src="/media/2024/a.jpg">', self::rewrite( '<img src="/wp-content/uploads/2024/a.jpg">', array(), $new ) );
	}

	public function test_applies_the_replacements_saved_in_the_backup() {
		$old = array( 'custom' => array( array( 'Old Brand', 'New Brand' ) ) );

		$this->assertSame( 'Welcome to New Brand', self::rewrite( 'Welcome to Old Brand', $old ) );
	}

	public function test_an_internal_address_different_from_the_public_one_is_moved_too() {
		$old = array(
			'internal_site_url' => 'http://staging.old.example',
			'internal_home_url' => 'http://staging.old.example',
		);

		$this->assertSame( 'https://new.example/x', self::rewrite( 'http://staging.old.example/x', $old ) );
	}
}
