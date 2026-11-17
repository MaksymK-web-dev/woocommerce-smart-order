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
	 * @param string $comment Raw customer comment.
	 */
	public function classify( string $comment ): UrgencyResult;
}
