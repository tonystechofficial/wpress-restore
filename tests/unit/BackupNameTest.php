<?php

use PHPUnit\Framework\TestCase;
use WPressRestore\Backup_Name;
use WPressRestore\Upload;

final class BackupNameTest extends TestCase {

	public function test_names_a_backup_like_ai1wm_does() {
		$this->assertSame( 'www-shop-example-20261027-153005-abc123def456.wpress', Backup_Name::for_site( 'https://www.Shop.example', '20261027-153005', 'abc123def456' ) );
	}

	public function test_keeps_the_folder_of_a_site_in_a_subfolder() {
		$this->assertSame( 'example-blogfr-20261027-153005-abc123def456.wpress', Backup_Name::for_site( 'https://example/blog_fr/', '20261027-153005', 'abc123def456' ) );
	}

	public function test_a_generated_name_is_a_safe_upload_name() {
		$name = Backup_Name::for_site( 'http://localhost:8082', '20261027-153005', Backup_Name::random() );

		$this->assertSame( $name, Upload::safe_name( $name ) );
		$this->assertMatchesRegularExpression( '/^localhost-20261027-153005-[a-z0-9]{12}\.wpress$/', $name );
	}
}
