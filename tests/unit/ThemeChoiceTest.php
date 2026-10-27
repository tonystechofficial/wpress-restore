<?php

use PHPUnit\Framework\TestCase;
use WPressRestore\Theme_Choice;

final class ThemeChoiceTest extends TestCase {

	private static function installed( array $themes ) {
		return static function ( $theme ) use ( $themes ) {
			return in_array( $theme, $themes, true );
		};
	}

	public function test_uses_the_theme_the_backup_ran() {
		$choice = Theme_Choice::pick(
			array( 'twentytwentyfour', 'twentytwentyfour' ),
			array( '', '' ),
			array( 'twentytwentyfive', 'twentytwentyfive' ),
			self::installed( array( 'twentytwentyfour', 'twentytwentyfive' ) )
		);

		$this->assertSame( array( 'twentytwentyfour', 'twentytwentyfour' ), $choice );
	}

	public function test_a_child_theme_needs_its_parent_too() {
		$installed = self::installed( array( 'child', 'twentytwentyfive' ) );

		$this->assertSame( array( 'twentytwentyfive', 'twentytwentyfive' ), Theme_Choice::pick( array( 'parent', 'child' ), array( '', '' ), array( 'twentytwentyfive', 'twentytwentyfive' ), $installed ) );
		$this->assertSame( array( 'parent', 'child' ), Theme_Choice::pick( array( 'parent', 'child' ), array( '', '' ), array( 'twentytwentyfive', 'twentytwentyfive' ), self::installed( array( 'parent', 'child' ) ) ) );
	}

	public function test_falls_back_to_the_restored_database_when_the_package_names_no_theme() {
		$choice = Theme_Choice::pick( array( '', '' ), array( 'astra', 'astra-child' ), array( 'twentytwentyfive', 'twentytwentyfive' ), self::installed( array( 'astra', 'astra-child' ) ) );

		$this->assertSame( array( 'astra', 'astra-child' ), $choice );
	}

	public function test_keeps_this_site_theme_when_the_backup_theme_is_not_installed() {
		$choice = Theme_Choice::pick( array( 'gone', 'gone' ), array( '', '' ), array( 'twentytwentyfive', 'twentytwentyfive' ), self::installed( array( 'twentytwentyfive' ) ) );

		$this->assertSame( array( 'twentytwentyfive', 'twentytwentyfive' ), $choice );
	}
}
