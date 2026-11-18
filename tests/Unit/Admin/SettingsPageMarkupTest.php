<?php
/**
 * Settings screen markup tests.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WSO\SmartOrder\Admin\ConnectionTester;
use WSO\SmartOrder\Admin\SettingsPage;
use WSO\SmartOrder\Config\Settings;

/**
 * Pins what the screen prints around each control.
 *
 * A stored secret must never be echoed back, and the AI key summary must not appear
 * under the bot token field.
 */
final class SettingsPageMarkupTest extends TestCase {

	/**
	 * Clears the option store.
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['wso_test_options'] = array();
	}

	/**
	 * Renders one control through the private builder.
	 *
	 * @param string $key  Option key.
	 * @param string $type Renderer type.
	 */
	private static function control( string $key, string $type ): string {
		$page   = new SettingsPage( new ConnectionTester() );
		$method = new ReflectionMethod( $page, 'buildControl' );
		$method->setAccessible( true );

		return (string) $method->invoke( $page, $key, $type, '' );
	}

	/**
	 * A stored secret is never printed back into the field.
	 */
	public function testStoredSecretsAreNotRendered(): void {
		$GLOBALS['wso_test_options'][ Settings::OPTION ] = array(
			'api_keys'       => array( Settings::PROVIDER_OPENAI => 'sk-' . 'SECRETVALUE' ),
			'telegram_token' => '123:SECRETBOTTOKEN',
		);

		self::assertStringNotContainsString( 'SECRETVALUE', self::control( 'api_key', 'password' ) );
		self::assertStringNotContainsString( 'SECRETBOTTOKEN', self::control( 'telegram_token', 'password' ) );
	}

	/**
	 * Both secret fields render as password inputs, so a paste is not shoulder-readable.
	 */
	public function testSecretsRenderAsPasswordInputs(): void {
		self::assertStringContainsString( 'type="password"', self::control( 'api_key', 'password' ) );
		self::assertStringContainsString( 'type="password"', self::control( 'telegram_token', 'password' ) );
	}

	/**
	 * The AI key field says which providers already hold a key.
	 */
	public function testAiFieldListsProviderKeys(): void {
		$markup = self::control( 'api_key', 'password' );

		self::assertStringContainsString( 'OpenAI:', $markup );
		self::assertStringContainsString( 'Google Gemini:', $markup );
	}

	/**
	 * The bot token field must not repeat the AI key summary.
	 */
	public function testBotTokenFieldDoesNotShowAiKeyStatus(): void {
		$markup = self::control( 'telegram_token', 'password' );

		self::assertStringNotContainsString( 'OpenAI:', $markup );
		self::assertStringNotContainsString( 'Google Gemini:', $markup );
	}

	/**
	 * Only the AI key offers the "remove stored key" box.
	 */
	public function testOnlyTheAiFieldOffersClearing(): void {
		self::assertStringContainsString( 'api_key_clear', self::control( 'api_key', 'password' ) );
		self::assertStringNotContainsString( 'api_key_clear', self::control( 'telegram_token', 'password' ) );
	}

	/**
	 * Each secret field carries its own live-check button.
	 */
	public function testEachSecretFieldHasACheckButton(): void {
		self::assertStringContainsString( 'data-target="ai"', self::control( 'api_key', 'password' ) );
		self::assertStringContainsString( 'data-target="telegram"', self::control( 'telegram_token', 'password' ) );
	}
}
