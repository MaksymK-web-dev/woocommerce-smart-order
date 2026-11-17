<?php
/**
 * Order hook listener.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Hook;

use WSO\SmartOrder\Contract\HookRegistrar;
use WSO\SmartOrder\Order\Pipeline;
use WSO\SmartOrder\Support\Logger;

/**
 * Runs the pipeline when a new order appears.
 *
 * `woocommerce_new_order` fires inside the very first `$order->save()`, before products,
 * addresses and the customer note are attached — reading the order there yields an empty
 * object. The listener therefore only collects ids and defers the work to `shutdown`,
 * when the order is fully populated and still inside the same request.
 *
 * `woocommerce_new_order` also covers admin and REST creation, and it passes the id, which
 * keeps this HPOS-safe: no direct access to the posts table anywhere.
 */
final class OrderListener implements HookRegistrar {

	/**
	 * Order ids collected during this request.
	 *
	 * @var array<int, bool>
	 */
	private array $pending = array();

	/**
	 * Whether the shutdown callback is attached.
	 */
	private bool $shutdownRegistered = false;

	/**
	 * @param Pipeline $pipeline  Order pipeline.
	 * @param callable $isEnabled Returns whether the plugin should act at all.
	 */
	public function __construct(
		private readonly Pipeline $pipeline,
		private readonly mixed $isEnabled
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'woocommerce_new_order', array( $this, 'collect' ), 20, 1 );
	}

	/**
	 * Records a new order id for deferred processing.
	 *
	 * @param int $orderId Order id.
	 */
	public function collect( int $orderId ): void {
		if ( ! ( $this->isEnabled )() ) {
			return;
		}

		$this->pending[ $orderId ] = true;

		if ( ! $this->shutdownRegistered ) {
			$this->shutdownRegistered = true;
			add_action( 'shutdown', array( $this, 'flush' ), 1 );
		}
	}

	/**
	 * Processes every order collected during the request.
	 */
	public function flush(): void {
		$ids           = array_keys( $this->pending );
		$this->pending = array();

		foreach ( $ids as $orderId ) {
			try {
				$this->pipeline->process( $orderId );
			} catch ( \Throwable $exception ) {
				// A notification failure must never break checkout for the customer.
				Logger::error( sprintf( 'Order %d processing failed: %s', $orderId, $exception->getMessage() ) );
			}
		}
	}
}
