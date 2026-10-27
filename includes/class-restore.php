<?php
/**
 * The restore, one time-boxed step per request.
 *
 * @package WPressRestore
 */

namespace WPressRestore;

/**
 * Runs a restore in steps of a few seconds, so any backup size fits any PHP time limit.
 *
 * Phases: scanning (headers), verifying (archive checksum), ready (waits for the owner),
 * extracting_db, importing_db (into temporary tables), extracting_files, finalizing (one atomic
 * RENAME TABLE switches the site), done. Nothing on the live site changes before files are
 * written, and the database changes at the very end, so a backup that cannot be imported
 * leaves the site as it was. Every step saves where it stopped; a failed step can be retried.
 */
final class Restore {

	/** Statuses in which the browser keeps calling step(), and the method each one runs. */
	const PHASES = array(
		'scanning'         => 'run_scanning',
		'verifying'        => 'run_verifying',
		'extracting_db'    => 'run_extract_db',
		'importing_db'     => 'run_import_db',
		'extracting_files' => 'run_extract_files',
		'finalizing'       => 'run_finalize',
	);

	/** Root entries the restore reads itself. */
	const SPECIAL = array( 'package.json', 'database.sql', 'multisite.json' );

	/** CREATE TABLE errors with a known fix: MySQL error number => Sql_Rewriter method. */
	const TABLE_FIXES = array(
		1071 => 'with_dynamic_rows',
		1709 => 'with_dynamic_rows',
		1214 => 'without_fulltext',
		1101 => 'without_text_defaults',
		1064 => 'with_portable_types',
		4161 => 'with_portable_types',
	);

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
	 * Starts restoring a backup from the backups folder.
	 *
	 * @param string $backup File name, already checked against the folder listing.
	 * @return Job
	 * @throws \RuntimeException When another restore is under way or the file cannot be read.
	 */
	public static function start( $backup ) {
		$current = Job::current();
		if ( $current ) {
			if ( ! in_array( $current->get( 'status' ), array( 'done', 'ready', 'failed' ), true ) ) {
				throw new \RuntimeException( 'A restore is already under way: let it finish or cancel it first.' );
			}
			( new self( $current ) )->cancel();
		}
		$path = Site::backups_dir() . '/' . $backup;
		clearstatcache( true, $path );
		$size = filesize( $path );
		if ( false === $size || ( PHP_INT_SIZE < 8 && ( $size < 0 || $size >= 2147483647 ) ) ) {
			throw new \RuntimeException( 'This server runs 32-bit PHP, which cannot read files of 2 GB or more. Ask your host for 64-bit PHP.' );
		}
		$job = Job::create(
			array(
				'version'      => 1,
				'backup'       => $backup,
				'backup_size'  => $size,
				'backup_mtime' => filemtime( $path ),
				'status'       => 'scanning',
				'created'      => time(),
			)
		);
		$job->log( sprintf( 'Restoring %1$s (%2$s) with WPress Restore %3$s on PHP %4$s.', $backup, size_format( $size, 1 ), WPRESS_RESTORE_VERSION, PHP_VERSION ) );
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
		$status = $this->job->get( 'status' );
		try {
			if ( isset( self::PHASES[ $status ] ) ) {
				$this->started = microtime( true );
				$this->budget  = self::budget();
				$this->check_backup();
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

	/**
	 * The owner confirms the restore; checks the password of an encrypted backup.
	 *
	 * @param string $password Password, "" when the backup is not encrypted.
	 * @throws \RuntimeException "password" when it does not open the backup.
	 */
	public function confirm( $password ) {
		if ( 'ready' !== $this->job->get( 'status' ) ) {
			throw new \RuntimeException( 'This restore is not waiting for confirmation.' );
		}
		$package = $this->package();
		if ( $package->is_encrypted() ) {
			if ( '' === $password || ! Codec::password_matches( $package->signature(), $password ) ) {
				throw new \RuntimeException( 'password' );
			}
			$this->job->set( 'password', $password );
		}
		$db  = new Database();
		$tag = '';
		do {
			$tag = bin2hex( random_bytes( 3 ) );
		} while ( $db->tables( 'wpr' . $tag ) || $db->tables( 'wpo' . $tag ) );
		$this->job->set( 'temp_prefix', 'wpr' . $tag . '_' );

		$guarded = Guard::install();
		$this->job->set( 'status', isset( $this->job->get( 'scan' )['entries']['database.sql'] ) ? 'extracting_db' : 'extracting_files' );
		$this->job->log( 'Restore confirmed.' . ( $guarded ? '' : ' The guard could not be written in mu-plugins: other plugins stay loaded during the restore.' ) );
		$this->job->save();
	}

	/** Resumes a failed restore where it stopped. */
	public function retry() {
		if ( 'failed' === $this->job->get( 'status' ) && $this->job->get( 'failed_during' ) ) {
			$this->job->set( 'status', $this->job->get( 'failed_during' ) );
			$this->job->set( 'error', null );
			$this->job->log( 'Retrying.' );
			$this->job->save();
		}
	}

	/**
	 * Abandons the restore: the temporary tables and the job go away. Files already written stay.
	 *
	 * @throws \RuntimeException Once the site has started switching to the restored database.
	 */
	public function cancel() {
		if ( $this->job->get( 'swapping' ) && 'done' !== $this->job->get( 'status' ) ) {
			throw new \RuntimeException( 'The site is switching to the restored database: this can no longer be cancelled. Retry instead.' );
		}
		if ( 'done' !== $this->job->get( 'status' ) && $this->job->get( 'temp_prefix' ) ) {
			$db = new Database();
			foreach ( $db->tables( $this->job->get( 'temp_prefix' ) ) as $table ) {
				$db->query( 'DROP TABLE IF EXISTS `' . $table . '`' );
			}
		}
		Guard::remove();
		$this->job->delete();
	}

	/**
	 * What the browser shows.
	 *
	 * @return array
	 */
	public function view() {
		$state = $this->job->state();
		return array(
			'status'        => $state['status'],
			'backup'        => $state['backup'],
			'error'         => isset( $state['error'] ) ? $state['error'] : null,
			'failed_during' => isset( $state['failed_during'] ) ? $state['failed_during'] : null,
			'switching'     => ! empty( $state['swapping'] ),
			'summary'       => isset( $state['summary'] ) ? $state['summary'] : null,
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
	 * Done and total units of the current phase.
	 *
	 * @param array $state Job state.
	 * @return int[]|null
	 */
	private static function progress( array $state ) {
		$get = static function ( array $path ) use ( $state ) {
			$value = $state;
			foreach ( $path as $key ) {
				if ( ! is_array( $value ) || ! isset( $value[ $key ] ) ) {
					return 0;
				}
				$value = $value[ $key ];
			}
			return (int) $value;
		};
		switch ( $state['status'] ) {
			case 'scanning':
				return array( $get( array( 'scan', 'offset' ) ), $get( array( 'backup_size' ) ) );
			case 'verifying':
				return array( $get( array( 'verify', 'offset' ) ), $get( array( 'scan', 'offset' ) ) );
			case 'extracting_db':
				return array( $get( array( 'extract_db', 'stored' ) ), $get( array( 'scan', 'entries', 'database.sql', 'size' ) ) );
			case 'importing_db':
				return array( $get( array( 'db', 'offset' ) ), $get( array( 'db', 'size' ) ) );
			case 'extracting_files':
				return array( $get( array( 'files', 'processed' ) ), $get( array( 'scan', 'bytes' ) ) );
		}
		return null;
	}

	/** Reads every header: structure, totals, and where package.json and database.sql are. */
	private function run_scanning() {
		$scan    = $this->job->get(
			'scan',
			array(
				'offset'  => 0,
				'files'   => 0,
				'bytes'   => 0,
				'entries' => array(),
				'eof'     => null,
			)
		);
		$size    = (int) $this->job->get( 'backup_size' );
		$archive = $this->open_archive();
		while ( null === $scan['eof'] && ! $this->out_of_time() ) {
			if ( $scan['offset'] + Archive_Format::HEADER_SIZE > $size ) {
				throw new Archive_Exception( 'The backup file is incomplete: it stops before its end marker. Upload it again (in binary mode if you use FTP).' );
			}
			try {
				$header = $this->read_header( $archive, $scan['offset'] );
			} catch ( Archive_Exception $e ) {
				if ( 0 === $scan['offset'] ) {
					throw new Archive_Exception( 'This file is not a .wpress backup, or it is damaged from its very start.' );
				}
				throw $e;
			}
			if ( 'eof' === $header['type'] ) {
				if ( $scan['offset'] + Archive_Format::HEADER_SIZE !== $size || ( null !== $header['archive_size'] && $header['archive_size'] !== $scan['offset'] ) ) {
					throw new Archive_Exception( 'The backup file does not end where its end marker says: it is damaged.' );
				}
				$scan['eof'] = array( 'crc32' => $header['crc32'] );
				break;
			}
			if ( in_array( $header['name'], self::SPECIAL, true ) ) {
				$scan['entries'][ $header['name'] ] = array(
					'start' => $scan['offset'] + Archive_Format::HEADER_SIZE,
					'size'  => $header['size'],
				);
			}
			++$scan['files'];
			$scan['bytes']  += $header['size'];
			$scan['offset'] += Archive_Format::HEADER_SIZE + $header['size'];
		}
		fclose( $archive );
		$this->job->set( 'scan', $scan );
		if ( null !== $scan['eof'] ) {
			$this->describe( $scan );
		}
		$this->job->save();
	}

	/**
	 * Reads package.json and prepares what the owner sees before confirming.
	 *
	 * @param array $scan Finished scan.
	 * @throws Archive_Exception When the backup cannot be restored here.
	 */
	private function describe( array $scan ) {
		if ( empty( $scan['entries']['package.json'] ) ) {
			throw new Archive_Exception( 'This file is not an All-in-One WP Migration backup: it has no package.json.' );
		}
		if ( isset( $scan['entries']['multisite.json'] ) ) {
			throw new Archive_Exception( 'This backup comes from a WordPress network (multisite): this plugin restores single sites only.' );
		}
		$entry = $scan['entries']['package.json'];
		if ( $entry['size'] > 4 * MB_IN_BYTES ) {
			throw new Archive_Exception( 'This file is not an All-in-One WP Migration backup: its package.json is not one.' );
		}
		$archive = $this->open_archive();
		fseek( $archive, $entry['start'] );
		$json = $entry['size'] > 0 ? (string) fread( $archive, $entry['size'] ) : '';
		fclose( $archive );

		$package     = Package::from_json( $json );
		$compression = $package->compression();
		new Codec( null, $compression );
		if ( 'bzip2' === $compression && ! function_exists( 'bzdecompress' ) ) {
			throw new Archive_Exception( 'This backup is compressed with bzip2, which this server cannot read: ask your host to enable the PHP bz2 extension.' );
		}
		if ( 'gzip' === $compression && ! function_exists( 'gzuncompress' ) ) {
			throw new Archive_Exception( 'This backup is compressed with gzip, which this server cannot read: ask your host to enable the PHP zlib extension.' );
		}
		if ( $package->is_encrypted() && ! function_exists( 'openssl_decrypt' ) ) {
			throw new Archive_Exception( 'This backup is encrypted, which this server cannot read: ask your host to enable the PHP OpenSSL extension.' );
		}
		$this->job->set( 'package', $json );

		$database = isset( $scan['entries']['database.sql'] ) ? $scan['entries']['database.sql'] : null;
		$warnings = $package->warnings(
			array(
				'wordpress' => get_bloginfo( 'version' ),
				'php'       => PHP_VERSION,
			)
		);
		$free     = function_exists( 'disk_free_space' ) ? @disk_free_space( WP_CONTENT_DIR ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors -- hosts may forbid it.
		$needed   = $scan['bytes'] + ( $database ? $database['size'] : 0 );
		if ( false !== $free && $free < $needed ) {
			$warnings[] = array(
				'about'  => 'disk',
				'backup' => $needed,
				'here'   => (int) $free,
			);
		}
		if ( ! $database ) {
			$warnings[] = array( 'about' => 'no_database' );
		}
		$old = $package->old_site();
		$this->job->set(
			'summary',
			array(
				'source'         => $old['home_url'],
				'same_site'      => untrailingslashit( $old['home_url'] ) === untrailingslashit( home_url() ),
				'wordpress'      => $package->wordpress_version(),
				'php'            => $package->php_version(),
				'made_with'      => $package->plugin_version(),
				'files'          => $scan['files'] - count( $scan['entries'] ),
				'bytes'          => $scan['bytes'],
				'database'       => null !== $database,
				'database_bytes' => $database ? $database['size'] : 0,
				'encrypted'      => $package->is_encrypted(),
				'compression'    => $compression,
				'plugins'        => count( $package->plugins() ),
				'theme'          => $package->stylesheet(),
				'warnings'       => $warnings,
			)
		);
		$this->job->set( 'status', $scan['eof']['crc32'] && PHP_INT_SIZE >= 8 ? 'verifying' : 'ready' );
		$this->job->log( sprintf( 'Backup of %1$s made by All-in-One WP Migration %2$s: %3$d files, %4$s.', $old['home_url'], $package->plugin_version(), $scan['files'], size_format( $scan['bytes'], 1 ) ) );
	}

	/** Checks the archive's CRC32 before anything is changed: a damaged upload fails here. */
	private function run_verifying() {
		$end     = (int) $this->job->get( 'scan' )['offset'];
		$verify  = $this->job->get(
			'verify',
			array(
				'offset' => 0,
				'crc'    => null,
			)
		);
		$archive = $this->open_archive();
		fseek( $archive, $verify['offset'] );
		$context = hash_init( 'crc32b' );
		$read    = 0;
		while ( $verify['offset'] + $read < $end && ! $this->out_of_time() ) {
			$chunk = fread( $archive, (int) min( 4 * MB_IN_BYTES, $end - $verify['offset'] - $read ) );
			if ( false === $chunk || '' === $chunk ) {
				throw new Archive_Exception( 'The backup file could not be read to the end.' );
			}
			hash_update( $context, $chunk );
			$read += strlen( $chunk );
		}
		fclose( $archive );
		if ( $read > 0 ) {
			$verify['crc']     = Crc32::combine( $verify['crc'], hash_final( $context ), $read );
			$verify['offset'] += $read;
		}
		$this->job->set( 'verify', $verify );
		if ( $verify['offset'] >= $end ) {
			if ( $verify['crc'] !== $this->job->get( 'scan' )['eof']['crc32'] ) {
				throw new Archive_Exception( 'The backup file is damaged: its checksum does not match. Transfer it again (in binary mode if you use FTP).' );
			}
			$this->job->set( 'status', 'ready' );
			$this->job->log( 'Checksum verified.' );
		}
		$this->job->save();
	}

	/** Unpacks database.sql into the job folder, decrypted and decompressed. */
	private function run_extract_db() {
		$entry = $this->job->get( 'scan' )['entries']['database.sql'];
		$state = $this->job->get(
			'extract_db',
			array(
				'stored'  => 0,
				'written' => 0,
			)
		);
		$out   = fopen( $this->job->path( 'database.sql' ), 'cb' );
		if ( ! $out || ! ftruncate( $out, $state['written'] ) || 0 !== fseek( $out, $state['written'] ) ) {
			throw new \RuntimeException( 'Cannot write in the working folder (wp-content/wpress-restore).' );
		}
		$archive = $this->open_archive();
		$stream  = new Entry_Stream( $archive, $entry['start'], $entry['size'], $state['stored'], $this->codec()->for_entry( 'database.sql' ) );
		while ( ! $this->out_of_time() && null !== ( $piece = $stream->read() ) ) {
			self::write( $out, $piece );
			$state['written'] += strlen( $piece );
			$state['stored']   = $stream->stored_offset();
		}
		fclose( $out );
		fclose( $archive );
		$this->job->set( 'extract_db', $state );
		if ( $state['stored'] >= $entry['size'] ) {
			$this->job->set( 'status', 'importing_db' );
			$this->job->set(
				'db',
				array(
					'offset'     => 0,
					'size'       => $state['written'],
					'statements' => 0,
					'rows'       => 0,
					'tables'     => array(),
					'skipped'    => 0,
					'refused'    => 0,
					'deferred'   => 0,
					'errors'     => 0,
					'too_large'  => 0,
				)
			);
			$this->job->log( sprintf( 'Database dump unpacked: %s.', size_format( $state['written'], 1 ) ) );
		}
		$this->job->save();
	}

	/** Imports the dump into temporary tables; the live tables are not touched. */
	private function run_import_db() {
		$state   = $this->job->get( 'db' );
		$db      = new Database();
		$package = $this->package();
		$db->prepare_import();
		$rewriter = new Sql_Rewriter(
			array(
				'temp_prefix'     => $this->job->get( 'temp_prefix' ),
				'final_prefix'    => $db->prefix(),
				'included_tables' => $package->included_tables(),
				'replacer'        => new Serialized_Replacer( Replace_Pairs::build( $package->old_site(), Site::new_site() ) ),
				'collations'      => new Collation_Map( $db->collations() ),
				'engines'         => $db->engines(),
			)
		);
		$dump   = fopen( $this->job->path( 'database.sql' ), 'rb' );
		$reader = new Sql_Reader( $dump, $state['offset'], max( MB_IN_BYTES, $db->max_allowed_packet() - 1024 ) );
		$done   = false;
		$saved  = microtime( true );
		$db->query( 'START TRANSACTION' );
		while ( ! $this->out_of_time() ) {
			$statement = $reader->next();
			if ( null === $statement ) {
				$done = true;
				break;
			}
			++$state['statements'];
			$committed = false;
			if ( $statement['too_large'] ) {
				++$state['too_large'];
				$this->job->log( sprintf( 'Skipped a statement of %1$s at byte %2$d: it is larger than this database server accepts (max_allowed_packet).', size_format( $statement['end'] - $statement['start'], 1 ), $statement['start'] ) );
			} else {
				$rewritten = $rewriter->rewrite( $statement['sql'] );
				if ( 'run' === $rewritten['action'] ) {
					$committed = $this->run_statement( $db, $rewritten, $state );
				} elseif ( 'defer' === $rewritten['action'] ) {
					++$state['deferred'];
					file_put_contents( $this->job->path( 'views.sql' ), $rewritten['sql'] . ";\n", FILE_APPEND );
				} elseif ( 'refuse' === $rewritten['action'] ) {
					if ( ++$state['refused'] <= 20 ) {
						$this->job->log( sprintf( 'Refused a statement (%1$s): %2$s', $rewritten['reason'], substr( $statement['sql'], 0, 160 ) ) );
					}
				} else {
					++$state['skipped'];
				}
			}
			$state['offset'] = $statement['end'];
			// Table statements commit by themselves: save right away so a crash never replays rows.
			if ( $committed || microtime( true ) - $saved > 2 ) {
				$db->query( 'COMMIT' );
				$this->job->set( 'db', $state );
				$this->job->save();
				$db->query( 'START TRANSACTION' );
				$saved = microtime( true );
			}
		}
		$db->query( 'COMMIT' );
		fclose( $dump );
		$this->job->set( 'db', $state );
		if ( $done ) {
			$this->job->set( 'status', 'extracting_files' );
			$this->job->log( sprintf( 'Database imported: %1$d tables, %2$d rows, %3$d rows refused by the server, %4$d statements left out.', count( $state['tables'] ), $state['rows'], $state['errors'] + $state['too_large'], $state['refused'] ) );
		}
		$this->job->save();
	}

	/**
	 * Runs one statement, retrying CREATE TABLE with the fixes known for its error.
	 *
	 * @param Database $db        Database.
	 * @param array    $statement Rewritten statement.
	 * @param array    $state     Import state.
	 * @return bool Whether it changed a table definition (and so committed).
	 * @throws \RuntimeException When a table cannot be created, or too many rows fail.
	 */
	private function run_statement( Database $db, array $statement, array &$state ) {
		if ( strlen( $statement['temp_table'] ) > 64 ) {
			throw new \RuntimeException( sprintf( 'The table name %s is too long for MySQL during the restore.', $statement['table'] ) );
		}
		$sql   = $statement['sql'];
		$ok    = $db->query( $sql );
		$tried = array();
		while ( ! $ok && 'create_table' === $statement['kind'] && isset( self::TABLE_FIXES[ $db->errno() ] ) && ! in_array( self::TABLE_FIXES[ $db->errno() ], $tried, true ) ) {
			$fix     = self::TABLE_FIXES[ $db->errno() ];
			$tried[] = $fix;
			$sql     = Sql_Rewriter::$fix( $sql );
			$ok      = $db->query( $sql );
		}
		if ( $ok ) {
			if ( 'create_table' === $statement['kind'] ) {
				$state['tables'][ $statement['table'] ] = $statement['temp_table'];
			} elseif ( 'insert' === $statement['kind'] ) {
				++$state['rows'];
			}
			return 'insert' !== $statement['kind'];
		}
		if ( 'insert' !== $statement['kind'] ) {
			throw new \RuntimeException( sprintf( 'The database server refused to create the table %1$s: %2$s', $statement['table'], $db->error() ) );
		}
		if ( ++$state['errors'] <= 20 ) {
			$this->job->log( sprintf( 'A row of %1$s was not imported: %2$s', $statement['table'], $db->error() ) );
		}
		if ( $state['errors'] > 1000 ) {
			throw new \RuntimeException( 'The database server refused more than 1000 rows, so the restore stopped. The log lists the first errors.' );
		}
		return false;
	}

	/** Writes the files. Must-use plugins wait for the finalizing step. */
	private function run_extract_files() {
		$files   = $this->job->get(
			'files',
			array(
				'offset'    => 0,
				'entry'     => null,
				'processed' => 0,
				'written'   => 0,
				'count'     => 0,
				'skipped'   => 0,
				'failed'    => 0,
				'later'     => array(),
			)
		);
		$map     = Site::destination_map();
		$codec   = $this->codec();
		$archive = $this->open_archive();
		$done    = false;
		while ( ! $this->out_of_time() ) {
			if ( null === $files['entry'] ) {
				$header = $this->read_header( $archive, $files['offset'] );
				if ( 'eof' === $header['type'] ) {
					$done = true;
					break;
				}
				$entry = array(
					'name'        => $header['name'],
					'destination' => $map->resolve( $header['name'] ),
					'start'       => $files['offset'] + Archive_Format::HEADER_SIZE,
					'size'        => $header['size'],
					'mtime'       => $header['mtime'],
					'stored'      => 0,
					'written'     => 0,
				);
				if ( null === $entry['destination'] || 0 === strpos( (string) Entry_Path::normalize( $header['name'] ), 'mu-plugins/' ) ) {
					if ( null !== $entry['destination'] ) {
						$files['later'][] = $entry;
					} elseif ( ! in_array( $header['name'], self::SPECIAL, true ) ) {
						++$files['skipped'];
					}
					$files['offset']    = $entry['start'] + $entry['size'];
					$files['processed'] += $entry['size'];
					continue;
				}
				$files['entry'] = $entry;
			}
			if ( $this->extract_entry( $archive, $files['entry'], $codec, $files, true ) ) {
				$files['offset']    = $files['entry']['start'] + $files['entry']['size'];
				$files['processed'] += $files['entry']['size'];
				$files['entry']     = null;
			}
		}
		fclose( $archive );
		$this->job->set( 'files', $files );
		if ( $done ) {
			$this->job->set( 'status', 'finalizing' );
			$this->job->log( sprintf( 'Files restored: %1$d written (%2$s), %3$d left out, %4$d could not be written.', $files['count'], size_format( $files['written'], 1 ), $files['skipped'], $files['failed'] ) );
		}
		$this->job->save();
	}

	/**
	 * Writes one entry: into the job folder first, then moved into place in one rename.
	 *
	 * @param resource $archive Archive.
	 * @param array    $entry   Entry; its progress is updated.
	 * @param Codec    $codec   Decoder.
	 * @param array    $files   Totals; updated.
	 * @param bool     $timed   Whether to stop when the step's time is up.
	 * @return bool Whether the entry is finished (written or given up).
	 */
	private function extract_entry( $archive, array &$entry, Codec $codec, array &$files, $timed ) {
		$part = $this->job->path( 'entry.part' );
		$out  = fopen( $part, 0 === $entry['written'] ? 'wb' : 'cb' );
		if ( ! $out || ! ftruncate( $out, $entry['written'] ) || 0 !== fseek( $out, $entry['written'] ) ) {
			throw new \RuntimeException( 'Cannot write in the working folder (wp-content/wpress-restore).' );
		}
		$stream = new Entry_Stream( $archive, $entry['start'], $entry['size'], $entry['stored'], $codec->for_entry( $entry['name'] ) );
		while ( null !== ( $piece = $stream->read() ) ) {
			self::write( $out, $piece );
			$entry['written'] += strlen( $piece );
			$entry['stored']   = $stream->stored_offset();
			if ( $timed && $entry['stored'] < $entry['size'] && $this->out_of_time() ) {
				fclose( $out );
				return false;
			}
		}
		fclose( $out );

		$destination = $entry['destination'];
		$problem     = null;
		if ( ! is_dir( dirname( $destination ) ) && ! wp_mkdir_p( dirname( $destination ) ) ) {
			$problem = 'its folder cannot be created';
		} elseif ( is_dir( $destination ) ) {
			$problem = 'a folder has its name';
		} else {
			@touch( $part, $entry['mtime'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- the date is a nicety.
			if ( ! @rename( $part, $destination ) && ! ( @copy( $part, $destination ) && @touch( $destination, $entry['mtime'] ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- failure is reported.
				$problem = 'it is not writable';
			} else {
				@chmod( $destination, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
		@unlink( $part ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( null === $problem ) {
			++$files['count'];
			$files['written'] += $entry['written'];
		} elseif ( ++$files['failed'] <= 50 ) {
			$this->job->log( sprintf( 'Not restored: %1$s (%2$s).', $entry['name'], $problem ) );
		}
		return true;
	}

	/** Adjusts the restored options, switches the site to the restored tables, then writes must-use plugins. */
	private function run_finalize() {
		$result = $this->job->get( 'result', array( 'deactivated' => array() ) );
		if ( isset( $this->job->get( 'scan' )['entries']['database.sql'] ) && ! $this->job->get( 'swapped' ) ) {
			$db     = new Database();
			$prefix = $db->prefix();
			$tables = $this->job->get( 'db' )['tables'];
			foreach ( array( 'options', 'users', 'usermeta' ) as $table ) {
				if ( ! isset( $tables[ $prefix . $table ] ) ) {
					throw new \RuntimeException( sprintf( 'The backup\'s database has no %s table, so the site was not switched to it.', $prefix . $table ) );
				}
			}
			if ( $this->job->get( 'swapping' ) && ! $db->tables( $tables[ $prefix . 'options' ] ) ) {
				// A previous attempt switched the tables and stopped before it could say so.
				$this->job->set( 'swapped', true );
			} else {
				$this->adjust_options( $db, $tables, $prefix, $result );
				$this->job->set( 'result', $result );
				$this->job->set( 'swapping', true );
				$this->job->save();
				$this->swap( $db, $tables );
				$this->job->set( 'swapped', true );
				$this->job->save();
				$this->run_views( $db );
			}
		}

		$files   = $this->job->get( 'files' );
		$archive = $this->open_archive();
		$codec   = $this->codec();
		foreach ( $files['later'] as $entry ) {
			$this->extract_entry( $archive, $entry, $codec, $files, false );
		}
		fclose( $archive );
		$files['later'] = array();
		$this->job->set( 'files', $files );

		wp_cache_flush();
		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset(); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- hosts may restrict it.
		}
		Guard::remove();
		foreach ( array( 'database.sql', 'views.sql', 'entry.part' ) as $file ) {
			@unlink( $this->job->path( $file ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$db_state = $this->job->get( 'db', array() );
		$result  += array(
			'files'        => $files['count'],
			'failed_files' => $files['failed'],
			'rows'         => isset( $db_state['rows'] ) ? $db_state['rows'] : 0,
			'failed_rows'  => isset( $db_state['errors'] ) ? $db_state['errors'] + $db_state['too_large'] : 0,
			'refused'      => isset( $db_state['refused'] ) ? $db_state['refused'] : 0,
			'database'     => isset( $this->job->get( 'scan' )['entries']['database.sql'] ),
			'finished'     => time(),
		);
		$this->job->set( 'result', $result );
		$this->job->set( 'password', null );
		$this->job->set( 'status', 'done' );
		$this->job->log( 'Restore finished.' );
		$this->job->save();
	}

	/**
	 * Gives the restored options what they need on this site, in the temporary table.
	 *
	 * @param Database $db     Database.
	 * @param string[] $tables Final => temporary table names.
	 * @param string   $prefix This site's prefix.
	 * @param array    $result Result; receives the deactivated plugins.
	 */
	private function adjust_options( Database $db, array $tables, $prefix, array &$result ) {
		$options = $tables[ $prefix . 'options' ];
		$package = $this->package();
		$live    = array_fill_keys( array( 'siteurl', 'home', 'upload_path', 'upload_url_path', 'template', 'stylesheet', 'active_plugins' ), '' );
		foreach ( $db->rows( "SELECT option_name, option_value FROM `{$prefix}options` WHERE option_name IN ('" . implode( "', '", array_keys( $live ) ) . "')" ) as $row ) {
			$live[ $row['option_name'] ] = (string) $row['option_value'];
		}
		// This site keeps its own address and upload settings, as with ai1wm.
		$this->set_option( $db, $options, 'siteurl', '' !== $live['siteurl'] ? $live['siteurl'] : site_url() );
		$this->set_option( $db, $options, 'home', '' !== $live['home'] ? $live['home'] : home_url() );
		$this->set_option( $db, $options, 'upload_path', $live['upload_path'] );
		$this->set_option( $db, $options, 'upload_url_path', $live['upload_url_path'] );

		// ai1wm empties the theme and the active plugins in its dump and records them in package.json.
		$theme = Theme_Choice::pick(
			array( $package->template(), $package->stylesheet() ),
			array( (string) $this->option( $db, $options, 'template' ), (string) $this->option( $db, $options, 'stylesheet' ) ),
			array( $live['template'], $live['stylesheet'] ),
			static function ( $theme ) {
				return wp_get_theme( $theme )->exists();
			}
		);
		$this->set_option( $db, $options, 'template', $theme[0] );
		$this->set_option( $db, $options, 'stylesheet', $theme[1] );
		$plugins = Plugin_List::for_restored_site(
			self::unserialize( $this->option( $db, $options, 'active_plugins' ) ),
			Site::basename(),
			Site::uses_https(),
			array_merge( $package->plugins(), Plugin_List::servmask( self::unserialize( $live['active_plugins'] ) ) ),
			static function ( $plugin ) {
				return is_file( WP_PLUGIN_DIR . '/' . $plugin );
			}
		);
		$this->set_option( $db, $options, 'active_plugins', serialize( $plugins['active'] ) );
		$result['deactivated'] = $plugins['deactivated'];
		$result['missing']     = $plugins['missing'];
		$this->job->log( sprintf( 'Theme: %1$s. Active plugins: %2$s.', $theme[1], implode( ', ', $plugins['active'] ) ) );
		if ( ! Site::uses_https() && null !== $this->option( $db, $options, 'woocommerce_force_ssl_checkout' ) ) {
			$this->set_option( $db, $options, 'woocommerce_force_ssl_checkout', 'no' );
		}
		$modules = self::unserialize( $this->option( $db, $options, 'jetpack_active_modules' ) );
		if ( is_array( $modules ) ) {
			$this->set_option( $db, $options, 'jetpack_active_modules', serialize( array_values( array_diff( $modules, array( 'photon', 'sso' ) ) ) ) );
		}
		// Regenerated on the next visit: permalinks for this server, page builder CSS for this address.
		$db->query( "DELETE FROM `{$options}` WHERE option_name IN ('rewrite_rules', '_elementor_global_css', '_elementor_assets_data', 'elementor-custom-breakpoints-files')" );
		if ( isset( $tables[ $prefix . 'postmeta' ] ) ) {
			$db->query( "DELETE FROM `{$tables[ $prefix . 'postmeta' ]}` WHERE meta_key IN ('_elementor_css', '_elementor_element_cache', '_elementor_page_assets')" );
		}
	}

	/**
	 * Switches the site to the restored tables in one RENAME TABLE, then drops the replaced ones.
	 *
	 * @param Database $db     Database.
	 * @param string[] $tables Final => temporary table names.
	 * @throws \RuntimeException When the server refuses the switch; then nothing changed.
	 */
	private function swap( Database $db, array $tables ) {
		$live       = array_flip( $db->tables() );
		$old_prefix = 'wpo' . substr( $this->job->get( 'temp_prefix' ), 3 );
		$renames    = array();
		$replaced   = array();
		foreach ( $tables as $final => $temp ) {
			if ( isset( $live[ $final ] ) ) {
				$old        = strlen( $old_prefix . $final ) <= 64 ? $old_prefix . $final : $old_prefix . md5( $final );
				$renames[]  = "`{$final}` TO `{$old}`";
				$replaced[] = $old;
			}
			$renames[] = "`{$temp}` TO `{$final}`";
		}
		if ( ! $db->query( 'RENAME TABLE ' . implode( ', ', $renames ) ) ) {
			throw new \RuntimeException( 'The database server refused to switch to the restored tables, so the site was left as it was: ' . $db->error() );
		}
		foreach ( $replaced as $table ) {
			$db->query( "DROP TABLE IF EXISTS `{$table}`" );
		}
		$this->job->log( sprintf( 'Switched the site to the restored database (%d tables).', count( $tables ) ) );
	}

	/**
	 * Creates the backup's views, now that their tables have their final names.
	 *
	 * @param Database $db Database.
	 */
	private function run_views( Database $db ) {
		$path = $this->job->path( 'views.sql' );
		if ( ! is_file( $path ) ) {
			return;
		}
		$handle = fopen( $path, 'rb' );
		$reader = new Sql_Reader( $handle, 0, 16 * MB_IN_BYTES );
		while ( null !== ( $statement = $reader->next() ) ) {
			if ( $statement['sql'] && ! $db->query( $statement['sql'] ) ) {
				$this->job->log( 'A view was not restored: ' . $db->error() );
			}
		}
		fclose( $handle );
	}

	/**
	 * An option of a restored options table.
	 *
	 * @param Database $db    Database.
	 * @param string   $table Table.
	 * @param string   $name  Option name.
	 * @return string|null
	 */
	private function option( Database $db, $table, $name ) {
		return $db->value( "SELECT option_value FROM `{$table}` WHERE option_name = " . $db->quote( $name ) . ' LIMIT 1' );
	}

	/**
	 * Sets an option in a restored options table.
	 *
	 * @param Database $db    Database.
	 * @param string   $table Table.
	 * @param string   $name  Option name.
	 * @param string   $value Value.
	 * @throws \RuntimeException When the server refuses.
	 */
	private function set_option( Database $db, $table, $name, $value ) {
		// Not option(): wpdb::get_var() reads an empty value as null, which would look like a missing row.
		$exists = null !== $db->value( "SELECT 1 FROM `{$table}` WHERE option_name = " . $db->quote( $name ) . ' LIMIT 1' );
		$sql    = $exists
			? "UPDATE `{$table}` SET option_value = " . $db->quote( $value ) . ' WHERE option_name = ' . $db->quote( $name )
			: "INSERT INTO `{$table}` (option_name, option_value, autoload) VALUES (" . $db->quote( $name ) . ', ' . $db->quote( $value ) . ", 'yes')";
		if ( ! $db->query( $sql ) ) {
			throw new \RuntimeException( sprintf( 'Could not set the option %1$s on the restored database: %2$s', $name, $db->error() ) );
		}
	}

	/**
	 * Unserializes an option without instantiating any object.
	 *
	 * @param string|null $value Serialized value.
	 * @return mixed Null when it is not serialized data.
	 */
	private static function unserialize( $value ) {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$data = @unserialize( $value, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions -- objects are refused.
		return ( false === $data && 'b:0;' !== $value ) ? null : $data;
	}

	/** @return Package The backup's package.json. */
	private function package() {
		return Package::from_json( $this->job->get( 'package' ) );
	}

	/** @return Codec Decoder for this backup. */
	private function codec() {
		return new Codec( $this->job->get( 'password' ), $this->package()->compression() );
	}

	/**
	 * Fails the step if the backup file was replaced or removed during the restore.
	 *
	 * @throws Archive_Exception When it changed.
	 */
	private function check_backup() {
		$path = Site::backups_dir() . '/' . $this->job->get( 'backup' );
		clearstatcache( true, $path );
		if ( ! is_file( $path ) || filesize( $path ) !== (int) $this->job->get( 'backup_size' ) || filemtime( $path ) !== (int) $this->job->get( 'backup_mtime' ) ) {
			throw new Archive_Exception( 'The backup file changed or disappeared during the restore.' );
		}
	}

	/**
	 * @return resource The backup, open for reading.
	 * @throws Archive_Exception When it cannot be opened.
	 */
	private function open_archive() {
		$handle = @fopen( Site::backups_dir() . '/' . $this->job->get( 'backup' ), 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- failure is reported.
		if ( ! $handle ) {
			throw new Archive_Exception( 'Cannot open the backup file.' );
		}
		return $handle;
	}

	/**
	 * Reads a block of the archive.
	 *
	 * @param resource $archive Archive.
	 * @param int      $offset  Offset.
	 * @return array
	 * @throws Archive_Exception When it is not a header.
	 */
	private function read_header( $archive, $offset ) {
		if ( 0 !== fseek( $archive, $offset ) ) {
			throw new Archive_Exception( 'Cannot move inside the backup file.' );
		}
		return Archive_Format::parse_header( (string) fread( $archive, Archive_Format::HEADER_SIZE ) );
	}

	/**
	 * Writes all of $data.
	 *
	 * @param resource $handle File.
	 * @param string   $data   Bytes.
	 * @throws \RuntimeException When the disk takes less.
	 */
	private static function write( $handle, $data ) {
		if ( '' !== $data && fwrite( $handle, $data ) !== strlen( $data ) ) {
			throw new \RuntimeException( 'Writing failed: the disk is probably full. Free some space, then retry.' );
		}
	}

	/** @return bool Whether this step has used its time. */
	private function out_of_time() {
		return microtime( true ) - $this->started > $this->budget;
	}

	/** @return float Seconds a step may work: 10, or a third of PHP's time limit if that is shorter. */
	private static function budget() {
		$budget = (float) apply_filters( 'wpress_restore_step_seconds', 10 );
		$limit  = (int) ini_get( 'max_execution_time' );
		return $limit > 0 ? min( $budget, max( 2, $limit / 3 ) ) : $budget;
	}
}
