<?php
/**
 * Classifier contract.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Contract;

use WSO\SmartOrder\Ai\UrgencyResult;

/**
 * Decides whether a free-form customer comment carries a delivery-time request.
 */
interface Classifier {

	/**
	 * Classifies a customer comment.
	 *
	 * Never throws: a provider failure is reported through
	 * UrgencyResult::unavailable() so the caller can decide what to do.
	 *
	 * @param string $comment Raw customer comment.
	 */
	public function classify( string $comment ): UrgencyResult;
}
