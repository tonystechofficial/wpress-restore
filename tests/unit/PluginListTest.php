<?php

use PHPUnit\Framework\TestCase;
use WPressRestore\Plugin_List;

final class PluginListTest extends TestCase {

	const OWN = 'wpress-restore/wpress-restore.php';

	public function test_keeps_the_backup_plugins_and_adds_this_one() {
		$result = Plugin_List::for_restored_site( array( 'akismet/akismet.php', 'woocommerce/woocommerce.php' ), self::OWN, true );

		$this->assertSame( array( 'akismet/akismet.php', 'woocommerce/woocommerce.php', self::OWN ), $result['active'] );
		$this->assertSame( array(), $result['deactivated'] );
	}

	public function test_does_not_list_this_plugin_twice() {
		$result = Plugin_List::for_restored_site( array( self::OWN, 'akismet/akismet.php' ), self::OWN, true );

		$this->assertSame( array( self::OWN, 'akismet/akismet.php' ), $result['active'] );
	}

	public function test_turns_off_https_enforcement_on_a_site_without_https() {
		$active = array( 'akismet/akismet.php', 'really-simple-ssl/rlrsssl-really-simple-ssl.php', 'really-simple-ssl-pro/rlrsssl-really-simple-ssl.php' );

		$result = Plugin_List::for_restored_site( $active, self::OWN, false );

		$this->assertSame( array( 'akismet/akismet.php', self::OWN ), $result['active'] );
		$this->assertSame( array( 'really-simple-ssl/rlrsssl-really-simple-ssl.php', 'really-simple-ssl-pro/rlrsssl-really-simple-ssl.php' ), $result['deactivated'] );
	}

	public function test_keeps_https_enforcement_on_a_site_with_https() {
		$result = Plugin_List::for_restored_site( array( 'really-simple-ssl/rlrsssl-really-simple-ssl.php' ), self::OWN, true );

		$this->assertContains( 'really-simple-ssl/rlrsssl-really-simple-ssl.php', $result['active'] );
	}

	public function test_turns_off_plugins_that_hide_the_login_page() {
		$result = Plugin_List::for_restored_site( array( 'wps-hide-login/wps-hide-login.php', 'akismet/akismet.php' ), self::OWN, true );

		$this->assertSame( array( 'akismet/akismet.php', self::OWN ), $result['active'] );
		$this->assertSame( array( 'wps-hide-login/wps-hide-login.php' ), $result['deactivated'] );
	}

	public function test_a_damaged_list_leaves_only_this_plugin() {
		$this->assertSame( array( self::OWN ), Plugin_List::for_restored_site( 'not a list', self::OWN, true )['active'] );
	}
}
