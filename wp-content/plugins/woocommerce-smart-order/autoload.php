<?php
/**
 * PSR-4 autoloader for the WSO\SmartOrder namespace.
 *
 * No Composer dependency: the plugin ships as a single directory and must work on
 * shared hosting where `composer install` never ran.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'WSO\\SmartOrder\\';

		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$path     = __DIR__ . '/src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);
