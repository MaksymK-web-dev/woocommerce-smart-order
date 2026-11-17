<?php
/**
 * Notifier contract.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Contract;

use WSO\SmartOrder\Ai\UrgencyResult;
use WSO\SmartOrder\Order\OrderPayload;

/**
 * Delivers a notification about a new order.
 */
interface Notifier {

	/**
	 * Sends the notification.
	 *
	 * @param OrderPayload  $payload Order data.
	 * @param UrgencyResult $urgency Classification result.
	 * @return bool True when the notification was accepted by the transport.
	 */
	public function send( OrderPayload $payload, UrgencyResult $urgency ): bool;
}
