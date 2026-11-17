<?php
/**
 * Notifier factory.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Notify;

use WSO\SmartOrder\Config\Settings;
use WSO\SmartOrder\Contract\Notifier;
use WSO\SmartOrder\Support\MessageFormatter;

/**
 * Builds the notifier the settings ask for, degrading to the log when Telegram is
 * not yet configured.
 */
final class NotifierFactory {

	/**
	 * Sets up the factory.
	 *
	 * @param MessageFormatter $formatter Message builder.
	 */
	public function __construct( private readonly MessageFormatter $formatter ) {}

	/**
	 * Builds the configured notifier.
	 */
	public function make(): Notifier {
		if ( Settings::MODE_TELEGRAM === Settings::notifyMode() ) {
			$token  = Settings::telegramToken();
			$chatId = Settings::telegramChatId();

			if ( '' !== $token && '' !== $chatId ) {
				return new TelegramNotifier( $token, $chatId, $this->formatter );
			}
		}

		return new LogNotifier( $this->formatter );
	}
}
