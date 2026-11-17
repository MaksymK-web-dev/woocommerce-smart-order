<?php
/**
 * Message formatter.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Support;

use WSO\SmartOrder\Ai\UrgencyResult;
use WSO\SmartOrder\Order\OrderPayload;

/**
 * Turns a payload and a classification into the manager-facing message.
 *
 * Telegram's HTML mode is a limited subset, so the output uses <b> only and escapes
 * every interpolated value. Customer text is untrusted input.
 */
final class MessageFormatter {

	/**
	 * Builds the message body.
	 *
	 * @param OrderPayload  $payload Order data.
	 * @param UrgencyResult $urgency Classification result.
	 */
	public function format( OrderPayload $payload, UrgencyResult $urgency ): string {
		$lines   = array();
		$lines[] = sprintf(
			'🛒 <b>%s #%s</b>',
			esc_html__( 'New order', 'woocommerce-smart-order' ),
			$this->escape( $payload->orderNumber )
		);
		$lines[] = '';
		$lines[] = sprintf(
			'👤 %s: <b>%s</b> (%s)',
			esc_html__( 'Customer', 'woocommerce-smart-order' ),
			$this->escape( $payload->customer ),
			$this->escape( $payload->phone )
		);
		$lines[] = '';
		$lines[] = sprintf(
			'📦 %s: %s — <b>%s</b>',
			esc_html__( 'Items', 'woocommerce-smart-order' ),
			$this->escape( $payload->items ),
			$this->escape( $payload->total )
		);

		if ( $payload->hasComment() ) {
			$lines[] = '';
			$lines[] = sprintf( '💬 %s: «%s»', esc_html__( 'Comment', 'woocommerce-smart-order' ), $this->escape( $payload->comment ) );
			$lines[] = '';
			$lines[] = $this->urgencyLine( $payload, $urgency );
		}

		return implode( "\n", $lines );
	}

	/**
	 * The classification line: three distinct states, never collapsed.
	 *
	 * @param OrderPayload  $payload Order data.
	 * @param UrgencyResult $urgency Classification result.
	 */
	private function urgencyLine( OrderPayload $payload, UrgencyResult $urgency ): string {
		if ( ! $urgency->available ) {
			return '⚠️ ' . esc_html__( 'AI unavailable — check the comment manually', 'woocommerce-smart-order' );
		}

		if ( ! $urgency->urgent ) {
			return '✅ ' . esc_html__( 'AI: no urgent delivery request found', 'woocommerce-smart-order' );
		}

		$note = '' !== $urgency->note ? $urgency->note : $payload->comment;

		return sprintf( '⚠️ %s: «%s»', esc_html__( 'AI flag', 'woocommerce-smart-order' ), $this->escape( $note ) );
	}

	/**
	 * Escapes the three characters Telegram's HTML parser treats as markup.
	 *
	 * `&` first, otherwise the ampersands introduced by the other two get escaped twice.
	 *
	 * @param string $value Raw value.
	 */
	private function escape( string $value ): string {
		return str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), $value );
	}
}
