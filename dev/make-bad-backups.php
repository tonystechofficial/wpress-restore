<?php
/**
 * Builds the damaged and hostile backups the failure tests restore:
 *   php dev/make-bad-backups.php dev/backups/plain.wpress dev/backups
 */

require dirname( __DIR__ ) . '/tests/support/Ai1wm.php';

list( , $source, $dir ) = $argv;
$good = file_get_contents( $source );

// A byte flipped inside a file's content: a transfer error.
$corrupt              = $good;
$corrupt[ 10000000 ] = chr( ord( $corrupt[ 10000000 ] ) ^ 0x01 );
file_put_contents( "$dir/corrupt.wpress", $corrupt );

// The first 15 MB only: an interrupted upload.
file_put_contents( "$dir/truncated.wpress", substr( $good, 0, 15000000 ) );

// Not a backup at all.
file_put_contents( "$dir/fake.wpress", random_bytes( 1000000 ) );

// A files-only backup whose entries try to leave wp-content.
file_put_contents(
	"$dir/escape.wpress",
	Ai1wm::archive(
		array(
			'package.json'               => Ai1wm::package( array( 'SiteURL' => 'http://localhost:8081', 'HomeURL' => 'http://localhost:8081' ) ),
			'../../escape-1.php'         => '<?php echo "escaped";',
			'uploads/../../../escape-2.php' => '<?php echo "escaped";',
			'uploads/..\\..\\escape-3.php' => '<?php echo "escaped";',
			'uploads/2026/10/fine.txt'   => 'this one belongs',
		)
	)
);
echo "built corrupt, truncated, fake and escape backups in $dir\n";
