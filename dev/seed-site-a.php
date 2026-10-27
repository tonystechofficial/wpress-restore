<?php
/**
 * Fills site A with what a restore must carry across: run with `wp eval-file`.
 * Prints the checks the restored site B must pass, as JSON.
 */

global $wpdb;
$home = home_url();

// Content linking to the site in the spellings plugins store.
$post_id = wp_insert_post(
	array(
		'post_title'   => 'Restore test',
		'post_status'  => 'publish',
		'post_content' => '<p>See <a href="' . $home . '/about/">about</a>, http://localhost:8081/legacy and <img src="/wp-content/uploads/2026/10/photo.jpg">.</p>',
	)
);
update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( array( array( 'url' => $home . '/x', 'mail' => 'hello@localhost' ) ) ) ) );
update_post_meta( $post_id, 'serialized_meta', array( 'link' => $home . '/meta', 'n' => 3 ) );
update_option( 'restore_test_option', array( 'url' => $home . '/opt', 'inner' => serialize( array( 'deep' => $home . '/deep' ) ), 'text' => 'Café — ' . $home ) );
update_option( 'restore_test_path', WP_CONTENT_DIR . '/uploads/file.txt' );
set_transient( 'restore_test_transient', 'should not travel', DAY_IN_SECONDS );

// An editor, whose capabilities live under a prefixed meta key.
$editor = wp_insert_user( array( 'user_login' => 'editor1', 'user_pass' => 'pass-editor', 'user_email' => 'editor@site-a.test', 'role' => 'editor' ) );

// A plugin table created without a collation: MySQL 8 gives it utf8mb4_0900_ai_ci, unknown to MariaDB.
$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}restore_log (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, note VARCHAR(190) NOT NULL, KEY note (note)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );
$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->prefix}restore_log (note) VALUES (%s), (%s), (%s)", "it's; tricky \\ \"quoted\"", 'line' . "\n" . 'break', $home . '/logged' ) );
// Tables tied by a foreign key, as WooCommerce has.
$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}restore_parent (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB" );
$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}restore_child (id INT NOT NULL PRIMARY KEY, parent_id INT NOT NULL, CONSTRAINT fk_{$wpdb->prefix}restore_child FOREIGN KEY (parent_id) REFERENCES {$wpdb->prefix}restore_parent (id) ON DELETE CASCADE) ENGINE=InnoDB" );
$wpdb->query( "INSERT IGNORE INTO {$wpdb->prefix}restore_parent VALUES (1), (2)" );
$wpdb->query( "INSERT IGNORE INTO {$wpdb->prefix}restore_child VALUES (10, 1), (20, 2)" );

// A binary upload and a must-use plugin of the site's own.
$uploads = wp_get_upload_dir();
wp_mkdir_p( $uploads['basedir'] . '/2026/10' );
file_put_contents( $uploads['basedir'] . '/2026/10/photo.jpg', random_bytes( 3 * 1024 * 1024 + 123 ) );
wp_mkdir_p( WPMU_PLUGIN_DIR );
file_put_contents( WPMU_PLUGIN_DIR . '/site-tweaks.php', "<?php\n// Site A tweaks.\nadd_filter( 'restore_test_mu', '__return_true' );\n" );

update_option( 'blogname', 'Site A restored' );
activate_plugin( 'hello.php' );

echo wp_json_encode(
	array(
		'post_id'    => $post_id,
		'editor_id'  => $editor,
		'photo_sha1' => sha1_file( $uploads['basedir'] . '/2026/10/photo.jpg' ),
	),
	JSON_PRETTY_PRINT
), "\n";
