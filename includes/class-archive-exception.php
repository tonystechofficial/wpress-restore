<?php
/**
 * Archive errors.
 *
 * @package WPressRestore
 */

namespace WPressRestore;

/**
 * The backup file cannot be read as a .wpress archive: damaged, incomplete, or wrongly decrypted.
 */
final class Archive_Exception extends \RuntimeException {
}
