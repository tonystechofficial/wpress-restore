<?php

use PHPUnit\Framework\TestCase;
use WPressRestore\Codec;
use WPressRestore\Package;
use WPressRestore\Package_Builder;

final class PackageBuilderTest extends TestCase {

	private static function site( array $overrides = array() ) {
		return array_replace(
			array(
				'site_url'          => 'https://shop.example',
				'home_url'          => 'https://shop.example',
				'internal_site_url' => 'https://shop.example',
				'internal_home_url' => 'https://shop.example',
				'wordpress'         => '7.1.3',
				'absolute'          => '/var/www/html/',
				'content'           => '/var/www/html/wp-content',
				'plugins_dir'       => '/var/www/html/wp-content/plugins',
				'themes_dirs'       => array( '/var/www/html/wp-content/themes' ),
				'uploads_dir'       => '/var/www/html/wp-content/uploads',
				'uploads_url'       => 'https://shop.example/wp-content/uploads/',
				'database'          => '8.0.46',
				'charset'           => 'utf8mb4',
				'collate'           => '',
				'prefix'            => 'wp_',
				'php'               => '8.3.36',
				'os'                => 'Linux',
				'int_size'          => 8,
				'active_plugins'    => array( 'akismet/akismet.php', 'all-in-one-wp-migration/all-in-one-wp-migration.php', 'wpress-restore/wpress-restore.php' ),
				'own_plugin'        => 'wpress-restore/wpress-restore.php',
				'template'          => 'twentytwentyfour',
				'stylesheet'        => 'child',
				'upload_path'       => '',
				'upload_url_path'   => '',
				'htaccess'          => "# BEGIN WordPress\n",
				'webconfig'         => '',
				'version'           => '1.1.0',
			),
			$overrides
		);
	}

	public function test_describes_the_site_in_the_shape_both_importers_read() {
		$package = Package_Builder::build( self::site(), array() );

		$this->assertSame( 'https://shop.example', $package['SiteURL'] );
		$this->assertSame( 'https://shop.example/wp-content/uploads/', $package['WordPress']['UploadsURL'] );
		$this->assertSame( 'wp_', $package['Database']['Prefix'] );
		$this->assertSame( array( 'akismet/akismet.php' ), $package['Plugins'] );
		$this->assertSame( array( 'twentytwentyfour', 'child' ), array( $package['Template'], $package['Stylesheet'] ) );
		$this->assertSame( "# BEGIN WordPress\n", base64_decode( $package['Server']['.htaccess'] ) );
		$this->assertArrayNotHasKey( 'Encrypted', $package );
		$this->assertArrayNotHasKey( 'Compression', $package );
	}

	public function test_names_the_tool_that_made_it() {
		$package = Package::from_json( json_encode( Package_Builder::build( self::site(), array() ) ) );

		$this->assertSame( 'WPress Restore 1.1.0', $package->made_with() );
	}

	public function test_records_a_password_only_as_its_signature() {
		$package = Package_Builder::build( self::site(), array( 'password' => 'S3cret pass!' ) );

		$this->assertTrue( $package['Encrypted'] );
		$this->assertTrue( Codec::password_matches( $package['EncryptedSignature'], 'S3cret pass!' ) );
		$this->assertStringNotContainsString( 'S3cret', json_encode( $package ) );
	}

	public function test_records_compression_and_what_was_left_out() {
		$package = Package_Builder::build( self::site(), array( 'compression' => 'gzip', 'no_media' => true, 'no_spam_comments' => true, 'no_post_revisions' => true ) );

		$this->assertSame( array( 'Enabled' => true, 'Type' => 'gzip' ), $package['Compression'] );
		$this->assertTrue( $package['NoMedia'] );
		$this->assertTrue( $package['NoSpamComments'] );
		$this->assertTrue( $package['NoPostRevisions'] );
	}
}
