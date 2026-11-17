<?php
/**
 * Order data transfer object.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Order;

/**
 * Immutable snapshot of the order fields the notification needs.
 *
 * Everything downstream reads this object instead of a WC_Order or an array, so the
 * classification and formatting code stays free of WooCommerce calls.
 */
final class OrderPayload {

	/**
	 * @param string $orderNumber Human-facing order number.
	 * @param int    $orderId     Internal order id.
	 * @param string $customer    Full name, or a placeholder.
	 * @param string $phone       Billing phone, or a placeholder.
	 * @param string $items       Human-readable item list, e.g. "Shirt (x2), Cap (x1)".
	 * @param string $total       Formatted total with currency.
	 * @param string $comment     Raw customer comment; empty string when absent.
	 * @param string $status      WooCommerce status without the wc- prefix.
	 */
	public function __construct(
		public readonly string $orderNumber,
		public readonly int $orderId,
		public readonly string $customer,
		public readonly string $phone,
		public readonly string $items,
		public readonly string $total,
		public readonly string $comment,
		public readonly string $status
	) {}

	/**
	 * Whether the customer left a comment worth classifying.
	 */
	public function hasComment(): bool {
		return '' !== trim( $this->comment );
	}
}
