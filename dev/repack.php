<?php
/**
 * Rewrites a real backup in another variant of the format, encoded byte for byte as ai1wm does:
 *   php dev/repack.php in.wpress out.wpress v1|v2 [password] [gzip|bzip2]
 * v1 is the pre-7 layout: 4096-byte paths, no CRC, an all-zero end block.
 */

require dirname( __DIR__ ) . '/includes/autoload.php';
require dirname( __DIR__ ) . '/tests/support/Ai1wm.php';

use WPressRestore\Archive_Format;
use WPressRestore\Codec;
use WPressRestore\Entry_Stream;

list( , $in, $out, $format ) = $argv;
$password    = isset( $argv[4] ) && '' !== $argv[4] ? $argv[4] : null;
$compression = isset( $argv[5] ) ? $argv[5] : null;

$handle = fopen( $in, 'rb' );
$files  = array();
for ( $offset = 0; ; ) {
	fseek( $handle, $offset );
	$header = Archive_Format::parse_header( fread( $handle, Archive_Format::HEADER_SIZE ) );
	if ( 'eof' === $header['type'] ) {
		break;
	}
	$stream  = new Entry_Stream( $handle, $offset + Archive_Format::HEADER_SIZE, $header['size'], 0, new Codec( null, null ) );
	$content = '';
	while ( null !== ( $piece = $stream->read() ) ) {
		$content .= $piece;
	}
	$files[ $header['name'] ] = $content;
	$offset                  += Archive_Format::HEADER_SIZE + $header['size'];
}

$package = json_decode( $files['package.json'], true );
unset( $package['Encrypted'], $package['EncryptedSignature'], $package['Compression'] );
if ( $password ) {
	$package['Encrypted']          = true;
	$package['EncryptedSignature'] = base64_encode( Ai1wm::encrypt( Ai1wm::SIGN_TEXT, $password ) );
}
if ( $compression ) {
	$package['Compression'] = array( 'Enabled' => true, 'Type' => $compression );
}
if ( 'v1' === $format ) {
	// Versions before 7 had no Plugin block and no internal addresses.
	$package['Version'] = '6.76';
	unset( $package['Plugin'], $package['InternalSiteURL'], $package['InternalHomeURL'] );
}
$files['package.json'] = json_encode( $package );

file_put_contents( $out, Ai1wm::archive( $files, $password, $compression, 'v2' === $format ) );
printf( "%s: %d entries, %d bytes\n", $out, count( $files ), filesize( $out ) );
