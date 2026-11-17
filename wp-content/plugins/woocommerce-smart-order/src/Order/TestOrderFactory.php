<?php
/**
 * Test order factory.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Order;

/**
 * Creates a realistic order for demos, tests and the CLI.
 *
 * One implementation, used by both the WP-CLI command and the standalone demo script.
 * The two had their own copies of this block, which meant an edit to the test data could
 * land in one and not the other.
 *
 * Everything is read through the WooCommerce public API, so it works with HPOS enabled.
 */
final class TestOrderFactory {

	/**
	 * Default billing details; override any key via $args['billing'].
	 *
	 * @var array<string, string>
	 */
	private const DEFAULT_BILLING = array(
		'first_name' => 'Іван',
		'last_name'  => 'Петренко',
		'phone'      => '+000000000000',
		'email'      => 'ivan.petrenko@example.com',
		'address_1'  => 'вул. Городоцька, 12',
		'city'       => 'Львів',
		'postcode'   => '79000',
		'country'    => 'UA',
	);

	/**
	 * Creates an order from the first published products.
	 *
	 * @param string               $comment Customer comment.
	 * @param array<string, mixed> $args    Optional: `products` (int, default 2),
	 *                                      `quantity` (int, default 1), `status`.
	 * @return int|null Order id, or null when the store has no published products.
	 */
	public function create( string $comment, array $args = array() ): ?int {
		$limit    = isset( $args['products'] ) && is_numeric( $args['products'] ) ? max( 1, (int) $args['products'] ) : 2;
		$quantity = isset( $args['quantity'] ) && is_numeric( $args['quantity'] ) ? max( 1, (int) $args['quantity'] ) : 1;
		$status   = isset( $args['status'] ) && is_string( $args['status'] ) && '' !== $args['status']
			? $args['status']
			: 'pending';

		$products = wc_get_products(
			array(
				'status' => 'publish',
				'limit'  => $limit,
			)
		);

		if ( empty( $products ) ) {
			return null;
		}

		$order = wc_create_order();

		if ( ! $order instanceof \WC_Order ) {
			return null;
		}

		foreach ( $products as $product ) {
			if ( $product instanceof \WC_Product ) {
				$order->add_product( $product, $quantity );
			}
		}

		$billing = self::DEFAULT_BILLING;

		if ( isset( $args['billing'] ) && is_array( $args['billing'] ) ) {
			$billing = array_merge( $billing, array_map( 'strval', $args['billing'] ) );
		}

		$order->set_address( $billing, 'billing' );
		$order->set_customer_note( $comment );
		$order->calculate_totals();
		$order->set_status( $status );
		$order->save();

		return $order->get_id();
	}
}
