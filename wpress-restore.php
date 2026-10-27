<?php
/**
 * Plugin Name:       WPress Restore
 * Plugin URI:        https://github.com/tonystechofficial/wpress-restore
 * Description:       Restores All-in-One WP Migration backups (.wpress) of any size. Upload by FTP or in small pieces, pick a backup, restore: the database is imported beside the live one and switched in at the end.
 * Version:           1.0.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            Tony's Tech
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wpress-restore
 *
 * @package WPressRestore
 */

defined( 'ABSPATH' ) || exit;

define( 'WPRESS_RESTORE_VERSION', '1.0.0' );
define( 'WPRESS_RESTORE_FILE', __FILE__ );

require __DIR__ . '/includes/autoload.php';

WPressRestore\Plugin::boot();
