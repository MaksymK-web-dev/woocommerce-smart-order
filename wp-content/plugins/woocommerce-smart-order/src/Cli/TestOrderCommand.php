<?php
/**
 * WP-CLI command.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Cli;

use WSO\SmartOrder\Ai\ClassifierFactory;
use WSO\SmartOrder\Config\Settings;
use WSO\SmartOrder\Notify\NotifierFactory;
use WSO\SmartOrder\Order\PayloadFactory;
use WSO\SmartOrder\Order\Pipeline;
use WSO\SmartOrder\Order\TestOrderFactory;
use WSO\SmartOrder\Support\MessageFormatter;

/**
 * Exercises the pipeline from the command line.
 */
final class TestOrderCommand {

	/**
	 * Registers the command with WP-CLI.
	 */
	public static function register(): void {
		\WP_CLI::add_command( 'smart-order', self::class );
	}

	/**
	 * Creates a test order carrying a customer comment and runs the pipeline on it.
	 *
	 * ## OPTIONS
	 *
	 * <comment>
	 * : The customer comment to classify.
	 *
	 * [--dry-run]
	 * : Classify and print, but do not create an order.
	 *
	 * ## EXAMPLES
	 *
	 *     wp smart-order test "Delivery after 18:00, call an hour ahead"
	 *
	 * @param array<int, string>    $args      Positional arguments.
	 * @param array<string, string> $assocArgs Flags.
	 */
	public function test( array $args, array $assocArgs ): void {
		$comment = isset( $args[0] ) && is_scalar( $args[0] ) ? trim( (string) $args[0] ) : '';

		if ( '' === $comment ) {
			\WP_CLI::error( 'A comment is required.' );
		}

		if ( isset( $assocArgs['dry-run'] ) ) {
			$this->printClassification( $comment );

			return;
		}

		$orderId = ( new TestOrderFactory() )->create( $comment );

		if ( null === $orderId ) {
			\WP_CLI::error( 'No published products to build an order from.' );
		}

		\WP_CLI::line( sprintf( 'Created order %d.', $orderId ) );

		$pipeline = new Pipeline(
			new PayloadFactory(),
			( new ClassifierFactory() )->make(),
			( new NotifierFactory( new MessageFormatter() ) )->make()
		);

		$sent = $pipeline->process( $orderId );

		if ( $sent ) {
			\WP_CLI::success( 'Pipeline processed the order.' );
		} else {
			\WP_CLI::warning( 'Pipeline ran but no notification was delivered.' );
		}

		if ( ! Settings::enabled() ) {
			\WP_CLI::warning( 'Processing is disabled in settings — the hook will not run.' );
		}
	}

	/**
	 * Classifies a comment and prints the result, without touching any order.
	 *
	 * @param string $comment Customer comment.
	 */
	private function printClassification( string $comment ): void {
		$result = ( new ClassifierFactory() )->make()->classify( $comment );

		$note = $result->note;

		if ( '' === $note && '' !== $result->error ) {
			$note = '(' . $result->error . ')';
		}

		if ( '' === $note ) {
			$note = '—';
		}

		\WP_CLI::line(
			sprintf(
				'urgent=%s available=%s note=%s',
				$result->urgent ? 'true' : 'false',
				$result->available ? 'true' : 'false',
				$note
			)
		);
	}
}
