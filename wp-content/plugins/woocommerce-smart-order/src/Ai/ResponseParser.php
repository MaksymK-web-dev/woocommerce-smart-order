<?php
/**
 * Shared parsing for provider adapters.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Ai;

/**
 * Turns a raw model answer into an UrgencyResult.
 */
final class ResponseParser {

	/**
	 * Parses the model's JSON answer.
	 *
	 * @param string $content Raw model text.
	 */
	public static function parse( string $content ): UrgencyResult {
		$content = trim( $content );

		if ( '' === $content ) {
			return UrgencyResult::unavailable( 'Empty response from the model.' );
		}

		// Some models fence JSON even when asked not to.
		$content = (string) preg_replace( '/^```(?:json)?\s*/i', '', $content );
		$content = (string) preg_replace( '/\s*```$/', '', $content );

		$decoded = json_decode( $content, true );

		if ( ! is_array( $decoded ) || ! array_key_exists( 'urgent', $decoded ) ) {
			return UrgencyResult::unavailable( 'Model response is not the expected JSON object.' );
		}

		// Strict comparison: the string "false" is truthy and would raise a false alarm.
		$urgent = true === $decoded['urgent'];
		$note   = isset( $decoded['note'] ) && is_scalar( $decoded['note'] )
			? trim( (string) $decoded['note'] )
			: '';

		return $urgent ? UrgencyResult::urgent( $note ) : UrgencyResult::clear();
	}
}
