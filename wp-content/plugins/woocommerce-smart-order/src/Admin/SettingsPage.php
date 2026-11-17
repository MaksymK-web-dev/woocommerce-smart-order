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
 * WooCommerce → Smart Order settings page built on the Settings API.
 *
 * Secrets are never rendered: keys come from wp-config.php constants, and if the option
 * fallback is used the field renders empty so a stored value cannot be read back from
 * the screen.
 */
final class SettingsPage implements HookRegistrar {

	private const SLUG = 'wso-smart-order';

	/**
	 * Attaches the admin hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addMenu' ) );
		add_action( 'admin_init', array( $this, 'registerSettings' ) );
	}

	/**
	 * Adds the submenu entry under WooCommerce.
	 */
	public function addMenu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Smart Order', 'woocommerce-smart-order' ),
			__( 'Smart Order', 'woocommerce-smart-order' ),
			'manage_woocommerce',
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
			static function (): void {
				echo '<p>' . esc_html__(
					'When a new order arrives, its customer comment is classified for delivery-time requests and the manager is notified.',
					'woocommerce-smart-order'
				) . '</p>';
			},
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
	 * Sanitizes the submitted option array.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string, mixed>
	 */
	public function sanitize( mixed $input ): array {
		$input   = is_array( $input ) ? $input : array();
		$current = Settings::all();

		$clean = array(
			'enabled'        => ! empty( $input['enabled'] ),
			'provider'       => in_array( $input['provider'] ?? '', array( Settings::PROVIDER_OPENAI, Settings::PROVIDER_ANTHROPIC ), true )
				? (string) $input['provider']
				: Settings::PROVIDER_OPENAI,
			'model'          => sanitize_text_field( (string) ( $input['model'] ?? '' ) ),
			'notify_mode'    => Settings::MODE_TELEGRAM === ( $input['notify_mode'] ?? '' )
				? Settings::MODE_TELEGRAM
				: Settings::MODE_LOG,
			'telegram_chat'  => sanitize_text_field( (string) ( $input['telegram_chat'] ?? '' ) ),
			'api_key'        => '' !== (string) ( $input['api_key'] ?? '' )
				? sanitize_text_field( (string) $input['api_key'] )
				: (string) ( $current['api_key'] ?? '' ),
			'telegram_token' => '' !== (string) ( $input['telegram_token'] ?? '' )
				? sanitize_text_field( (string) $input['telegram_token'] )
				: (string) ( $current['telegram_token'] ?? '' ),
		);

		if ( ! array_key_exists( 'api_key', $input ) && array_key_exists( 'api_key', $current ) ) {
			$clean['api_key'] = $current['api_key'];
		}

		return $clean;
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Smart Order', 'woocommerce-smart-order' ) . '</h1>';
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
			fn () => $this->renderField( $key, $type ),
			self::SLUG,
			'wso_main'
		);
	}

	/**
	 * Renders one control.
	 *
	 * @param string $key  Option key.
	 * @param string $type Renderer type.
	 */
	private function renderField( string $key, string $type ): void {
		$settings = Settings::all();
		$name     = sprintf( '%s[%s]', Settings::OPTION, $key );

		switch ( $type ) {
			case 'checkbox':
				printf(
					'<label><input type="checkbox" name="%s" value="1" %s> %s</label>',
					esc_attr( $name ),
					checked( (bool) ( $settings[ $key ] ?? false ), true, false ),
					esc_html__( 'Run the pipeline for every new order', 'woocommerce-smart-order' )
				);
				break;

			case 'select':
				printf( '<select name="%s">', esc_attr( $name ) );
				foreach ( array(
					Settings::PROVIDER_OPENAI    => 'OpenAI',
					Settings::PROVIDER_ANTHROPIC => 'Anthropic',
				) as $value => $text ) {
					printf(
						'<option value="%s" %s>%s</option>',
						esc_attr( $value ),
						selected( (string) ( $settings[ $key ] ?? '' ), $value, false ),
						esc_html( $text )
					);
				}
				echo '</select>';
				break;

			case 'select_notify':
				printf( '<select name="%s">', esc_attr( $name ) );
				foreach ( array(
					Settings::MODE_LOG      => __( 'Log only (no credentials needed)', 'woocommerce-smart-order' ),
					Settings::MODE_TELEGRAM => __( 'Telegram', 'woocommerce-smart-order' ),
				) as $value => $text ) {
					printf(
						'<option value="%s" %s>%s</option>',
						esc_attr( $value ),
						selected( (string) ( $settings[ $key ] ?? '' ), $value, false ),
						esc_html( $text )
					);
				}
				echo '</select>';
				break;

			case 'password':
				printf(
					'<input type="password" name="%s" value="" autocomplete="new-password" class="regular-text" placeholder="%s">',
					esc_attr( $name ),
					esc_attr__( 'leave empty to keep the stored value', 'woocommerce-smart-order' )
				);
				break;

			default:
				printf(
					'<input type="text" name="%s" value="%s" class="regular-text">',
					esc_attr( $name ),
					esc_attr( (string) ( $settings[ $key ] ?? '' ) )
				);
		}
	}
}
