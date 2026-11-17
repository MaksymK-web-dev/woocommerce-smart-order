<?php
/**
 * Plugin composition root.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder;

use WSO\SmartOrder\Admin\SettingsPage;
use WSO\SmartOrder\Ai\ClassifierFactory;
use WSO\SmartOrder\Cli\TestOrderCommand;
use WSO\SmartOrder\Config\Settings;
use WSO\SmartOrder\Contract\HookRegistrar;
use WSO\SmartOrder\Hook\OrderListener;
use WSO\SmartOrder\Notify\NotifierFactory;
use WSO\SmartOrder\Order\PayloadFactory;
use WSO\SmartOrder\Order\Pipeline;
use WSO\SmartOrder\Support\MessageFormatter;

/**
 * Wires the services together and boots them once.
 *
 * No service locator and no static container: the dependency graph is small enough to
 * be readable in one method, and explicit constructor injection keeps it testable.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 */
	private static ?self $instance = null;

	/**
	 * Whether boot() already ran.
	 */
	private bool $booted = false;

	/**
	 * Returns the shared instance.
	 */
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/**
	 * Builds the object graph and registers every hook owner.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		$formatter = new MessageFormatter();
		$pipeline  = new Pipeline(
			new PayloadFactory(),
			( new ClassifierFactory() )->make(),
			( new NotifierFactory( $formatter ) )->make()
		);

		/** @var HookRegistrar[] $registrars */
		$registrars = array(
			new OrderListener( $pipeline, static fn (): bool => Settings::enabled() ),
			new SettingsPage(),
		);

		foreach ( $registrars as $registrar ) {
			$registrar->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			TestOrderCommand::register();
		}

		do_action( 'wso_smart_order_booted', $this );
	}

	/**
	 * Composition root: the container is this class.
	 */
	private function __construct() {}
}
