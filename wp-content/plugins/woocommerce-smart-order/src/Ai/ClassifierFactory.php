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
	 */
	public function make(): Classifier {
		$key   = Settings::apiKey();
		$model = Settings::model();

		return Settings::PROVIDER_ANTHROPIC === Settings::provider()
			? new AnthropicClassifier( $key, $model )
			: new OpenAiClassifier( $key, $model );
	}
}
