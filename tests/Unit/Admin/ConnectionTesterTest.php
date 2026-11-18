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
use WSO\SmartOrder\Config\Settings;

/**
 * Pins what the admin sees for each kind of answer a provider can give.
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
	 * Stores plugin settings.
	 *
	 * @param array<string, mixed> $values Settings.
	 */
	private static function configure( array $values ): void {
		$GLOBALS['wso_test_options'][ Settings::OPTION ] = $values;
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
		self::configure( array( 'provider' => Settings::PROVIDER_OPENAI ) );

		$result = ( new ConnectionTester() )->checkAi();

		self::assertFalse( $result->ok );
		self::assertStringContainsString( 'No API key', $result->message );
		self::assertSame( array(), self::requests() );
	}

	/**
	 * A stored key reaches OpenAI and a 200 is a pass.
	 */
	public function testAiAcceptsAWorkingKey(): void {
		self::configure(
			array(
				'provider' => Settings::PROVIDER_OPENAI,
				'api_key'  => 'sk-test',
			)
		);
		self::respond( 200, array( 'data' => array( array( 'id' => 'gpt-4o-mini' ) ) ) );

		$result = ( new ConnectionTester() )->checkAi();

		self::assertTrue( $result->ok, $result->message );
		self::assertStringContainsString( 'OpenAI', $result->message );
		self::assertCount( 1, self::requests() );
		self::assertStringContainsString( 'api.openai.com', self::requests()[0] );
	}

	/**
	 * A key typed into the form wins over the stored one.
	 */
	public function testAnUnsavedKeyIsUsedForTheCheck(): void {
		self::configure(
			array(
				'provider' => Settings::PROVIDER_OPENAI,
				'api_key'  => 'sk-stored',
			)
		);
		self::respond( 200, array( 'data' => array() ) );

		$result = ( new ConnectionTester() )->checkAi( 'sk-typed' );

		self::assertTrue( $result->ok, $result->message );
	}

	/**
	 * The provider's own wording is what the admin needs to see.
	 */
	public function testProviderErrorMessageIsSurfaced(): void {
		self::configure(
			array(
				'provider' => Settings::PROVIDER_OPENAI,
				'api_key'  => 'sk-revoked',
			)
		);
		self::respond(
			401,
			array( 'error' => array( 'message' => 'Incorrect API key provided: sk-revoked.' ) )
		);

		$result = ( new ConnectionTester() )->checkAi();

		self::assertFalse( $result->ok );
		self::assertStringContainsString( 'Incorrect API key provided', $result->message );
	}

	/**
	 * The Anthropic branch must not call the OpenAI endpoint.
	 */
	public function testAnthropicUsesItsOwnEndpoint(): void {
		self::configure(
			array(
				'provider' => Settings::PROVIDER_ANTHROPIC,
				'api_key'  => 'sk-ant-test',
			)
		);
		self::respond( 200, array( 'data' => array() ) );

		$result = ( new ConnectionTester() )->checkAi();

		self::assertTrue( $result->ok, $result->message );
		self::assertStringContainsString( 'anthropic.com', self::requests()[0] );
	}

	/**
	 * A transport failure is a failure, not a silently empty success.
	 */
	public function testTransportErrorIsReported(): void {
		self::configure(
			array(
				'provider' => Settings::PROVIDER_OPENAI,
				'api_key'  => 'sk-test',
			)
		);
		self::respondWithError( 'cURL error 7: Failed to connect' );

		$result = ( new ConnectionTester() )->checkAi();

		self::assertFalse( $result->ok );
		self::assertStringContainsString( 'curl', strtolower( $result->message ) );
	}

	/**
	 * Telegram without a token never leaves the site.
	 */
	public function testTelegramWithoutTokenNeverCallsTheApi(): void {
		$result = ( new ConnectionTester() )->checkTelegram();

		self::assertFalse( $result->ok );
		self::assertStringContainsString( 'No bot token', $result->message );
		self::assertSame( array(), self::requests() );
	}

	/**
	 * A rejected token reports Telegram's description.
	 */
	public function testTelegramRejectsABadToken(): void {
		self::configure( array( 'telegram_token' => '123:bad' ) );
		self::respond(
			401,
			array(
				'ok'          => false,
				'description' => 'Unauthorized',
			)
		);

		$result = ( new ConnectionTester() )->checkTelegram();

		self::assertFalse( $result->ok );
		self::assertStringContainsString( 'Unauthorized', $result->message );
	}

	/**
	 * A valid token with no group id stops after the identity call.
	 */
	public function testTelegramTokenOnlyIdentifiesTheBot(): void {
		self::configure( array( 'telegram_token' => '123:good' ) );
		self::respond(
			200,
			array(
				'ok'     => true,
				'result' => array( 'username' => 'wso_orders_bot' ),
			)
		);

		$result = ( new ConnectionTester() )->checkTelegram();

		self::assertTrue( $result->ok, $result->message );
		self::assertStringContainsString( 'wso_orders_bot', $result->message );
		self::assertCount( 1, self::requests() );
	}

	/**
	 * Token plus group id checks the group and names it.
	 */
	public function testTelegramConfirmsTheGroupIsReachable(): void {
		self::configure(
			array(
				'telegram_token' => '123:good',
				'telegram_chat'  => '-1001234567890',
			)
		);
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

		$result = ( new ConnectionTester() )->checkTelegram();

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
		self::configure(
			array(
				'telegram_token' => '123:good',
				'telegram_chat'  => '-100999',
			)
		);
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

		$result = ( new ConnectionTester() )->checkTelegram();

		self::assertFalse( $result->ok );
		self::assertStringContainsString( 'chat not found', $result->message );
	}
}
