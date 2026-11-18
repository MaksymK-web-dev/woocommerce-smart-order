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
 * API keys are stored per provider. A single shared key is the reason a provider
 * switch used to send the previous provider's key to the new one, which fails with
 * an error about the wrong service.
 */
final class Settings {

	public const OPTION = 'wso_smart_order';

	public const PROVIDER_OPENAI    = 'openai';
	public const PROVIDER_ANTHROPIC = 'anthropic';
	public const PROVIDER_GEMINI    = 'gemini';
	public const PROVIDER_CUSTOM    = 'custom';

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
	 * Base URL for each provider. A provider is a URL plus a model name, not a class:
	 * every one of these speaks the same `/chat/completions` protocol except Anthropic.
	 *
	 * @var array<string, string>
	 */
	private const BASE_URLS = array(
		self::PROVIDER_OPENAI    => 'https://api.openai.com/v1',
		self::PROVIDER_GEMINI    => 'https://generativelanguage.googleapis.com/v1beta/openai',
		self::PROVIDER_ANTHROPIC => 'https://api.anthropic.com/v1',
		self::PROVIDER_CUSTOM    => '',
	);

	/**
	 * Default model for each provider.
	 *
	 * @var array<string, string>
	 */
	private const DEFAULT_MODELS = array(
		self::PROVIDER_OPENAI    => 'gpt-4o-mini',
		self::PROVIDER_ANTHROPIC => 'claude-3-5-haiku-latest',
		self::PROVIDER_GEMINI    => 'gemini-2.5-flash',
		self::PROVIDER_CUSTOM    => 'gpt-4o-mini',
	);

	/**
	 * Name of the wp-config.php constant holding a provider's key, when the site keeps one there.
	 *
	 * @var array<string, string>
	 */
	private const KEY_CONSTANTS = array(
		self::PROVIDER_OPENAI    => 'WSO_OPENAI_API_KEY',
		self::PROVIDER_ANTHROPIC => 'WSO_ANTHROPIC_API_KEY',
		self::PROVIDER_GEMINI    => 'WSO_AI_API_KEY',
		self::PROVIDER_CUSTOM    => 'WSO_AI_API_KEY',
	);

	/**
	 * Default values, also used as the Settings API default.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'enabled'       => false,
			'provider'      => self::PROVIDER_OPENAI,
			'model'         => self::DEFAULT_MODELS[ self::PROVIDER_OPENAI ],
			'base_url'      => '',
			'notify_mode'   => self::MODE_LOG,
			'telegram_chat' => '',
			'api_keys'      => array(),
		);
	}

	/**
	 * Stored settings merged with defaults.
	 *
	 * The legacy single `api_key` is read into the map of the provider it was stored
	 * with, so an existing install keeps working without a migration script.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$merged = array_merge( self::defaults(), $stored );

		$keys = isset( $merged['api_keys'] ) && is_array( $merged['api_keys'] ) ? $merged['api_keys'] : array();

		if ( array() === $keys && isset( $stored['api_key'] ) && is_scalar( $stored['api_key'] ) ) {
			$legacy = trim( (string) $stored['api_key'] );

			if ( '' !== $legacy ) {
				$keys[ self::normalizeProvider( (string) ( $merged['provider'] ?? '' ) ) ] = $legacy;
			}
		}

		$merged['api_keys'] = $keys;

		// The legacy field is not carried forward: writing it again would re-seed
		// every provider with the same key.
		unset( $merged['api_key'] );

		return $merged;
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
		return self::normalizeProvider( (string) ( self::all()['provider'] ?? '' ) );
	}

	/**
	 * Provider identifiers the settings screen offers.
	 *
	 * @return string[]
	 */
	public static function providers(): array {
		return array_keys( self::BASE_URLS );
	}

	/**
	 * Whether a string is a provider this plugin knows.
	 *
	 * @param string $provider Candidate.
	 */
	public static function isProvider( string $provider ): bool {
		return array_key_exists( $provider, self::BASE_URLS );
	}

	/**
	 * Maps an unknown or empty provider onto the default one.
	 *
	 * @param string $provider Candidate.
	 */
	public static function normalizeProvider( string $provider ): string {
		return self::isProvider( $provider ) ? $provider : self::PROVIDER_OPENAI;
	}

	/**
	 * Whether a provider speaks the Anthropic messages protocol.
	 *
	 * @param string $provider Provider identifier.
	 */
	public static function usesAnthropicProtocolFor( string $provider ): bool {
		return self::PROVIDER_ANTHROPIC === $provider;
	}

	/**
	 * Whether the selected provider speaks the Anthropic messages protocol.
	 */
	public static function usesAnthropicProtocol(): bool {
		return self::usesAnthropicProtocolFor( self::provider() );
	}

