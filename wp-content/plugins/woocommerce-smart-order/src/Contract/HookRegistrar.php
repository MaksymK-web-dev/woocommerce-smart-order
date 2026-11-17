<?php
/**
 * Marker for classes that register their own WordPress hooks.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Contract;

/**
 * A service that owns a set of WordPress hooks.
 */
interface HookRegistrar {

	/**
	 * Attaches the service to WordPress.
	 */
	public function register(): void;
}
