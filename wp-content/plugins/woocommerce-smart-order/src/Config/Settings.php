<?php
/**
 * Plugin settings.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Config;

/**
 * Typed access to the single `wso_smart_order` option.
 *
 * Secrets are read from wp-config.php constants when defined, so the database never
 * holds an API key and the settings screen never displays one.
 */
final class Settings {

	public const OPTION = 'wso_smart_order';

	public const PROVIDER_OPENAI    = 'openai';
	public const PROVIDER_ANTHROPIC = 'anthropic';

	public const MODE_TELEGRAM = 'telegram';
	public const MODE_LOG      = 'log';

	/**
	 * Returns all stored settings merged with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );

		return array_merge(
			array(
				'enabled'       => false,
				'provider'      => self::PROVIDER_OPENAI,
				'model'         => 'gpt-4o-mini',
				'notify_mode'   => self::MODE_LOG,
				'telegram_chat' => '',
			),
			is_array( $stored ) ? $stored : array()
		);
	}

	/**
	 * Whether order processing is switched on.
	 */
	public static function enabled(): bool {
		return (bool) ( self::all()['enabled'] ?? false );
	}

	/**
	 * Selected AI provider identifier.
	 */
	public static function provider(): string {
		$provider = (string) ( self::all()['provider'] ?? self::PROVIDER_OPENAI );

		return in_array( $provider, array( self::PROVIDER_OPENAI, self::PROVIDER_ANTHROPIC ), true )
			? $provider
			: self::PROVIDER_OPENAI;
	}

	/**
	 * Model name for the selected provider.
	 */
	public static function model(): string {
		$model = trim( (string) ( self::all()['model'] ?? '' ) );

		return '' !== $model ? $model : 'gpt-4o-mini';
	}

	/**
	 * Notification transport.
	 */
	public static function notifyMode(): string {
		return self::MODE_TELEGRAM === ( self::all()['notify_mode'] ?? '' )
			? self::MODE_TELEGRAM
			: self::MODE_LOG;
	}

	/**
	 * Telegram group id.
	 */
	public static function telegramChatId(): string {
		return trim( (string) ( self::all()['telegram_chat'] ?? '' ) );
	}

	/**
	 * Reads the AI key: constant first, option as a fallback for local testing.
	 */
	public static function apiKey(): string {
		$constant = self::PROVIDER_ANTHROPIC === self::provider() ? 'WSO_ANTHROPIC_API_KEY' : 'WSO_OPENAI_API_KEY';

		if ( defined( $constant ) && '' !== (string) constant( $constant ) ) {
			return (string) constant( $constant );
		}

		return trim( (string) ( self::all()['api_key'] ?? '' ) );
	}

	/**
	 * Reads the Telegram bot token: constant first, option as a fallback.
	 */
	public static function telegramToken(): string {
		if ( defined( 'WSO_TELEGRAM_BOT_TOKEN' ) && '' !== (string) WSO_TELEGRAM_BOT_TOKEN ) {
			return (string) WSO_TELEGRAM_BOT_TOKEN;
		}

		return trim( (string) ( self::all()['telegram_token'] ?? '' ) );
	}

	/**
	 * Default values for the settings screen.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'enabled'       => false,
			'provider'      => self::PROVIDER_OPENAI,
			'model'         => 'gpt-4o-mini',
			'notify_mode'   => self::MODE_LOG,
			'telegram_chat' => '',
		);
	}
}
