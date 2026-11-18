<?php
/**
 * OpenAI-compatible adapter tests.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Tests\Unit\Ai;

use PHPUnit\Framework\TestCase;
use WSO\SmartOrder\Ai\OpenAiCompatibleClassifier;

/**
 * Pins that any provider speaking the chat completions protocol can be used, and
 * that a provider rejecting an optional parameter still gets a usable answer.
 */
final class OpenAiCompatibleClassifierTest extends TestCase {

	private const BASE = 'https://example.test/v1';

	/**
	 * Clears the HTTP queue.
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['wso_test_http']     = array();
		$GLOBALS['wso_test_requests'] = array();
		$GLOBALS['wso_test_bodies']   = array();
	}

	/**
	 * Queues one JSON answer.
	 *
	 * @param int                  $status HTTP status.
	 * @param array<string, mixed> $body   Decoded body.
	 */
	private static function respond( int $status, array $body ): void {
		$GLOBALS['wso_test_http'][] = array(
			'response' => array( 'code' => $status ),
			'body'     => (string) json_encode( $body ),
		);
	}

	/**
	 * Queues a successful classification.
	 *
	 * @param string $json Model answer.
	 */
	private static function respondWithContent( string $json ): void {
		self::respond(
			200,
			array(
				'choices' => array(
					array( 'message' => array( 'content' => $json ) ),
				),
			)
		);
	}

	/**
	 * Queues a provider complaint.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $message Provider message.
	 */
	private static function respondWithError( int $status, string $message ): void {
		self::respond( $status, array( 'error' => array( 'message' => $message ) ) );
	}

	/**
	 * Last request body, decoded.
	 *
	 * @return array<string, mixed>
	 */
	private static function lastBody(): array {
		$bodies = $GLOBALS['wso_test_bodies'];
		$body   = json_decode( (string) end( $bodies ), true );

		return is_array( $body ) ? $body : array();
	}

	/**
	 * The request goes to the configured base URL, not to a hardcoded host.
	 */
	public function testRequestsTheConfiguredBaseUrl(): void {
		self::respondWithContent( '{"urgent": true, "note": "After 18:00"}' );

		( new OpenAiCompatibleClassifier( 'k', 'any-model', self::BASE ) )->classify( 'Delivery after 18:00' );

		self::assertSame( self::BASE . '/chat/completions', $GLOBALS['wso_test_requests'][0] );
	}

	/**
	 * The model name is passed through untouched.
	 */
	public function testForwardsAnyModelName(): void {
		self::respondWithContent( '{"urgent": false, "note": ""}' );

		( new OpenAiCompatibleClassifier( 'k', 'llama3.2:3b', self::BASE ) )->classify( 'Hello' );

		self::assertSame( 'llama3.2:3b', self::lastBody()['model'] );
	}

	/**
	 * A strict JSON answer becomes an urgent result.
	 */
	public function testParsesAnUrgentAnswer(): void {
		self::respondWithContent( '{"urgent": true, "note": "Call an hour ahead"}' );

		$result = ( new OpenAiCompatibleClassifier( 'k', 'm', self::BASE ) )->classify( 'Call first' );

		self::assertTrue( $result->available );
		self::assertTrue( $result->urgent );
		self::assertSame( 'Call an hour ahead', $result->note );
	}

	/**
	 * A missing key never leaves the site.
	 */
	public function testNoKeyNeverCallsTheProvider(): void {
		$result = ( new OpenAiCompatibleClassifier( '', 'm', self::BASE ) )->classify( 'Hi' );

		self::assertFalse( $result->available );
		self::assertSame( array(), $GLOBALS['wso_test_requests'] );
	}

	/**
	 * Some providers refuse `response_format`; the request must be retried without it
	 * rather than losing the notification.
	 */
	public function testRetriesWithoutResponseFormatWhenRejected(): void {
		self::respondWithError( 400, "Unsupported parameter: 'response_format' is not supported with this model." );
		self::respondWithContent( '{"urgent": true, "note": "x"}' );

		$result = ( new OpenAiCompatibleClassifier( 'k', 'm', self::BASE ) )->classify( 'Hi' );

		self::assertTrue( $result->available );
		self::assertCount( 2, $GLOBALS['wso_test_requests'] );
		self::assertArrayNotHasKey( 'response_format', self::lastBody() );
	}

	/**
	 * Reasoning models refuse a fixed temperature.
	 */
	public function testRetriesWithoutTemperatureWhenRejected(): void {
		self::respondWithError( 400, "Unsupported value: 'temperature' does not support 0 with this model." );
		self::respondWithContent( '{"urgent": false, "note": ""}' );

		$result = ( new OpenAiCompatibleClassifier( 'k', 'm', self::BASE ) )->classify( 'Hi' );

		self::assertTrue( $result->available );
		self::assertArrayNotHasKey( 'temperature', self::lastBody() );
	}

	/**
	 * Both optional parameters can be dropped, but the ladder is finite.
	 */
	public function testDropsBothParametersAtMostOnce(): void {
		self::respondWithError( 400, "'response_format' unsupported" );
		self::respondWithError( 400, "'temperature' unsupported" );
		self::respondWithContent( '{"urgent": false, "note": ""}' );

		$result = ( new OpenAiCompatibleClassifier( 'k', 'm', self::BASE ) )->classify( 'Hi' );

		self::assertTrue( $result->available );
		self::assertCount( 3, $GLOBALS['wso_test_requests'] );
		self::assertSame( array( 'model', 'messages' ), array_keys( self::lastBody() ) );
	}

	/**
	 * A real rejection is reported, not retried forever.
	 */
	public function testAuthFailureIsReportedAsUnavailable(): void {
		self::respondWithError( 401, 'Incorrect API key provided' );

		$result = ( new OpenAiCompatibleClassifier( 'k', 'm', self::BASE ) )->classify( 'Hi' );

		self::assertFalse( $result->available );
		self::assertSame( 'Incorrect API key provided', $result->error );
		self::assertCount( 1, $GLOBALS['wso_test_requests'] );
	}
}
