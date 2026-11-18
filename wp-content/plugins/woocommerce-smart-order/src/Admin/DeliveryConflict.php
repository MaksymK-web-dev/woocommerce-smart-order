<?php
/**
 * Duplicate-delivery warning.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Admin;

use WSO\SmartOrder\Config\Settings;
use WSO\SmartOrder\Contract\HookRegistrar;

/**
 * Warns when the plugin and a WooCommerce webhook would both notify the manager
 * for the same order.
 */
final class DeliveryConflict implements HookRegistrar {

	private const SCREEN = 'woocommerce_page_wso-smart-order';

	/**
	 * Attaches the admin notice.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Prints the notice on this plugin's screen only.
	 */
	public function render(): void {
		if ( ! Settings::enabled() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || self::SCREEN !== $screen->id ) {
			return;
		}

		$urls = $this->activeOrderWebhooks();

		if ( array() === $urls ) {
			return;
		}

		$lines = array_map( 'esc_html', $urls );

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong></p><p>%s</p><ul><li>%s</li></ul></div>',
			esc_html__( 'Two delivery chains are active', 'woocommerce-smart-order' ),
			esc_html__( 'This plugin is processing new orders and a WooCommerce webhook does the same, so the manager would receive two messages per order. Disable one of them.', 'woocommerce-smart-order' ),
			implode( '</li><li>', $lines ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each entry escaped above.
		);
	}

	/**
	 * Delivery URLs of active order webhooks.
	 *
	 * @return string[]
	 */
	private function activeOrderWebhooks(): array {
		if ( ! function_exists( 'wc_get_webhook' ) ) {
			return array();
		}

		$urls = array();

		try {
			$store = \WC_Data_Store::load( 'webhook' );

			foreach ( (array) $store->get_webhooks_ids( 'active' ) as $id ) {
				$webhook = wc_get_webhook( (int) $id );

				if ( ! $webhook || 'order.created' !== $webhook->get_topic() ) {
					continue;
				}

				$urls[] = sprintf( '%s: %s', $webhook->get_name(), $webhook->get_delivery_url() );
			}
		} catch ( \Throwable $exception ) {
			return array();
		}

		return $urls;
	}
}
