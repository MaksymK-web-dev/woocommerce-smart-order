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
 */
final class Settings {

	public const OPTION = 'wso_smart_order';

	public const PROVIDER_OPENAI    = 'openai';
	public const PROVIDER_ANTHROPIC = 'anthropic';

	public const MODE_TELEGRAM = 'telegram';
	public const MODE_LOG      = 'log';

	public const SOURCE_CONSTANT = 'constant';
	public const SOURCE_STORED   = 'stored';
	public const SOURCE_NONE     = 'none';

	/**
	 * Name of the wp-config.php constant that overrides the stored bot token.
	 */
	public const TELEGRAM_CONSTANT = 'WSO_TELEGRAM_BOT_TOKEN';

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
	 * Name of the wp-config.php constant that overrides the stored AI key.
	 */
	public static function apiKeyConstant(): string {
		return self::PROVIDER_ANTHROPIC === self::provider() ? 'WSO_ANTHROPIC_API_KEY' : 'WSO_OPENAI_API_KEY';
	}

	/**
	 * Reads the AI key: constant first, option as a fallback for local testing.
	 */
	public static function apiKey(): string {
		$key = self::constantValue( self::apiKeyConstant() );

		return '' !== $key ? $key : trim( (string) ( self::all()['api_key'] ?? '' ) );
	}

	/**
	 * Where the effective AI key comes from: `constant`, `stored` or `none`.
	 */
	public static function apiKeySource(): string {
		if ( '' !== self::constantValue( self::apiKeyConstant() ) ) {
			return self::SOURCE_CONSTANT;
		}

		return '' !== trim( (string) ( self::all()['api_key'] ?? '' ) ) ? self::SOURCE_STORED : self::SOURCE_NONE;
	}

	/**
	 * Reads the Telegram bot token: constant first, option as a fallback.
	 */
	public static function telegramToken(): string {
		$token = self::constantValue( self::TELEGRAM_CONSTANT );

		return '' !== $token ? $token : trim( (string) ( self::all()['telegram_token'] ?? '' ) );
	}

	/**
	 * Where the effective bot token comes from: `constant`, `stored` or `none`.
	 */
	public static function telegramTokenSource(): string {
		if ( '' !== self::constantValue( self::TELEGRAM_CONSTANT ) ) {
			return self::SOURCE_CONSTANT;
		}

		return '' !== trim( (string) ( self::all()['telegram_token'] ?? '' ) ) ? self::SOURCE_STORED : self::SOURCE_NONE;
	}

	/**
	 * Reads a constant when it exists and is not empty.
	 *
	 * @param string $name Constant name.
	 */
	private static function constantValue( string $name ): string {
		if ( ! defined( $name ) ) {
			return '';
		}

		return trim( (string) constant( $name ) );
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
