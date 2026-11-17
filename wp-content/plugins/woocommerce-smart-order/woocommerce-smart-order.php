<?php
/**
 * Plugin Name:       WooCommerce Smart Order
 * Plugin URI:        https://github.com/MaksymK-web-dev/woocommerce-smart-order
 * Description:       Classifies the free-form customer comment on a new order for delivery-time requests and notifies the manager's Telegram group.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Maksym Krokhal
 * License:           MIT
 * Text Domain:       woocommerce-smart-order
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to:      11.3
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder;

defined( 'ABSPATH' ) || exit;

define( 'WSO_SMART_ORDER_FILE', __FILE__ );
define( 'WSO_SMART_ORDER_DIR', __DIR__ );

require_once __DIR__ . '/autoload.php';

/**
 * Declares compatibility with HPOS before WooCommerce initialises its order tables.
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

/**
 * Boots the plugin once WooCommerce is available.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html__( 'WooCommerce Smart Order requires WooCommerce to be active.', 'woocommerce-smart-order' )
					);
				}
			);
			return;
		}

		Plugin::instance()->boot();
	},
	20
);
