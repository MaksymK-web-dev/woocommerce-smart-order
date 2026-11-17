<?php
/**
 * Telegram notifier.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Notify;

use WSO\SmartOrder\Ai\UrgencyResult;
use WSO\SmartOrder\Contract\Notifier;
use WSO\SmartOrder\Order\OrderPayload;
use WSO\SmartOrder\Support\Logger;
use WSO\SmartOrder\Support\MessageFormatter;

/**
 * Posts the formatted message to a Telegram group through the Bot API.
 */
final class TelegramNotifier implements Notifier {

	/**
	 * @param string            $token     Bot token.
	 * @param string            $chatId    Target group id.
	 * @param MessageFormatter  $formatter Message builder.
	 */
	public function __construct(
		private readonly string $token,
		private readonly string $chatId,
		private readonly MessageFormatter $formatter
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function send( OrderPayload $payload, UrgencyResult $urgency ): bool {
		if ( '' === $this->token || '' === $this->chatId ) {
			Logger::warning( 'Telegram is not configured; skipping notification.' );

			return false;
		}

		$response = wp_remote_post(
			sprintf( 'https://api.telegram.org/bot%s/sendMessage', $this->token ),
			array(
				'timeout' => 20,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'chat_id'                  => $this->chatId,
						'parse_mode'               => 'HTML',
						'disable_web_page_preview' => true,
						'text'                     => $this->formatter->format( $payload, $urgency ),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			Logger::error( 'Telegram request failed: ' . $response->get_error_message() );

			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! is_array( $body ) || empty( $body['ok'] ) ) {
			Logger::error(
				sprintf(
					'Telegram rejected the message (HTTP %d): %s',
					$code,
					is_array( $body ) ? (string) ( $body['description'] ?? '' ) : ''
				)
			);

			return false;
		}

		return true;
	}
}
