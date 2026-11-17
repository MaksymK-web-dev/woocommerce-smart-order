<?php
/**
 * ResponseParser tests.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Tests\Unit\Ai;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WSO\SmartOrder\Ai\ResponseParser;

/**
 * Covers the parser against the shapes a model actually returns.
 */
final class ResponseParserTest extends TestCase {

	/**
	 * Parses a plain JSON answer.
	 */
	public function testParsesUrgentAnswer(): void {
		$result = ResponseParser::parse( '{"urgent": true, "note": "доставка після 18:00"}' );

		self::assertTrue( $result->available );
		self::assertTrue( $result->urgent );
		self::assertSame( 'доставка після 18:00', $result->note );
	}

	/**
	 * Parses a clean answer.
	 */
	public function testParsesClearAnswer(): void {
		$result = ResponseParser::parse( '{"urgent": false, "note": ""}' );

		self::assertTrue( $result->available );
		self::assertFalse( $result->urgent );
		self::assertSame( '', $result->note );
	}

	/**
	 * Strips a markdown fence the model added against instructions.
	 */
	public function testStripsMarkdownFence(): void {
		$result = ResponseParser::parse( "```json\n{\"urgent\": true, \"note\": \"курьер до 12:00\"}\n```" );

		self::assertTrue( $result->available );
		self::assertTrue( $result->urgent );
		self::assertSame( 'курьер до 12:00', $result->note );
	}

	/**
	 * The string "false" is truthy in PHP — a false alarm would be the worst outcome.
	 */
	public function testStringFalseIsNotUrgent(): void {
		$result = ResponseParser::parse( '{"urgent": "false", "note": "x"}' );

		self::assertTrue( $result->available );
		self::assertFalse( $result->urgent );
	}

	/**
	 * Prose instead of JSON must be reported as unavailable, never as "not urgent".
	 */
	public function testProseIsUnavailable(): void {
		$result = ResponseParser::parse( 'Sure! Here is the JSON you asked for.' );

		self::assertFalse( $result->available );
		self::assertFalse( $result->urgent );
		self::assertNotSame( '', $result->error );
	}

	/**
	 * An empty answer is a failure, not a clean verdict.
	 */
	public function testEmptyAnswerIsUnavailable(): void {
		self::assertFalse( ResponseParser::parse( '' )->available );
	}

	/**
	 * A JSON array is not the expected object.
	 */
	public function testJsonArrayIsUnavailable(): void {
		self::assertFalse( ResponseParser::parse( '["urgent"]' )->available );
	}

	/**
	 * A missing contract key is a failure even when the JSON parses.
	 */
	public function testMissingKeyIsUnavailable(): void {
		self::assertFalse( ResponseParser::parse( '{"note": "no verdict"}' )->available );
	}

	/**
	 * Notes are trimmed.
	 */
	public function testNoteIsTrimmed(): void {
		$result = ResponseParser::parse( '{"urgent": true, "note": "  call first  "}' );

		self::assertSame( 'call first', $result->note );
	}

	/**
	 * A non-scalar note must not break parsing.
	 */
	public function testNonScalarNoteIsIgnored(): void {
		$result = ResponseParser::parse( '{"urgent": true, "note": {"nested": true}}' );

		self::assertTrue( $result->urgent );
		self::assertSame( '', $result->note );
	}

	/**
	 * Whitespace outside the object is tolerated.
	 *
	 * @param string $raw Model answer.
	 */
	#[DataProvider( 'tolerantInputs' )]
	public function testToleratesSurroundingWhitespace( string $raw ): void {
		$result = ResponseParser::parse( $raw );

		self::assertTrue( $result->available );
		self::assertTrue( $result->urgent );
	}

	/**
	 * Inputs that differ only in whitespace.
	 *
	 * @return array<string, array{string}>
	 */
	public static function tolerantInputs(): array {
		return array(
			'leading newlines'  => array( "\n\n{\"urgent\": true, \"note\": \"n\"}" ),
			'trailing newlines' => array( "{\"urgent\": true, \"note\": \"n\"}\n\n" ),
			'indented'          => array( "  {\"urgent\": true, \"note\": \"n\"}  " ),
		);
	}
}
