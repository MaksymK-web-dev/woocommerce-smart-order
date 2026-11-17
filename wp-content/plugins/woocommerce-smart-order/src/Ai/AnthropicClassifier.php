<?php
/**
 * Anthropic adapter.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Ai;

use WSO\SmartOrder\Contract\Classifier;
use WSO\SmartOrder\Support\Logger;

/**
 * Classifies comments through the Anthropic messages endpoint.
 *
 * Swapping providers is a settings change, not a code change: this class fulfils the
 * same Classifier contract as OpenAiClassifier.
 */
final class AnthropicClassifier implements Classifier {

	private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

	private const API_VERSION = '2023-06-01';

	/**
	 * Sets up the adapter.
	 *
	 * @param string $apiKey API key.
	 * @param string $model  Model name.
	 */
	public function __construct(
		private readonly string $apiKey,
		private readonly string $model
	) {}

	/**
	 * Classifies a comment through Anthropic.
	 *
	 * @param string $comment Customer comment.
	 */
	public function classify( string $comment ): UrgencyResult {
		if ( '' === $this->apiKey ) {
			return UrgencyResult::unavailable( 'Anthropic API key is not configured.' );
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 25,
				'headers' => array(
					'x-api-key'         => $this->apiKey,
					'anthropic-version' => self::API_VERSION,
					'Content-Type'      => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'       => $this->model,
						'max_tokens'  => 256,
						'temperature' => 0,
						// Prefilling the assistant turn with "{" makes the model continue a
						// JSON object instead of opening with prose.
						'messages'    => array(
							array(
								'role'    => 'user',
								'content' => Prompt::system() . "\n\n" . Prompt::user( $comment ),
							),
							array(
								'role'    => 'assistant',
								'content' => '{',
							),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->fail( $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$message = is_array( $body ) && isset( $body['error']['message'] )
				? (string) $body['error']['message']
				: sprintf( 'HTTP %d', $code );

			return $this->fail( $message );
		}

		$text = '';
		foreach ( (array) ( $body['content'] ?? array() ) as $block ) {
			if ( is_array( $block ) && 'text' === ( $block['type'] ?? '' ) ) {
				$text .= (string) ( $block['text'] ?? '' );
			}
		}

		// Restore the brace we used as a prefill.
		return ResponseParser::parse( '{' . ltrim( $text ) );
	}

	/**
	 * Logs the failure and reports it as unavailable.
	 *
	 * @param string $message Diagnostic message.
	 */
	private function fail( string $message ): UrgencyResult {
		Logger::warning( 'Anthropic classification failed: ' . $message );

		return UrgencyResult::unavailable( $message );
	}
}
