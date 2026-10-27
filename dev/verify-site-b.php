<?php
/**
 * Checks site B after restoring site A's backup: run with `wp eval-file`.
 * Prints one line per check and exits non-zero if any fails.
 *   wp eval-file verify-site-b.php          after a restore by WPress Restore
 *   wp eval-file verify-site-b.php ai1wm    after an import by All-in-One WP Migration, which
 *                                           activates itself and rebuilds the permalinks
 */

global $wpdb;
$by_ai1wm = isset( $args[0] ) && 'ai1wm' === $args[0];
$checks   = array();
$check  = static function ( $label, $ok, $detail = '' ) use ( &$checks ) {
	$checks[] = array( $ok, $label, $detail );
};

wp_cache_flush();
$expected_home = 'http://localhost:8082';
$check( 'keeps its own address', get_option( 'siteurl' ) === $expected_home && get_option( 'home' ) === $expected_home, get_option( 'home' ) );
$check( 'has the backup\'s content', 'Site A restored' === get_option( 'blogname' ), get_option( 'blogname' ) );
$check( 'runs the backup\'s theme', 'twentytwentyfour' === get_option( 'stylesheet' ), get_option( 'stylesheet' ) );
$plugins = (array) get_option( 'active_plugins' );
$wanted  = array( 'akismet/akismet.php', 'hello.php', $by_ai1wm ? 'all-in-one-wp-migration/all-in-one-wp-migration.php' : 'wpress-restore/wpress-restore.php' );
$sorted  = $plugins;
sort( $wanted );
sort( $sorted );
$check( 'runs the backup\'s plugins and the importer', $wanted === $sorted, implode( ', ', $plugins ) );

$roles = get_option( $wpdb->prefix . 'user_roles' );
$check( 'roles live under this site\'s prefix', is_array( $roles ) && isset( $roles['administrator'] ), $wpdb->prefix . 'user_roles' );
$admin  = get_user_by( 'login', 'admin' );
$editor = get_user_by( 'login', 'editor1' );
$check( 'the backup\'s admin is an administrator', $admin && in_array( 'administrator', $admin->roles, true ) );
$check( 'the backup\'s editor is an editor', $editor && in_array( 'editor', $editor->roles, true ) );
$check( 'the backup\'s password works', $admin && wp_check_password( 'pass-a', $admin->user_pass, $admin->ID ) );
$check( 'this site\'s own user is gone', ! get_user_by( 'login', 'bob' ) );

$post = get_page_by_title( 'Restore test', OBJECT, 'post' ); // phpcs:ignore -- test script.
$check( 'links in posts point here', $post && false !== strpos( $post->post_content, $expected_home . '/about/' ) && false === strpos( $post->post_content, ':8081' ), $post ? $post->post_content : 'no post' );
$elementor = $post ? get_post_meta( $post->ID, '_elementor_data', true ) : '';
$check( 'JSON-escaped links point here', false !== strpos( $elementor, 'http:\/\/localhost:8082\/x' ), $elementor );
$meta = $post ? get_post_meta( $post->ID, 'serialized_meta', true ) : null;
$check( 'serialized meta still unserializes, moved', is_array( $meta ) && $expected_home . '/meta' === $meta['link'], wp_json_encode( $meta ) );
$option = get_option( 'restore_test_option' );
$inner  = is_array( $option ) ? unserialize( $option['inner'] ) : null;
$check( 'serialized option still unserializes, moved', is_array( $option ) && $expected_home . '/opt' === $option['url'] && 'Café — ' . $expected_home === $option['text'], wp_json_encode( $option ) );
$check( 'serialized data inside serialized data, moved', is_array( $inner ) && $expected_home . '/deep' === $inner['deep'], wp_json_encode( $inner ) );
$check( 'transients stayed behind', false === get_transient( 'restore_test_transient' ) );

$table = $wpdb->prefix . 'restore_log';
$rows  = $wpdb->get_col( "SELECT note FROM {$table} ORDER BY id" );
$check( 'plugin table with tricky strings', array( "it's; tricky \\ \"quoted\"", "line\nbreak", $expected_home . '/logged' ) === $rows, wp_json_encode( $rows ) );
$collation = $wpdb->get_var( $wpdb->prepare( 'SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
$check( 'MySQL 8 collation fitted to this server', null !== $collation, (string) $collation );
$foreign = (int) $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = '{$wpdb->prefix}restore_child'" );
$check( 'foreign-keyed table restored, key dropped', 2 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}restore_child" ) && 0 === $foreign );
$leftovers = $wpdb->get_col( "SHOW TABLES WHERE `Tables_in_" . DB_NAME . "` REGEXP '^wp[ro][0-9a-f]{6}_'" );
$check( 'no temporary or replaced tables left', array() === $leftovers, implode( ', ', $leftovers ) );

$photo = wp_get_upload_dir()['basedir'] . '/2026/10/photo.jpg';
$check( 'binary upload identical', is_file( $photo ) && '741e488cd5c1a98c78125fc2e1db886e4dfc26ad' === sha1_file( $photo ) );
$check( 'the backup\'s must-use plugin is in place', is_file( WPMU_PLUGIN_DIR . '/site-tweaks.php' ) );
$check( 'the guard is gone', ! is_file( WPMU_PLUGIN_DIR . '/wpress-restore-guard.php' ) );
if ( ! $by_ai1wm ) {
	$check( 'rewrite rules left to regenerate', ! $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'rewrite_rules'" ) );
}

$failed = 0;
foreach ( $checks as $line ) {
	list( $ok, $label, $detail ) = $line;
	$failed += $ok ? 0 : 1;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, ( ! $ok || '' !== $detail ) && strlen( $detail ) < 200 ? '  [' . $detail . ']' : '' );
}
printf( "%d checks, %d failed\n", count( $checks ), $failed );
exit( $failed ? 1 : 0 );
