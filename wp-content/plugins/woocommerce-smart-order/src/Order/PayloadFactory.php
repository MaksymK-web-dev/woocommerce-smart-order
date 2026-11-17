<?php
/**
 * Builds payloads from WooCommerce orders.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Order;

/**
 * Reads a WC_Order through its public API (HPOS-safe) and flattens it.
 */
final class PayloadFactory {

	/**
	 * Builds a payload from an order id.
	 *
	 * @param int $orderId Order id.
	 * @return OrderPayload|null Null when the id does not resolve to an order.
	 */
	public function fromOrderId( int $orderId ): ?OrderPayload {
		$order = wc_get_order( $orderId );

		return $order instanceof \WC_Order ? $this->fromOrder( $order ) : null;
	}

	/**
	 * Builds a payload from an order object.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function fromOrder( \WC_Order $order ): OrderPayload {
		$name = trim( $order->get_formatted_billing_full_name() );
		$phone = trim( (string) $order->get_billing_phone() );

		return new OrderPayload(
			orderNumber: (string) $order->get_order_number(),
			orderId: $order->get_id(),
			customer: '' !== $name ? $name : __( 'No name', 'woocommerce-smart-order' ),
			phone: '' !== $phone ? $phone : __( 'not provided', 'woocommerce-smart-order' ),
			items: $this->itemList( $order ),
			total: $this->formatTotal( $order ),
			comment: trim( (string) $order->get_customer_note() ),
			status: (string) $order->get_status()
		);
	}

	/**
	 * "Shirt (x2), Cap (x1)" — or an em dash for an empty order.
	 */
	private function itemList( \WC_Order $order ): string {
		$parts = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$parts[] = sprintf( '%s (x%d)', $item->get_name(), $item->get_quantity() );
		}

		return $parts ? implode( ', ', $parts ) : '—';
	}

	/**
	 * Total formatted with the order currency.
	 */
	private function formatTotal( \WC_Order $order ): string {
		$total = $order->get_total();

		if ( '' === (string) $total ) {
			return '—';
		}

		return sprintf( '%s %s', $total, $order->get_currency() );
	}
}
