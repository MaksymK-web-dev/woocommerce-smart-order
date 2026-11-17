<?php
/**
 * Demo driver for the WooCommerce → n8n → Telegram chain.
 *
 *   php tools/wc-setup.php webhook http://127.0.0.1:5678/webhook/wc-order-created
 *   php tools/wc-setup.php order "Delivery after 18:00, call an hour ahead"
 *   php tools/wc-setup.php deliver 28
 *   php tools/wc-setup.php cron
 *   php tools/wc-setup.php list
 *
 * WordPress root: --root=/path/to/wordpress, or WSO_WP_ROOT, or three levels up.
 *
 * @package WSO\Tools
 */

declare( strict_types = 1 );

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	fwrite( STDERR, "CLI only" . PHP_EOL );
	exit( 1 );
}

/**
 * Finds wp-load.php before anything else touches WordPress.
 *
 * @param string[] $argv Raw arguments.
 * @return string Absolute path to wp-load.php.
 */
function wso_smart_order_locate_wp_root( array $argv ): string {
	$root = getenv( 'WSO_WP_ROOT' ) ?: '';

	foreach ( $argv as $arg ) {
		if ( str_starts_with( $arg, '--root=' ) ) {
			$root = substr( $arg, 7 );
		}
	}

	if ( '' !== $root ) {
		$candidate = rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR . 'wp-load.php';
		if ( file_exists( $candidate ) ) {
			return $candidate;
		}
		fwrite( STDERR, "wp-load.php not found under {$root}\n" );
		exit( 1 );
	}

	$candidate = dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR . 'wp-load.php';
	if ( file_exists( $candidate ) ) {
		return $candidate;
	}

	fwrite( STDERR, "Cannot find wp-load.php. Pass --root=/path/to/wordpress or set WSO_WP_ROOT.\n" );
	exit( 1 );
}

require_once wso_smart_order_locate_wp_root( $argv );

$command  = $argv[1] ?? 'help';
$argument = $argv[2] ?? '';

if ( ! class_exists( 'WC_Webhook' ) ) {
	fwrite( STDERR, "WooCommerce is not active.\n" );
	exit( 1 );
}

/**
 * Lists every webhook.
 *
 * wc_get_webhooks() no longer exists in WooCommerce 11.x, hence the data store.
 *
 * @return WC_Webhook[]
 */
function wso_smart_order_all_webhooks(): array {
	$store = WC_Data_Store::load( 'webhook' );
	$hooks = array();

	foreach ( $store->get_webhooks_ids() as $id ) {
		$webhook = wc_get_webhook( (int) $id );
		if ( $webhook ) {
			$hooks[] = $webhook;
		}
	}

	return $hooks;
}

switch ( $command ) {
	case 'webhook':
		if ( '' === $argument ) {
			fwrite( STDERR, "Usage: webhook <delivery-url>\n" );
			exit( 1 );
		}

		$webhook = null;
		foreach ( wso_smart_order_all_webhooks() as $existing ) {
			if ( $existing->get_delivery_url() === $argument ) {
				$webhook = $existing;
				break;
			}
		}

		if ( $webhook ) {
			$webhook->set_status( 'active' );
			$webhook->save();
			echo "Updated existing webhook.\n";
		} else {
			$webhook = new WC_Webhook();
			$webhook->set_name( 'n8n — new order' );
			$webhook->set_topic( 'order.created' );
			$webhook->set_delivery_url( $argument );
			$webhook->set_status( 'active' );
			// build_payload() switches to this user before building the REST payload,
			// so it must be an account that can manage WooCommerce.
			$webhook->set_user_id( 1 );
			$webhook->save();
			echo "Created webhook.\n";
		}

		printf(
			"id=%d topic=%s status=%s url=%s\n",
			$webhook->get_id(),
			$webhook->get_topic(),
			$webhook->get_status(),
			$webhook->get_delivery_url()
		);
		break;

	case 'list':
		$hooks = wso_smart_order_all_webhooks();
		if ( ! $hooks ) {
			echo "No webhooks.\n";
			break;
		}
		foreach ( $hooks as $webhook ) {
			printf(
				"#%d %s | %s | %s | %s\n",
				$webhook->get_id(),
				$webhook->get_name(),
				$webhook->get_topic(),
				$webhook->get_status(),
				$webhook->get_delivery_url()
			);
		}
		break;

	case 'order':
		$note = $argument;

		// Loaded lazily: the autoloader works even when the plugin is inactive.
		if ( ! class_exists( \WSO\SmartOrder\Order\TestOrderFactory::class ) ) {
			$autoload = WP_PLUGIN_DIR . '/woocommerce-smart-order/autoload.php';

			if ( ! is_readable( $autoload ) ) {
				fwrite( STDERR, "woocommerce-smart-order plugin not found.\n" );
				exit( 1 );
			}

			require_once $autoload;
		}

		$order_id = ( new \WSO\SmartOrder\Order\TestOrderFactory() )->create( $note );

		if ( null === $order_id ) {
			fwrite( STDERR, "No published products.\n" );
			exit( 1 );
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			fwrite( STDERR, "Order {$order_id} could not be read back.\n" );
			exit( 1 );
		}

		printf(
			"Order #%s (id=%d), total %s %s, comment: %s\n",
			$order->get_order_number(),
			$order->get_id(),
			$order->get_total(),
			$order->get_currency(),
			'' !== $note ? $note : '—'
		);

		// Webhook delivery is asynchronous, and a local site has no traffic to drain the queue.
		do_action( 'action_scheduler_run_queue', 'WP Cron' );
		break;

	case 'deliver':
		$order_id = (int) $argument;
		if ( $order_id <= 0 ) {
			fwrite( STDERR, "Usage: deliver <order-id> [webhook-id]\n" );
			exit( 1 );
		}

		$webhook = wc_get_webhook( (int) ( $argv[3] ?? 1 ) );
		if ( ! $webhook ) {
			fwrite( STDERR, "Webhook not found.\n" );
			exit( 1 );
		}

		// build_payload() expects a resource id, not a WC_Order instance.
		$webhook->deliver( $order_id );
		printf( "Delivered webhook #%d for order %d\n", $webhook->get_id(), $order_id );
		break;

	case 'cron':
		do_action( 'action_scheduler_run_queue', 'WP Cron' );
		echo "Action Scheduler queue drained.\n";
		break;

	default:
		echo "Usage: php wc-setup.php webhook|order|deliver|cron|list [arg]\n";
}
