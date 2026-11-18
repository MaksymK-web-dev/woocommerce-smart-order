<?php
/**
 * Settings tests.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use WSO\SmartOrder\Config\Settings;

/**
 * Pins provider resolution and the per-provider key store.
 *
 * One shared key is what made a provider switch send the previous service's key to
 * the new one; these tests keep that from coming back.
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
	 * Stores an option value.
	 *
	 * @param array<string, mixed> $value Option value.
	 */
	private static function store( array $value ): void {
		$GLOBALS['wso_test_options'][ Settings::OPTION ] = $value;
	}

	/**
	 * Nothing stored and no constant: every secret is unset.
	 */
	public function testSecretsAreUnsetByDefault(): void {
		self::assertSame( Settings::SOURCE_NONE, Settings::apiKeySource() );
		self::assertSame( Settings::SOURCE_NONE, Settings::telegramTokenSource() );
		self::assertSame( '', Settings::apiKey() );
		self::assertSame( '', Settings::telegramToken() );
	}

	/**
	 * Each provider's key is stored and read separately.
	 */
	public function testKeysAreStoredPerProvider(): void {
		self::store(
			array(
				'provider' => Settings::PROVIDER_GEMINI,
				'api_keys' => array(
					Settings::PROVIDER_OPENAI => 'sk-openai',
					Settings::PROVIDER_GEMINI => 'AIza-gemini',
				),
			)
		);

		self::assertSame( 'AIza-gemini', Settings::apiKey() );
		self::assertSame( 'sk-openai', Settings::apiKeyFor( Settings::PROVIDER_OPENAI ) );
		self::assertSame( 'AIza-gemini', Settings::apiKeyFor( Settings::PROVIDER_GEMINI ) );
		self::assertSame( '', Settings::apiKeyFor( Settings::PROVIDER_ANTHROPIC ) );
	}

	/**
	 * Switching the provider must not hand it the previous provider's key.
	 */
	public function testSwitchingProviderDoesNotReuseAnotherKey(): void {
		self::store(
			array(
				'provider' => Settings::PROVIDER_GEMINI,
				'api_keys' => array( Settings::PROVIDER_OPENAI => 'sk-openai' ),
			)
		);

		self::assertSame( '', Settings::apiKey() );
		self::assertSame( Settings::SOURCE_NONE, Settings::apiKeySource() );
	}

	/**
	 * A site upgrading from the single-key version keeps its key working.
	 */
	public function testLegacySingleKeyIsReadForItsOwnProvider(): void {
		self::store(
			array(
				'provider' => Settings::PROVIDER_ANTHROPIC,
				'api_key'  => 'sk-ant-legacy',
			)
		);

		self::assertSame( 'sk-ant-legacy', Settings::apiKey() );
		self::assertSame( Settings::SOURCE_STORED, Settings::apiKeySource() );
	}

	/**
	 * The legacy key is not offered to a provider it never belonged to.
	 */
	public function testLegacyKeyDoesNotLeakToAnotherProvider(): void {
		self::store(
			array(
				'provider' => Settings::PROVIDER_OPENAI,
				'api_key'  => 'sk-legacy',
			)
		);

		self::assertSame( 'sk-legacy', Settings::apiKey() );
		self::assertSame( '', Settings::apiKeyFor( Settings::PROVIDER_GEMINI ) );
	}

	/**
	 * Once the map exists, the legacy field is ignored.
	 */
	public function testStoredMapWinsOverTheLegacyField(): void {
		self::store(
			array(
				'provider' => Settings::PROVIDER_OPENAI,
				'api_key'  => 'sk-legacy',
				'api_keys' => array( Settings::PROVIDER_OPENAI => 'sk-new' ),
			)
		);

		self::assertSame( 'sk-new', Settings::apiKey() );
	}

	/**
	 * Whitespace is not a credential.
	 */
	public function testBlankSecretsCountAsUnset(): void {
		self::store(
			array(
				'api_keys'       => array( Settings::PROVIDER_OPENAI => '   ' ),
				'telegram_token' => '   ',
			)
		);

		self::assertSame( Settings::SOURCE_NONE, Settings::apiKeySource() );
		self::assertSame( Settings::SOURCE_NONE, Settings::telegramTokenSource() );
	}

	/**
	 * A value saved through the settings screen is reported as stored.
	 */
	public function testStoredSecretsAreReportedAsStored(): void {
		self::store(
			array(
				'api_keys'       => array( Settings::PROVIDER_OPENAI => 'sk-stored' ),
				'telegram_token' => '123:stored',
			)
		);

		self::assertSame( Settings::SOURCE_STORED, Settings::apiKeySource() );
		self::assertSame( Settings::SOURCE_STORED, Settings::telegramTokenSource() );
		self::assertSame( '123:stored', Settings::telegramToken() );
	}

	/**
	 * The constant name follows the provider.
	 */
	public function testApiKeyConstantFollowsTheProvider(): void {
		self::store( array( 'provider' => Settings::PROVIDER_OPENAI ) );
		self::assertSame( 'WSO_OPENAI_API_KEY', Settings::apiKeyConstant() );

		self::store( array( 'provider' => Settings::PROVIDER_ANTHROPIC ) );
		self::assertSame( 'WSO_ANTHROPIC_API_KEY', Settings::apiKeyConstant() );

		self::store( array( 'provider' => Settings::PROVIDER_GEMINI ) );
		self::assertSame( 'WSO_AI_API_KEY', Settings::apiKeyConstant() );
	}

	/**
	 * An unknown provider falls back to OpenAI instead of breaking the pipeline.
	 */
	public function testUnknownProviderFallsBackToOpenAi(): void {
		self::store( array( 'provider' => 'cohere' ) );

		self::assertSame( Settings::PROVIDER_OPENAI, Settings::provider() );
		self::assertSame( 'https://api.openai.com/v1', Settings::baseUrl() );
	}

	/**
	 * Every supported provider resolves to its own endpoint and model.
	 */
	public function testEachProviderHasItsOwnEndpointAndModel(): void {
		$expected = array(
			Settings::PROVIDER_OPENAI    => array( 'https://api.openai.com/v1', 'gpt-4o-mini' ),
			Settings::PROVIDER_ANTHROPIC => array( 'https://api.anthropic.com/v1', 'claude-3-5-haiku-latest' ),
			Settings::PROVIDER_GEMINI    => array( 'https://generativelanguage.googleapis.com/v1beta/openai', 'gemini-2.5-flash' ),
		);

		foreach ( $expected as $provider => $pair ) {
			self::store( array( 'provider' => $provider ) );

			self::assertSame( $pair[0], Settings::baseUrl(), $provider . ' base URL' );
			self::assertSame( $pair[1], Settings::model(), $provider . ' model' );
		}
	}

	/**
	 * A stored custom URL is used only by the custom provider.
	 */
	public function testCustomProviderUsesTheStoredBaseUrl(): void {
		self::store(
			array(
				'provider' => Settings::PROVIDER_CUSTOM,
				'base_url' => 'https://openrouter.ai/api/v1',
			)
		);

		self::assertSame( 'https://openrouter.ai/api/v1', Settings::baseUrl() );

		self::store(
			array(
				'provider' => Settings::PROVIDER_GEMINI,
				'base_url' => 'https://openrouter.ai/api/v1',
			)
		);

		self::assertSame(
			'https://generativelanguage.googleapis.com/v1beta/openai',
			Settings::baseUrl(),
			'a stored custom URL must not override a built-in provider'
		);
	}

	/**
	 * A custom provider with no URL falls back to the OpenAI endpoint, and Draft flags it.
	 */
	public function testCustomProviderWithoutUrlFallsBackToOpenAi(): void {
		self::store( array( 'provider' => Settings::PROVIDER_CUSTOM ) );

		self::assertSame( 'https://api.openai.com/v1', Settings::baseUrl() );
	}

	/**
	 * A model name carried over from another provider would 404, so it is replaced.
	 */
	public function testAForeignModelNameFallsBackToTheProviderDefault(): void {
		self::store(
			array(
				'provider' => Settings::PROVIDER_GEMINI,
				'model'    => 'gpt-4o-mini',
			)
		);

		self::assertSame( 'gemini-2.5-flash', Settings::model() );
		self::assertSame( 'gemini-2.5-flash', Settings::modelFor( Settings::PROVIDER_GEMINI, 'gpt-4o-mini' ) );
	}

	/**
	 * A name that is not any provider's default is passed through untouched.
	 */
	public function testACustomModelNameIsKept(): void {
		self::store(
			array(
				'provider' => Settings::PROVIDER_CUSTOM,
				'model'    => 'llama3.2:3b',
			)
		);

		self::assertSame( 'llama3.2:3b', Settings::model() );
	}

	/**
	 * Anthropic is the only provider on its own protocol.
	 */
	public function testOnlyAnthropicUsesItsNativeProtocol(): void {
		self::assertTrue( Settings::usesAnthropicProtocolFor( Settings::PROVIDER_ANTHROPIC ) );
		self::assertFalse( Settings::usesAnthropicProtocolFor( Settings::PROVIDER_GEMINI ) );
		self::assertFalse( Settings::usesAnthropicProtocolFor( Settings::PROVIDER_OPENAI ) );

		self::store( array( 'provider' => Settings::PROVIDER_ANTHROPIC ) );
		self::assertTrue( Settings::usesAnthropicProtocol() );
	}

	/**
	 * The provider list the screen offers matches the ones the code can serve.
	 */
	public function testProviderListMatchesKnownProviders(): void {
		$providers = Settings::providers();

		foreach ( $providers as $provider ) {
			self::assertTrue( Settings::isProvider( $provider ) );
			self::assertNotSame( '', Settings::defaultModelFor( $provider ), $provider . ' has no default model' );

			// The custom provider has no endpoint of its own: the admin supplies it.
			if ( Settings::PROVIDER_CUSTOM !== $provider ) {
				self::assertNotSame( '', Settings::defaultBaseUrl( $provider ), $provider . ' has no base URL' );
			}
		}

		self::assertFalse( Settings::isProvider( 'cohere' ) );
	}
}
