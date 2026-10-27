<?php
/**
 * Listing the files of a backup.
 *
 * @package WPressRestore
 */

namespace WPressRestore;

/**
 * Lists the files under a folder, a folder at a time, so the listing can stop and resume.
 *
 * Like All-in-One WP Migration: excluded paths leave out everything below them, names holding a
 * line break are skipped, and links to folders are followed. A link is followed once, and never
 * when it points back inside the folder being listed, so a link loop cannot run forever.
 * One line per file: size, mtime, path and archive name, the last two in base64 so any byte fits.
 */
final class File_Walker {

	/**
	 * Folder listed, "/" separated.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Its real path.
	 *
	 * @var string
	 */
	private $real_root;

	/**
	 * Prefix of the archive names.
	 *
	 * @var string
	 */
	private $prefix;

	/**
	 * Excluded absolute paths => true.
	 *
	 * @var bool[]
	 */
	private $excluded = array();

	/**
	 * @param string   $root     Folder to list.
	 * @param string   $prefix   Archive name prefix of its files ("", "uploads/", "plugins/" or "themes/").
	 * @param string[] $excluded Absolute paths left out, with everything below them.
	 */
	public function __construct( $root, $prefix, array $excluded ) {
		$this->root      = self::normalize( $root );
		$real            = realpath( $root );
		$this->real_root = false === $real ? $this->root : self::normalize( $real );
		$this->prefix    = $prefix;
		foreach ( $excluded as $path ) {
			$this->excluded[ self::normalize( $path ) ] = true;
		}
	}

	/** @return array The state of a listing that has not started. */
	public static function start() {
		return array(
			'pending'  => array( '' ),
			'files'    => 0,
			'bytes'    => 0,
			'followed' => array(),
		);
	}

	/**
	 * Lists folders until done or told to stop; $state keeps the folders still to list.
	 *
	 * @param array    $state Listing state, from start().
	 * @param resource $list  Where file lines are written.
	 * @param callable $stop  Returns true when the work must stop for this request.
	 * @return bool Whether the listing is complete.
	 */
	public function walk( array &$state, $list, callable $stop ) {
		while ( $state['pending'] ) {
			$relative = array_pop( $state['pending'] );
			$dir      = '' === $relative ? $this->root : $this->root . '/' . $relative;
			$names    = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- an unreadable folder is skipped, as ai1wm does.
			$subdirs  = array();
			foreach ( false === $names ? array() : $names as $name ) {
				if ( '.' === $name || '..' === $name || false !== strpbrk( $name, "\r\n" ) ) {
					continue;
				}
				$path = $dir . '/' . $name;
				$rel  = '' === $relative ? $name : $relative . '/' . $name;
				if ( isset( $this->excluded[ $path ] ) ) {
					continue;
				}
				if ( is_dir( $path ) ) {
					if ( is_link( $path ) && ! $this->follow( $path, $state ) ) {
						continue;
					}
					$subdirs[] = $rel;
				} elseif ( is_file( $path ) ) {
					$size = (int) filesize( $path );
					fwrite( $list, $size . "\t" . (int) filemtime( $path ) . "\t" . base64_encode( $path ) . "\t" . base64_encode( $this->prefix . $rel ) . "\n" );
					++$state['files'];
					$state['bytes'] += $size;
				}
			}
			foreach ( array_reverse( $subdirs ) as $subdir ) {
				$state['pending'][] = $subdir;
			}
			if ( $state['pending'] && $stop() ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Decodes a line written by walk().
	 *
	 * @param string $line Line.
	 * @return array|null size, mtime, path, name.
	 */
	public static function read_line( $line ) {
		$parts = explode( "\t", rtrim( $line, "\n" ) );
		if ( 4 !== count( $parts ) ) {
			return null;
		}
		return array(
			'size'  => (int) $parts[0],
			'mtime' => (int) $parts[1],
			'path'  => base64_decode( $parts[2] ),
			'name'  => base64_decode( $parts[3] ),
		);
	}

	/**
	 * Whether to list the folder a link points to: once, and only outside the listed folder.
	 *
	 * @param string $path  Link.
	 * @param array  $state Listing state; records the folders followed.
	 * @return bool
	 */
	private function follow( $path, array &$state ) {
		$target = realpath( $path );
		if ( false === $target ) {
			return false;
		}
		$target = self::normalize( $target );
		if ( $target === $this->real_root || 0 === strpos( $target . '/', $this->real_root . '/' ) || in_array( $target, $state['followed'], true ) ) {
			return false;
		}
		$state['followed'][] = $target;
		return true;
	}

	/**
	 * A path with "/" separators and no trailing one.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function normalize( $path ) {
		return rtrim( str_replace( '\\', '/', $path ), '/' );
	}
}
