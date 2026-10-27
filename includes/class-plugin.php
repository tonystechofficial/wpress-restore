<?php
/**
 * WordPress wiring: admin page and AJAX endpoint.
 *
 * @package WPressRestore
 */

namespace WPressRestore;

/**
 * Registers the Tools page and the one AJAX action every operation goes through.
 */
final class Plugin {

	const PAGE = 'wpress-restore';

	/** Hooks the plugin into WordPress. */
	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'wp_ajax_wpress_restore', array( __CLASS__, 'ajax' ) );
		add_filter( 'plugin_action_links_' . Site::basename(), array( __CLASS__, 'action_links' ) );
	}

	/** Adds Tools → WPress Restore. */
	public static function menu() {
		$hook = add_management_page( 'WPress Restore', 'WPress Restore', 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
		add_action( 'admin_enqueue_scripts', static function ( $current ) use ( $hook ) {
			if ( $current === $hook ) {
				wp_enqueue_style( 'wpress-restore', plugins_url( 'assets/admin.css', WPRESS_RESTORE_FILE ), array(), WPRESS_RESTORE_VERSION );
				wp_enqueue_script( 'wpress-restore', plugins_url( 'assets/admin.js', WPRESS_RESTORE_FILE ), array(), WPRESS_RESTORE_VERSION, true );
			}
		} );
	}

	/**
	 * Adds a "Backups" link on the Plugins screen.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'tools.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Backups', 'wpress-restore' ) . '</a>' );
		return $links;
	}

	/** Renders the page; the browser script draws the backups and the job in progress. */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap wpress-restore"><h1>WPress Restore</h1>';
		$refusal = self::refusal();
		if ( null !== $refusal ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $refusal ) . '</p></div></div>';
			return;
		}
		Site::protect( Site::backups_dir(), false );
		$job = Job::current();
		wp_add_inline_script(
			'wpress-restore',
			// JSON_HEX_TAG: a backup name or address holding "<!--<script>" must not unbalance the script tag.
			'window.wpressRestore = ' . wp_json_encode(
				array(
					'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
					'nonce'     => wp_create_nonce( 'wpress_restore' ),
					'chunkSize' => (int) min( 8 * MB_IN_BYTES, max( 256 * KB_IN_BYTES, wp_max_upload_size() * 0.9 ) ),
					'backups'   => Backups::all(),
					'job'       => $job ? Runner::of( $job )->view() : null,
					'loginUrl'  => wp_login_url( admin_url( 'tools.php?page=' . self::PAGE ) ),
					'home'      => home_url(),
					'is32bit'   => PHP_INT_SIZE < 8,
					'i18n'      => Strings::all(),
				),
				JSON_HEX_TAG | JSON_HEX_AMP
			) . ';',
			'before'
		);
		$folder = Site::backups_dir();
		?>
		<p class="wpr-intro"><?php esc_html_e( 'Creates and restores All-in-One WP Migration backups (.wpress files), whatever their size: the work is done a few seconds at a time, so no upload or time limit applies.', 'wpress-restore' ); ?></p>
		<?php if ( PHP_INT_SIZE < 8 ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'This server runs 32-bit PHP: backups of 2 GB or more cannot be read or written. Ask your host for 64-bit PHP.', 'wpress-restore' ); ?></p></div>
		<?php endif; ?>
		<?php if ( ! wp_is_writable( WP_CONTENT_DIR ) ) : ?>
			<div class="notice notice-error inline"><p><?php esc_html_e( 'The wp-content folder is not writable by the web server: backups and restores need to write there.', 'wpress-restore' ); ?></p></div>
		<?php endif; ?>
		<div id="wpr-job" class="wpr-panel" hidden></div>
		<div class="wpr-panel wpr-create">
			<h2><?php esc_html_e( 'Create a backup', 'wpress-restore' ); ?></h2>
			<p><?php esc_html_e( 'Saves the database, media, plugins and themes into a .wpress file in the backups folder. WPress Restore and All-in-One WP Migration can both restore it.', 'wpress-restore' ); ?></p>
			<fieldset class="wpr-options">
				<label><input type="checkbox" id="wpr-no-media"> <?php esc_html_e( 'Leave out the media library (uploads)', 'wpress-restore' ); ?></label>
				<label><input type="checkbox" id="wpr-no-revisions"> <?php esc_html_e( 'Leave out post revisions', 'wpress-restore' ); ?></label>
				<label><input type="checkbox" id="wpr-no-spam"> <?php esc_html_e( 'Leave out spam comments', 'wpress-restore' ); ?></label>
				<label><input type="checkbox" id="wpr-no-cache"> <?php esc_html_e( 'Leave out the cache folder (wp-content/cache)', 'wpress-restore' ); ?></label>
			</fieldset>
			<p>
				<label for="wpr-compression"><?php esc_html_e( 'Compression', 'wpress-restore' ); ?></label><br>
				<select id="wpr-compression">
					<option value=""><?php esc_html_e( 'None: fastest', 'wpress-restore' ); ?></option>
					<option value="gzip"<?php disabled( ! function_exists( 'gzcompress' ) ); ?>><?php esc_html_e( 'gzip: smaller', 'wpress-restore' ); ?></option>
					<option value="bzip2"<?php disabled( ! function_exists( 'bzcompress' ) ); ?>><?php esc_html_e( 'bzip2: smallest, slowest', 'wpress-restore' ); ?></option>
				</select>
			</p>
			<?php if ( function_exists( 'openssl_encrypt' ) ) : ?>
				<p class="wpr-password-row">
					<span>
						<label for="wpr-export-password"><?php esc_html_e( 'Password (optional)', 'wpress-restore' ); ?></label><br>
						<input type="password" id="wpr-export-password" class="regular-text" autocomplete="new-password">
					</span>
					<span>
						<label for="wpr-export-password2"><?php esc_html_e( 'Repeat the password', 'wpress-restore' ); ?></label><br>
						<input type="password" id="wpr-export-password2" class="regular-text" autocomplete="new-password">
					</span>
				</p>
				<p class="description"><?php esc_html_e( 'With a password, the backup is encrypted. Keep the password safe: without it, nobody can restore the backup.', 'wpress-restore' ); ?></p>
			<?php endif; ?>
			<p><button type="button" class="button button-primary" id="wpr-create"><?php esc_html_e( 'Create backup', 'wpress-restore' ); ?></button></p>
		</div>
		<div class="wpr-panel wpr-upload">
			<h2><?php esc_html_e( 'Add a backup', 'wpress-restore' ); ?></h2>
			<p>
				<?php esc_html_e( 'Upload it by FTP or SFTP into this folder, then reload this page:', 'wpress-restore' ); ?>
				<code><?php echo esc_html( $folder ); ?></code>
			</p>
			<p><?php esc_html_e( 'Or send it from this computer, in small pieces that pass any upload limit:', 'wpress-restore' ); ?></p>
			<p class="wpr-upload-row">
				<input type="file" id="wpr-file" accept=".wpress">
				<button type="button" class="button" id="wpr-send" disabled><?php esc_html_e( 'Upload', 'wpress-restore' ); ?></button>
			</p>
			<div id="wpr-upload-status" hidden></div>
		</div>
		<h2><?php esc_html_e( 'Backups', 'wpress-restore' ); ?></h2>
		<table class="widefat striped wpr-backups">
			<thead><tr>
				<th><?php esc_html_e( 'File', 'wpress-restore' ); ?></th>
				<th><?php esc_html_e( 'Size', 'wpress-restore' ); ?></th>
				<th><?php esc_html_e( 'Date', 'wpress-restore' ); ?></th>
				<th></th>
			</tr></thead>
			<tbody id="wpr-backups"></tbody>
		</table>
		<p class="description"><?php esc_html_e( 'A backup holds the whole database, password hashes included: delete it from the server once you no longer need it.', 'wpress-restore' ); ?></p>
		</div>
		<?php
	}

	/** Every browser request: one action, an operation, a nonce, and the right to manage the site. */
	public static function ajax() {
		if ( ! check_ajax_referer( 'wpress_restore', 'nonce', false ) ) {
			self::fail( __( 'Your session has expired: reload the page.', 'wpress-restore' ), 'session', 403 );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			self::fail( __( 'You are not allowed to manage backups.', 'wpress-restore' ), 'forbidden', 403 );
		}
		$refusal = self::refusal();
		if ( null !== $refusal ) {
			self::fail( $refusal, 'forbidden', 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$op   = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$text = static function ( $key ) {
			return isset( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- names and passwords are checked where used.
		};
		$int  = static function ( $key ) {
			return isset( $_POST[ $key ] ) ? (int) $_POST[ $key ] : 0;
		};
		// phpcs:enable

		// Hosts may disable set_time_limit(); on PHP 8 calling it would then be fatal.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- steps keep themselves short; this only lifts a stricter host limit.
		}
		wp_raise_memory_limit( 'admin' );
		try {
			switch ( $op ) {
				case 'backups':
					self::send( array( 'backups' => Backups::all() ) );
					break;

				case 'upload_begin':
					$name = Upload::safe_name( $text( 'name' ) );
					if ( null === $name ) {
						self::fail( __( 'Only .wpress files can be restored.', 'wpress-restore' ), 'name', 400 );
					}
					Site::protect( Site::backups_dir(), false );
					try {
						$offset = ( new Upload( Site::backups_dir() ) )->begin( $name, $int( 'size' ), '1' === $text( 'overwrite' ) );
					} catch ( \RuntimeException $e ) {
						if ( 'exists' === $e->getMessage() ) {
							self::fail( __( 'A backup with this name is already on the server.', 'wpress-restore' ), 'exists', 409 );
						}
						throw $e;
					}
					$begun = array(
						'name'   => $name,
						'offset' => $offset,
						'done'   => $offset === $int( 'size' ),
					);
					if ( $begun['done'] ) {
						$begun['backups'] = Backups::all();
					}
					self::send( $begun );
					break;

				case 'upload_chunk':
					$name = $text( 'name' );
					if ( Upload::safe_name( $name ) !== $name ) {
						self::fail( __( 'Only .wpress files can be restored.', 'wpress-restore' ), 'name', 400 );
					}
					// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- checked above; only the temporary path is used.
					$chunk = isset( $_FILES['chunk'] ) ? $_FILES['chunk'] : null;
					if ( ! $chunk || UPLOAD_ERR_OK !== $chunk['error'] || ! is_uploaded_file( $chunk['tmp_name'] ) ) {
						$too_big = $chunk && in_array( $chunk['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true );
						self::fail( __( 'The server refused a piece of the upload.', 'wpress-restore' ), $too_big ? 'chunk_size' : 'chunk', $too_big ? 413 : 400 );
					}
					// Moved first: open_basedir may forbid reading PHP's upload folder, never move_uploaded_file().
					$piece = Site::backups_dir() . '/' . $name . '.chunk';
					if ( ! move_uploaded_file( $chunk['tmp_name'], $piece ) ) {
						self::fail( __( 'The backups folder is not writable.', 'wpress-restore' ), 'chunk', 500 );
					}
					try {
						$result = ( new Upload( Site::backups_dir() ) )->append( $name, $int( 'size' ), $int( 'offset' ), $piece );
					} finally {
						@unlink( $piece ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					}
					if ( $result['done'] ) {
						$result['backups'] = Backups::all();
					}
					self::send( $result );
					break;

				case 'delete':
					Backups::delete( $text( 'name' ) );
					self::send( array( 'backups' => Backups::all() ) );
					break;

				case 'start':
					$job = Restore::start( Backups::find( $text( 'name' ) ) );
					self::send( array( 'job' => ( new Restore( $job ) )->view() ) );
					break;

				case 'export_start':
					$job = Export::start(
						array(
							'no_media'          => '1' === $text( 'no_media' ),
							'no_spam_comments'  => '1' === $text( 'no_spam_comments' ),
							'no_post_revisions' => '1' === $text( 'no_post_revisions' ),
							'no_cache'          => '1' === $text( 'no_cache' ),
							'compression'       => $text( 'compression' ),
							'password'          => $text( 'password' ),
						)
					);
					self::send( array( 'job' => ( new Export( $job ) )->view() ) );
					break;

				case 'step':
					$runner = Runner::of( self::job() );
					$ran    = $runner->step();
					$step   = array(
						'job'  => $runner->view(),
						'busy' => ! $ran,
					);
					if ( 'done' === $step['job']['status'] ) {
						$step['backups'] = Backups::all();
					}
					self::send( $step );
					break;

				case 'confirm':
					$restore = Runner::of( self::job() );
					if ( ! $restore instanceof Restore ) {
						self::fail( __( 'There is no restore waiting for confirmation.', 'wpress-restore' ), 'no_job', 404 );
					}
					try {
						$restore->confirm( $text( 'password' ) );
					} catch ( \RuntimeException $e ) {
						if ( 'password' === $e->getMessage() ) {
							self::fail( __( 'This password does not open the backup.', 'wpress-restore' ), 'password', 400 );
						}
						throw $e;
					}
					self::send( array( 'job' => $restore->view() ) );
					break;

				case 'retry':
					$runner = Runner::of( self::job() );
					$runner->retry();
					self::send( array( 'job' => $runner->view() ) );
					break;

				case 'cancel':
					$job = Job::current();
					if ( $job ) {
						Runner::of( $job )->cancel();
					}
					self::send( array( 'backups' => Backups::all() ) );
					break;

				case 'dismiss':
					$job = Job::current();
					if ( $job ) {
						Runner::of( $job )->dismiss();
					}
					self::send( array( 'backups' => Backups::all() ) );
					break;

				case 'log':
					self::send( array( 'log' => Runner::of( self::job() )->log_text() ) );
					break;

				default:
					self::fail( __( 'Unknown operation.', 'wpress-restore' ), 'op', 400 );
			}
		} catch ( \OutOfBoundsException $e ) {
			self::fail( $e->getMessage(), 'not_found', 404 );
		} catch ( \Throwable $e ) {
			self::fail( $e->getMessage(), 'error', 500 );
		}
	}

	/**
	 * Why restoring is not possible here, or null.
	 *
	 * @return string|null
	 */
	private static function refusal() {
		if ( is_multisite() ) {
			return __( 'WPress Restore restores single sites only: it does not run on a WordPress network.', 'wpress-restore' );
		}
		// A restore installs plugins and themes: a site that forbids that (DISALLOW_FILE_MODS) forbids restores.
		if ( ! current_user_can( 'install_plugins' ) ) {
			return __( 'A restore writes plugins and themes, which this site does not let you do: file changes are disabled (DISALLOW_FILE_MODS) or your account cannot install plugins.', 'wpress-restore' );
		}
		if ( ! Database::is_supported() ) {
			return __( 'This site reaches its database through a drop-in such as HyperDB, LudicrousDB or SQLite: WPress Restore needs WordPress\'s standard MySQL connection.', 'wpress-restore' );
		}
		return null;
	}

	/**
	 * The job in progress.
	 *
	 * @return Job
	 */
	private static function job() {
		$job = Job::current();
		if ( ! $job ) {
			self::fail( __( 'There is no backup or restore in progress.', 'wpress-restore' ), 'no_job', 404 );
		}
		return $job;
	}

	/**
	 * Sends a success response and stops.
	 *
	 * @param array $data Data.
	 */
	private static function send( array $data ) {
		wp_send_json_success( $data );
	}

	/**
	 * Sends an error response and stops.
	 *
	 * @param string $message Message for the owner.
	 * @param string $code    Machine-readable code.
	 * @param int    $status  HTTP status.
	 */
	private static function fail( $message, $code, $status ) {
		wp_send_json_error(
			array(
				'message' => $message,
				'code'    => $code,
			),
			$status
		);
	}
}
