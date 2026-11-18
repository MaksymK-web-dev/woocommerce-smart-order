<?php
/**
 * Connection-tester tests.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use WSO\SmartOrder\Admin\ConnectionTester;
use WSO\SmartOrder\Config\Draft;
use WSO\SmartOrder\Config\Settings;

/**
 * Pins what the admin sees for each kind of answer a provider can give.
 *
 * Every case builds a draft, which is what the screen submits: the tester must never
 * fall back to the stored configuration on its own.
 */
final class ConnectionTesterTest extends TestCase {

	/**
	 * Clears the option store and the HTTP queue.
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['wso_test_options']  = array();
		$GLOBALS['wso_test_http']     = array();
		$GLOBALS['wso_test_requests'] = array();
	}

	/**
	 * Builds the draft a submitted form would produce.
	 *
	 * @param array<string, string> $input Form values.
	 */
	private static function draft( array $input ): Draft {
		return Draft::fromInput( $input );
	}

	/**
	 * Queues one HTTP response.
	 *
	 * @param int                  $status HTTP status.
	 * @param array<string, mixed> $body   Decoded body.
	 */
	private static function respond( int $status, array $body = array() ): void {
		$GLOBALS['wso_test_http'][] = array(
			'response' => array( 'code' => $status ),
			'body'     => (string) json_encode( $body ),
		);
	}

	/**
	 * Queues a transport failure.
	 *
	 * @param string $message Error message.
	 */
	private static function respondWithError( string $message ): void {
		$GLOBALS['wso_test_http'][] = new \WP_Error( 'http_request_failed', $message );
	}

	/**
	 * URLs requested so far.
	 *
	 * @return string[]
	 */
	private static function requests(): array {
		return $GLOBALS['wso_test_requests'];
	}

	/**
	 * A missing key must be reported without touching the network.
	 */
	public function testAiWithoutKeyNeverCallsTheProvider(): void {
		$result = ( new ConnectionTester() )->checkAi( self::draft( array( 'provider' => Settings::PROVIDER_OPENAI ) ) );

		self::assertFalse( $result->ok );
		self::assertStringContainsString( 'No API key', $result->message );
		self::assertSame( array(), self::requests() );
	}

	/**
	 * A typed key is used for the check, before anything is saved.
	 */
	public function testTypedKeyIsCheckedBeforeSaving(): void {
		self::respond( 200, array( 'data' => array() ) );

		$result = ( new ConnectionTester() )->checkAi(
			self::draft(
				array(
					'provider' => Settings::PROVIDER_OPENAI,
					'api_key'  => 'sk-typed',
				)
			)
		);

		self::assertTrue( $result->ok, $result->message );
		self::assertStringContainsString( 'api.openai.com', self::requests()[0] );
		self::assertSame( array(), $GLOBALS['wso_test_options'], 'the check must not write the option' );
	}

	/**
	 * The provider chosen in the form decides the endpoint, not the stored one.
	 */
	public function testProviderFromTheFormDecidesTheEndpoint(): void {
		$GLOBALS['wso_test_options'][ Settings::OPTION ] = array( 'provider' => Settings::PROVIDER_OPENAI );
		self::respond( 200, array( 'data' => array() ) );

		$result = ( new ConnectionTester() )->checkAi(
			self::draft(
				array(
					'provider' => Settings::PROVIDER_GEMINI,
					'api_key'  => 'AIzaSyTEST',
				)
			)
		);

		self::assertTrue( $result->ok, $result->message );
		self::assertStringContainsString( 'generativelanguage.googleapis.com', self::requests()[0] );
	}

	/**
	 * A Google key with the OpenAI provider is named as such, without a wasted request.
	 */
	public function testKeyBelongingToAnotherServiceIsNamed(): void {
		$result = ( new ConnectionTester() )->checkAi(
			self::draft(
				array(
					'provider' => Settings::PROVIDER_OPENAI,
					'api_key'  => 'AIza' . 'SyTEST',
				)
			)
		);

		self::assertFalse( $result->ok );
		self::assertStringContainsString( 'Google Gemini', $result->message );
		self::assertStringContainsString( 'OpenAI', $result->message );
		self::assertSame( array(), self::requests(), 'a mismatch must not reach either API' );
	}

