<?php
/**
 * Admin settings screen.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Admin;

use WSO\SmartOrder\Config\Settings;
use WSO\SmartOrder\Contract\HookRegistrar;

/**
 * WooCommerce → Smart Order settings screen.
 *
 * Secrets are never printed: an option fallback renders as an empty field.
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
	 * Attaches the admin hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addMenu' ) );
		add_action( 'admin_init', array( $this, 'registerSettings' ) );
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
		$page_title = __( 'Smart Order', 'woocommerce-smart-order' );
		$menu_title = __( 'Smart Order', 'woocommerce-smart-order' );

		add_submenu_page(
			'woocommerce',
			$page_title,
			$menu_title,
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
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

		$this->addField( 'enabled', __( 'Enable processing', 'woocommerce-smart-order' ), 'checkbox' );
		$this->addField( 'provider', __( 'AI provider', 'woocommerce-smart-order' ), 'select' );
		$this->addField( 'model', __( 'Model', 'woocommerce-smart-order' ), 'text' );
		$this->addField( 'notify_mode', __( 'Notification transport', 'woocommerce-smart-order' ), 'select_notify' );
		$this->addField( 'telegram_chat', __( 'Telegram group id', 'woocommerce-smart-order' ), 'text' );
		$this->addField( 'api_key', __( 'API key (dev only — prefer the constant)', 'woocommerce-smart-order' ), 'password' );
		$this->addField( 'telegram_token', __( 'Bot token (dev only — prefer the constant)', 'woocommerce-smart-order' ), 'password' );
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

		$provider = isset( $input['provider'] ) && is_scalar( $input['provider'] )
			? (string) $input['provider']
			: '';

		$notify_mode = isset( $input['notify_mode'] ) && is_scalar( $input['notify_mode'] )
			? (string) $input['notify_mode']
			: '';

		$api_key = isset( $input['api_key'] ) && is_scalar( $input['api_key'] )
			? trim( (string) $input['api_key'] )
			: '';

		$telegram_token = isset( $input['telegram_token'] ) && is_scalar( $input['telegram_token'] )
			? trim( (string) $input['telegram_token'] )
			: '';

		$telegram_chat = isset( $input['telegram_chat'] ) && is_scalar( $input['telegram_chat'] )
			? trim( (string) $input['telegram_chat'] )
			: '';

		$clean = array(
			'enabled'        => ! empty( $input['enabled'] ),
			'provider'       => in_array( $provider, array( Settings::PROVIDER_OPENAI, Settings::PROVIDER_ANTHROPIC ), true )
				? $provider
				: Settings::PROVIDER_OPENAI,
			'model'          => sanitize_text_field( (string) ( $input['model'] ?? '' ) ),
			'notify_mode'    => Settings::MODE_TELEGRAM === $notify_mode
				? Settings::MODE_TELEGRAM
				: Settings::MODE_LOG,
			// A Telegram chat is a numeric id (negative for groups) or a public @username.
			'telegram_chat'  => $this->sanitizeChatId( $telegram_chat ),
			// Empty means "keep what is stored" — the field is never prefilled.
			'api_key'        => '' !== $api_key
				? sanitize_text_field( $api_key )
				: (string) ( $current['api_key'] ?? '' ),
			'telegram_token' => '' !== $telegram_token
				? sanitize_text_field( $telegram_token )
				: (string) ( $current['telegram_token'] ?? '' ),
		);

		if ( ! array_key_exists( 'model', $input ) && array_key_exists( 'model', $current ) ) {
			$clean['model'] = (string) $current['model'];
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

		$title = esc_html__( 'Smart Order', 'woocommerce-smart-order' );

		echo '<div class="wrap"><h1>' . $title . '</h1>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		echo '<form method="post" action="options.php">';

		settings_fields( self::SLUG );
		do_settings_sections( self::SLUG );
		submit_button();

		echo '</form></div>';
	}

	/**
	 * Registers one field and its renderer.
	 *
	 * @param string $key   Option key.
	 * @param string $label Field label.
	 * @param string $type  Renderer type.
	 */
	private function addField( string $key, string $label, string $type ): void {
		add_settings_field(
			'wso_' . $key,
			$label,
			function () use ( $key, $type ): void {
				echo $this->buildControl( $key, $type ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped in buildControl().
			},
			self::SLUG,
			'wso_main'
		);
	}

	/**
	 * Builds the escaped markup for one control.
	 *
	 * @param string $key  Option key.
	 * @param string $type Renderer type.
	 */
	private function buildControl( string $key, string $type ): string {
		$settings = Settings::all();
		$name     = sprintf( '%s[%s]', Settings::OPTION, $key );
		$current  = $settings[ $key ] ?? '';

		switch ( $type ) {
			case 'checkbox':
				return $this->checkbox( $name, (bool) $current );

			case 'select':
				return $this->select(
					$name,
					(string) $current,
					array(
						Settings::PROVIDER_OPENAI    => 'OpenAI',
						Settings::PROVIDER_ANTHROPIC => 'Anthropic',
					)
				);

			case 'select_notify':
				return $this->select(
					$name,
					(string) $current,
					array(
						Settings::MODE_LOG      => __( 'Log only (no credentials needed)', 'woocommerce-smart-order' ),
						Settings::MODE_TELEGRAM => __( 'Telegram', 'woocommerce-smart-order' ),
					)
				);

			case 'password':
				return sprintf(
					'<input type="password" name="%s" value="" autocomplete="new-password" class="regular-text" placeholder="%s">',
					esc_attr( $name ),
					esc_attr__( 'leave empty to keep the stored value', 'woocommerce-smart-order' )
				);

			default:
				return sprintf(
					'<input type="text" name="%s" value="%s" class="regular-text">',
					esc_attr( $name ),
					esc_attr( (string) $current )
				);
		}
	}

	/**
	 * Builds a checkbox control.
	 *
	 * @param string $name    Field name.
	 * @param bool   $checked Current state.
	 */
	private function checkbox( string $name, bool $checked ): string {
		$checked_attribute = $checked ? ' checked="checked"' : '';
		$label             = esc_html__( 'Run the pipeline for every new order', 'woocommerce-smart-order' );

		return sprintf(
			'<label><input type="checkbox" name="%s" value="1"%s> %s</label>',
			esc_attr( $name ),
			$checked_attribute,
			$label
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
