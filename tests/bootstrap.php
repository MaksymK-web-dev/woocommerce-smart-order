<?php
/**
 * PHPUnit bootstrap.
 *
 * Stubs the few WordPress functions the covered classes call.
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

/**
 * In-memory option store. Tests reset it in setUp().
 *
 * @var array<string, mixed>
 */
$GLOBALS['wso_test_options'] = array();

/**
 * Queued HTTP responses, consumed in order. Tests reset it in setUp().
 *
 * @var array<int, mixed>
 */
$GLOBALS['wso_test_http'] = array();

/**
 * URLs the code under test requested.
 *
 * @var string[]
 */
$GLOBALS['wso_test_requests'] = array();

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Option read stub.
	 *
	 * @param string $name    Option name.
	 * @param mixed  $default Value when the option is absent.
	 * @return mixed
	 */
	function get_option( string $name, mixed $default = false ): mixed {
		return $GLOBALS['wso_test_options'][ $name ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Option write stub.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	function update_option( string $name, mixed $value ): bool {
		$GLOBALS['wso_test_options'][ $name ] = $value;

		return true;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error.
	 */
	class WP_Error {

		/**
		 * Sets up the error.
		 *
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 */
		public function __construct(
			private readonly string $code = '',
			private readonly string $message = ''
		) {}

		/**
		 * Returns the message.
		 */
		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Error test stub.
	 *
	 * @param mixed $thing Value.
	 * @return bool
	 */
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	/**
	 * HTTP stub: returns the next queued response.
	 *
	 * @param string                $url  Requested URL.
	 * @param array<string, mixed>  $args Request arguments.
	 * @return mixed
	 */
	function wp_remote_get( string $url, array $args = array() ): mixed {
		$GLOBALS['wso_test_requests'][] = $url;

		return array_shift( $GLOBALS['wso_test_http'] );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	/**
	 * Status code stub.
	 *
	 * @param mixed $response Response.
	 * @return int
	 */
	function wp_remote_retrieve_response_code( mixed $response ): int {
		return (int) ( $response['response']['code'] ?? 0 );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	/**
	 * Body stub.
	 *
	 * @param mixed $response Response.
	 * @return string
	 */
	function wp_remote_retrieve_body( mixed $response ): string {
		return (string) ( $response['body'] ?? '' );
	}
}
