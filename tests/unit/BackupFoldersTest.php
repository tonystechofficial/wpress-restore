<?php

use PHPUnit\Framework\TestCase;
use WPressRestore\Backup_Folders;

final class BackupFoldersTest extends TestCase {

	private function site( array $changes = array() ) {
		return $changes + array(
			'content'     => '/www/wp-content',
			'uploads'     => '/www/wp-content/uploads',
			'plugins'     => '/www/wp-content/plugins',
			'themes'      => array( '/www/wp-content/themes' ),
			'backups'     => '/www/wp-content/ai1wm-backups',
			'work'        => '/www/wp-content/wpress-restore',
			'guard'       => '/www/wp-content/mu-plugins/wpress-restore-guard.php',
			'own_plugin'  => 'wpress-restore',
			'plugin_dirs' => array( 'akismet', 'all-in-one-wp-migration', 'all-in-one-wp-migration-unlimited-extension', 'sqlite-database-integration', 'wpress-restore', 'hello.php' ),
		);
	}

	private static function by_prefix( array $folders ) {
		$found = array();
		foreach ( $folders as $folder ) {
			$found[] = $folder['prefix'] . ' ' . $folder['path'];
		}
		return $found;
	}

	public function test_lists_wp_content_then_uploads_plugins_and_themes_as_ai1wm_names_them() {
		$folders = Backup_Folders::for_site( $this->site(), array() );

		$this->assertSame(
			array( ' /www/wp-content', 'uploads/ /www/wp-content/uploads', 'plugins/ /www/wp-content/plugins', 'themes/ /www/wp-content/themes' ),
			self::by_prefix( $folders )
		);
	}

	public function test_wp_content_leaves_out_the_other_folders_backups_and_files_importers_read_at_its_root() {
		$excluded = Backup_Folders::for_site( $this->site(), array() )[0]['excluded'];

		foreach ( array(
			'/www/wp-content/uploads',
			'/www/wp-content/plugins',
			'/www/wp-content/themes',
			'/www/wp-content/ai1wm-backups',
			'/www/wp-content/wpress-restore',
			'/www/wp-content/package.json',
			'/www/wp-content/multisite.json',
			'/www/wp-content/database.sql',
			'/www/wp-content/w3tc-config/master.php',
			'/www/wp-content/mu-plugins/wpress-restore-guard.php',
		) as $path ) {
			$this->assertContains( $path, $excluded );
		}
		$this->assertNotContains( '/www/wp-content/cache', $excluded );
	}

	public function test_leaves_out_the_cache_folder_on_request() {
		$this->assertContains( '/www/wp-content/cache', Backup_Folders::for_site( $this->site(), array( 'no_cache' => true ) )[0]['excluded'] );
	}

	public function test_leaves_out_the_media_library_on_request() {
		$folders = Backup_Folders::for_site( $this->site(), array( 'no_media' => true ) );

		$this->assertSame( array( ' /www/wp-content', 'plugins/ /www/wp-content/plugins', 'themes/ /www/wp-content/themes' ), self::by_prefix( $folders ) );
		$this->assertContains( '/www/wp-content/uploads', $folders[0]['excluded'], 'nor through wp-content' );
	}

	public function test_leaves_out_the_migration_plugins_each_importer_brings_itself() {
		$excluded = Backup_Folders::for_site( $this->site(), array() )[2]['excluded'];

		$this->assertContains( '/www/wp-content/plugins/wpress-restore', $excluded );
		$this->assertContains( '/www/wp-content/plugins/all-in-one-wp-migration', $excluded );
		$this->assertContains( '/www/wp-content/plugins/all-in-one-wp-migration-unlimited-extension', $excluded );
		$this->assertContains( '/www/wp-content/plugins/sqlite-database-integration', $excluded );
		$this->assertNotContains( '/www/wp-content/plugins/akismet', $excluded );
	}

	public function test_every_folder_leaves_out_the_backups_and_the_work_folder() {
		$site    = $this->site( array( 'backups' => '/www/wp-content/uploads/backups' ) );
		$folders = Backup_Folders::for_site( $site, array() );

		foreach ( $folders as $folder ) {
			$this->assertContains( '/www/wp-content/uploads/backups', $folder['excluded'], $folder['path'] );
			$this->assertContains( '/www/wp-content/wpress-restore', $folder['excluded'], $folder['path'] );
		}
	}

	public function test_lists_each_theme_folder_under_themes() {
		$site    = $this->site( array( 'themes' => array( '/www/wp-content/themes', '/srv/shared-themes' ) ) );
		$folders = Backup_Folders::for_site( $site, array() );

		$this->assertSame( 'themes/ /srv/shared-themes', self::by_prefix( $folders )[4] );
		$this->assertContains( '/srv/shared-themes', $folders[0]['excluded'] );
	}
}
