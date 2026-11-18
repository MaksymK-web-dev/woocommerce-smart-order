<?php
/**
 * JSON body normalisation tests.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use WSO\SmartOrder\Support\JsonBody;

/**
 * Pins the array-wrapped response shape some gateways return.
 */
final class JsonBodyTest extends TestCase {

	/**
	 * A plain object body is returned unchanged.
	 */
	public function testObjectBodyPassesThrough(): void {
		$body = array( 'choices' => array( array( 'message' => array( 'content' => 'x' ) ) ) );

		self::assertSame( $body, JsonBody::unwrap( $body ) );
	}

	/**
	 * Gemini's OpenAI-compatible endpoint wraps errors in a list.
	 */
	public function testArrayWrappedBodyIsUnwrapped(): void {
		$wrapped = array(
			array(
				'error' => array(
					'code'    => 400,
					'message' => 'Please pass a valid API key',
				),
			),
		);

		self::assertSame( 'Please pass a valid API key', JsonBody::unwrap( $wrapped )['error']['message'] );
	}

	/**
	 * Garbage decodes to an empty array, never to a warning.
	 */
	public function testNonArrayInputBecomesEmpty(): void {
		self::assertSame( array(), JsonBody::unwrap( null ) );
		self::assertSame( array(), JsonBody::unwrap( 'text' ) );
		self::assertSame( array(), JsonBody::unwrap( array() ) );
	}
}
