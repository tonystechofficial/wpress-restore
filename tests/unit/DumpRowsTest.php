<?php

use PHPUnit\Framework\TestCase;
use WPressRestore\Dump_Rows;

final class DumpRowsTest extends TestCase {

	/**
	 * @dataProvider tables
	 */
	public function test_recognizes_the_wordpress_tables_whose_rows_it_filters_or_changes( $table, $prefix, $fold, $expected ) {
		$this->assertSame( $expected, Dump_Rows::core_table( $table, $prefix, $fold ) );
	}

	public function tables() {
		return array(
			'options'                       => array( 'wp_options', 'wp_', false, 'options' ),
			'user meta'                     => array( 'wpb_usermeta', 'wpb_', false, 'usermeta' ),
			'a plugin table'                => array( 'wp_restore_log', 'wp_', false, null ),
			'another site in the database'  => array( 'wpb_options', 'wp_', false, null ),
			'prefix case on a folding server' => array( 'wp_options', 'WP_', true, 'options' ),
			'prefix case elsewhere'         => array( 'wp_options', 'WP_', false, null ),
		);
	}

	public function test_leaves_out_ai1wm_settings_and_the_caches_both_importers_drop() {
		$this->assertSame(
			"`option_name` NOT IN ('ai1wm_status', 'ai1wm_secret_key', 'ai1wm_auth_user', 'ai1wm_auth_password', 'ai1wm_auth_header', 'ai1wm_backups_labels', 'ai1wm_sites_links')"
			. " AND LEFT(`option_name`, 11) <> '_transient_' AND LEFT(`option_name`, 16) <> '_site_transient_'"
			. " AND LEFT(`option_name`, 12) <> '_wc_session_' AND LEFT(`option_name`, 21) <> '_wpallimport_session_'",
			Dump_Rows::filter( 'options', 'wp_', array() )
		);
	}

	public function test_leaves_out_spam_comments_and_their_meta_on_request() {
		$options = array( 'no_spam_comments' => true );

		$this->assertSame( "`comment_approved` <> 'spam'", Dump_Rows::filter( 'comments', 'wpb_', $options ) );
		$this->assertSame( "`comment_id` IN (SELECT `comment_ID` FROM `wpb_comments` WHERE `comment_approved` <> 'spam')", Dump_Rows::filter( 'commentmeta', 'wpb_', $options ) );
		$this->assertSame( '', Dump_Rows::filter( 'comments', 'wpb_', array( 'no_spam_comments' => false ) ) );
	}

	public function test_leaves_out_revisions_and_their_meta_on_request() {
		$options = array( 'no_post_revisions' => true );

		$this->assertSame( "`post_type` <> 'revision'", Dump_Rows::filter( 'posts', 'wp_', $options ) );
		$this->assertSame( "`post_id` IN (SELECT `ID` FROM `wp_posts` WHERE `post_type` <> 'revision')", Dump_Rows::filter( 'postmeta', 'wp_', $options ) );
		$this->assertSame( '', Dump_Rows::filter( 'postmeta', 'wp_', array() ) );
	}

	public function test_keeps_every_row_of_other_tables() {
		$this->assertSame( '', Dump_Rows::filter( null, 'wp_', array( 'no_spam_comments' => true, 'no_post_revisions' => true ) ) );
	}

	public function test_leaves_the_theme_and_active_plugins_to_package_json_and_marks_the_roles_option() {
		$change  = Dump_Rows::changer( 'options', array( 'option_id', 'option_name', 'option_value', 'autoload' ), 'wp_' );
		$changed = static function ( $name, $value ) use ( $change ) {
			return $change( array( '7', $name, $value, 'yes' ) );
		};

		$this->assertSame( array( '7', 'active_plugins', 'a:0:{}', 'yes' ), $changed( 'active_plugins', 'a:1:{i:0;s:9:"hello.php";}' ) );
		$this->assertSame( array( '7', 'template', '', 'yes' ), $changed( 'template', 'twentytwentyfour' ) );
		$this->assertSame( array( '7', 'stylesheet', '', 'yes' ), $changed( 'stylesheet', 'child' ) );
		$this->assertSame( array( '7', 'SERVMASK_PREFIX_user_roles', 'a:0:{}', 'yes' ), $changed( 'wp_user_roles', 'a:0:{}' ) );
		$this->assertSame( array( '7', 'wp_mail_smtp', 'x', 'yes' ), $changed( 'wp_mail_smtp', 'x' ), 'other options keep their name' );
	}

	public function test_marks_the_prefix_in_user_meta_keys() {
		$change = Dump_Rows::changer( 'usermeta', array( 'umeta_id', 'user_id', 'meta_key', 'meta_value' ), 'wp_' );

		$this->assertSame( array( '1', '1', 'SERVMASK_PREFIX_capabilities', 'x' ), $change( array( '1', '1', 'wp_capabilities', 'x' ) ) );
		$this->assertSame( array( '2', '1', 'nickname', 'admin' ), $change( array( '2', '1', 'nickname', 'admin' ) ) );
		$this->assertSame( array( '3', '1', null, 'x' ), $change( array( '3', '1', null, 'x' ) ) );
	}

	public function test_changes_no_other_row() {
		$this->assertNull( Dump_Rows::changer( null, array( 'id', 'note' ), 'wp_' ) );
		$this->assertNull( Dump_Rows::changer( 'posts', array( 'ID', 'post_content' ), 'wp_' ) );
		$this->assertNull( Dump_Rows::changer( 'options', array( 'id', 'name' ), 'wp_' ), 'an options table of an unexpected shape is left as it is' );
	}

	/**
	 * @dataProvider batches
	 */
	public function test_sizes_the_next_query_to_about_two_megabytes_of_dump( $batch, $bytes, $expected ) {
		$this->assertSame( $expected, Dump_Rows::next_batch( $batch, $bytes ) );
	}

	public function batches() {
		return array(
			'small rows: grows, four times at most' => array( 100, 200000, 400 ),
			'large rows: shrinks'                   => array( 100, 10485760, 20 ),
			'one huge row: one row at a time'       => array( 1, 52428800, 1 ),
			'never more than 5000 rows'             => array( 4000, 1000, 5000 ),
			'nothing written'                       => array( 100, 0, 400 ),
		);
	}
}
