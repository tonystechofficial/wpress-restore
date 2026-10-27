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
	 * Adds a "Restore a backup" link on the Plugins screen.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'tools.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Restore a backup', 'wpress-restore' ) . '</a>' );
		return $links;
	}

	/** Renders the page; the browser script draws the backups and the restore. */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap wpress-restore"><h1>WPress Restore</h1>';
		if ( is_multisite() ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'WPress Restore restores single sites only: it does not run on a WordPress network.', 'wpress-restore' ) . '</p></div></div>';
			return;
		}
		Site::protect( Site::backups_dir(), false );
		$job = Job::current();
		wp_add_inline_script(
			'wpress-restore',
			'window.wpressRestore = ' . wp_json_encode(
				array(
					'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
					'nonce'     => wp_create_nonce( 'wpress_restore' ),
					'chunkSize' => (int) min( 8 * MB_IN_BYTES, max( 256 * KB_IN_BYTES, wp_max_upload_size() * 0.9 ) ),
					'backups'   => Backups::all(),
					'job'       => $job ? ( new Restore( $job ) )->view() : null,
					'loginUrl'  => wp_login_url( admin_url( 'tools.php?page=' . self::PAGE ) ),
					'home'      => home_url(),
					'is32bit'   => PHP_INT_SIZE < 8,
					'i18n'      => Strings::all(),
				)
			) . ';',
			'before'
		);
		$folder = Site::backups_dir();
		?>
		<p class="wpr-intro"><?php esc_html_e( 'Restores backups made with All-in-One WP Migration (.wpress files), whatever their size: the work is done a few seconds at a time, so no upload or time limit applies.', 'wpress-restore' ); ?></p>
		<?php if ( PHP_INT_SIZE < 8 ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'This server runs 32-bit PHP: backups of 2 GB or more cannot be read. Ask your host for 64-bit PHP.', 'wpress-restore' ); ?></p></div>
		<?php endif; ?>
		<?php if ( ! wp_is_writable( WP_CONTENT_DIR ) ) : ?>
			<div class="notice notice-error inline"><p><?php esc_html_e( 'The wp-content folder is not writable by the web server: a restore needs to write there.', 'wpress-restore' ); ?></p></div>
		<?php endif; ?>
		<div id="wpr-job" class="wpr-panel" hidden></div>
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
		if ( ! current_user_can( 'manage_options' ) || is_multisite() ) {
			self::fail( __( 'You are not allowed to restore backups.', 'wpress-restore' ), 'forbidden', 403 );
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

		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- steps keep themselves short; this only lifts a stricter host limit.
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
					self::send(
						array(
							'name'   => $name,
							'offset' => $offset,
						)
					);
					break;

				case 'upload_chunk':
					// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- checked above; only the temporary path is used.
					$chunk = isset( $_FILES['chunk'] ) ? $_FILES['chunk'] : null;
					if ( ! $chunk || UPLOAD_ERR_OK !== $chunk['error'] || ! is_uploaded_file( $chunk['tmp_name'] ) ) {
						$too_big = $chunk && in_array( $chunk['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true );
						self::fail( __( 'The server refused a piece of the upload.', 'wpress-restore' ), $too_big ? 'chunk_size' : 'chunk', $too_big ? 413 : 400 );
					}
					$result = ( new Upload( Site::backups_dir() ) )->append( $text( 'name' ), $int( 'size' ), $int( 'offset' ), $chunk['tmp_name'] );
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

				case 'step':
					$restore = new Restore( self::job() );
					$ran     = $restore->step();
					self::send(
						array(
							'job'  => $restore->view(),
							'busy' => ! $ran,
						)
					);
					break;

				case 'confirm':
					$restore = new Restore( self::job() );
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
					$restore = new Restore( self::job() );
					$restore->retry();
					self::send( array( 'job' => $restore->view() ) );
					break;

				case 'cancel':
					$job = Job::current();
					if ( $job ) {
						( new Restore( $job ) )->cancel();
					}
					self::send( array( 'backups' => Backups::all() ) );
					break;

				case 'log':
					self::send( array( 'log' => ( new Restore( self::job() ) )->log_text() ) );
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
	 * The job in progress.
	 *
	 * @return Job
	 */
	private static function job() {
		$job = Job::current();
		if ( ! $job ) {
			self::fail( __( 'There is no restore in progress.', 'wpress-restore' ), 'no_job', 404 );
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
