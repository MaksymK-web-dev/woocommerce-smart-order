<?php
/**
 * Plugin logger.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Support;

/**
 * Writes to the WooCommerce log so entries land in WooCommerce → Status → Logs.
 */
final class Logger {

	private const SOURCE = 'woocommerce-smart-order';

	/**
	 * Debug-level entry.
	 *
	 * @param string $message Message.
	 */
	public static function debug( string $message ): void {
		self::log( 'debug', $message );
	}

	/**
	 * Warning-level entry.
	 *
	 * @param string $message Message.
	 */
	public static function warning( string $message ): void {
		self::log( 'warning', $message );
	}

	/**
	 * Error-level entry.
	 *
	 * @param string $message Message.
	 */
	public static function error( string $message ): void {
		self::log( 'error', $message );
	}

	/**
	 * Dispatches to wc_get_logger() when WooCommerce provides it.
	 *
	 * @param string $level   PSR-3 level.
	 * @param string $message Message.
	 */
	private static function log( string $level, string $message ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		wc_get_logger()->log( $level, $message, array( 'source' => self::SOURCE ) );
	}

	/**
	 * Keeps the class uninstantiable.
	 */
	private function __construct() {}
}
