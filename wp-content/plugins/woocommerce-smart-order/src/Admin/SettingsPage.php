<?php
/**
 * Admin settings screen.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Admin;

use WSO\SmartOrder\Config\Draft;
use WSO\SmartOrder\Config\Settings;
use WSO\SmartOrder\Contract\HookRegistrar;
use WSO\SmartOrder\Support\CheckResult;
use WSO\SmartOrder\Support\Url;

/**
 * WooCommerce → Smart Order settings screen.
 *
 * Secrets are never printed: an option fallback renders as an empty field. "Test
 * connection" submits the whole form, so it checks the configuration on screen
 * rather than the one last saved.
 */
final class SettingsPage implements HookRegistrar {

	/**
	 * Menu slug, also used as the Settings API option group.
	 */
	private const SLUG = 'wso-smart-order';

	/**
	 * Capability required to view and save the settings.
	 */
	private const CAPABILITY = 'manage_woocommerce';

	/**
	 * AJAX action that runs a live credential check.
	 */
	private const CHECK_ACTION = 'wso_smart_order_check';

	/**
	 * Nonce action for the check request.
	 */
	private const CHECK_NONCE = 'wso_smart_order_check';

	/**
	 * Name of the option array posted by the form.
	 */
	private const FIELD = Settings::OPTION;

	/**
	 * Attaches the admin hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addMenu' ) );
		add_action( 'admin_init', array( $this, 'registerSettings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAssets' ) );
		add_action( 'wp_ajax_' . self::CHECK_ACTION, array( $this, 'handleCheck' ) );
		// options.php requires `manage_options` for every option group unless this filter
		// says otherwise, and shop managers do not have it.
		add_filter( 'option_page_capability_' . self::SLUG, array( $this, 'optionPageCapability' ) );
	}

	/**
	 * Allows shop managers to save the page.
	 *
	 * @return string Capability name.
	 */
	public function optionPageCapability(): string {
		return self::CAPABILITY;
	}

	/**
	 * Adds the submenu entry under WooCommerce.
	 */
	public function addMenu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Smart Order', 'woocommerce-smart-order' ),
			__( 'Smart Order', 'woocommerce-smart-order' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Loads the check script on this screen only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueueAssets( string $hook ): void {
		if ( 'woocommerce_page_' . self::SLUG !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'wso-smart-order-admin',
			plugins_url( 'assets/admin.js', WSO_SMART_ORDER_FILE ),
			array(),
			WSO_SMART_ORDER_VERSION,
			true
		);

		wp_localize_script(
			'wso-smart-order-admin',
			'wsoSmartOrderAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::CHECK_ACTION,
				'nonce'   => wp_create_nonce( self::CHECK_NONCE ),
				'field'   => self::FIELD,
				'strings' => array(
					'checking' => __( 'Checking…', 'woocommerce-smart-order' ),
					'failed'   => __( 'The check request failed.', 'woocommerce-smart-order' ),
				),
			)
		);

		wp_register_style( 'wso-smart-order-admin', false, array(), WSO_SMART_ORDER_VERSION );
		wp_enqueue_style( 'wso-smart-order-admin' );
		wp_add_inline_style(
			'wso-smart-order-admin',
			'.wso-check-result--pass{color:#1e7b34}.wso-check-result--fail{color:#b32d2e}'
		);
	}

	/**
	 * Runs one live credential check against the submitted form and answers the script.
	 */
	public function handleCheck(): void {
		check_ajax_referer( self::CHECK_NONCE, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You are not allowed to test these settings.', 'woocommerce-smart-order' ) ),
				403
			);
		}

		$target = isset( $_POST['target'] ) && is_string( $_POST['target'] )
			? sanitize_key( wp_unslash( $_POST['target'] ) )
			: '';

		if ( ! in_array( $target, array( 'ai', 'telegram' ), true ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Unknown check.', 'woocommerce-smart-order' ) ),
				400
			);
		}

		// The whole form travels with the request: the check must describe what the
		// screen shows, not what was last saved. Read here so the nonce above covers it.
		$raw = isset( $_POST[ self::FIELD ] ) && is_array( $_POST[ self::FIELD ] )
			? wp_unslash( $_POST[ self::FIELD ] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per value in toScalars().
			: array();

		$draft  = Draft::fromInput( $this->toScalars( $raw ) );
		$tester = new ConnectionTester();

		$this->sendResult( 'telegram' === $target ? $tester->checkTelegram( $draft ) : $tester->checkAi( $draft ) );
	}

