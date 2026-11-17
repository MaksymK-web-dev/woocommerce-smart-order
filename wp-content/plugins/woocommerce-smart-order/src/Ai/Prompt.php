<?php
/**
 * Classification prompt.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Ai;

/**
 * The system prompt and the JSON contract every provider adapter shares.
 *
 * Kept in one place so switching providers cannot silently change the classification
 * criteria.
 */
final class Prompt {

	public const JSON_SHAPE = '{"urgent": true|false, "note": "<short restatement, or empty string>"}';

	/**
	 * System prompt.
	 */
	public static function system(): string {
		return implode(
			' ',
			array(
				'You are an assistant to an e-commerce order manager.',
				'You receive the free-form comment a customer attached to their order.',
				'Decide whether the comment contains an urgent request about delivery TIME or METHOD,',
				'for example: "deliver after 18:00", "call me in an hour", "courier before noon",',
				'"pick up today", "do not call, message me on Telegram".',
				'Packaging requests, gift notes, product questions and polite wishes are NOT urgent.',
				'Return STRICT JSON and nothing else, in this shape:',
				self::JSON_SHAPE,
				'Write the note in the same language the customer used, at most 12 words.',
			)
		);
	}

	/**
	 * User message for a given comment.
	 *
	 * @param string $comment Raw comment.
	 */
	public static function user( string $comment ): string {
		return 'Customer comment: ' . wp_json_encode( $comment, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}
}
