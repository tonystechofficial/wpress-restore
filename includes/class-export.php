<?php
/**
 * The export, one time-boxed step per request.
 *
 * @package WPressRestore
 */

namespace WPressRestore;

/**
 * Makes a .wpress backup of this site in steps of a few seconds, as All-in-One WP Migration 7
 * writes them, so that either plugin restores it.
 *
 * Phases: listing (the files), dumping (the database, into the job folder), packing (package.json,
 * the files, then the dump, into an archive in the job folder), sealing (the archive's checksum
 * and end block, then the move into the backups folder), done. Nothing on the site changes; every
 * step saves where it stopped, and a failed step can be retried.
 */
final class Export {

	/** Statuses in which the browser keeps calling step(), and the method each one runs. */
	const PHASES = array(
		'listing' => 'run_listing',
		'dumping' => 'run_dumping',
		'packing' => 'run_packing',
		'sealing' => 'run_sealing',
	);

	/** Rows asked of a table's first query; later queries are sized by Dump_Rows::next_batch(). */
	const FIRST_BATCH = 100;

	/**
	 * The job.
	 *
	 * @var Job
	 */
	private $job;

	/**
	 * When this step started.
	 *
	 * @var float
	 */
	private $started = 0.0;

	/**
	 * Seconds this step may work.
	 *
	 * @var float
	 */
	private $budget = 10.0;

	/**
	 * @param Job $job The job.
	 */
	public function __construct( Job $job ) {
		$this->job = $job;
	}

	/**
	 * Starts a backup of this site.
	 *
	 * @param array $options no_media, no_spam_comments, no_post_revisions, no_cache (booleans), compression
	 *                       (gzip, bzip2 or ""), password ("" for none).
	 * @return Job
	 * @throws \RuntimeException When another job is under way, or the server cannot do what an option asks.
	 */
	public static function start( array $options ) {
		Runner::clear_previous();
		$compression = isset( $options['compression'] ) && in_array( $options['compression'], array( 'gzip', 'bzip2' ), true ) ? $options['compression'] : null;
		$password    = isset( $options['password'] ) ? (string) $options['password'] : '';
		if ( 'gzip' === $compression && ! function_exists( 'gzcompress' ) ) {
			throw new \RuntimeException( 'This server cannot compress with gzip: ask your host to enable the PHP zlib extension.' );
		}
		if ( 'bzip2' === $compression && ! function_exists( 'bzcompress' ) ) {
			throw new \RuntimeException( 'This server cannot compress with bzip2: ask your host to enable the PHP bz2 extension.' );
		}
		if ( '' !== $password && ! function_exists( 'openssl_encrypt' ) ) {
			throw new \RuntimeException( 'This server cannot encrypt: ask your host to enable the PHP OpenSSL extension.' );
		}
		$settings = array( 'compression' => $compression );
		foreach ( array( 'no_media', 'no_spam_comments', 'no_post_revisions', 'no_cache' ) as $flag ) {
			$settings[ $flag ] = ! empty( $options[ $flag ] );
		}
		$package = wp_json_encode( Package_Builder::build( self::site(), $settings + array( 'password' => $password ) ) );
		if ( false === $package ) {
			throw new \RuntimeException( 'This site\'s settings could not be written as JSON.' );
		}
		$name = Backup_Name::for_site( home_url(), current_time( 'Ymd-His' ), Backup_Name::random() );
		$job  = Job::create(
			array(
				'version' => 1,
				'type'    => 'export',
				'backup'  => $name,
				'options' => $settings,
				'package' => $package,
				// Only the derived key is kept between requests, never the password itself.
				'key'     => ( new Codec( $password, null ) )->key(),
				'status'  => 'listing',
				'created' => time(),
			)
		);
		$job->log( sprintf( 'Backing up %1$s into %2$s with WPress Restore %3$s on PHP %4$s.', home_url(), $name, WPRESS_RESTORE_VERSION, PHP_VERSION ) );
		return $job;
	}

	/**
	 * Runs the current phase for a few seconds.
	 *
	 * @return bool False when another request is already running a step.
	 */
	public function step() {
		if ( ! $this->job->lock() ) {
			return false;
		}
		$this->job->reload();
		$status = $this->job->get( 'status' );
		try {
			if ( isset( self::PHASES[ $status ] ) ) {
				$this->started = microtime( true );
				$this->budget  = Runner::budget();
				$this->{ self::PHASES[ $status ] }();
			}
		} catch ( \Throwable $e ) {
			$this->job->set( 'status', 'failed' );
			$this->job->set( 'failed_during', $status );
			$this->job->set( 'error', $e->getMessage() );
			$this->job->log( 'Stopped during ' . $status . ': ' . $e->getMessage() );
			$this->job->save();
		}
		$this->job->unlock();
		return true;
	}