	/**
	 * Narrows a submitted option array to scalar strings.
	 *
	 * @param array<mixed> $raw Unslashed request data.
	 * @return array<string, string>
	 */
	private function toScalars( array $raw ): array {
		$clean = array();

		foreach ( $raw as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$clean[ (string) $key ] = sanitize_text_field( (string) $value );
			}
		}

		return $clean;
	}

	/**
	 * Registers the option, its sanitizer and the fields.
	 */
	public function registerSettings(): void {
		register_setting(
			self::SLUG,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);

		add_settings_section(
			'wso_main',
			__( 'Order processing', 'woocommerce-smart-order' ),
			array( $this, 'renderSection' ),
			self::SLUG
		);

		$this->addField(
			'enabled',
			__( 'Enable processing', 'woocommerce-smart-order' ),
			'checkbox'
		);
		$this->addField(
			'provider',
			__( 'AI provider', 'woocommerce-smart-order' ),
			'select',
			__( 'Each provider keeps its own API key, so switching providers never sends one service’s key to another.', 'woocommerce-smart-order' )
		);
		$this->addField(
			'base_url',
			__( 'Custom base URL', 'woocommerce-smart-order' ),
			'text',
			__( 'Used only by the “Custom endpoint” provider, e.g. https://openrouter.ai/api/v1 or http://localhost:11434/v1. Must start with http:// or https://.', 'woocommerce-smart-order' )
		);
		$this->addField(
			'model',
			__( 'Model', 'woocommerce-smart-order' ),
			'text',
			__( 'Any model the endpoint serves: gemini-2.5-flash, gpt-4o-mini, claude-3-5-haiku-latest, llama3.2. A name that is not the current provider’s default is passed through as-is.', 'woocommerce-smart-order' )
		);
		$this->addField(
			'notify_mode',
			__( 'Notification transport', 'woocommerce-smart-order' ),
			'select_notify'
		);
		$this->addField(
			'telegram_chat',
			__( 'Telegram group id', 'woocommerce-smart-order' ),
			'text',
			__( 'Numeric id (negative for groups) or an @channel name. The bot has to be a member of the group.', 'woocommerce-smart-order' )
		);
		$this->addField(
			'api_key',
			__( 'AI API key', 'woocommerce-smart-order' ),
			'password',
			__( 'Stored per provider in the database. Leave the field empty to keep the current key.', 'woocommerce-smart-order' )
		);
		$this->addField(
			'telegram_token',
			__( 'Bot token', 'woocommerce-smart-order' ),
			'password',
			__( 'Stored in the database. Leave the field empty to keep the current token.', 'woocommerce-smart-order' )
		);
	}

	/**
	 * Prints the section description.
	 */
	public function renderSection(): void {
		echo '<p>' . esc_html__( 'When a new order arrives, its customer comment is classified for delivery-time requests and the manager is notified.', 'woocommerce-smart-order' ) . '</p>';
	}

	/**
	 * Sanitizes the submitted option array.
	 *
	 * @param mixed $input Raw input from options.php.
	 * @return array<string, mixed>
	 */
	public function sanitize( mixed $input ): array {
		$input   = is_array( $input ) ? $input : array();
		$current = Settings::all();

		$provider = Settings::normalizeProvider(
			isset( $input['provider'] ) && is_scalar( $input['provider'] )
				? (string) $input['provider']
				: (string) ( $current['provider'] ?? '' )
		);

		$notify_mode = isset( $input['notify_mode'] ) && is_scalar( $input['notify_mode'] )
			? (string) $input['notify_mode']
			: '';

		$raw_base = isset( $input['base_url'] ) && is_scalar( $input['base_url'] )
			? trim( (string) $input['base_url'] )
			: '';

		$base_url = Url::normalizeBaseUrl( $raw_base );
		if ( '' !== $raw_base && '' === $base_url ) {
			add_settings_error(
				Settings::OPTION,
				'wso_base_url_invalid',
				sprintf(
					/* translators: %s: the rejected value, truncated. */
					__( 'The custom base URL was discarded: “%s” is not an http:// or https:// address.', 'woocommerce-smart-order' ),
					mb_substr( $raw_base, 0, 40 )
				)
			);
		}

		$telegram_token = isset( $input['telegram_token'] ) && is_scalar( $input['telegram_token'] )
			? trim( (string) $input['telegram_token'] )
			: '';

		$telegram_chat = isset( $input['telegram_chat'] ) && is_scalar( $input['telegram_chat'] )
			? trim( (string) $input['telegram_chat'] )
			: '';

		$typed_key = isset( $input['api_key'] ) && is_scalar( $input['api_key'] )
			? trim( (string) $input['api_key'] )
			: '';

		$keys = is_array( $current['api_keys'] ?? null ) ? $current['api_keys'] : array();

		if ( ! empty( $input['api_key_clear'] ) ) {
			unset( $keys[ $provider ] );
		} elseif ( '' !== $typed_key ) {
			$keys[ $provider ] = sanitize_text_field( $typed_key );
		}

		$clean = array(
			'enabled'        => ! empty( $input['enabled'] ),
			'provider'       => $provider,
			'base_url'       => $base_url,
			'model'          => sanitize_text_field( (string) ( $input['model'] ?? '' ) ),
			'notify_mode'    => Settings::MODE_TELEGRAM === $notify_mode
				? Settings::MODE_TELEGRAM
				: Settings::MODE_LOG,
			'telegram_chat'  => $this->sanitizeChatId( $telegram_chat ),
			'api_keys'       => array_filter( $keys, static fn ( $value ): bool => is_scalar( $value ) && '' !== trim( (string) $value ) ),
			'telegram_token' => '' !== $telegram_token
				? sanitize_text_field( $telegram_token )
				: (string) ( $current['telegram_token'] ?? '' ),
		);

		if ( ! array_key_exists( 'model', $input ) && is_scalar( $current['model'] ?? null ) ) {
			$clean['model'] = (string) $current['model'];
		}

		// Silence here would look like a working setup that never delivers.
		if ( Settings::MODE_TELEGRAM === $clean['notify_mode'] && '' === $clean['telegram_chat'] ) {
			add_settings_error(
				Settings::OPTION,
				'wso_telegram_chat_missing',
				__( 'Telegram is selected but the group id is empty, so messages will be written to the log instead of sent. Add the bot to a group and paste its id.', 'woocommerce-smart-order' ),
				'warning'
			);
		}

		return $clean;
	}


	/**
	 * Accepts a numeric id or an @channel name.
	 *
	 * @param string $value Raw value.
	 */
	private function sanitizeChatId( string $value ): string {
		if ( preg_match( '/^-?\d{1,20}$/', $value ) ) {
			return $value;
		}

		if ( preg_match( '/^@[A-Za-z][A-Za-z0-9_]{4,31}$/', $value ) ) {
			return $value;
		}

		return '';
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Smart Order', 'woocommerce-smart-order' ) . '</h1>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.

		// Without this, a rejected value disappears without a word.
		settings_errors( Settings::OPTION );

		echo '<form method="post" action="options.php">';

		settings_fields( self::SLUG );
		do_settings_sections( self::SLUG );
		submit_button();

		echo '</form></div>';
	}

	/**
	 * Registers one field and its renderer.
	 *
	 * @param string $key         Option key.
	 * @param string $label       Field label.
	 * @param string $type        Renderer type.
	 * @param string $description Optional help text under the control.
	 */
	private function addField( string $key, string $label, string $type, string $description = '' ): void {
		add_settings_field(
			'wso_' . $key,
			$label,
			function () use ( $key, $type, $description ): void {
				echo $this->buildControl( $key, $type, $description ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped in buildControl().
			},
			self::SLUG,
			'wso_main'
		);
	}

	/**
	 * Builds the escaped markup for one control.
	 *
	 * @param string $key         Option key.
	 * @param string $type        Renderer type.
	 * @param string $description Help text shown under the control.
	 */
	private function buildControl( string $key, string $type, string $description = '' ): string {
		$settings = Settings::all();
		$name     = sprintf( '%s[%s]', self::FIELD, $key );
		$current  = $settings[ $key ] ?? '';
		$markup   = '';

		switch ( $type ) {
			case 'checkbox':
				$markup = $this->checkbox( $name, (bool) $current, __( 'Run the pipeline for every new order', 'woocommerce-smart-order' ) );
				break;

			case 'select':
				$markup = $this->select(
					$name,
					Settings::provider(),
					array(
						Settings::PROVIDER_OPENAI    => 'OpenAI',
						Settings::PROVIDER_ANTHROPIC => 'Anthropic',
						Settings::PROVIDER_GEMINI    => __( 'Google Gemini', 'woocommerce-smart-order' ),
						Settings::PROVIDER_CUSTOM    => __( 'Custom endpoint (OpenAI-compatible)', 'woocommerce-smart-order' ),
					)
				);
				break;

			case 'select_notify':
				$markup = $this->select(
					$name,
					(string) $current,
					array(
						Settings::MODE_LOG      => __( 'Log only (no credentials needed)', 'woocommerce-smart-order' ),
						Settings::MODE_TELEGRAM => __( 'Telegram', 'woocommerce-smart-order' ),
					)
				);
				break;

			case 'password':
				$markup = $this->secret( $name, $key, $description );
				break;

			default:
				$markup = sprintf(
					'<input type="text" name="%s" value="%s" class="regular-text">',
					esc_attr( $name ),
					esc_attr( (string) $current )
				);
		}

		if ( 'password' !== $type && '' !== $description ) {
			$markup .= '<p class="description">' . esc_html( $description ) . '</p>';
		}

		return $markup;
	}

	/**
	 * Builds a secret field: an empty input, where the values come from, and a
	 * button that runs a live check against the whole form.
	 *
	 * @param string $name        Field name.
	 * @param string $key         Option key.
	 * @param string $description Help text.
	 */
	private function secret( string $name, string $key, string $description ): string {
		$is_ai    = 'api_key' === $key;
		$target   = $is_ai ? 'ai' : 'telegram';
		$field_id = 'wso-' . str_replace( '_', '-', $key );

		$input = sprintf(
			'<input type="password" id="%s" name="%s" value="" autocomplete="new-password" class="regular-text" placeholder="%s">',
			esc_attr( $field_id ),
			esc_attr( $name ),
			esc_attr__( 'paste a new value to replace the stored one', 'woocommerce-smart-order' )
		);

		$button = sprintf(
			'<button type="button" class="button wso-check" data-target="%s">%s</button>',
			esc_attr( $target ),
			esc_html__( 'Test connection', 'woocommerce-smart-order' )
		);

		$output = sprintf(
			'<span class="wso-check-result" data-target="%s" role="status" aria-live="polite"></span>',
			esc_attr( $target )
		);

		$clear = $is_ai
			? sprintf(
				'<p><label><input type="checkbox" name="%s" value="1"> %s</label></p>',
				esc_attr( sprintf( '%s[api_key_clear]', self::FIELD ) ),
				esc_html__( 'Remove the stored key for the selected provider', 'woocommerce-smart-order' )
			)
			: '';

		return $input . ( $is_ai ? $this->keyStatus() : '' ) . $this->hint( $description ) . $clear . '<p>' . $button . ' ' . $output . '</p>';
	}

	/**
	 * Lists which providers already hold a key.
	 *
	 * Rendered as a list rather than "the current one" because the provider select can
	 * change before the page is saved.
	 */
	private function keyStatus(): string {
		$rows = array();

		foreach ( Settings::providers() as $provider ) {
			$source = Settings::apiKeySourceFor( $provider );
			$label  = \WSO\SmartOrder\Ai\KeyPrefix::label( $provider );

			$status = match ( $source ) {
					Settings::SOURCE_STORED   => __( 'saved in the database', 'woocommerce-smart-order' ),
				default                   => __( 'not set', 'woocommerce-smart-order' ),
			};

			$rows[] = esc_html( sprintf( '%s: %s', $label, $status ) );
		}

		return '<p class="description">' . implode( ' · ', $rows ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each row escaped above.
	}

	/**
	 * Renders optional help text below a control.
	 *
	 * @param string $text Help text.
	 */
	private function hint( string $text ): string {
		if ( '' === $text ) {
			return '';
		}

		return '<p class="description">' . esc_html( $text ) . '</p>';
	}

	/**
	 * Turns a check result into the AJAX envelope.
	 *
	 * @param CheckResult $result Check outcome.
	 */
	private function sendResult( CheckResult $result ): void {
		$payload = $result->toArray();

		if ( $result->ok ) {
			wp_send_json_success( $payload );
		}

		wp_send_json_error( $payload );
	}

	/**
	 * Builds a checkbox control.
	 *
	 * @param string $name    Field name.
	 * @param bool   $checked Current state.
	 * @param string $label   Label text.
	 */
	private function checkbox( string $name, bool $checked, string $label ): string {
		$checked_attribute = $checked ? ' checked="checked"' : '';

		return sprintf(
			'<label><input type="checkbox" name="%s" value="1"%s> %s</label>',
			esc_attr( $name ),
			$checked_attribute,
			esc_html( $label )
		);
	}

	/**
	 * Builds a select control.
	 *
	 * @param string                $name    Field name.
	 * @param string                $current Current value.
	 * @param array<string, string> $choices Value => label pairs.
	 */
	private function select( string $name, string $current, array $choices ): string {
		$options = '';

		foreach ( $choices as $value => $label ) {
			$selected = $value === $current ? ' selected="selected"' : '';
			$options .= sprintf(
				'<option value="%s"%s>%s</option>',
				esc_attr( (string) $value ),
				$selected,
				esc_html( $label )
			);
		}

		return sprintf( '<select name="%s">%s</select>', esc_attr( $name ), $options );
	}
}
