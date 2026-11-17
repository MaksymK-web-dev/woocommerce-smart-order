<?php
/**
 * Classification result.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Ai;

/**
 * Outcome of a comment classification.
 *
 * Three states, not two: "not urgent" and "could not classify" must never look the
 * same to a manager.
 */
final class UrgencyResult {

	/**
	 * Keeps construction behind the named constructors below.
	 *
	 * @param bool   $urgent      Whether the comment carries a delivery-time request.
	 * @param string $note        Short restatement of the request, in the customer's language.
	 * @param bool   $available   False when the classifier could not run or returned garbage.
	 * @param string $error       Diagnostic message when unavailable.
	 */
	private function __construct(
		public readonly bool $urgent,
		public readonly string $note,
		public readonly bool $available,
		public readonly string $error
	) {}

	/**
	 * A successful classification with no delivery-time request.
	 */
	public static function clear(): self {
		return new self( false, '', true, '' );
	}

	/**
	 * A successful classification carrying a request.
	 *
	 * @param string $note Restatement of the request.
	 */
	public static function urgent( string $note ): self {
		return new self( true, $note, true, '' );
	}

	/**
	 * The classifier could not produce a verdict.
	 *
	 * @param string $error Diagnostic message.
	 */
	public static function unavailable( string $error ): self {
		return new self( false, '', false, $error );
	}
}
