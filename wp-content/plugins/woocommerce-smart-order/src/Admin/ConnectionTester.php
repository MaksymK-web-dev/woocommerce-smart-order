<?php
/**
 * Live credential checks.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Admin;

use WSO\SmartOrder\Ai\KeyPrefix;
use WSO\SmartOrder\Config\Draft;
use WSO\SmartOrder\Config\Settings;
use WSO\SmartOrder\Support\CheckResult;
use WSO\SmartOrder\Support\HttpResult;
use WSO\SmartOrder\Support\JsonBody;

/**
 * Answers "does this key still work?" without spending tokens: the model listing
 * authenticates the key and costs nothing.
 *
 * Every check takes a draft, so the answer describes the configuration on screen
 * rather than the one last saved.
 */
final class ConnectionTester {

	private const ANTHROPIC_MODELS  = 'https://api.anthropic.com/v1/models?limit=1';
	private const ANTHROPIC_VERSION = '2023-06-01';
	private const TELEGRAM_ENDPOINT = 'https://api.telegram.org/bot%s/%s';

	/**
	 * Checks the AI provider described by a draft.
	 *
	 * @param Draft $draft Configuration to check.
	 */
	public function checkAi( Draft $draft ): CheckResult {
		if ( $draft->customEndpointMissing() ) {
			return CheckResult::fail(
				__( 'The “Custom endpoint” provider needs a base URL, for example https://openrouter.ai/api/v1.', 'woocommerce-smart-order' )
			);
		}

		$mismatch = $this->keyMismatch( $draft );

		if ( '' !== $mismatch ) {
			return CheckResult::fail( $mismatch );
		}

		if ( '' === $draft->apiKey ) {
			return CheckResult::fail(
				sprintf(
					/* translators: %s: provider name. */
					__( 'No API key is configured for %s.', 'woocommerce-smart-order' ),
					$draft->providerLabel()
				)
			);
		}

		return $draft->usesAnthropicProtocol()
			? $this->checkAnthropic( $draft )
			: $this->checkOpenAiCompatible( $draft );
	}