	/**
	 * The matching key is sent to its own provider.
	 */
	public function testMatchingKeyIsAccepted(): void {
		self::respond( 200, array( 'data' => array() ) );

		$result = ( new ConnectionTester() )->checkAi(
			self::draft(
				array(
					'provider' => Settings::PROVIDER_GEMINI,
					'api_key'  => 'AIzaSyTEST',
				)
			)
		);

		self::assertTrue( $result->ok, $result->message );
		self::assertStringContainsString( 'Google Gemini', $result->message );
	}

	/**
	 * The Anthropic prefix must not be mistaken for an OpenAI one.
	 */
	public function testAnthropicKeyIsNotReadAsOpenAi(): void {
		self::respond( 200, array( 'data' => array() ) );

		$result = ( new ConnectionTester() )->checkAi(
			self::draft(
				array(
					'provider' => Settings::PROVIDER_ANTHROPIC,
					'api_key'  => 'sk-' . 'ant-' . 'api03TEST',
				)
			)
		);

		self::assertTrue( $result->ok, $result->message );
		self::assertStringContainsString( 'anthropic.com', self::requests()[0] );
	}

	/**
	 * A custom endpoint may hold any key format, so nothing is inferred.
	 */
	public function testCustomEndpointAcceptsAnyKeyFormat(): void {
		self::respond( 200, array( 'data' => array() ) );

		$result = ( new ConnectionTester() )->checkAi(
			self::draft(
				array(
					'provider' => Settings::PROVIDER_CUSTOM,
					'base_url' => 'https://openrouter.ai/api/v1',
					'api_key'  => 'sk-' . 'or-v1-TEST',
				)
			)
		);

		self::assertTrue( $result->ok, $result->message );
		self::assertStringContainsString( 'openrouter.ai', self::requests()[0] );
	}

	/**
	 * A custom provider without a URL cannot be checked, and says why.
	 */
	public function testCustomEndpointWithoutUrlIsRefused(): void {
		$result = ( new ConnectionTester() )->checkAi(
			self::draft(
				array(
					'provider' => Settings::PROVIDER_CUSTOM,
					'base_url' => '',
					'api_key'  => 'sk-' . 'TEST',
				)
			)
		);

		self::assertFalse( $result->ok );
		self::assertStringContainsString( 'needs a base URL', $result->message );
		self::assertSame( array(), self::requests() );
	}

	/**
	 * The provider's own wording is what the admin needs to see.
	 */
	public function testProviderErrorMessageIsSurfaced(): void {
		self::respond(
			401,
			array( 'error' => array( 'message' => 'Incorrect API key provided.' ) )
		);

		$result = ( new ConnectionTester() )->checkAi(
			self::draft(
				array(
					'provider' => Settings::PROVIDER_OPENAI,
					'api_key'  => 'sk-bad',
				)
			)
		);

		self::assertFalse( $result->ok );
		self::assertStringContainsString( 'Incorrect API key provided', $result->message );
	}

	/**
	 * A wrong model is named, because "HTTP 404" says nothing actionable.
	 */
	public function testUnknownModelIsNamed(): void {
		// A missing listing falls through to a one-token probe; that probe is what
		// reports the model name.
		self::respond( 404, array( 'error' => array( 'message' => 'model not found' ) ) );
		self::respond( 404, array( 'error' => array( 'message' => 'model not found' ) ) );

		$result = ( new ConnectionTester() )->checkAi(
			self::draft(
				array(
					'provider' => Settings::PROVIDER_GEMINI,
					'api_key'  => 'AIzaSyTEST',
					'model'    => 'gemini-9.9-imaginary',
				)
			)
		);

		self::assertFalse( $result->ok );
		self::assertStringContainsString( 'gemini-9.9-imaginary', $result->message );
	}

	/**
	 * A transport failure is a failure, not a silently empty success.
	 */
	public function testTransportErrorIsReported(): void {
		self::respondWithError( 'cURL error 7: Failed to connect' );

		$result = ( new ConnectionTester() )->checkAi(
			self::draft(
				array(
					'provider' => Settings::PROVIDER_OPENAI,
					'api_key'  => 'sk-test',
				)
			)
		);

		self::assertFalse( $result->ok );
		self::assertStringContainsString( 'curl', strtolower( $result->message ) );
	}