	/** Resumes a failed backup where it stopped. */
	public function retry() {
		$this->job->lock( true );
		try {
			$this->job->reload();
			if ( 'failed' === $this->job->get( 'status' ) && $this->job->get( 'failed_during' ) ) {
				$this->job->set( 'status', $this->job->get( 'failed_during' ) );
				$this->job->set( 'error', null );
				$this->job->log( 'Retrying.' );
				$this->job->save();
			}
		} finally {
			$this->job->unlock();
		}
	}

	/** Abandons the backup: the job folder goes, with the unfinished archive. Waits for a running step to end first. */
	public function cancel() {
		$this->job->lock( true );
		try {
			$this->job->delete();
		} finally {
			$this->job->unlock();
		}
	}

	/** Clears a finished backup from the page; the backup stays in the backups folder. */
	public function dismiss() {
		$this->job->lock( true );
		try {
			$this->job->reload();
			if ( 'done' !== $this->job->get( 'status' ) ) {
				throw new \RuntimeException( 'This backup has not finished: cancel it instead.' );
			}
			$this->job->delete();
		} finally {
			$this->job->unlock();
		}
	}

	/**
	 * What the browser shows.
	 *
	 * @return array
	 */
	public function view() {
		$state = $this->job->state();
		return array(
			'type'          => 'export',
			'status'        => $state['status'],
			'backup'        => $state['backup'],
			'error'         => isset( $state['error'] ) ? $state['error'] : null,
			'failed_during' => isset( $state['failed_during'] ) ? $state['failed_during'] : null,
			'listed'        => isset( $state['listing']['walk'] ) ? array( $state['listing']['walk']['files'], $state['listing']['walk']['bytes'] ) : null,
			'result'        => 'done' === $state['status'] && isset( $state['result'] ) ? $state['result'] : null,
			'progress'      => self::progress( $state ),
			'created'       => $state['created'],
		);
	}

	/** @return string The log. */
	public function log_text() {
		return $this->job->log_text();
	}

	/**
	 * Done and total units of the current phase: bytes, or tables while the database is saved.
	 *
	 * @param array $state Job state.
	 * @return array|null
	 */
	private static function progress( array $state ) {
		switch ( $state['status'] ) {
			case 'dumping':
				return isset( $state['dump'] ) ? array( $state['dump']['index'], count( $state['dump']['tables'] ), 'tables' ) : null;
			case 'packing':
				$pack = isset( $state['pack'] ) ? $state['pack'] : array(
					'done'  => 0,
					'entry' => null,
				);
				$read = isset( $pack['entry']['read'] ) ? $pack['entry']['read'] : 0;
				return array( $pack['done'] + $read, $state['listing']['walk']['bytes'] + $state['dump']['size'] + strlen( $state['package'] ) );
			case 'sealing':
				return array( isset( $state['seal'] ) ? $state['seal']['offset'] : 0, $state['pack']['size'] );
		}
		return null;
	}

	/** Lists the files of the backup into files.list, a folder at a time. */
	private function run_listing() {
		$state = $this->job->get( 'listing' );
		if ( null === $state ) {
			$state = array(
				'folders' => Backup_Folders::for_site( self::paths(), $this->job->get( 'options' ) ),
				'folder'  => 0,
				'walk'    => File_Walker::start(),
				'size'    => 0,
			);
		}
		$list = $this->open_work_file( 'files.list', $state['size'] );
		$stop = function () {
			return $this->out_of_time();
		};
		while ( $state['folder'] < count( $state['folders'] ) && ! $this->out_of_time() ) {
			$folder = $state['folders'][ $state['folder'] ];
			$walker = new File_Walker( $folder['path'], $folder['prefix'], $folder['excluded'] );
			if ( ! $walker->walk( $state['walk'], $list, $stop ) ) {
				break;
			}
			++$state['folder'];
			$state['walk']['pending'] = array( '' );
		}
		$state['size'] = ftell( $list );
		fclose( $list );
		$this->job->set( 'listing', $state );
		if ( $state['folder'] >= count( $state['folders'] ) ) {
			$this->job->set( 'status', 'dumping' );
			$this->job->log( sprintf( 'Listed %1$d files (%2$s).', $state['walk']['files'], size_format( $state['walk']['bytes'], 1 ) ) );
		}
		$this->job->save();
	}

