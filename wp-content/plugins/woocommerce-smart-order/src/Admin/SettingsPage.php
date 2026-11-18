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
use WSO\SmartOrder\Support\CheckResult;

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
	 * AJAX action that runs a live credential check.
	 */
	private const CHECK_ACTION = 'wso_smart_order_check';

	/**
	 * Nonce action for the check request.
	 */
	private const CHECK_NONCE = 'wso_smart_order_check';

	/**
	 * Sets up the screen.
	 *
	 * @param ConnectionTester $tester Live credential checks.
	 */
	public function __construct( private readonly ConnectionTester $tester ) {}

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
	 * Runs one live credential check and answers the admin script.
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

		// An unsaved value wins, so a key can be tested before it is stored.
		$value = isset( $_POST['value'] ) && is_string( $_POST['value'] ) ? sanitize_text_field( wp_unslash( $_POST['value'] ) ) : '';

		$result = 'telegram' === $target ? $this->tester->checkTelegram( $value ) : $this->tester->checkAi( $value );

		$this->sendResult( $result );
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
			'checkbox',
			__( 'Turn this off when another chain already notifies the manager — for example the n8n workflow this plugin ships with. Two chains mean two messages per order.', 'woocommerce-smart-order' )
		);
		$this->addField( 'provider', __( 'AI provider', 'woocommerce-smart-order' ), 'select' );
		$this->addField(
			'model',
			__( 'Model', 'woocommerce-smart-order' ),
			'text',
			__( 'Must be a model the selected provider serves, e.g. gpt-4o-mini or claude-3-5-haiku-latest.', 'woocommerce-smart-order' )
		);
		$this->addField( 'notify_mode', __( 'Notification transport', 'woocommerce-smart-order' ), 'select_notify' );
		$this->addField(
			'telegram_chat',
			__( 'Telegram group id', 'woocommerce-smart-order' ),
			'text',
			__( 'Numeric id (negative for groups) or an @channel name. The bot has to be a member of the group.', 'woocommerce-smart-order' )
		);
		$this->addField(
			'api_key',
			__( 'API key', 'woocommerce-smart-order' ),
			'password',
			__( 'Stored in the database. A WSO_OPENAI_API_KEY or WSO_ANTHROPIC_API_KEY constant in wp-config.php takes priority.', 'woocommerce-smart-order' )
		);
		$this->addField(
			'telegram_token',
			__( 'Bot token', 'woocommerce-smart-order' ),
			'password',
			__( 'Stored in the database. A WSO_TELEGRAM_BOT_TOKEN constant in wp-config.php takes priority.', 'woocommerce-smart-order' )
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
		$name     = sprintf( '%s[%s]', Settings::OPTION, $key );
		$current  = $settings[ $key ] ?? '';
		$markup   = '';

		switch ( $type ) {
			case 'checkbox':
				$markup = $this->checkbox( $name, (bool) $current );
				break;

			case 'select':
				$markup = $this->select(
					$name,
					(string) $current,
					array(
						Settings::PROVIDER_OPENAI    => 'OpenAI',
						Settings::PROVIDER_ANTHROPIC => 'Anthropic',
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
	 * Builds a secret field: an empty input, where the value currently comes from,
	 * and a button that runs a live check.
	 *
	 * @param string $name        Field name.
	 * @param string $key         Option key.
	 * @param string $description Help text shown under the input.
	 */
	private function secret( string $name, string $key, string $description ): string {
		$is_ai    = 'api_key' === $key;
		$source   = $is_ai ? Settings::apiKeySource() : Settings::telegramTokenSource();
		$target   = $is_ai ? 'ai' : 'telegram';
		$field_id = 'wso-' . str_replace( '_', '-', $key );

		$input = sprintf(
			'<input type="password" id="%s" name="%s" value="" autocomplete="new-password" class="regular-text" placeholder="%s">',
			esc_attr( $field_id ),
			esc_attr( $name ),
			esc_attr__( 'leave empty to keep the stored value', 'woocommerce-smart-order' )
		);

		$button = sprintf(
			'<button type="button" class="button wso-check" data-target="%s" data-field="%s">%s</button>',
			esc_attr( $target ),
			esc_attr( $field_id ),
			esc_html__( 'Test connection', 'woocommerce-smart-order' )
		);

		$output = sprintf(
			'<span class="wso-check-result" data-target="%s" role="status" aria-live="polite"></span>',
			esc_attr( $target )
		);

		return $input . $this->hint( $this->sourceText( $source ), $description ) . '<p>' . $button . ' ' . $output . '</p>';
	}

	/**
	 * Renders one hint paragraph: where the secret comes from, then the help text.
	 *
	 * @param string $source Where the effective value comes from.
	 * @param string $help   Optional help text.
	 */
	private function hint( string $source, string $help ): string {
		$text = '' !== $help ? $source . ' ' . $help : $source;

		if ( '' === $text ) {
			return '';
		}

		return '<p class="description">' . esc_html( $text ) . '</p>';
	}

	/**
	 * Describes where the effective secret comes from.
	 *
	 * @param string $source One of the Settings::SOURCE_* values.
	 */
	private function sourceText( string $source ): string {
		return match ( $source ) {
			Settings::SOURCE_CONSTANT => __( 'Currently: set in wp-config.php — the stored value is ignored.', 'woocommerce-smart-order' ),
			Settings::SOURCE_STORED   => __( 'Currently: saved in the database.', 'woocommerce-smart-order' ),
			default                   => __( 'Currently: not set.', 'woocommerce-smart-order' ),
		};
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
