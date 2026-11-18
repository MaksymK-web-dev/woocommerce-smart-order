<?php
/**
 * Decoded-body normalisation.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Support;

/**
 * Some gateways wrap a JSON payload in a one-element array. Unwrapping it once
 * here keeps every caller working with an object-shaped body.
 */
final class JsonBody {

	/**
	 * Returns the body as an associative array with any outer list removed.
	 *
	 * @param mixed $decoded Whatever `json_decode( ..., true )` produced.
	 * @return array<string, mixed>
	 */
	public static function unwrap( mixed $decoded ): array {
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		if ( array() === $decoded ) {
			return array();
		}

		$first = $decoded[0] ?? null;

		if ( array_is_list( $decoded ) && is_array( $first ) ) {
			return $first;
		}

		return $decoded;
	}

	/**
	 * Keeps the class uninstantiable.
	 */
	private function __construct() {}
}
