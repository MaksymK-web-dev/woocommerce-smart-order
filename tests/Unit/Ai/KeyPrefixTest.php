<?php
/**
 * Key-prefix tests.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Tests\Unit\Ai;

use PHPUnit\Framework\TestCase;
use WSO\SmartOrder\Ai\KeyPrefix;
use WSO\SmartOrder\Config\Settings;

/**
 * Pins which service a key is recognised as belonging to.
 *
 * Sample values are assembled from fragments so no test file holds a string that
 * looks like a live credential.
 */
final class KeyPrefixTest extends TestCase {

	/**
	 * A key shaped like Google's.
	 */
	private const GEMINI_SAMPLE = 'AI' . 'zaSySAMPLE';

	/**
	 * A key shaped like Anthropic's.
	 */
	private const ANTHROPIC_SAMPLE = 'sk-' . 'ant-' . 'api03SAMPLE';

	/**
	 * A key shaped like OpenAI's.
	 */
	private const OPENAI_SAMPLE = 'sk-' . 'SAMPLE';

	/**
	 * Known prefixes map to their provider.
	 */
	public function testKnownPrefixesAreIdentified(): void {
		self::assertSame( Settings::PROVIDER_GEMINI, KeyPrefix::providerForKey( self::GEMINI_SAMPLE ) );
		self::assertSame( Settings::PROVIDER_OPENAI, KeyPrefix::providerForKey( self::OPENAI_SAMPLE ) );
		self::assertSame( Settings::PROVIDER_ANTHROPIC, KeyPrefix::providerForKey( self::ANTHROPIC_SAMPLE ) );
	}

	/**
	 * `sk-ant-` must not be read as the shorter `sk-`.
	 */
	public function testAnthropicPrefixWinsOverOpenAiPrefix(): void {
		self::assertSame( Settings::PROVIDER_ANTHROPIC, KeyPrefix::providerForKey( self::ANTHROPIC_SAMPLE ) );
		self::assertNotSame( Settings::PROVIDER_OPENAI, KeyPrefix::providerForKey( self::ANTHROPIC_SAMPLE ) );
	}

	/**
	 * An unknown format belongs to nobody, so nothing is inferred.
	 */
	public function testUnknownPrefixIsAmbiguous(): void {
		self::assertSame( '', KeyPrefix::providerForKey( 'gsk_' . 'SAMPLE' ) );
		self::assertSame( '', KeyPrefix::providerForKey( 'AI' ) );
		self::assertSame( '', KeyPrefix::providerForKey( '' ) );
		self::assertSame( '', KeyPrefix::providerForKey( '   ' ) );
	}

	/**
	 * Labels are what the admin reads.
	 */
	public function testLabels(): void {
		self::assertSame( 'OpenAI', KeyPrefix::label( Settings::PROVIDER_OPENAI ) );
		self::assertSame( 'Google Gemini', KeyPrefix::label( Settings::PROVIDER_GEMINI ) );
		self::assertSame( 'Anthropic', KeyPrefix::label( Settings::PROVIDER_ANTHROPIC ) );
	}
}