	/**
	 * Checks the bot token described by a draft.
	 *
	 * @param Draft $draft Configuration to check.
	 */
	public function checkTelegram( Draft $draft ): CheckResult {
		if ( '' === $draft->telegramToken ) {
			return CheckResult::fail( __( 'No bot token is configured.', 'woocommerce-smart-order' ) );
		}

		$identity = $this->get( sprintf( self::TELEGRAM_ENDPOINT, $draft->telegramToken, 'getMe' ) );

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
		$chat_id  = $draft->telegramChat;

		if ( '' === $chat_id ) {
			return CheckResult::pass(
				sprintf(
					/* translators: %s: bot username. */
					__( 'Token is valid (@%s). Group id is not set yet.', 'woocommerce-smart-order' ),
					'' !== $username ? $username : 'bot'
				)
			);
		}

		$chat = $this->get(
			sprintf( self::TELEGRAM_ENDPOINT, $draft->telegramToken, 'getChat?chat_id=' . rawurlencode( $chat_id ) )
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
				'' !== $title ? $title : $chat_id
			)
		);
	}

	/**
	 * Explains a key that belongs to a different service than the selected provider.
	 *
	 * @param Draft $draft Configuration to check.
	 */
	private function keyMismatch( Draft $draft ): string {
		// A custom endpoint may accept any key format, so nothing can be inferred.
		if ( Settings::PROVIDER_CUSTOM === $draft->provider ) {
			return '';
		}

		$owner = KeyPrefix::providerForKey( $draft->apiKey );

		if ( '' === $owner || $owner === $draft->provider ) {
			return '';
		}

		return sprintf(
			/* translators: 1: provider the key belongs to, 2: selected provider. */
			__( 'This looks like a %1$s key, but the selected provider is %2$s. Choose %1$s, or paste the key for %2$s.', 'woocommerce-smart-order' ),
			KeyPrefix::label( $owner ),
			$draft->providerLabel()
		);
	}

	/**
	 * Checks an Anthropic key against its native models endpoint.
	 *
	 * @param Draft $draft Configuration to check.
	 */
	private function checkAnthropic( Draft $draft ): CheckResult {
		$response = $this->get(
			self::ANTHROPIC_MODELS,
			array(
				'x-api-key'         => $draft->apiKey,
				'anthropic-version' => self::ANTHROPIC_VERSION,
			)
		);

		if ( ! $response->ok() ) {
			return $this->rejected( $draft, $response );
		}

		return CheckResult::pass(
			sprintf(
				/* translators: %s: provider name. */
				__( 'Key accepted by %s.', 'woocommerce-smart-order' ),
				$draft->providerLabel()
			)
		);
	}

	/**
	 * Checks an OpenAI-compatible key.
	 *
	 * The model listing is free, but not every compatible server implements it, so
	 * a missing listing falls back to a one-token request that proves the model name
	 * as well as the key.
	 *
	 * @param Draft $draft Configuration to check.
	 */
	private function checkOpenAiCompatible( Draft $draft ): CheckResult {
		$response = $this->get( $draft->baseUrl . '/models', array( 'Authorization' => 'Bearer ' . $draft->apiKey ) );

		if ( $response->ok() ) {
			return CheckResult::pass(
				sprintf(
					/* translators: 1: provider name, 2: base URL. */
					__( 'Key accepted by %1$s (%2$s).', 'woocommerce-smart-order' ),
					$draft->providerLabel(),
					$draft->baseUrl
				)
			);
		}

		if ( ! $response->failed() && in_array( $response->status, array( 404, 405 ), true ) ) {
			return $this->probeCompletion( $draft );
		}

		return $this->rejected( $draft, $response );
	}

	/**
	 * Falls back to a minimal completion when there is no model listing.
	 *
	 * @param Draft $draft Configuration to check.
	 */
	private function probeCompletion( Draft $draft ): CheckResult {
		$response = wp_remote_post(
			$draft->baseUrl . '/chat/completions',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $draft->apiKey,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'      => $draft->model,
						'max_tokens' => 1,
						'messages'   => array(
							array(
								'role'    => 'user',
								'content' => 'ping',
							),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return CheckResult::fail(
				sprintf(
					/* translators: %s: transport error. */
					__( 'The provider could not be reached: %s', 'woocommerce-smart-order' ),
					$response->get_error_message()
				)
			);
		}

		$body   = JsonBody::unwrap( json_decode( (string) wp_remote_retrieve_body( $response ), true ) );
		$result = new HttpResult( (int) wp_remote_retrieve_response_code( $response ), $body );

		if ( ! $result->ok() ) {
			return $this->rejected( $draft, $result );
		}

		return CheckResult::pass(
			sprintf(
				/* translators: 1: provider name, 2: model name. */
				__( 'Key accepted by %1$s; model %2$s answered.', 'woocommerce-smart-order' ),
				$draft->providerLabel(),
				$draft->model
			)
		);
	}

	/**
	 * Wraps a provider rejection, naming the model when it is the likely culprit.
	 *
	 * @param Draft      $draft  Configuration that was checked.
	 * @param HttpResult $result Response.
	 */
	private function rejected( Draft $draft, HttpResult $result ): CheckResult {
		$message = $this->errorMessage( $result );

		if ( 404 === $result->status ) {
			return CheckResult::fail(
				sprintf(
					/* translators: 1: model name, 2: provider name, 3: error reported by the provider. */
					__( 'Model “%1$s” was not found at %2$s. Check the model name: %3$s', 'woocommerce-smart-order' ),
					$draft->model,
					$draft->providerLabel(),
					$message
				)
			);
		}

		return CheckResult::fail(
			sprintf(
				/* translators: 1: provider name, 2: error reported by the provider. */
				__( '%1$s rejected the request: %2$s', 'woocommerce-smart-order' ),
				$draft->providerLabel(),
				$message
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

		$body = JsonBody::unwrap( json_decode( (string) wp_remote_retrieve_body( $response ), true ) );

		return new HttpResult( (int) wp_remote_retrieve_response_code( $response ), $body );
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
