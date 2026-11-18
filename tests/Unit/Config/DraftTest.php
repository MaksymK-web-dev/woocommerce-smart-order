<?php
/**
 * Draft tests.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use WSO\SmartOrder\Config\Draft;
use WSO\SmartOrder\Config\Settings;

/**
 * Pins what a submitted form resolves to before it is saved.
 */
final class DraftTest extends TestCase {

	/**
	 * Clears the option store.
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['wso_test_options'] = array();
	}

	/**
	 * A typed key belongs to the provider selected in the same form.
	 */
	public function testTypedKeyPairsWithTheSelectedProvider(): void {
		$draft = Draft::fromInput(
			array(
				'provider' => Settings::PROVIDER_GEMINI,
				'api_key'  => 'AIzaSyTYPED',
				'model'    => 'gemini-2.5-flash',
			)
		);

		self::assertSame( Settings::PROVIDER_GEMINI, $draft->provider );
		self::assertSame( 'AIzaSyTYPED', $draft->apiKey );
		self::assertSame( 'https://generativelanguage.googleapis.com/v1beta/openai', $draft->baseUrl );
	}

	/**
	 * Empty form fields fall back to storage, exactly as Save would keep them.
	 */
	public function testEmptyFieldsFallBackToStorage(): void {
		$GLOBALS['wso_test_options'][ Settings::OPTION ] = array(
			'provider' => Settings::PROVIDER_ANTHROPIC,
			'api_keys' => array( Settings::PROVIDER_ANTHROPIC => 'sk-' . 'ant-' . 'SAMPLE' ),
			'model'    => 'claude-3-5-haiku-latest',
		);

		$draft = Draft::fromInput( array( 'provider' => Settings::PROVIDER_ANTHROPIC ) );

		self::assertSame( 'sk-' . 'ant-' . 'SAMPLE', $draft->apiKey );
		self::assertSame( 'claude-3-5-haiku-latest', $draft->model );
	}

	/**
	 * A provider with no stored key must not receive another provider's key.
	 */
	public function testNoKeyIsBorrowedFromAnotherProvider(): void {
		$GLOBALS['wso_test_options'][ Settings::OPTION ] = array(
			'provider' => Settings::PROVIDER_OPENAI,
			'api_keys' => array( Settings::PROVIDER_OPENAI => 'sk-openai' ),
		);

		$draft = Draft::fromInput( array( 'provider' => Settings::PROVIDER_GEMINI ) );

		self::assertSame( '', $draft->apiKey );
	}

	/**
	 * The stored snapshot describes the saved configuration.
	 */
	public function testFromStoredReadsTheOption(): void {
		$GLOBALS['wso_test_options'][ Settings::OPTION ] = array(
			'provider'       => Settings::PROVIDER_GEMINI,
			'api_keys'       => array( Settings::PROVIDER_GEMINI => 'AIzaSySTORED' ),
			'telegram_token' => '123:stored',
			'telegram_chat'  => '-100',
		);

		$draft = Draft::fromStored();

		self::assertSame( Settings::PROVIDER_GEMINI, $draft->provider );
		self::assertSame( 'AIzaSySTORED', $draft->apiKey );
		self::assertSame( '123:stored', $draft->telegramToken );
		self::assertSame( '-100', $draft->telegramChat );
	}

	/**
	 * A custom provider without a URL is reported, not silently pointed at OpenAI.
	 */
	public function testCustomEndpointWithoutUrlIsDetected(): void {
		$without = Draft::fromInput(
			array(
				'provider' => Settings::PROVIDER_CUSTOM,
				'base_url' => '',
			)
		);

		self::assertTrue( $without->customEndpointMissing() );

		$with = Draft::fromInput(
			array(
				'provider' => Settings::PROVIDER_CUSTOM,
				'base_url' => 'http://localhost:11434/v1',
			)
		);

		self::assertFalse( $with->customEndpointMissing() );
		self::assertSame( 'http://localhost:11434/v1', $with->baseUrl );
	}

	/**
	 * A model typed for one provider is kept as typed.
	 */
	public function testTypedModelIsKept(): void {
		$draft = Draft::fromInput(
			array(
				'provider' => Settings::PROVIDER_CUSTOM,
				'base_url' => 'https://openrouter.ai/api/v1',
				'model'    => 'deepseek/deepseek-chat',
			)
		);

		self::assertSame( 'deepseek/deepseek-chat', $draft->model );
	}

	/**
	 * A garbage provider from a tampered form falls back instead of breaking.
	 */
	public function testUnknownProviderFallsBack(): void {
		$draft = Draft::fromInput( array( 'provider' => 'not-a-provider' ) );

		self::assertSame( Settings::PROVIDER_OPENAI, $draft->provider );
	}

	/**
	 * A garbage key-shaped value in the URL field is not an endpoint.
	 */
	public function testCustomUrlThatIsNotAUrlIsRejected(): void {
		$draft = Draft::fromInput(
			array(
				'provider' => Settings::PROVIDER_CUSTOM,
				'base_url' => 'AIza' . 'SyPASTED',
			)
		);

		self::assertTrue( $draft->customEndpointMissing(), 'a pasted key must not become an endpoint' );
		self::assertStringNotContainsString( 'AIza', $draft->baseUrl );
	}

	/**
	 * The draft never writes to the option store.
	 */
	public function testDraftNeverWrites(): void {
		$GLOBALS['wso_test_options'][ Settings::OPTION ] = array( 'provider' => Settings::PROVIDER_OPENAI );
		$before = $GLOBALS['wso_test_options'];

		Draft::fromInput(
			array(
				'provider' => Settings::PROVIDER_GEMINI,
				'api_key'  => 'AIzaSyTYPED',
			)
		);

		self::assertSame( $before, $GLOBALS['wso_test_options'] );
	}
}
