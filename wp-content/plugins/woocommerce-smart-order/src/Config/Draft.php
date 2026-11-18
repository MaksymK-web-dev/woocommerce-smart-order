<?php
/**
 * Configuration candidate.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Config;

use WSO\SmartOrder\Ai\KeyPrefix;
use WSO\SmartOrder\Support\Url;

/**
 * The configuration a form is about to save, resolved without writing anything.
 *
 * "Test connection" has to check what the screen currently shows, not what the
 * database still holds. Building a draft from the submitted form and handing it to
 * the tester is what makes the button answer the question the admin is asking.
 */
final class Draft {

	/**
	 * Builds a draft.
	 *
	 * @param string $provider      Provider identifier.
	 * @param string $baseUrl       Base URL that will be used.
	 * @param string $model         Model that will be used.
	 * @param string $apiKey        Effective AI key.
	 * @param bool   $apiKeyTyped   Whether the key came from the form rather than storage.
	 * @param string $telegramToken Effective bot token.
	 * @param string $telegramChat  Target chat id.
	 */
	private function __construct(
		public readonly string $provider,
		public readonly string $baseUrl,
		public readonly string $model,
		public readonly string $apiKey,
		public readonly bool $apiKeyTyped,
		public readonly string $telegramToken,
		public readonly string $telegramChat
	) {}

	/**
	 * The configuration as currently saved.
	 */
	public static function fromStored(): self {
		$stored = Settings::all();

		return self::build(
			(string) ( $stored['provider'] ?? '' ),
			(string) ( $stored['base_url'] ?? '' ),
			(string) ( $stored['model'] ?? '' ),
			'',
			(string) ( $stored['telegram_token'] ?? '' ),
			(string) ( $stored['telegram_chat'] ?? '' )
		);
	}

	/**
	 * The configuration a submitted form describes.
	 *
	 * Empty fields fall back to what is stored, matching what Save would keep.
	 *
	 * @param array<string, mixed> $input Already-sanitized form values.
	 */
	public static function fromInput( array $input ): self {
		$stored = Settings::all();

		$provider = Settings::normalizeProvider(
			isset( $input['provider'] ) && is_scalar( $input['provider'] )
				? (string) $input['provider']
				: (string) ( $stored['provider'] ?? '' )
		);

		$base_url = isset( $input['base_url'] ) && is_scalar( $input['base_url'] )
			? (string) $input['base_url']
			: (string) ( $stored['base_url'] ?? '' );

		$model = isset( $input['model'] ) && is_scalar( $input['model'] )
			? (string) $input['model']
			: (string) ( $stored['model'] ?? '' );

		$typed_key = isset( $input['api_key'] ) && is_scalar( $input['api_key'] )
			? trim( (string) $input['api_key'] )
			: '';

		$telegram_token = isset( $input['telegram_token'] ) && is_scalar( $input['telegram_token'] )
			? trim( (string) $input['telegram_token'] )
			: '';

		$telegram_chat = isset( $input['telegram_chat'] ) && is_scalar( $input['telegram_chat'] )
			? trim( (string) $input['telegram_chat'] )
			: (string) ( $stored['telegram_chat'] ?? '' );

		return self::build( $provider, $base_url, $model, $typed_key, $telegram_token, $telegram_chat );
	}

	/**
	 * Resolves every value for one provider.
	 *
	 * @param string $provider       Provider identifier.
	 * @param string $customBaseUrl  Stored or submitted custom base URL.
	 * @param string $storedModel    Stored or submitted model.
	 * @param string $typedKey       Key typed into the form.
	 * @param string $typedBotToken  Bot token typed into the form.
	 * @param string $chatId         Target chat id.
	 */
	private static function build(
		string $provider,
		string $customBaseUrl,
		string $storedModel,
		string $typedKey,
		string $typedBotToken,
		string $chatId
	): self {
		$provider = Settings::normalizeProvider( $provider );

		// A typed key wins: the admin is checking what they just pasted. Otherwise
		// the stored key for this provider, then the wp-config.php constant.
		$api_key = '' !== $typedKey
			? $typedKey
			: Settings::apiKeyFor( $provider );

		$bot = '' !== $typedBotToken ? $typedBotToken : Settings::telegramToken();

		// The submitted value is normalised here as well: a credential pasted into the
		// URL field must never become the host of a live request.
		$custom = Url::normalizeBaseUrl( $customBaseUrl );

		return new self(
			$provider,
			Settings::baseUrlFor( $provider, $custom ),
			Settings::modelFor( $provider, $storedModel ),
			$api_key,
			'' !== $typedKey,
			$bot,
			trim( $chatId )
		);
	}

	/**
	 * Whether a custom endpoint still lacks its URL.
	 */
	public function customEndpointMissing(): bool {
		return Settings::PROVIDER_CUSTOM === $this->provider
			&& $this->baseUrl === Settings::defaultBaseUrl( Settings::PROVIDER_OPENAI );
	}

	/**
	 * Whether the provider speaks the Anthropic messages protocol.
	 */
	public function usesAnthropicProtocol(): bool {
		return Settings::usesAnthropicProtocolFor( $this->provider );
	}

	/**
	 * Provider name for a message.
	 */
	public function providerLabel(): string {
		return KeyPrefix::label( $this->provider );
	}
}
