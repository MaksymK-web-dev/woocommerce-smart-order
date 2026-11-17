<?php
/**
 * PHPUnit bootstrap.
 *
 * The unit tests cover classes that do not talk to WordPress, WooCommerce or the network.
 * A handful of global functions from WordPress are stubbed below so those classes can be
 * exercised without loading a full WordPress install — fast in CI, no database required.
 *
 * Functions that receive untrusted text (escaping, JSON encoding) are stubbed with their
 * real semantics, not with no-ops, so the assertions still mean something.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

require_once __DIR__ . '/../vendor/autoload.php';

$plugin = dirname( __DIR__ ) . '/wp-content/plugins/woocommerce-smart-order';

require_once $plugin . '/autoload.php';

if ( ! function_exists( '__' ) ) {
	/**
	 * Translation stub: returns the source string unchanged.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain (ignored).
	 * @return string
	 */
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * Escaped translation stub.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain (ignored).
	 * @return string
	 */
	function esc_html__( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * JSON encode stub with WordPress' default flags.
	 *
	 * @param mixed $data    Data.
	 * @param int   $options Flags.
	 * @param int   $depth   Depth.
	 * @return string|false
	 */
	function wp_json_encode( mixed $data, int $options = 0, int $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'wc_get_logger' ) ) {
	/**
	 * Logger stub: discards entries.
	 *
	 * @return object
	 */
	function wc_get_logger(): object {
		return new class() {
			/**
			 * Swallows a log entry.
			 *
			 * @param string               $level   Level.
			 * @param string               $message Message.
			 * @param array<string, mixed> $context Context.
			 */
			public function log( string $level, string $message, array $context = array() ): void {}
		};
	}
}
