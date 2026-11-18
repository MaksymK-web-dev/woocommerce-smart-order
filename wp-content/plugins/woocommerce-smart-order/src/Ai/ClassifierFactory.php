<?php
/**
 * Classifier factory.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Ai;

use WSO\SmartOrder\Config\Settings;
use WSO\SmartOrder\Contract\Classifier;

/**
 * Picks the adapter that matches the configured provider.
 */
final class ClassifierFactory {

	/**
	 * Builds the configured classifier.
	 *
	 * Anthropic keeps its native protocol; everything else speaks OpenAI's chat
	 * completions, which Gemini, Groq, DeepSeek, OpenRouter and local model
	 * servers all expose. A provider with its own protocol can be supplied from
	 * another plugin through the filter.
	 */
	public function make(): Classifier {
		$key   = Settings::apiKey();
		$model = Settings::model();

		$classifier = Settings::usesAnthropicProtocol()
			? new AnthropicClassifier( $key, $model )
			: new OpenAiCompatibleClassifier( $key, $model, Settings::baseUrl() );

		return apply_filters( 'wso_smart_order_classifier', $classifier );
	}
}
