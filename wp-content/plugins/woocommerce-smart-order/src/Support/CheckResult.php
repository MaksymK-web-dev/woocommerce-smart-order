<?php
/**
 * Check result.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Support;

/**
 * Outcome of a live connection check.
 */
final class CheckResult {

	/**
	 * Builds a result.
	 *
	 * @param bool   $ok      Whether the remote side accepted the credentials.
	 * @param string $message Human-readable explanation.
	 */
	private function __construct(
		public readonly bool $ok,
		public readonly string $message
	) {}

	/**
	 * The remote side answered and accepted what we sent.
	 *
	 * @param string $message Explanation.
	 */
	public static function pass( string $message ): self {
		return new self( true, $message );
	}

	/**
	 * The check did not succeed.
	 *
	 * @param string $message Explanation.
	 */
	public static function fail( string $message ): self {
		return new self( false, $message );
	}

	/**
	 * Shape sent to the admin screen.
	 *
	 * @return array{ok: bool, message: string}
	 */
	public function toArray(): array {
		return array(
			'ok'      => $this->ok,
			'message' => $this->message,
		);
	}
}
