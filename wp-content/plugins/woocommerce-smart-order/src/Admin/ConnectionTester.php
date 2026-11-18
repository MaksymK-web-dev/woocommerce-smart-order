<?php
/**
 * Live credential checks.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Admin;

use WSO\SmartOrder\Config\Settings;
use WSO\SmartOrder\Support\CheckResult;
use WSO\SmartOrder\Support\HttpResult;

/**
 * Answers "does this key still work?" without spending tokens: both AI providers
 * expose a models listing that authenticates the key and costs nothing.
 */
final class ConnectionTester {

	private const OPENAI_MODELS     = 'https://api.openai.com/v1/models?limit=1';
	private const ANTHROPIC_MODELS  = 'https://api.anthropic.com/v1/models?limit=1';
	private const ANTHROPIC_VERSION = '2023-06-01';
	private const TELEGRAM_ENDPOINT = 'https://api.telegram.org/bot%s/%s';

	/**
	 * Checks the configured AI provider.
	 *
	 * @param string $key Key typed into the form; empty means "use the stored one".
	 */
	public function checkAi( string $key = '' ): CheckResult {
		$key = '' !== trim( $key ) ? trim( $key ) : Settings::apiKey();

		if ( '' === $key ) {
			return CheckResult::fail( __( 'No API key is configured.', 'woocommerce-smart-order' ) );
		}

		$anthropic = Settings::PROVIDER_ANTHROPIC === Settings::provider();

		$response = $this->get(
			$anthropic ? self::ANTHROPIC_MODELS : self::OPENAI_MODELS,
			$anthropic
				? array(
					'x-api-key'         => $key,
					'anthropic-version' => self::ANTHROPIC_VERSION,
				)
				: array( 'Authorization' => 'Bearer ' . $key )
		);

		if ( ! $response->ok() ) {
			return CheckResult::fail(
				sprintf(
					/* translators: %s: error reported by the provider. */
					__( 'The provider rejected the key: %s', 'woocommerce-smart-order' ),
					$this->errorMessage( $response )
				)
			);
		}

		return CheckResult::pass(
			sprintf(
				/* translators: %s: provider name. */
				__( 'Key accepted by %s.', 'woocommerce-smart-order' ),
				$anthropic ? 'Anthropic' : 'OpenAI'
			)
		);
	}

	/**
	 * Checks the bot token and, when a group id is stored, that the bot can see it.
	 *
	 * @param string $token Token typed into the form; empty means "use the stored one".
	 */
	public function checkTelegram( string $token = '' ): CheckResult {
		$token = '' !== trim( $token ) ? trim( $token ) : Settings::telegramToken();

		if ( '' === $token ) {
			return CheckResult::fail( __( 'No bot token is configured.', 'woocommerce-smart-order' ) );
		}

		$identity = $this->get( sprintf( self::TELEGRAM_ENDPOINT, $token, 'getMe' ) );

		if ( ! $identity->ok() ) {
			return CheckResult::fail(
				sprintf(
					/* translators: %s: error reported by Telegram. */
					__( 'Telegram rejected the token: %s', 'woocommerce-smart-order' ),
					$this->errorMessage( $identity )
				)
			);
		}

		$username = (string) ( $identity->body['result']['username'] ?? '' );
		$chatId   = Settings::telegramChatId();

		if ( '' === $chatId ) {
			return CheckResult::pass(
				sprintf(
					/* translators: %s: bot username. */
					__( 'Token is valid (@%s). Group id is not set yet.', 'woocommerce-smart-order' ),
					'' !== $username ? $username : 'bot'
				)
			);
		}

		$chat = $this->get(
			sprintf( self::TELEGRAM_ENDPOINT, $token, 'getChat?chat_id=' . rawurlencode( $chatId ) )
		);

		if ( ! $chat->ok() ) {
			return CheckResult::fail(
				sprintf(
					/* translators: 1: bot username, 2: error reported by Telegram. */
					__( 'Token is valid (@%1$s), but the group is not reachable: %2$s', 'woocommerce-smart-order' ),
					$username,
					$this->errorMessage( $chat )
				)
			);
		}

		$title = (string) ( $chat->body['result']['title'] ?? '' );

		return CheckResult::pass(
			sprintf(
				/* translators: 1: bot username, 2: group title. */
				__( 'Token is valid (@%1$s); the bot can post to «%2$s».', 'woocommerce-smart-order' ),
				$username,
				'' !== $title ? $title : $chatId
			)
		);
	}

	/**
	 * Performs a GET and normalises the response.
	 *
	 * @param string                $url     Endpoint.
	 * @param array<string, string> $headers Request headers.
	 */
	private function get( string $url, array $headers = array() ): HttpResult {
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new HttpResult( 0, array(), $response->get_error_message() );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return new HttpResult(
			(int) wp_remote_retrieve_response_code( $response ),
			is_array( $body ) ? $body : array()
		);
	}

	/**
	 * Extracts the most specific message a transport can offer.
	 *
	 * @param HttpResult $result Response.
	 */
	private function errorMessage( HttpResult $result ): string {
		if ( $result->failed() ) {
			return $result->error;
		}

		$error = $result->body['error'] ?? null;

		if ( is_array( $error ) && isset( $error['message'] ) && is_scalar( $error['message'] ) ) {
			return (string) $error['message'];
		}

		// Telegram reports failures as {ok: false, description: "..."}.
		if ( isset( $result->body['description'] ) && is_scalar( $result->body['description'] ) ) {
			return (string) $result->body['description'];
		}

		return sprintf(
			/* translators: %d: HTTP status code. */
			__( 'HTTP %d', 'woocommerce-smart-order' ),
			$result->status
		);
	}
}
