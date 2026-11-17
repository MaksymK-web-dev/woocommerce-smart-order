<?php
/**
 * Log notifier.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Notify;

use WSO\SmartOrder\Ai\UrgencyResult;
use WSO\SmartOrder\Contract\Notifier;
use WSO\SmartOrder\Order\OrderPayload;
use WSO\SmartOrder\Support\Logger;
use WSO\SmartOrder\Support\MessageFormatter;

/**
 * Writes the formatted message to the WooCommerce log instead of sending it.
 *
 * Used while credentials are missing, and as the safe default on a fresh install:
 * the pipeline is observable end to end without a bot token.
 */
final class LogNotifier implements Notifier {

	/**
	 * Sets up the notifier.
	 *
	 * @param MessageFormatter $formatter Message builder.
	 */
	public function __construct( private readonly MessageFormatter $formatter ) {}

	/**
	 * Writes the message to the WooCommerce log.
	 *
	 * @param OrderPayload  $payload Order data.
	 * @param UrgencyResult $urgency Classification result.
	 */
	public function send( OrderPayload $payload, UrgencyResult $urgency ): bool {
		Logger::debug(
			sprintf(
				'Notification for order #%s (urgent=%s, available=%s): %s',
				$payload->orderNumber,
				$urgency->urgent ? 'yes' : 'no',
				$urgency->available ? 'yes' : 'no',
				str_replace( "\n", ' | ', $this->formatter->format( $payload, $urgency ) )
			)
		);

		return true;
	}
}
