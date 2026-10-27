<?php
/**
 * Stresses the export on site A: run with `wp eval-file seed-export-a.php seed`, then compare
 * `wp eval-file seed-export-a.php sums` on site A and on the site that restored the backup.
 *
 * seed: tables paged by key, by offset (composite key, no key) and by size (1 MB blobs); a post
 *       revision, a spam comment with meta, a cache folder, a 300 MB upload, and a mu-plugin
 *       that shortens each step to 0.05 s so every phase stops and resumes many times.
 * sums: row counts and content hashes of those tables, under this site's prefix, as JSON.
 */

global $wpdb;
$mode = isset( $args[0] ) ? $args[0] : 'sums';
$p    = $wpdb->prefix;

if ( 'seed' === $mode ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$p}big_keyed, {$p}big_composite, {$p}big_nokey, {$p}big_blob" );
	$wpdb->query( "CREATE TABLE {$p}big_keyed (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, payload VARCHAR(100) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );
	$wpdb->query( "CREATE TABLE {$p}big_composite (a INT NOT NULL, b INT NOT NULL, v VARCHAR(20), PRIMARY KEY (a, b)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );
	$wpdb->query( "CREATE TABLE {$p}big_nokey (v VARCHAR(40), n INT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );
	$wpdb->query( "CREATE TABLE {$p}big_blob (id INT NOT NULL PRIMARY KEY, data LONGBLOB) ENGINE=InnoDB" );
	for ( $chunk = 0; $chunk < 30; $chunk++ ) {
		$values = array();
		for ( $i = 0; $i < 1000; $i++ ) {
			$values[] = $wpdb->prepare( '(%s)', "row {$chunk}-{$i} it's \"quoted\" \\ ; é " . str_repeat( 'x', $i % 50 ) );
		}
		$wpdb->query( "INSERT INTO {$p}big_keyed (payload) VALUES " . implode( ',', $values ) );
	}
	// Gaps in the key: paging must not assume consecutive ids.
	$wpdb->query( "DELETE FROM {$p}big_keyed WHERE id % 7 = 3" );
	for ( $a = 0; $a < 50; $a++ ) {
		$values = array();
		for ( $b = 0; $b < 100; $b++ ) {
			$values[] = $wpdb->prepare( '(%d, %d, %s)', $a, $b, "c{$a}-{$b}" );
		}
		$wpdb->query( "INSERT INTO {$p}big_composite VALUES " . implode( ',', $values ) );
	}
	$values = array();
	for ( $i = 0; $i < 3000; $i++ ) {
		// Duplicate rows on purpose: a table without a key may hold them.
		$values[] = $wpdb->prepare( '(%s, %d)', 'dup ' . ( $i % 1000 ), $i % 1000 );
	}
	$wpdb->query( "INSERT INTO {$p}big_nokey VALUES " . implode( ',', $values ) );
	for ( $i = 1; $i <= 20; $i++ ) {
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$p}big_blob VALUES (%d, UNHEX(%s))", $i, bin2hex( random_bytes( 1048576 ) ) ) );
	}

	$post = get_page_by_title( 'Restore test', OBJECT, 'post' ); // phpcs:ignore -- test script.
	wp_update_post( array( 'ID' => $post->ID, 'post_content' => $post->post_content . ' <!-- edited -->' ) );
	wp_update_post( array( 'ID' => $post->ID, 'post_content' => $post->post_content ) );
	$spam = wp_insert_comment( array( 'comment_post_ID' => $post->ID, 'comment_content' => 'Buy now', 'comment_approved' => 'spam', 'comment_author' => 'spammer' ) );
	add_comment_meta( $spam, 'spam_meta', 'x' );
	$good = wp_insert_comment( array( 'comment_post_ID' => $post->ID, 'comment_content' => 'Nice post', 'comment_approved' => '1', 'comment_author' => 'reader' ) );
	add_comment_meta( $good, 'good_meta', 'y' );

	wp_mkdir_p( WP_CONTENT_DIR . '/cache/page' );
	file_put_contents( WP_CONTENT_DIR . '/cache/page/index.html', '<html>cached</html>' );
	$big = wp_get_upload_dir()['basedir'] . '/2026/10/big-export.bin';
	$out = fopen( $big, 'wb' );
	for ( $i = 0; $i < 300; $i++ ) {
		fwrite( $out, random_bytes( 1048576 ) );
	}
	fclose( $out );
	file_put_contents( WPMU_PLUGIN_DIR . '/short-steps.php', "<?php\nadd_filter( 'wpress_restore_step_seconds', function () { return 0.05; } );\n" );
	echo "seeded; big file sha1 ", sha1_file( $big ), "\n";
	return;
}

$sums = array();
foreach ( array( 'big_keyed' => 'id', 'big_composite' => 'a, b', 'big_nokey' => 'v, n', 'big_blob' => 'id' ) as $table => $order ) {
	$hash  = hash_init( 'sha1' );
	$count = 0;
	foreach ( (array) $wpdb->get_results( "SELECT * FROM {$p}{$table} ORDER BY {$order}", ARRAY_N ) as $row ) {
		hash_update( $hash, json_encode( array_map( 'bin2hex', $row ) ) );
		++$count;
	}
	$sums[ $table ] = array( $count, hash_final( $hash ) );
}
$sums['revisions']   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}posts WHERE post_type = 'revision'" );
$sums['spam']        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}comments WHERE comment_approved = 'spam'" );
$sums['commentmeta'] = $wpdb->get_col( "SELECT meta_key FROM {$p}commentmeta ORDER BY meta_key" );
$big                 = wp_get_upload_dir()['basedir'] . '/2026/10/big-export.bin';
$sums['big_file']    = is_file( $big ) ? sha1_file( $big ) : null;
$sums['cache']       = is_file( WP_CONTENT_DIR . '/cache/page/index.html' );
echo json_encode( $sums ), "\n";
