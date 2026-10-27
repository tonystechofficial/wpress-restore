<?php
/**
 * Reads a .wpress file with the plugin's own classes, outside WordPress:
 *   php dev/inspect.php backup.wpress [password]
 * Lists the entries, checks the archive CRC, prints package.json and counts the dump's statements.
 */

require dirname( __DIR__ ) . '/includes/autoload.php';

use WPressRestore\Archive_Format;
use WPressRestore\Codec;
use WPressRestore\Crc32;
use WPressRestore\Entry_Stream;
use WPressRestore\Package;
use WPressRestore\Sql_Reader;

$path     = $argv[1];
$password = isset( $argv[2] ) ? $argv[2] : null;
$handle   = fopen( $path, 'rb' );
$size     = filesize( $path );
$offset   = 0;
$entries  = array();
$eof      = null;
while ( true ) {
	fseek( $handle, $offset );
	$header = Archive_Format::parse_header( fread( $handle, Archive_Format::HEADER_SIZE ) );
	if ( 'eof' === $header['type'] ) {
		$eof = $header;
		break;
	}
	$entries[ $header['name'] ] = array( 'start' => $offset + Archive_Format::HEADER_SIZE, 'size' => $header['size'], 'crc32' => $header['crc32'] );
	$offset                    += Archive_Format::HEADER_SIZE + $header['size'];
}
printf( "%d entries, end block at %d of %d, end ok: %s\n", count( $entries ), $offset, $size, $offset + Archive_Format::HEADER_SIZE === $size ? 'yes' : 'NO' );

$crc = null;
fseek( $handle, 0 );
for ( $left = $offset; $left > 0; ) {
	$chunk = fread( $handle, min( 4 << 20, $left ) );
	$crc   = Crc32::combine( $crc, hash( 'crc32b', $chunk ), strlen( $chunk ) );
	$left -= strlen( $chunk );
}
printf( "archive crc %s, end block says %s\n", $crc, var_export( $eof['crc32'], true ) );

fseek( $handle, $entries['package.json']['start'] );
$json    = fread( $handle, $entries['package.json']['size'] );
$package = Package::from_json( $json );
echo "package.json: ", $json, "\n";

$codec = new Codec( $password, $package->compression() );
$tops  = array();
$bad   = 0;
foreach ( $entries as $name => $entry ) {
	$top          = strstr( $name . '/', '/', true );
	$tops[ $top ] = ( isset( $tops[ $top ] ) ? $tops[ $top ] : 0 ) + 1;
	if ( $entry['crc32'] ) {
		$stream = new Entry_Stream( $handle, $entry['start'], $entry['size'], 0, $codec->for_entry( $name ) );
		$hash   = hash_init( 'crc32b' );
		while ( null !== ( $piece = $stream->read() ) ) {
			hash_update( $hash, $piece );
		}
		if ( hash_final( $hash ) !== $entry['crc32'] ) {
			++$bad;
			echo "CRC MISMATCH: $name\n";
		}
	}
}
echo 'entries by top folder: ', json_encode( $tops ), "\n";
echo "per-file CRC mismatches: $bad\n";

$dump   = tmpfile();
$stream = new Entry_Stream( $handle, $entries['database.sql']['start'], $entries['database.sql']['size'], 0, $codec->for_entry( 'database.sql' ) );
while ( null !== ( $piece = $stream->read() ) ) {
	fwrite( $dump, $piece );
}
$reader = new Sql_Reader( $dump, 0, 16 << 20 );
$kinds  = array();
$first  = array();
while ( null !== ( $statement = $reader->next() ) ) {
	$word           = strtoupper( strtok( preg_replace( '/^(\s*--[^\n]*\n)*\s*/', '', $statement['sql'] ), " \n(" ) );
	$kinds[ $word ] = ( isset( $kinds[ $word ] ) ? $kinds[ $word ] : 0 ) + 1;
	if ( count( $first ) < 3 && 'CREATE' === $word ) {
		$first[] = $statement['sql'];
	}
}
echo 'statements: ', json_encode( $kinds ), "\n", implode( "\n\n", $first ), "\n";
