<?php
/**
 * Order pipeline.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Order;

use WSO\SmartOrder\Ai\UrgencyResult;
use WSO\SmartOrder\Contract\Classifier;
use WSO\SmartOrder\Contract\Notifier;
use WSO\SmartOrder\Support\Logger;

/**
 * Order in → classification → notification out.
 */
final class Pipeline {

	/**
	 * Sets up the pipeline.
	 *
	 * @param PayloadFactory $payloads  Order reader.
	 * @param Classifier     $classifier AI adapter.
	 * @param Notifier       $notifier   Transport.
	 */
	public function __construct(
		private readonly PayloadFactory $payloads,
		private readonly Classifier $classifier,
		private readonly Notifier $notifier
	) {}

	/**
	 * Processes one order.
	 *
	 * @param int $orderId Order id.
	 * @return bool True when a notification was emitted.
	 */
	public function process( int $orderId ): bool {
		$payload = $this->payloads->fromOrderId( $orderId );

		if ( ! $payload ) {
			Logger::warning( sprintf( 'Order %d could not be read.', $orderId ) );

			return false;
		}

		// Nothing to classify and nothing to flag: skip the API call entirely.
		$urgency = $payload->hasComment()
			? $this->classifier->classify( $payload->comment )
			: UrgencyResult::clear();

		Logger::debug(
			sprintf(
				'Order #%s classified: urgent=%s available=%s',
				$payload->orderNumber,
				$urgency->urgent ? 'yes' : 'no',
				$urgency->available ? 'yes' : 'no'
			)
		);

		return $this->notifier->send( $payload, $urgency );
	}
}
