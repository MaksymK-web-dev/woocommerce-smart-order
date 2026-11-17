<?php
/**
 * MessageFormatter tests.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use WSO\SmartOrder\Ai\UrgencyResult;
use WSO\SmartOrder\Order\OrderPayload;
use WSO\SmartOrder\Support\MessageFormatter;

/**
 * Covers the three message states and the HTML escaping that keeps customer text from
 * breaking Telegram markup.
 */
final class MessageFormatterTest extends TestCase {

	/**
	 * Builds a payload, overriding the comment when needed.
	 *
	 * @param string $comment Customer comment.
	 * @param string $name    Customer name.
	 */
	private function payload( string $comment, string $name = 'Іван Петренко' ): OrderPayload {
		return new OrderPayload(
			orderNumber: '1042',
			orderId: 42,
			customer: $name,
			phone: '+000000000000',
			items: 'Футболка (x2), Кепка (x1)',
			total: '1450.00 UAH',
			comment: $comment,
			status: 'pending'
		);
	}

	/**
	 * Urgent branch renders the AI flag with the note.
	 */
	public function testUrgentBranchRendersFlag(): void {
		$text = ( new MessageFormatter() )->format(
			$this->payload( 'Доставка після 18:00' ),
			UrgencyResult::urgent( 'доставка після 18:00' )
		);

		self::assertStringContainsString( '⚠️ AI flag', $text );
		self::assertStringContainsString( '«доставка після 18:00»', $text );
	}

	/**
	 * An urgent verdict with an empty note falls back to the comment itself.
	 */
	public function testUrgentWithoutNoteFallsBackToComment(): void {
		$text = ( new MessageFormatter() )->format(
			$this->payload( 'Зателефонувати за годину' ),
			UrgencyResult::urgent( '' )
		);

		self::assertStringContainsString( '«Зателефонувати за годину»', $text );
	}

	/**
	 * Clear branch states explicitly that nothing was found.
	 */
	public function testClearBranchIsExplicit(): void {
		$text = ( new MessageFormatter() )->format(
			$this->payload( 'Подарункове пакування' ),
			UrgencyResult::clear()
		);

		self::assertStringContainsString( '✅', $text );
		self::assertStringNotContainsString( '⚠️ AI flag', $text );
	}

	/**
	 * A failed classification must never look like a clean comment.
	 */
	public function testUnavailableBranchIsDistinct(): void {
		$text = ( new MessageFormatter() )->format(
			$this->payload( 'Доставка після 18:00' ),
			UrgencyResult::unavailable( 'HTTP 401' )
		);

		self::assertStringContainsString( 'AI unavailable', $text );
		self::assertStringNotContainsString( '✅', $text );
	}

	/**
	 * A comment containing markup is escaped instead of being rendered as HTML.
	 */
	public function testCustomerMarkupIsEscaped(): void {
		$text = ( new MessageFormatter() )->format(
			$this->payload( 'доставка <script>alert(1)</script>' ),
			UrgencyResult::clear()
		);

		self::assertStringContainsString( '&lt;script&gt;', $text );
		self::assertStringNotContainsString( '<script>', $text );
		self::assertStringNotContainsString( '</script>', $text );
	}

	/**
	 * Ampersands are escaped once, not twice.
	 */
	public function testAmpersandIsEscapedOnce(): void {
		$text = ( new MessageFormatter() )->format(
			$this->payload( 'Tom & Jerry' ),
			UrgencyResult::clear()
		);

		self::assertStringContainsString( 'Tom &amp; Jerry', $text );
		self::assertStringNotContainsString( '&amp;amp;', $text );
	}

	/**
	 * Only <b> survives as markup in the output.
	 */
	public function testOnlyBoldTagsRemain(): void {
		$text = ( new MessageFormatter() )->format(
			$this->payload( 'ok' ),
			UrgencyResult::clear()
		);

		self::assertStringContainsString( '<b>', $text );
		self::assertSame( 0, preg_match( '#<(?!b>|/b>)[a-z]#i', $text ) );
	}

	/**
	 * An order without a comment omits the comment and classification lines entirely.
	 */
	public function testEmptyCommentOmitsClassification(): void {
		$text = ( new MessageFormatter() )->format( $this->payload( '' ), UrgencyResult::clear() );

		self::assertStringNotContainsString( '💬', $text );
		self::assertStringNotContainsString( '✅', $text );
	}

	/**
	 * Order number, customer, phone, items and total all reach the message.
	 */
	public function testCoreFieldsArePresent(): void {
		$text = ( new MessageFormatter() )->format( $this->payload( 'ok' ), UrgencyResult::clear() );

		self::assertStringContainsString( '#1042', $text );
		self::assertStringContainsString( 'Іван Петренко', $text );
		self::assertStringContainsString( '+000000000000', $text );
		self::assertStringContainsString( 'Футболка (x2), Кепка (x1)', $text );
		self::assertStringContainsString( '1450.00 UAH', $text );
	}
}
