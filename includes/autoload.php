<?php
/**
 * Maps WPressRestore\Foo_Bar to includes/class-foo-bar.php.
 *
 * @package WPressRestore
 */

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'WPressRestore\\';
		if ( strncmp( $class_name, $prefix, strlen( $prefix ) ) !== 0 ) {
			return;
		}
		$file = __DIR__ . '/class-' . strtolower( str_replace( '_', '-', substr( $class_name, strlen( $prefix ) ) ) ) . '.php';
		if ( is_file( $file ) ) {
			require $file;
		}
	}
);
