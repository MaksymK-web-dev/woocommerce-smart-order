<?php
/**
 * OrderPayload tests.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Tests\Unit\Order;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WSO\SmartOrder\Order\OrderPayload;

/**
 * The DTO is the boundary between WooCommerce and everything else, so its one piece of
 * behaviour — deciding whether there is anything to classify — is pinned down.
 */
final class OrderPayloadTest extends TestCase {

	/**
	 * Builds a payload with a given comment.
	 *
	 * @param string $comment Customer comment.
	 */
	private function payload( string $comment ): OrderPayload {
		return new OrderPayload( '1', 1, 'Name', 'Phone', 'Item (x1)', '1.00 UAH', $comment, 'pending' );
	}

	/**
	 * Comments that carry content.
	 */
	public function testNonEmptyCommentIsPresent(): void {
		self::assertTrue( $this->payload( 'Доставка після 18:00' )->hasComment() );
	}

	/**
	 * Whitespace-only comments are treated as absent, so no API call is made for them.
	 *
	 * @param string $comment Comment under test.
	 */
	#[DataProvider( 'blankComments' )]
	public function testBlankCommentIsAbsent( string $comment ): void {
		self::assertFalse( $this->payload( $comment )->hasComment() );
	}

	/**
	 * Blank inputs.
	 *
	 * @return array<string, array{string}>
	 */
	public static function blankComments(): array {
		return array(
			'empty'    => array( '' ),
			'space'    => array( ' ' ),
			'newlines' => array( "\n\n" ),
			'tabs'     => array( "\t" ),
		);
	}

	/**
	 * The DTO is immutable.
	 */
	public function testPropertiesAreReadonly(): void {
		$this->expectException( \Error::class );

		$payload = $this->payload( 'x' );

		// @phpstan-ignore-next-line Intentional: proving the property cannot be reassigned.
		$payload->comment = 'changed';
	}
}