	/** Writes the database into database.sql, a batch of rows at a time. */
	private function run_dumping() {
		$db     = new Database();
		$prefix = $db->prefix();
		$fold   = $db->folds_table_names();
		$state  = $this->job->get( 'dump' );
		if ( null === $state ) {
			$found = $db->export_tables( $prefix );
			$state = array(
				'tables' => $found['tables'],
				'views'  => $found['views'],
				'index'  => 0,
				'begun'  => false,
				'after'  => null,
				'offset' => 0,
				'batch'  => self::FIRST_BATCH,
				'rows'   => 0,
				'size'   => 0,
			);
		}
		$names = array();
		foreach ( $state['tables'] as $table ) {
			$names[ $table ] = Dump_Format::table_name( $table, $prefix, $fold );
		}
		$dump = $this->open_work_file( 'database.sql', $state['size'] );
		if ( 0 === $state['size'] ) {
			Runner::write( $dump, Dump_Format::header( 'WPress Restore ' . WPRESS_RESTORE_VERSION ) );
		}
		while ( $state['index'] < count( $state['tables'] ) && ! $this->out_of_time() ) {
			$table = $state['tables'][ $state['index'] ];
			$view  = in_array( $table, $state['views'], true );
			if ( ! $state['begun'] ) {
				$create = $db->create_statement( $table, $view );
				$create = $view ? Dump_Format::create_view( $create, $names ) : Dump_Format::create_table( $create, $table, $names[ $table ] );
				Runner::write( $dump, "\nDROP " . ( $view ? 'VIEW' : 'TABLE' ) . ' IF EXISTS `' . $names[ $table ] . "`;\n" . $create . ";\n\n" );
				$state['begun'] = true;
			}
			if ( $view || $this->dump_rows( $db, $dump, $table, $names[ $table ], Dump_Rows::core_table( $table, $prefix, $fold ), $state ) ) {
				++$state['index'];
				$state['begun']  = false;
				$state['after']  = null;
				$state['offset'] = 0;
				$state['batch']  = self::FIRST_BATCH;
			}
		}
		$state['size'] = ftell( $dump );
		fclose( $dump );
		$this->job->set( 'dump', $state );
		if ( $state['index'] >= count( $state['tables'] ) ) {
			$this->check_space();
			$this->job->set( 'status', 'packing' );
			$this->job->log( sprintf( 'Database saved: %1$d tables, %2$d rows (%3$s).', count( $state['tables'] ), $state['rows'], size_format( $state['size'], 1 ) ) );
		}
		$this->job->save();
	}

	/**
	 * Writes the next rows of a table, one query at a time, until the table ends or time is up.
	 *
	 * @param Database    $db    Database.
	 * @param resource    $dump  Dump file.
	 * @param string      $table Table.
	 * @param string      $name  Its name in the dump.
	 * @param string|null $core  What Dump_Rows::core_table() says of it.
	 * @param array       $state Dump state; records where the table stands.
	 * @return bool Whether the table is done.
	 */
	private function dump_rows( Database $db, $dump, $table, $name, $core, array &$state ) {
		$columns = $db->columns( $table );
		$types   = array_values( $columns );
		$key     = $db->primary_key( $table );
		// Paging after the last key read stays fast on large tables; other tables page by offset.
		$by_key = 1 === count( $key ) && isset( $columns[ $key[0] ] ) && ! preg_match( '/^(?:float|double|real|generated)\b/i', $columns[ $key[0] ] );
		$at     = $by_key ? array_search( $key[0], array_keys( $columns ), true ) : null;
		$filter = Dump_Rows::filter( $core, $db->prefix(), $this->job->get( 'options' ) );
		$change = Dump_Rows::changer( $core, array_keys( $columns ), $db->prefix() );
		do {
			$where = '' === $filter ? array() : array( $filter );
			if ( $by_key && null !== $state['after'] ) {
				$where[] = self::quote_name( $key[0] ) . ' > ' . Dump_Format::value( $state['after'], $columns[ $key[0] ] );
			}
			$sql  = 'SELECT * FROM ' . self::quote_name( $table ) . ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' );
			$sql .= $key ? ' ORDER BY ' . implode( ', ', array_map( array( __CLASS__, 'quote_name' ), $key ) ) : '';
			$sql .= ' LIMIT ' . ( $by_key ? '' : $state['offset'] . ', ' ) . $state['batch'];
			$rows = $db->fetch_rows( $sql );
			if ( ! $rows ) {
				return true;
			}
			$lines = '';
			foreach ( $rows as $row ) {
				$lines .= Dump_Format::insert( $name, $change ? $change( $row ) : $row, $types );
			}
			Runner::write( $dump, "START TRANSACTION;\n" . $lines . "COMMIT;\n" );
			$count            = count( $rows );
			$asked            = $state['batch'];
			$state['rows']   += $count;
			$state['offset'] += $count;
			$state['batch']   = Dump_Rows::next_batch( $asked, strlen( $lines ) );
			if ( $by_key ) {
				$state['after'] = $rows[ $count - 1 ][ $at ];
			}
			if ( $count < $asked ) {
				return true;
			}
		} while ( ! $this->out_of_time() );
		return false;
	}

