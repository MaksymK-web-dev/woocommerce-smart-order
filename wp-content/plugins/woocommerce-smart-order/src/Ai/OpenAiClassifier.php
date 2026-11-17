<?php
/**
 * OpenAI adapter.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Ai;

use WSO\SmartOrder\Contract\Classifier;
use WSO\SmartOrder\Support\Logger;

/**
 * Classifies comments through the OpenAI chat completions endpoint.
 */
final class OpenAiClassifier implements Classifier {

	private const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

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
	 * Classifies a comment through OpenAI.
	 *
	 * @param string $comment Customer comment.
	 */
	public function classify( string $comment ): UrgencyResult {
		if ( '' === $this->apiKey ) {
			return UrgencyResult::unavailable( 'OpenAI API key is not configured.' );
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 25,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->apiKey,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
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

		$content = $body['choices'][0]['message']['content'] ?? '';

		return ResponseParser::parse( is_string( $content ) ? $content : '' );
	}

	/**
	 * Logs the failure and reports it as unavailable.
	 *
	 * @param string $message Diagnostic message.
	 */
	private function fail( string $message ): UrgencyResult {
		Logger::warning( 'OpenAI classification failed: ' . $message );

		return UrgencyResult::unavailable( $message );
	}
}
