<?php
/**
 * A restore's saved state.
 *
 * @package WPressRestore
 */

namespace WPressRestore;

/**
 * The state of the restore in progress, saved between requests.
 *
 * It lives in wp-content/wpress-restore/job-<random>/: the folder name cannot be guessed, and the
 * folder refuses web access where the server reads .htaccess or web.config. It holds the state,
 * a log, and while the restore runs the unpacked database dump.
 */
final class Job {

	/**
	 * Job folder.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Saved state.
	 *
	 * @var array
	 */
	private $state = array();

	/**
	 * Handle holding the step lock.
	 *
	 * @var resource|null
	 */
	private $lock;

	/**
	 * @param string $dir Job folder.
	 */
	private function __construct( $dir ) {
		$this->dir = $dir;
	}

	/**
	 * The job in the working folder, if any.
	 *
	 * @return Job|null
	 */
	public static function current() {
		$work = Site::work_dir();
		foreach ( is_dir( $work ) ? (array) scandir( $work ) : array() as $entry ) {
			if ( preg_match( '/^job-[a-f0-9]{32}$/', $entry ) ) {
				$job   = new self( $work . '/' . $entry );
				$state = json_decode( (string) @file_get_contents( $job->path( 'job.json' ) ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a missing file is no job.
				if ( is_array( $state ) ) {
					$job->state = $state;
					return $job;
				}
			}
		}
		return null;
	}

	/**
	 * Creates a job folder and saves its first state.
	 *
	 * @param array $state Initial state.
	 * @return Job
	 * @throws \RuntimeException When wp-content is not writable.
	 */
	public static function create( array $state ) {
		$work = Site::work_dir();
		Site::protect( $work, true );
		$job = new self( $work . '/job-' . bin2hex( random_bytes( 16 ) ) );
		if ( ! wp_mkdir_p( $job->dir ) ) {
			throw new \RuntimeException( sprintf( 'Cannot create %s: wp-content must be writable by the web server.', $work ) );
		}
		Site::protect( $job->dir, true );
		$job->state = $state;
		$job->save();
		return $job;
	}

	/** Reads the state again: another request may have saved it since this one loaded it. */
	public function reload() {
		$state = json_decode( (string) @file_get_contents( $this->path( 'job.json' ) ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a deleted job keeps its last state.
		if ( is_array( $state ) ) {
			$this->state = $state;
		}
	}

	/**
	 * Path of a file in the job folder.
	 *
	 * @param string $file File name.
	 * @return string
	 */
	public function path( $file ) {
		return $this->dir . '/' . $file;
	}

	/**
	 * @param string $key     Key.
	 * @param mixed  $default Value when absent.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		return array_key_exists( $key, $this->state ) ? $this->state[ $key ] : $default;
	}

	/**
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 */
	public function set( $key, $value ) {
		$this->state[ $key ] = $value;
	}

	/** @return array The whole state. */
	public function state() {
		return $this->state;
	}

	/**
	 * Writes the state, atomically.
	 *
	 * @throws \RuntimeException When the disk refuses it.
	 */
	public function save() {
		$this->state['updated'] = time();
		$temp                   = $this->path( 'job.json.tmp' );
		if ( false === file_put_contents( $temp, wp_json_encode( $this->state ) ) || ! rename( $temp, $this->path( 'job.json' ) ) ) {
			throw new \RuntimeException( 'Cannot save the restore progress: the disk may be full.' );
		}
	}

	/**
	 * Appends a line to the log.
	 *
	 * @param string $message Line.
	 */
	public function log( $message ) {
		@file_put_contents( $this->path( 'restore.log' ), gmdate( 'Y-m-d H:i:s' ) . '  ' . $message . "\n", FILE_APPEND ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- logging must never stop a restore.
	}

	/** @return string The log. */
	public function log_text() {
		return (string) @file_get_contents( $this->path( 'restore.log' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- no log yet is an empty log.
	}

	/**
	 * Takes the step lock, so two tabs, or a step and a cancel, never work on the job at once.
	 *
	 * @param bool $wait Whether to wait for a running step to finish (a step lasts seconds).
	 * @return bool False when another request holds it and $wait is false.
	 */
	public function lock( $wait = false ) {
		$this->lock = fopen( $this->path( 'lock' ), 'c' );
		if ( ! $this->lock ) {
			return true;
		}
		$busy = false;
		// Only a held lock means busy: where the filesystem cannot lock at all, the restore still runs.
		return flock( $this->lock, $wait ? LOCK_EX : LOCK_EX | LOCK_NB, $busy ) || ! $busy;
	}

	/** Releases the step lock. */
	public function unlock() {
		if ( $this->lock ) {
			flock( $this->lock, LOCK_UN );
			fclose( $this->lock );
			$this->lock = null;
		}
	}

	/** Deletes the job folder. */
	public function delete() {
		$this->unlock();
		if ( ! is_dir( $this->dir ) ) {
			return;
		}
		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $files as $file ) {
			$file->isDir() ? @rmdir( $file->getPathname() ) : @unlink( $file->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		@rmdir( $this->dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
}
