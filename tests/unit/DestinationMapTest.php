<?php

use PHPUnit\Framework\TestCase;
use WPressRestore\Destination_Map;

final class DestinationMapTest extends TestCase {

	private function map() {
		return new Destination_Map(
			'/var/www/wp-content',
			array(
				'plugins'    => '/var/www/wp-content/plugins',
				'themes'     => '/srv/themes',
				'uploads'    => '/data/uploads',
				'mu-plugins' => '/var/www/wp-content/mu-plugins',
			),
			'wpress-restore'
		);
	}

	/**
	 * @dataProvider restored
	 */
	public function test_maps_each_folder_to_where_this_site_keeps_it( $name, $expected ) {
		$this->assertSame( $expected, $this->map()->resolve( $name ) );
	}

	public function restored() {
		return array(
			'plugin'                        => array( 'plugins/akismet/akismet.php', '/var/www/wp-content/plugins/akismet/akismet.php' ),
			'theme in a custom theme root'  => array( 'themes/twentytwentyfour/style.css', '/srv/themes/twentytwentyfour/style.css' ),
			'upload in a custom uploads dir' => array( 'uploads/2024/01/a.jpg', '/data/uploads/2024/01/a.jpg' ),
			'own must-use plugin'           => array( 'mu-plugins/site-tweaks.php', '/var/www/wp-content/mu-plugins/site-tweaks.php' ),
			'other wp-content folder'       => array( 'languages/fr_FR.mo', '/var/www/wp-content/languages/fr_FR.mo' ),
			'drop-in name deeper down'      => array( 'plugins/redis-cache/includes/object-cache.php', '/var/www/wp-content/plugins/redis-cache/includes/object-cache.php' ),
			'folder that only starts alike' => array( 'uploadsx/a.txt', '/var/www/wp-content/uploadsx/a.txt' ),
			'unsafe name'                   => array( 'uploads/../../wp-config.php', null ),
		);
	}

	/**
	 * @dataProvider skipped
	 */
	public function test_skips_what_must_not_be_restored( $name ) {
		$this->assertNull( $this->map()->resolve( $name ) );
	}

	public function skipped() {
		return array(
			'package description'         => array( 'package.json' ),
			'network description'         => array( 'multisite.json' ),
			'database dump'               => array( 'database.sql' ),
			'object cache drop-in'        => array( 'object-cache.php' ),
			'page cache drop-in'          => array( 'advanced-cache.php' ),
			'database drop-in'            => array( 'db.php' ),
			'this plugin'                 => array( 'plugins/wpress-restore/wpress-restore.php' ),
			'this plugin working folder'  => array( 'wpress-restore/job.json' ),
			'backups folder'              => array( 'ai1wm-backups/site.wpress' ),
			'host must-use plugin'        => array( 'mu-plugins/wp-stack-cache.php' ),
			'host must-use plugin folder' => array( 'mu-plugins/wpcomsh/loader.php' ),
			'restore guard'               => array( 'mu-plugins/wpress-restore-guard.php' ),
			'stray theme functions'       => array( 'themes/functions.php' ),
			'elementor css cache'         => array( 'uploads/elementor/css/post-1.css' ),
			'civicrm settings'            => array( 'uploads/civicrm/civicrm.settings.php' ),
			'less cache'                  => array( 'uploads/theme.less.cache' ),
			'sqlite database'             => array( 'database/.ht.sqlite' ),
			'a root folder itself'        => array( 'plugins' ),
		);
	}
}
