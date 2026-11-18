<?php
/**
 * OpenAI-compatible adapter.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Ai;

use WSO\SmartOrder\Contract\Classifier;
use WSO\SmartOrder\Support\JsonBody;
use WSO\SmartOrder\Support\Logger;

/**
 * Classifies comments through any provider that speaks OpenAI's chat completions
 * protocol: OpenAI, Gemini, Groq, DeepSeek, Mistral, OpenRouter, Ollama, LM Studio.
 *
 * Providers disagree about optional parameters, so an unsupported one is dropped
 * and the request retried instead of failing the chain.
 */
final class OpenAiCompatibleClassifier implements Classifier {

	/**
	 * Parameters that may not survive the retry ladder.
	 */
	private const OPTIONAL_PARAMS = array( 'response_format', 'temperature' );

	/**
	 * Sets up the adapter.
	 *
	 * @param string $apiKey  API key.
	 * @param string $model   Model name.
	 * @param string $baseUrl Base URL, without a trailing slash.
	 */
	public function __construct(
		private readonly string $apiKey,
		private readonly string $model,
		private readonly string $baseUrl
	) {}

	/**
	 * Classifies a comment.
	 *
	 * @param string $comment Customer comment.
	 */
	public function classify( string $comment ): UrgencyResult {
		if ( '' === $this->apiKey ) {
			return UrgencyResult::unavailable( 'AI API key is not configured.' );
		}

		$payload  = $this->payload( $comment );
		$response = $this->exchange( $payload );
		$attempts = count( self::OPTIONAL_PARAMS );

		for ( $retry = 0; $retry < $attempts; $retry++ ) {
			if ( 200 === $response['status'] ) {
				break;
			}

			$param = $this->unsupportedParam( $response['error'] );

			if ( null === $param || ! array_key_exists( $param, $payload ) ) {
				break;
			}

			unset( $payload[ $param ] );
			$response = $this->exchange( $payload );
		}

		if ( 200 !== $response['status'] ) {
			return $this->fail( '' !== $response['error'] ? $response['error'] : sprintf( 'HTTP %d', $response['status'] ) );
		}

		$content = $response['body']['choices'][0]['message']['content'] ?? '';

		return ResponseParser::parse( is_string( $content ) ? $content : '' );
	}

	/**
	 * Builds the request body.
	 *
	 * @param string $comment Customer comment.
	 * @return array<string, mixed>
	 */
	private function payload( string $comment ): array {
		return array(
			'model'           => $this->model,
			// Classification, not generation: variance is a defect here.
			'temperature'     => 0,
			// Forces a machine-readable answer instead of prose.
			'response_format' => array( 'type' => 'json_object' ),
			'messages'        => array(
				array(
					'role'    => 'system',
					'content' => Prompt::system(),
				),
				array(
					'role'    => 'user',
					'content' => Prompt::user( $comment ),
				),
			),
		);
	}

	/**
	 * Sends one request.
	 *
	 * @param array<string, mixed> $payload Request body.
	 * @return array{status: int, body: array<string, mixed>, error: string}
	 */
	private function exchange( array $payload ): array {
		$response = wp_remote_post(
			$this->baseUrl . '/chat/completions',
			array(
				'timeout' => 25,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->apiKey,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 0,
				'body'   => array(),
				'error'  => $response->get_error_message(),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = JsonBody::unwrap( json_decode( (string) wp_remote_retrieve_body( $response ), true ) );

		$error = '';

		if ( 200 !== $status ) {
			$error = isset( $body['error']['message'] ) && is_scalar( $body['error']['message'] )
				? (string) $body['error']['message']
				: '';
		}

		return array(
			'status' => $status,
			'body'   => $body,
			'error'  => $error,
		);
	}

	/**
	 * Finds the optional parameter a provider complained about.
	 *
	 * @param string $message Provider error message.
	 */
	private function unsupportedParam( string $message ): ?string {
		foreach ( self::OPTIONAL_PARAMS as $param ) {
			if ( str_contains( strtolower( $message ), $param ) ) {
				return $param;
			}
		}

		return null;
	}

	/**
	 * Logs the failure and reports it as unavailable.
	 *
	 * @param string $message Diagnostic message.
	 */
	private function fail( string $message ): UrgencyResult {
		Logger::warning( 'AI classification failed: ' . $message );

		return UrgencyResult::unavailable( $message );
	}
}
