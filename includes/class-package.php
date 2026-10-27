<?php
/**
 * package.json, the backup's description.
 *
 * @package WPressRestore
 */

namespace WPressRestore;

/**
 * What a backup says about the site it came from.
 */
final class Package {

	/**
	 * Decoded package.json.
	 *
	 * @var array
	 */
	private $data;

	/**
	 * @param array $data Decoded package.json.
	 */
	private function __construct( array $data ) {
		$this->data = $data;
	}

	/**
	 * @param string $json package.json content.
	 * @return Package
	 * @throws Archive_Exception When it is not an ai1wm package.
	 */
	public static function from_json( $json ) {
		$data = json_decode( (string) $json, true );
		if ( ! is_array( $data ) || empty( $data['SiteURL'] ) || ! is_string( $data['SiteURL'] ) ) {
			throw new Archive_Exception( 'This file is not an All-in-One WP Migration backup: its package.json is missing or unreadable.' );
		}
		return new self( $data );
	}

	/**
	 * The old site, in the shape Replace_Pairs::build() takes.
	 *
	 * @return array
	 */
	public function old_site() {
		$custom = array();
		$old    = $this->value( array( 'Replace', 'OldValues' ) );
		$new    = $this->value( array( 'Replace', 'NewValues' ) );
		if ( is_array( $old ) && is_array( $new ) ) {
			foreach ( $old as $i => $value ) {
				if ( is_string( $value ) && '' !== $value && isset( $new[ $i ] ) && is_string( $new[ $i ] ) && '' !== $new[ $i ] ) {
					$custom[] = array( $value, $new[ $i ] );
				}
			}
		}
		$site_url = $this->text( array( 'SiteURL' ) );
		return array(
			'site_url'          => $site_url,
			'home_url'          => $this->text( array( 'HomeURL' ) ) ?: $site_url,
			'internal_site_url' => $this->text( array( 'InternalSiteURL' ) ),
			'internal_home_url' => $this->text( array( 'InternalHomeURL' ) ),
			'uploads_url'       => $this->text( array( 'WordPress', 'UploadsURL' ) ),
			'absolute'          => $this->text( array( 'WordPress', 'Absolute' ) ),
			'content'           => $this->text( array( 'WordPress', 'Content' ) ),
			'custom'            => $custom,
			'replace_email'     => ! isset( $this->data['NoEmailReplace'] ),
		);
	}

	/** @return bool */
	public function is_encrypted() {
		return ! empty( $this->data['Encrypted'] ) && '' !== $this->signature();
	}

	/** @return string Base64 EncryptedSignature. */
	public function signature() {
		return $this->text( array( 'EncryptedSignature' ) );
	}

	/** @return string|null gzip, bzip2, or null when not compressed. */
	public function compression() {
		if ( ! $this->value( array( 'Compression', 'Enabled' ) ) ) {
			return null;
		}
		$type = $this->text( array( 'Compression', 'Type' ) );
		return '' === $type ? null : $type;
	}

	/** @return string[] Plugins active when the backup was made. */
	public function plugins() {
		return array_values( array_filter( (array) $this->value( array( 'Plugins' ) ), 'is_string' ) );
	}

	/** @return string */
	public function template() {
		return $this->text( array( 'Template' ) );
	}

	/** @return string */
	public function stylesheet() {
		return $this->text( array( 'Stylesheet' ) );
	}

	/** @return string */
	public function wordpress_version() {
		return $this->text( array( 'WordPress', 'Version' ) );
	}

	/** @return string */
	public function php_version() {
		return $this->text( array( 'PHP', 'Version' ) );
	}

	/** @return string Version of All-in-One WP Migration that made the backup. */
	public function plugin_version() {
		return $this->text( array( 'Plugin', 'Version' ) ) ?: $this->text( array( 'Version' ) );
	}

	/** @return string */
	public function table_prefix() {
		return $this->text( array( 'Database', 'Prefix' ) );
	}

	/** @return string[] Tables saved under their own name, outside the WordPress prefix. */
	public function included_tables() {
		return array_values( array_filter( (array) $this->value( array( 'Database', 'IncludedTables' ) ), 'is_string' ) );
	}

	/**
	 * Software the backup needs and this server may lack.
	 *
	 * @param array $here wordpress and php versions of this server.
	 * @return array[] about (wordpress or php), backup, here.
	 */
	public function warnings( array $here ) {
		$warnings  = array();
		$wordpress = $this->wordpress_version();
		// WordPress 6.8 stores passwords with bcrypt ($wp$2y$), which earlier versions cannot check.
		if ( '' !== $wordpress && version_compare( $wordpress, '6.8', '>=' ) && version_compare( $here['wordpress'], '6.8', '<' ) ) {
			$warnings[] = array(
				'about'  => 'passwords',
				'backup' => $wordpress,
				'here'   => $here['wordpress'],
			);
		}
		if ( '' !== $wordpress && version_compare( $wordpress, $here['wordpress'], '>' ) ) {
			$warnings[] = array(
				'about'  => 'wordpress',
				'backup' => $wordpress,
				'here'   => $here['wordpress'],
			);
		}
		$php = $this->php_version();
		if ( '' !== $php && version_compare( self::minor( $php ), self::minor( $here['php'] ), '>' ) ) {
			$warnings[] = array(
				'about'  => 'php',
				'backup' => $php,
				'here'   => $here['php'],
			);
		}
		return $warnings;
	}

	/**
	 * The first two parts of a version.
	 *
	 * @param string $version Version.
	 * @return string
	 */
	private static function minor( $version ) {
		return implode( '.', array_slice( explode( '.', $version ), 0, 2 ) );
	}

	/**
	 * A nested value, or null.
	 *
	 * @param string[] $path Keys.
	 * @return mixed
	 */
	private function value( array $path ) {
		$value = $this->data;
		foreach ( $path as $key ) {
			if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
				return null;
			}
			$value = $value[ $key ];
		}
		return $value;
	}

	/**
	 * A nested string, or "".
	 *
	 * @param string[] $path Keys.
	 * @return string
	 */
	private function text( array $path ) {
		$value = $this->value( $path );
		return is_string( $value ) || is_int( $value ) || is_float( $value ) ? (string) $value : '';
	}
}
