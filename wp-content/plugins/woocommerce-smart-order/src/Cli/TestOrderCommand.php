<?php
/**
 * WP-CLI command.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Cli;

use WSO\SmartOrder\Ai\ClassifierFactory;
use WSO\SmartOrder\Config\Settings;
use WSO\SmartOrder\Notify\NotifierFactory;
use WSO\SmartOrder\Order\PayloadFactory;
use WSO\SmartOrder\Order\Pipeline;
use WSO\SmartOrder\Support\MessageFormatter;

/**
 * Exercises the pipeline from the command line.
 *
 * `wp smart-order test "comment"` creates a real order and runs the real pipeline, so the
 * full path is verifiable without logging into wp-admin.
 */
final class TestOrderCommand {

	/**
	 * Registers the command with WP-CLI.
	 */
	public static function register(): void {
		\WP_CLI::add_command( 'smart-order', self::class );
	}

	/**
	 * Creates a test order carrying a customer comment and runs the pipeline on it.
	 *
	 * ## OPTIONS
	 *
	 * <comment>
	 * : The customer comment to classify.
	 *
	 * [--dry-run]
	 * : Classify and print, but do not create an order.
	 *
	 * ## EXAMPLES
	 *
	 *     wp smart-order test "Доставка після 18:00, зателефонувати за годину"
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assocArgs  Flags.
	 */
	public function test( array $args, array $assocArgs ): void {
		$comment = (string) ( $args[0] ?? '' );

		if ( '' === $comment ) {
			\WP_CLI::error( 'A comment is required.' );
		}

		if ( isset( $assocArgs['dry-run'] ) ) {
			$result = ( new ClassifierFactory() )->make()->classify( $comment );
			\WP_CLI::line(
				sprintf(
					'urgent=%s available=%s note=%s',
					$result->urgent ? 'true' : 'false',
					$result->available ? 'true' : 'false',
					'' !== $result->note ? $result->note : ( '' !== $result->error ? '(' . $result->error . ')' : '—' )
				)
			);

			return;
		}

		$orderId = $this->createOrder( $comment );
		\WP_CLI::line( sprintf( 'Created order %d.', $orderId ) );

		$pipeline = new Pipeline(
			new PayloadFactory(),
			( new ClassifierFactory() )->make(),
			( new NotifierFactory( new MessageFormatter() ) )->make()
		);

		$sent = $pipeline->process( $orderId );

		$sent
			? \WP_CLI::success( 'Pipeline processed the order.' )
			: \WP_CLI::warning( 'Pipeline ran but no notification was delivered.' );

		if ( ! Settings::enabled() ) {
			\WP_CLI::warning( 'Processing is disabled in settings — trigger through the hook will not run.' );
		}
	}

	/**
	 * Creates a minimal order with two products and the given customer comment.
	 *
	 * @param string $comment Customer comment.
	 */
	private function createOrder( string $comment ): int {
		$products = wc_get_products(
			array(
				'status' => 'publish',
				'limit'  => 2,
			)
		);

		if ( ! $products ) {
			\WP_CLI::error( 'No published products to build an order from.' );
		}

		$order = wc_create_order();

		foreach ( $products as $product ) {
			$order->add_product( $product, 1 );
		}

		$order->set_address(
			array(
				'first_name' => 'Іван',
				'last_name'  => 'Петренко',
				'phone'      => '+000000000000',
				'email'      => 'ivan.petrenko@example.com',
				'address_1'  => 'вул. Городоцька, 12',
				'city'       => 'Львів',
				'postcode'   => '79000',
				'country'    => 'UA',
			),
			'billing'
		);

		$order->set_customer_note( $comment );
		$order->calculate_totals();
		$order->set_status( 'pending' );
		$order->save();

		return $order->get_id();
	}
}