	/**
	 * Base URL a provider uses when nothing overrides it.
	 *
	 * @param string $provider Provider identifier.
	 */
	public static function defaultBaseUrl( string $provider ): string {
		return rtrim( self::BASE_URLS[ self::normalizeProvider( $provider ) ] ?? '', '/' );
	}

	/**
	 * Base URL actually used: the stored one for a custom endpoint, the provider's own otherwise.
	 *
	 * @param string $provider    Provider identifier.
	 * @param string $customValue Stored custom base URL.
	 */
	public static function baseUrlFor( string $provider, string $customValue ): string {
		$provider = self::normalizeProvider( $provider );

		if ( self::PROVIDER_CUSTOM !== $provider ) {
			return self::defaultBaseUrl( $provider );
		}

		$custom = rtrim( trim( $customValue ), '/' );

		return '' !== $custom ? $custom : self::defaultBaseUrl( self::PROVIDER_OPENAI );
	}

	/**
	 * Base URL without a trailing slash, for the selected provider.
	 */
	public static function baseUrl(): string {
		return self::baseUrlFor( self::provider(), (string) ( self::all()['base_url'] ?? '' ) );
	}

	/**
	 * Default model for a provider.
	 *
	 * @param string $provider Provider identifier.
	 */
	public static function defaultModelFor( string $provider ): string {
		return self::DEFAULT_MODELS[ self::normalizeProvider( $provider ) ] ?? self::DEFAULT_MODELS[ self::PROVIDER_OPENAI ];
	}

	/**
	 * Model to use for a provider, given a stored name.
	 *
	 * A name carried over from another provider is replaced: sending `gpt-4o-mini` to
	 * Gemini fails with a confusing "model not found".
	 *
	 * @param string $provider Provider identifier.
	 * @param string $stored   Stored model name.
	 */
	public static function modelFor( string $provider, string $stored ): string {
		$provider = self::normalizeProvider( $provider );
		$stored   = trim( $stored );

		if ( '' === $stored ) {
			return self::defaultModelFor( $provider );
		}

		$is_foreign_default = in_array( $stored, self::DEFAULT_MODELS, true )
			&& self::defaultModelFor( $provider ) !== $stored;

		return $is_foreign_default ? self::defaultModelFor( $provider ) : $stored;
	}

	/**
	 * Model name for the selected provider.
	 */
	public static function model(): string {
		return self::modelFor( self::provider(), (string) ( self::all()['model'] ?? '' ) );
	}

	/**
	 * Default model for the selected provider.
	 */
	public static function defaultModel(): string {
		return self::defaultModelFor( self::provider() );
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
	 * Name of the wp-config.php constant that can hold a provider's key.
	 *
	 * @param string $provider Provider identifier.
	 */
	public static function apiKeyConstantFor( string $provider ): string {
		return self::KEY_CONSTANTS[ self::normalizeProvider( $provider ) ] ?? 'WSO_AI_API_KEY';
	}

	/**
	 * Name of the wp-config.php constant for the selected provider.
	 */
	public static function apiKeyConstant(): string {
		return self::apiKeyConstantFor( self::provider() );
	}

	/**
	 * Key stored for one provider.
	 *
	 * @param string $provider Provider identifier.
	 */
	public static function storedApiKeyFor( string $provider ): string {
		$keys = self::all()['api_keys'];
		$key  = $keys[ self::normalizeProvider( $provider ) ] ?? '';

		return is_scalar( $key ) ? trim( (string) $key ) : '';
	}

	/**
	 * Key stored for the selected provider.
	 */
	public static function storedApiKey(): string {
		return self::storedApiKeyFor( self::provider() );
	}

	/**
	 * Effective key for a provider: the constant first, the stored value second.
	 *
	 * @param string $provider Provider identifier.
	 */
	public static function apiKeyFor( string $provider ): string {
		$constant = self::constantValue( self::apiKeyConstantFor( $provider ) );

		return '' !== $constant ? $constant : self::storedApiKeyFor( $provider );
	}

	/**
	 * Effective key for the selected provider.
	 */
	public static function apiKey(): string {
		return self::apiKeyFor( self::provider() );
	}

	/**
	 * Where a provider's effective key comes from: `constant`, `stored` or `none`.
	 *
	 * @param string $provider Provider identifier.
	 */
	public static function apiKeySourceFor( string $provider ): string {
		if ( '' !== self::constantValue( self::apiKeyConstantFor( $provider ) ) ) {
			return self::SOURCE_CONSTANT;
		}

		return '' !== self::storedApiKeyFor( $provider ) ? self::SOURCE_STORED : self::SOURCE_NONE;
	}

	/**
	 * Where the selected provider's key comes from.
	 */
	public static function apiKeySource(): string {
		return self::apiKeySourceFor( self::provider() );
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
		if ( '' === $name || ! defined( $name ) ) {
			return '';
		}

		return trim( (string) constant( $name ) );
	}
}
