<?php
/**
 * Builds archives byte for byte the way All-in-One WP Migration 7.x writes them, for the tests.
 */
final class Ai1wm {

	const SIGN_TEXT = '"How long do you want these messages to remain secret? I want them to remain secret for as long as men are capable of evil." - Neal Stephenson';

	/** ai1wm_encrypt_string(): AES-256-CBC, key = first 16 bytes of sha1(password), IV prepended. */
	public static function encrypt( $plain, $password, $iv = null ) {
		$iv = null === $iv ? random_bytes( 16 ) : $iv;
		return $iv . openssl_encrypt( $plain, 'AES-256-CBC', substr( sha1( $password, true ), 0, 16 ), OPENSSL_RAW_DATA, $iv );
	}

	/** Ai1wm_Compressor::add_file(): 512000-byte chunks, compressed, then encrypted, then length-prefixed when compressed. */
	public static function encode( $content, $password = null, $compression = null ) {
		$out = '';
		for ( $at = 0; $at < strlen( $content ); $at += 512000 ) {
			$chunk = substr( $content, $at, 512000 );
			if ( 'gzip' === $compression ) {
				$chunk = gzcompress( $chunk, 9 );
			} elseif ( 'bzip2' === $compression ) {
				$chunk = bzcompress( $chunk, 9 );
			}
			if ( null !== $password ) {
				$chunk = self::encrypt( $chunk, $password );
			}
			if ( null !== $compression ) {
				$chunk = pack( 'N', strlen( $chunk ) ) . $chunk;
			}
			$out .= $chunk;
		}
		return $out;
	}

	/**
	 * A whole archive. $files maps archive names to contents; package.json and multisite.json stay plain.
	 *
	 * @param array       $files       Name => content, in archive order.
	 * @param string|null $password    Encryption password.
	 * @param string|null $compression gzip or bzip2.
	 * @param bool        $v2          Current format (per-file and archive CRC) or the old one.
	 */
	public static function archive( array $files, $password = null, $compression = null, $v2 = true ) {
		$out = '';
		foreach ( $files as $name => $content ) {
			$plain  = in_array( $name, array( 'package.json', 'multisite.json' ), true );
			$stored = $plain ? $content : self::encode( $content, $password, $compression );
			$dir    = dirname( $name );
			$out   .= $v2
				? pack( 'a255a14a12a4088a8', basename( $name ), strlen( $stored ), 1700000000, $dir, hash( 'crc32b', $content ) )
				: pack( 'a255a14a12a4096', basename( $name ), strlen( $stored ), 1700000000, $dir );
			$out   .= $stored;
		}
		return $out . ( $v2 ? pack( 'a255a14a4100a8', '', strlen( $out ), '', hash( 'crc32b', $out ) ) : str_repeat( "\0", 4377 ) );
	}

	/** A package.json like the one the current version writes. */
	public static function package( array $overrides = array() ) {
		return json_encode(
			array_replace_recursive(
				array(
					'SiteURL'         => 'https://old.example',
					'HomeURL'         => 'https://old.example',
					'InternalSiteURL' => 'https://old.example',
					'InternalHomeURL' => 'https://old.example',
					'Plugin'          => array( 'Version' => '7.112' ),
					'WordPress'       => array(
						'Version'    => '6.8.3',
						'Absolute'   => '/home/old/public_html/',
						'Content'    => '/home/old/public_html/wp-content',
						'Uploads'    => '/home/old/public_html/wp-content/uploads',
						'UploadsURL' => 'https://old.example/wp-content/uploads/',
					),
					'Database'        => array( 'Version' => '8.0.36', 'Charset' => 'utf8mb4', 'Collate' => 'utf8mb4_unicode_520_ci', 'Prefix' => 'wp_' ),
					'PHP'             => array( 'Version' => '8.2.0', 'System' => 'Linux', 'Integer' => 8 ),
					'Plugins'         => array( 'akismet/akismet.php' ),
					'Template'        => 'twentytwentyfour',
					'Stylesheet'      => 'twentytwentyfour',
				),
				$overrides
			)
		);
	}
}
