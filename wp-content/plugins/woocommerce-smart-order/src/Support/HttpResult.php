<?php
/**
 * Raw HTTP response.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Support;

/**
 * Normalises what `wp_remote_get()` returns into one shape.
 */
final class HttpResult {

	/**
	 * Builds a result.
	 *
	 * @param int                  $status HTTP status, 0 when the request never completed.
	 * @param array<string, mixed> $body   Decoded JSON body, empty when it does not decode.
	 * @param string               $error  Transport error message, empty on a completed request.
	 */
	public function __construct(
		public readonly int $status,
		public readonly array $body = array(),
		public readonly string $error = ''
	) {}

	/**
	 * Whether the request never reached a response.
	 */
	public function failed(): bool {
		return '' !== $this->error;
	}

	/**
	 * Whether the response carries a 2xx status.
	 */
	public function ok(): bool {
		return ! $this->failed() && $this->status >= 200 && $this->status < 300;
	}
}
