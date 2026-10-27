<?php

use PHPUnit\Framework\TestCase;
use WPressRestore\Archive_Exception;
use WPressRestore\Package;

final class PackageTest extends TestCase {

	public function test_reads_what_the_restore_needs_from_a_current_package() {
		$package = Package::from_json( Ai1wm::package() );

		$this->assertSame( 'https://old.example', $package->old_site()['site_url'] );
		$this->assertSame( 'https://old.example/wp-content/uploads/', $package->old_site()['uploads_url'] );
		$this->assertSame( '/home/old/public_html/', $package->old_site()['absolute'] );
		$this->assertTrue( $package->old_site()['replace_email'] );
		$this->assertSame( array( 'akismet/akismet.php' ), $package->plugins() );
		$this->assertSame( array( 'twentytwentyfour', 'twentytwentyfour' ), array( $package->template(), $package->stylesheet() ) );
		$this->assertSame( '6.8.3', $package->wordpress_version() );
		$this->assertSame( 'wp_', $package->table_prefix() );
		$this->assertFalse( $package->is_encrypted() );
		$this->assertNull( $package->compression() );
	}

	public function test_names_the_tool_that_made_the_backup() {
		$this->assertSame( 'All-in-One WP Migration 7.112', Package::from_json( Ai1wm::package() )->made_with() );
		$this->assertSame( 'All-in-One WP Migration 6.76', Package::from_json( '{"SiteURL":"https://x.example","Version":"6.76"}' )->made_with() );
	}

	public function test_reads_encryption_and_compression() {
		$package = Package::from_json(
			Ai1wm::package(
				array(
					'Encrypted'          => true,
					'EncryptedSignature' => 'c2ln',
					'Compression'        => array( 'Enabled' => true, 'Type' => 'gzip' ),
				)
			)
		);

		$this->assertTrue( $package->is_encrypted() );
		$this->assertSame( 'c2ln', $package->signature() );
		$this->assertSame( 'gzip', $package->compression() );
	}

	public function test_disabled_compression_is_no_compression() {
		$package = Package::from_json( Ai1wm::package( array( 'Compression' => array( 'Enabled' => false, 'Type' => 'gzip' ) ) ) );

		$this->assertNull( $package->compression() );
	}

	public function test_reads_the_replacements_and_email_choice_saved_in_the_backup() {
		$package = Package::from_json(
			Ai1wm::package(
				array(
					'NoEmailReplace' => true,
					'Replace'        => array( 'OldValues' => array( 'Old Brand', '' ), 'NewValues' => array( 'New Brand', 'x' ) ),
				)
			)
		);

		$this->assertFalse( $package->old_site()['replace_email'] );
		$this->assertSame( array( array( 'Old Brand', 'New Brand' ) ), $package->old_site()['custom'] );
	}

	public function test_lists_the_tables_saved_outside_the_wordpress_prefix() {
		$package = Package::from_json( Ai1wm::package( array( 'Database' => array( 'IncludedTables' => array( 'custom_log' ) ) ) ) );

		$this->assertSame( array( 'custom_log' ), $package->included_tables() );
	}

	/**
	 * @dataProvider not_packages
	 */
	public function test_refuses_what_is_not_an_ai1wm_package( $json ) {
		$this->expectException( Archive_Exception::class );

		Package::from_json( $json );
	}

	public function not_packages() {
		return array(
			'not json'       => array( '{oops' ),
			'not an object'  => array( '"text"' ),
			'no site url'    => array( '{"HomeURL":"https://x.example"}' ),
		);
	}

	public function test_warns_when_the_backup_needs_newer_software_than_this_server() {
		$package = Package::from_json( Ai1wm::package( array( 'WordPress' => array( 'Version' => '7.2' ), 'PHP' => array( 'Version' => '8.4.1' ) ) ) );

		$warnings = $package->warnings( array( 'wordpress' => '7.1.3', 'php' => '8.2.20' ) );

		$this->assertSame(
			array(
				array( 'about' => 'wordpress', 'backup' => '7.2', 'here' => '7.1.3' ),
				array( 'about' => 'php', 'backup' => '8.4.1', 'here' => '8.2.20' ),
			),
			$warnings
		);
	}

	public function test_warns_that_nobody_could_log_in_when_wordpress_is_older_than_the_backup_password_format() {
		$package = Package::from_json( Ai1wm::package( array( 'WordPress' => array( 'Version' => '7.1.3' ) ) ) );

		$warnings = $package->warnings( array( 'wordpress' => '6.1.1', 'php' => '8.3.0' ) );

		$this->assertContains( array( 'about' => 'passwords', 'backup' => '7.1.3', 'here' => '6.1.1' ), $warnings );
	}

	public function test_no_password_warning_when_both_sides_hash_passwords_alike() {
		$recent = Package::from_json( Ai1wm::package( array( 'WordPress' => array( 'Version' => '6.8' ) ) ) );
		$older  = Package::from_json( Ai1wm::package( array( 'WordPress' => array( 'Version' => '6.7.2' ) ) ) );

		$this->assertNotContains( 'passwords', array_column( $recent->warnings( array( 'wordpress' => '6.8.1', 'php' => '8.3.0' ) ), 'about' ) );
		$this->assertNotContains( 'passwords', array_column( $older->warnings( array( 'wordpress' => '6.1.1', 'php' => '8.3.0' ) ), 'about' ) );
	}

	public function test_no_warning_for_the_same_or_older_versions() {
		$package = Package::from_json( Ai1wm::package( array( 'PHP' => array( 'Version' => '8.2.0' ) ) ) );

		$this->assertSame( array(), $package->warnings( array( 'wordpress' => '7.1.3', 'php' => '8.2.20' ) ) );
	}
}
