<?php
/**
 * Base URL validation.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Support;

/**
 * Accepts only a real http(s) endpoint.
 *
 * A paste-in-the-wrong-field is the reason this exists: a bare API key used to be
 * turned into `http://<key>` and stored as an endpoint, which both leaked the key
 * into the rendered form and pointed requests at a host that does not exist.
 */
final class Url {

	/**
	 * Normalises a base URL, or returns an empty string when it is not one.
	 *
	 * @param string $value Raw value.
	 */
	public static function normalizeBaseUrl( string $value ): string {
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		// Whitespace, a query, or a fragment means this is not a base URL.
		if ( preg_match( '/\s/', $value ) || str_contains( $value, '?' ) || str_contains( $value, '#' ) ) {
			return '';
		}

		$parts = wp_parse_url( $value );

		if ( ! is_array( $parts ) ) {
			return '';
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}

		$host = (string) ( $parts['host'] ?? '' );

		if ( '' === $host || ! self::isHostLike( $host ) ) {
			return '';
		}

		if ( isset( $parts['port'] ) && ( $parts['port'] < 1 || $parts['port'] > 65535 ) ) {
			return '';
		}

		$built = $scheme . '://' . $host;

		if ( isset( $parts['port'] ) ) {
			$built .= ':' . (int) $parts['port'];
		}

		$path = (string) ( $parts['path'] ?? '' );

		if ( '' !== $path ) {
			$built .= '/' . trim( $path, '/' );
		}

		return $built;
	}

	/**
	 * Whether the host part is a domain, localhost or an IP address.
	 *
	 * An API key pasted into the field has a single "label" and no dot, which this
	 * rejects.
	 *
	 * @param string $host Host part.
	 */
	private static function isHostLike( string $host ): bool {
		if ( 'localhost' === strtolower( $host ) ) {
			return true;
		}

		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return true;
		}

		// A domain needs a dot and a plausible top-level label.
		return 1 === preg_match( '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $host );
	}

	/**
	 * Keeps the class uninstantiable.
	 */
	private function __construct() {}
}
