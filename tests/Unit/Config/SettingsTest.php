<?php
/**
 * Settings source tests.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use WSO\SmartOrder\Config\Settings;

/**
 * Pins the "where does this secret come from" answers the admin screen prints.
 */
final class SettingsTest extends TestCase {

	/**
	 * Clears the option store.
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['wso_test_options'] = array();
	}

	/**
	 * Nothing stored and no constant: both secrets are unset.
	 */
	public function testSecretsAreUnsetByDefault(): void {
		self::assertSame( Settings::SOURCE_NONE, Settings::apiKeySource() );
		self::assertSame( Settings::SOURCE_NONE, Settings::telegramTokenSource() );
		self::assertSame( '', Settings::apiKey() );
		self::assertSame( '', Settings::telegramToken() );
	}

	/**
	 * A value saved through the settings screen is reported as stored.
	 */
	public function testStoredSecretsAreReportedAsStored(): void {
		$GLOBALS['wso_test_options'][ Settings::OPTION ] = array(
			'api_key'        => 'sk-stored',
			'telegram_token' => '123:stored',
		);

		self::assertSame( Settings::SOURCE_STORED, Settings::apiKeySource() );
		self::assertSame( Settings::SOURCE_STORED, Settings::telegramTokenSource() );
		self::assertSame( 'sk-stored', Settings::apiKey() );
		self::assertSame( '123:stored', Settings::telegramToken() );
	}

	/**
	 * Whitespace is not a credential.
	 */
	public function testBlankSecretsCountAsUnset(): void {
		$GLOBALS['wso_test_options'][ Settings::OPTION ] = array(
			'api_key'        => '   ',
			'telegram_token' => '   ',
		);

		self::assertSame( Settings::SOURCE_NONE, Settings::apiKeySource() );
		self::assertSame( Settings::SOURCE_NONE, Settings::telegramTokenSource() );
	}

	/**
	 * The constant name follows the selected provider.
	 */
	public function testApiKeyConstantFollowsTheProvider(): void {
		$GLOBALS['wso_test_options'][ Settings::OPTION ] = array( 'provider' => Settings::PROVIDER_OPENAI );
		self::assertSame( 'WSO_OPENAI_API_KEY', Settings::apiKeyConstant() );

		$GLOBALS['wso_test_options'][ Settings::OPTION ] = array( 'provider' => Settings::PROVIDER_ANTHROPIC );
		self::assertSame( 'WSO_ANTHROPIC_API_KEY', Settings::apiKeyConstant() );
	}

	/**
	 * An unknown provider falls back to OpenAI instead of breaking the pipeline.
	 */
	public function testUnknownProviderFallsBackToOpenAi(): void {
		$GLOBALS['wso_test_options'][ Settings::OPTION ] = array( 'provider' => 'gemini' );

		self::assertSame( Settings::PROVIDER_OPENAI, Settings::provider() );
	}
}
