<?php
/**
 * Decryption and decompression of stored content.
 *
 * @package WPressRestore
 */

namespace WPressRestore;

/**
 * Turns stored chunks back into file content, for archives made with a password or with compression.
 *
 * All-in-One WP Migration cuts each file into 512000-byte chunks and, per chunk, compresses
 * (zlib or bzip2), then encrypts (AES-256-CBC, random IV in front), then, when compressed,
 * prefixes the result with its length on 4 bytes. package.json and multisite.json stay plain.
 */
final class Codec {

	/** The text ai1wm encrypts into package.json's EncryptedSignature, to check a password. */
	const SIGN_TEXT = '"How long do you want these messages to remain secret? I want them to remain secret for as long as men are capable of evil." - Neal Stephenson';

	const CHUNK     = 512000;
	const IV_LENGTH = 16;
	const CIPHER    = 'AES-256-CBC';

	/**
	 * Encryption password, null when the archive is not encrypted.
	 *
	 * @var string|null
	 */
	private $password;

	/**
	 * gzip, bzip2 or null.
	 *
	 * @var string|null
	 */
	private $compression;

	/**
	 * @param string|null $password    Encryption password.
	 * @param string|null $compression gzip, bzip2 or null.
	 * @throws Archive_Exception On an unknown compression.
	 */
	public function __construct( $password, $compression ) {
		if ( null !== $compression && ! in_array( $compression, array( 'gzip', 'bzip2' ), true ) ) {
			throw new Archive_Exception( sprintf( 'The backup uses a compression this plugin does not know: %s.', $compression ) );
		}
		$this->password    = ( null === $password || '' === $password ) ? null : (string) $password;
		$this->compression = $compression;
	}

	/**
	 * The codec for one entry: the root config files are never encoded.
	 *
	 * @param string $name Relative entry name.
	 * @return Codec
	 */
	public function for_entry( $name ) {
		return in_array( $name, array( 'package.json', 'multisite.json' ), true ) ? new self( null, null ) : $this;
	}

	/** @return bool */
	public function is_plain() {
		return null === $this->password && null === $this->compression;
	}

	/** @return bool */
	public function is_compressed() {
		return null !== $this->compression;
	}

	/** @return bool */
	public function is_encrypted() {
		return null !== $this->password;
	}

	/**
	 * Length of the next chunk of an encrypted, uncompressed entry, as ai1wm's extractor reads it:
	 * a full 512000-byte chunk grows by an IV and a padding block.
	 *
	 * @param int $remaining Stored bytes left in the entry.
	 * @return int
	 */
	public static function encrypted_chunk_length( $remaining ) {
		return $remaining > self::CHUNK ? min( $remaining, self::CHUNK + 2 * self::IV_LENGTH ) : $remaining;
	}

	/**
	 * Decodes one stored chunk (without the length prefix).
	 *
	 * @param string $stored Chunk as stored.
	 * @return string Original content.
	 * @throws Archive_Exception When the chunk cannot be decrypted or decompressed.
	 */
	public function decode( $stored ) {
		$data = null === $this->password ? $stored : self::decrypt( $stored, $this->password );
		if ( 'gzip' === $this->compression ) {
			$data = @gzuncompress( $data ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- failure is checked below.
			if ( false === $data ) {
				throw new Archive_Exception( 'A compressed part of the backup cannot be decompressed: the file is damaged.' );
			}
		} elseif ( 'bzip2' === $this->compression ) {
			if ( ! function_exists( 'bzdecompress' ) ) {
				throw new Archive_Exception( 'This backup is compressed with bzip2, which this server cannot read (PHP bz2 extension missing).' );
			}
			$data = bzdecompress( $data );
			if ( ! is_string( $data ) ) {
				throw new Archive_Exception( 'A compressed part of the backup cannot be decompressed: the file is damaged.' );
			}
		}
		return $data;
	}

	/**
	 * Decrypts like ai1wm_decrypt_string(): the key is the first 16 bytes of sha1( password ), the IV leads the data.
	 *
	 * @param string $data     IV followed by ciphertext.
	 * @param string $password Password.
	 * @return string
	 * @throws Archive_Exception When decryption fails.
	 */
	public static function decrypt( $data, $password ) {
		if ( strlen( $data ) <= self::IV_LENGTH ) {
			throw new Archive_Exception( 'An encrypted part of the backup is too short: the file is damaged.' );
		}
		$plain = openssl_decrypt(
			substr( $data, self::IV_LENGTH ),
			self::CIPHER,
			substr( sha1( $password, true ), 0, self::IV_LENGTH ),
			OPENSSL_RAW_DATA,
			substr( $data, 0, self::IV_LENGTH )
		);
		if ( false === $plain ) {
			throw new Archive_Exception( 'Part of the backup cannot be decrypted: wrong password or damaged file.' );
		}
		return $plain;
	}

	/**
	 * Whether a password opens the backup, checked against package.json's EncryptedSignature.
	 *
	 * @param string $signature Base64 signature.
	 * @param string $password  Candidate password.
	 * @return bool
	 */
	public static function password_matches( $signature, $password ) {
		$raw = base64_decode( (string) $signature, true );
		if ( false === $raw ) {
			return false;
		}
		try {
			return hash_equals( self::SIGN_TEXT, self::decrypt( $raw, (string) $password ) );
		} catch ( Archive_Exception $e ) {
			return false;
		}
	}
}
