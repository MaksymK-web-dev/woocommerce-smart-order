<?php
/**
 * Key-prefix detection.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Ai;

use WSO\SmartOrder\Config\Settings;

/**
 * Recognises which service a key belongs to from its prefix.
 *
 * Pasting a Google key while the provider is OpenAI produces a message about
 * `platform.openai.com`, which reads like a broken plugin. Naming the mismatch
 * instead turns a confusing failure into one sentence of guidance.
 */
final class KeyPrefix {

	/**
	 * Prefixes that identify a provider beyond doubt.
	 *
	 * @var array<string, string>
	 */
	private const PREFIXES = array(
		'AIza'     => Settings::PROVIDER_GEMINI,
		'sk-ant-'  => Settings::PROVIDER_ANTHROPIC,
		'sk-proj-' => Settings::PROVIDER_OPENAI,
		'sk-'      => Settings::PROVIDER_OPENAI,
	);

	/**
	 * Provider a key appears to belong to, or an empty string when it is ambiguous.
	 *
	 * A custom endpoint may accept any format, so callers must not enforce this
	 * against `Settings::PROVIDER_CUSTOM`.
	 *
	 * @param string $key API key.
	 */
	public static function providerForKey( string $key ): string {
		$key = trim( $key );

		if ( '' === $key ) {
			return '';
		}

		// Longest prefix first, so `sk-ant-` is not mistaken for `sk-`.
		foreach ( self::PREFIXES as $prefix => $provider ) {
			if ( str_starts_with( $key, $prefix ) ) {
				return $provider;
			}
		}

		return '';
	}

	/**
	 * Human-readable provider name.
	 *
	 * @param string $provider Provider identifier.
	 */
	public static function label( string $provider ): string {
		return match ( $provider ) {
			Settings::PROVIDER_OPENAI    => 'OpenAI',
			Settings::PROVIDER_ANTHROPIC => 'Anthropic',
			Settings::PROVIDER_GEMINI    => 'Google Gemini',
			default                      => __( 'the custom endpoint', 'woocommerce-smart-order' ),
		};
	}

	/**
	 * Keeps the class uninstantiable.
	 */
	private function __construct() {}
}
