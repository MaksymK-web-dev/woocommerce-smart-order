<?php
/**
 * Plugin Name: WSO — Local Webhook Transport
 * Description: Allows WordPress to deliver WooCommerce webhooks to a local n8n instance
 *              (127.0.0.1:5678), which wp_safe_remote_post() blocks by default.
 * Version:     1.0.0
 * Requires PHP: 7.4
 *
 * WooCommerce delivers webhooks through wp_safe_remote_request(), so every delivery URL
 * passes WordPress' URL validation first. That validation rejects:
 *
 *   1. hosts that resolve to a private or loopback address
 *      (unless the `http_request_host_is_external` filter allows them), and
 *   2. ports outside `http_allowed_safe_ports` (80, 443, 8080 by default).
 *
 * A local n8n sits at 127.0.0.1:5678 and fails both checks, so WooCommerce reports the
 * delivery action as "complete" while nothing ever leaves the server. This drop-in grants
 * exactly that one destination and nothing else — the allowlist is a host + port + path
 * check, not a blanket "private IPs are fine".
 *
 * Install: copy to wp-content/mu-plugins/ (must-use, always active).
 * Scope:   local development only. A hosted n8n needs no part of this file.
 *
 * @package WSO\Transport
 */

declare( strict_types = 1 );

namespace WSO\Transport;

defined( 'ABSPATH' ) || exit;

/**
 * Grants wp_safe_remote_request() access to the local n8n webhook endpoint.
 */
final class LocalWebhookTransport {

	/**
	 * Port the local n8n listens on.
	 */
	private const PORT = 5678;

	/**
	 * Path prefix of an n8n webhook trigger.
	 */
	private const PATH_MARKER = '/webhook/';

	/**
	 * Hosts considered local for the purpose of this plugin.
	 *
	 * @var string[]
	 */
	private const LOCAL_HOSTS = array( '127.0.0.1', 'localhost' );

	/**
	 * Registers the filters. Called once, at load time.
	 */
	public static function boot(): void {
		add_filter( 'http_request_host_is_external', array( self::class, 'allowLocalHost' ), 10, 3 );
		add_filter( 'http_allowed_safe_ports', array( self::class, 'allowN8nPort' ), 10, 3 );
	}

	/**
	 * Marks a local n8n host as an acceptable external host.
	 *
	 * @param bool   $external Whether WordPress already considers the host external.
	 * @param string $host     Host name of the requested URL.
	 * @param string $url      Requested URL.
	 * @return bool
	 */
	public static function allowLocalHost( bool $external, string $host, string $url ): bool {
		if ( $external ) {
			return true;
		}

		if ( ! self::isN8nWebhook( $url ) ) {
			return false;
		}

		return in_array( strtolower( $host ), self::LOCAL_HOSTS, true );
	}

	/**
	 * Adds the n8n port to the list of ports WordPress considers safe.
	 *
	 * @param array  $ports Allowed ports.
	 * @param string $host  Host name of the requested URL.
	 * @param string $url   Requested URL.
	 * @return array
	 */
	public static function allowN8nPort( array $ports, string $host, string $url ): array {
		if ( ! self::isN8nWebhook( $url ) ) {
			return $ports;
		}

		if ( ! in_array( self::PORT, $ports, true ) ) {
			$ports[] = self::PORT;
		}

		return $ports;
	}

	/**
	 * Whether the URL points at an n8n webhook trigger on the n8n port.
	 *
	 * @param string $url Requested URL.
	 * @return bool
	 */
	private static function isN8nWebhook( string $url ): bool {
		return false !== strpos( $url, ':' . self::PORT . self::PATH_MARKER );
	}

	/**
	 * Keeps the class uninstantiable.
	 */
	private function __construct() {}
}

LocalWebhookTransport::boot();