	/**
	 * Telegram without a token never leaves the site.
	 */
	public function testTelegramWithoutTokenNeverCallsTheApi(): void {
		$result = ( new ConnectionTester() )->checkTelegram( self::draft( array() ) );

		self::assertFalse( $result->ok );
		self::assertStringContainsString( 'No bot token', $result->message );
		self::assertSame( array(), self::requests() );
	}

	/**
	 * A rejected token reports Telegram's description.
	 */
	public function testTelegramRejectsABadToken(): void {
		self::respond(
			401,
			array(
				'ok'          => false,
				'description' => 'Unauthorized',
			)
		);

		$result = ( new ConnectionTester() )->checkTelegram( self::draft( array( 'telegram_token' => '123:bad' ) ) );

		self::assertFalse( $result->ok );
		self::assertStringContainsString( 'Unauthorized', $result->message );
	}

	/**
	 * A valid token with no group id stops after the identity call.
	 */
	public function testTelegramTokenOnlyIdentifiesTheBot(): void {
		self::respond(
			200,
			array(
				'ok'     => true,
				'result' => array( 'username' => 'wso_orders_bot' ),
			)
		);

		$result = ( new ConnectionTester() )->checkTelegram( self::draft( array( 'telegram_token' => '123:good' ) ) );

		self::assertTrue( $result->ok, $result->message );
		self::assertStringContainsString( 'wso_orders_bot', $result->message );
		self::assertCount( 1, self::requests() );
	}

	/**
	 * Token plus group id checks the group and names it.
	 */
	public function testTelegramConfirmsTheGroupIsReachable(): void {
		self::respond(
			200,
			array(
				'ok'     => true,
				'result' => array( 'username' => 'wso_orders_bot' ),
			)
		);
		self::respond(
			200,
			array(
				'ok'     => true,
				'result' => array( 'title' => 'Orders' ),
			)
		);

		$result = ( new ConnectionTester() )->checkTelegram(
			self::draft(
				array(
					'telegram_token' => '123:good',
					'telegram_chat'  => '-1001234567890',
				)
			)
		);

		self::assertTrue( $result->ok, $result->message );
		self::assertStringContainsString( 'Orders', $result->message );
		self::assertCount( 2, self::requests() );
		self::assertStringContainsString( 'getChat', self::requests()[1] );
		self::assertStringContainsString( '-1001234567890', self::requests()[1] );
	}

	/**
	 * A token that works but a group the bot cannot see is a distinct failure.
	 */
	public function testTelegramReportsAnUnreachableGroup(): void {
		self::respond(
			200,
			array(
				'ok'     => true,
				'result' => array( 'username' => 'wso_orders_bot' ),
			)
		);
		self::respond(
			400,
			array(
				'ok'          => false,
				'description' => 'Bad Request: chat not found',
			)
		);

		$result = ( new ConnectionTester() )->checkTelegram(
			self::draft(
				array(
					'telegram_token' => '123:good',
					'telegram_chat'  => '-100999',
				)
			)
		);

		self::assertFalse( $result->ok );
		self::assertStringContainsString( 'chat not found', $result->message );
	}

	/**
	 * A group id typed into the form is checked without saving it first.
	 */
	public function testChatIdFromTheFormIsUsed(): void {
		self::respond(
			200,
			array(
				'ok'     => true,
				'result' => array( 'username' => 'wso_orders_bot' ),
			)
		);
		self::respond(
			200,
			array(
				'ok'     => true,
				'result' => array( 'title' => 'Typed Group' ),
			)
		);

		$result = ( new ConnectionTester() )->checkTelegram(
			self::draft(
				array(
					'telegram_token' => '123:good',
					'telegram_chat'  => '-100777',
				)
			)
		);

		self::assertTrue( $result->ok, $result->message );
		self::assertStringContainsString( '-100777', self::requests()[1] );
		self::assertSame( array(), $GLOBALS['wso_test_options'], 'the check must not write the option' );
	}
}