	/**
	 * Stops before packing when the disk cannot hold the backup, rather than filling it up under
	 * the site. A compressed backup may fit in less, so it only gets a note in the log.
	 *
	 * @throws \RuntimeException When the backup cannot fit.
	 */
	private function check_space() {
		$needed = $this->job->get( 'listing' )['walk']['bytes'] + $this->job->get( 'dump' )['size'];
		if ( PHP_INT_SIZE < 8 && $needed >= 2147483647 - 1048576 ) {
			throw new \RuntimeException( 'This server runs 32-bit PHP, which cannot write files of 2 GB or more. Leave out the media library, or ask your host for 64-bit PHP.' );
		}
		$free = function_exists( 'disk_free_space' ) ? @disk_free_space( WP_CONTENT_DIR ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors -- hosts may forbid it.
		if ( false === $free || $free >= $needed ) {
			return;
		}
		$message = sprintf( 'The backup needs up to %1$s of disk space and %2$s is free.', size_format( $needed, 1 ), size_format( $free, 1 ) );
		if ( ! $this->job->get( 'options' )['compression'] ) {
			throw new \RuntimeException( $message . ' Free some space or leave out the media library, then try again.' );
		}
		$this->job->log( $message . ' Compression may make it fit.' );
	}

	/** Writes package.json, the listed files and the dump into the archive, a chunk at a time. */
	private function run_packing() {
		$state   = $this->job->get(
			'pack',
			array(
				'stage'   => 'package',
				'line'    => 0,
				'entry'   => null,
				'size'    => 0,
				'done'    => 0,
				'files'   => 0,
				'missing' => 0,
			)
		);
		$archive = $this->open_work_file( 'backup.wpress', $state['size'], 'c+b' );
		$writer  = new Archive_Writer( $archive, Codec::from_key( $this->job->get( 'key' ), $this->job->get( 'options' )['compression'] ) );
		$list    = fopen( $this->job->path( 'files.list' ), 'rb' );
		if ( ! $list || 0 !== fseek( $list, $state['line'] ) ) {
			throw new \RuntimeException( 'Cannot read the list of files in the working folder (wp-content/wpress-restore).' );
		}
		$stop = function () {
			return $this->out_of_time();
		};
		while ( ! $this->out_of_time() ) {
			if ( null === $state['entry'] ) {
				$state['entry'] = $this->next_entry( $state, $list );
				if ( null === $state['entry'] ) {
					break;
				}
			}
			$probe = @fopen( $state['entry']['source'], 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a file gone since the listing is left out.
			if ( ! $probe ) {
				// Gone or unreadable since the listing: left out, as ai1wm does, with whatever was written of it.
				if ( isset( $state['entry']['at'] ) ) {
					ftruncate( $archive, $state['entry']['at'] );
				}
				if ( ++$state['missing'] <= 50 ) {
					$this->job->log( 'Left out, no longer readable: ' . $state['entry']['name'] );
				}
				$state['entry'] = null;
				continue;
			}
			fclose( $probe );
			if ( $writer->add( $state['entry'], $stop ) ) {
				$state['done'] += $state['entry']['read'];
				++$state['files'];
				$state['entry'] = null;
			}
		}
		fclose( $list );
		fseek( $archive, 0, SEEK_END );
		$state['size'] = ftell( $archive );
		fclose( $archive );
		$this->job->set( 'pack', $state );
		if ( 'done' === $state['stage'] && null === $state['entry'] ) {
			foreach ( array( 'files.list', 'database.sql', 'package.json' ) as $file ) {
				@unlink( $this->job->path( $file ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
			$this->job->set( 'status', 'sealing' );
			$this->job->log( sprintf( 'Packed %1$d files into %2$s; %3$d left out.', $state['files'], size_format( $state['size'], 1 ), $state['missing'] ) );
		}
		$this->job->save();
	}

	/**
	 * The next file to pack: package.json first, then the listed files, then the dump.
	 *
	 * @param array    $state Packing state; moves on.
	 * @param resource $list  files.list, at the next line.
	 * @return array|null source, name, mtime; null once all are packed.
	 */
	private function next_entry( array &$state, $list ) {
		if ( 'package' === $state['stage'] ) {
			$state['stage'] = 'files';
			if ( false === file_put_contents( $this->job->path( 'package.json' ), $this->job->get( 'package' ) ) ) {
				throw new \RuntimeException( 'Cannot write in the working folder (wp-content/wpress-restore).' );
			}
			return array(
				'source' => $this->job->path( 'package.json' ),
				'name'   => 'package.json',
				'mtime'  => time(),
			);
		}
		while ( 'files' === $state['stage'] ) {
			$line = fgets( $list );
			if ( false === $line ) {
				$state['stage'] = 'database';
				break;
			}
			$state['line'] = ftell( $list );
			$file          = File_Walker::read_line( $line );
			if ( null !== $file ) {
				return array(
					'source' => $file['path'],
					'name'   => $file['name'],
					'mtime'  => $file['mtime'],
				);
			}
		}
		if ( 'database' === $state['stage'] ) {
			$state['stage'] = 'done';
			return array(
				'source' => $this->job->path( 'database.sql' ),
				'name'   => 'database.sql',
				'mtime'  => time(),
			);
		}
		return null;
	}

	/** Sums the archive, ends it with its size and checksum, and moves it into the backups folder. */
	private function run_sealing() {
		$state = $this->job->get(
			'seal',
			array(
				'offset' => 0,
				'crc'    => null,
				'sealed' => false,
			)
		);
		$path  = $this->job->path( 'backup.wpress' );
		if ( ! $state['sealed'] ) {
			$archive = $this->open_work_file( 'backup.wpress', $this->job->get( 'pack' )['size'], 'c+b' );
			$writer  = new Archive_Writer( $archive, new Codec( null, null ) );
			$sum     = $writer->checksum(
				$state['offset'],
				$state['crc'],
				function () {
					return $this->out_of_time();
				}
			);
			$state['offset'] = $sum['offset'];
			$state['crc']    = $sum['crc'];
			if ( $sum['done'] ) {
				$writer->seal( $sum['crc'] );
				$state['sealed'] = true;
			}
			fclose( $archive );
			$this->job->set( 'seal', $state );
			$this->job->save();
			if ( ! $state['sealed'] ) {
				return;
			}
		}

		$folder = Site::backups_dir();
		$target = $folder . '/' . $this->job->get( 'backup' );
		Site::protect( $folder, false );
		if ( is_file( $path ) ) {
			// The name is new: a file already there is an earlier attempt's unfinished copy.
			@unlink( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( ! @rename( $path, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- failure is handled.
				if ( ! @copy( $path, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- failure is reported.
					@unlink( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					throw new \RuntimeException( sprintf( 'The backup could not be moved into %s: check that this folder is writable and has room.', $folder ) );
				}
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
		clearstatcache( true, $target );
		if ( ! is_file( $target ) ) {
			throw new \RuntimeException( 'The finished backup is no longer in the working folder nor in the backups folder.' );
		}
		$pack    = $this->job->get( 'pack' );
		$dump    = $this->job->get( 'dump' );
		$options = $this->job->get( 'options' );
		$this->job->set(
			'result',
			array(
				'size'        => (int) filesize( $target ),
				'files'       => $pack['files'] - 2,
				'missing'     => $pack['missing'],
				'tables'      => count( $dump['tables'] ),
				'rows'        => $dump['rows'],
				'encrypted'   => null !== $this->job->get( 'key' ),
				'compression' => $options['compression'],
				'finished'    => time(),
			)
		);
		$this->job->set( 'key', null );
		$this->job->set( 'status', 'done' );
		$this->job->log( sprintf( 'Backup finished: %1$s (%2$s).', $this->job->get( 'backup' ), size_format( filesize( $target ), 1 ) ) );
		$this->job->save();
	}

	/**
	 * Opens a file of the job folder, cut back to the size saved with the job's state: anything
	 * an interrupted request wrote after that goes.
	 *
	 * @param string $file File name.
	 * @param int    $size Saved size.
	 * @param string $mode "cb", or "c+b" to read it too.
	 * @return resource At its end.
	 * @throws \RuntimeException When it cannot be written.
	 */
	private function open_work_file( $file, $size, $mode = 'cb' ) {
		$handle = @fopen( $this->job->path( $file ), $mode ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- failure is reported.
		if ( ! $handle || ! ftruncate( $handle, $size ) || 0 !== fseek( $handle, $size ) ) {
			throw new \RuntimeException( 'Cannot write in the working folder (wp-content/wpress-restore).' );
		}
		return $handle;
	}

	/**
	 * This site, in the shape Package_Builder::build() takes.
	 *
	 * @return array
	 */
	private static function site() {
		global $wpdb;
		$uploads = wp_get_upload_dir();
		$stored  = wp_load_alloptions();
		$read    = static function ( $file ) {
			return is_file( $file ) ? (string) @file_get_contents( $file ) : ''; // phpcs:ignore WordPress.PHP.NoSilencedErrors -- an unreadable file is left empty.
		};
		return array(
			'site_url'          => site_url(),
			'home_url'          => home_url(),
			'internal_site_url' => isset( $stored['siteurl'] ) ? $stored['siteurl'] : site_url(),
			'internal_home_url' => isset( $stored['home'] ) ? $stored['home'] : home_url(),
			'version'           => WPRESS_RESTORE_VERSION,
			'wordpress'         => get_bloginfo( 'version' ),
			'absolute'          => ABSPATH,
			'content'           => WP_CONTENT_DIR,
			'plugins_dir'       => untrailingslashit( WP_PLUGIN_DIR ),
			'themes_dirs'       => self::theme_roots(),
			'uploads_dir'       => untrailingslashit( $uploads['basedir'] ),
			'uploads_url'       => trailingslashit( $uploads['baseurl'] ),
			'database'          => $wpdb->dbh instanceof \mysqli ? mysqli_get_server_info( $wpdb->dbh ) : $wpdb->db_version(),
			'charset'           => defined( 'DB_CHARSET' ) ? DB_CHARSET : 'undefined',
			'collate'           => defined( 'DB_COLLATE' ) ? DB_COLLATE : 'undefined',
			'prefix'            => $wpdb->prefix,
			'php'               => PHP_VERSION,
			'os'                => PHP_OS,
			'int_size'          => PHP_INT_SIZE,
			'active_plugins'    => array_values( (array) get_option( 'active_plugins', array() ) ),
			'own_plugin'        => Site::basename(),
			'template'          => (string) get_option( 'template' ),
			'stylesheet'        => (string) get_option( 'stylesheet' ),
			'upload_path'       => get_option( 'upload_path' ),
			'upload_url_path'   => get_option( 'upload_url_path' ),
			'htaccess'          => $read( ABSPATH . '.htaccess' ),
			'webconfig'         => $read( ABSPATH . 'web.config' ),
		);
	}

	/**
	 * Where this site's folders are, in the shape Backup_Folders::for_site() takes.
	 *
	 * @return array
	 */
	private static function paths() {
		$names = @scandir( WP_PLUGIN_DIR ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a missing folder has no plugins.
		return array(
			'content'     => untrailingslashit( WP_CONTENT_DIR ),
			'uploads'     => untrailingslashit( wp_get_upload_dir()['basedir'] ),
			'plugins'     => untrailingslashit( WP_PLUGIN_DIR ),
			'themes'      => self::theme_roots(),
			'backups'     => Site::backups_dir(),
			'work'        => Site::work_dir(),
			'guard'       => Guard::path(),
			'own_plugin'  => dirname( Site::basename() ),
			'plugin_dirs' => is_array( $names ) ? array_values( array_diff( $names, array( '.', '..' ) ) ) : array(),
		);
	}

	/** @return string[] The folders holding themes, as All-in-One WP Migration lists them. */
	private static function theme_roots() {
		$roots  = array();
		$themes = search_theme_directories();
		foreach ( is_array( $themes ) ? $themes : array() as $theme ) {
			if ( isset( $theme['theme_root'] ) && ! in_array( untrailingslashit( $theme['theme_root'] ), $roots, true ) ) {
				$roots[] = untrailingslashit( $theme['theme_root'] );
			}
		}
		return $roots;
	}

	/**
	 * A table or column name, quoted.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	private static function quote_name( $name ) {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	/** @return bool Whether this step has used its time. */
	private function out_of_time() {
		return microtime( true ) - $this->started > $this->budget;
	}
}
