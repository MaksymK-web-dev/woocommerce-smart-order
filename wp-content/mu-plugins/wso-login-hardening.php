<?php
/**
 * Plugin Name: WSO — Login Hardening
 * Description: Stops the login form from revealing which usernames exist.
 * Version:     1.0.0
 * Requires PHP: 8.1
 *
 * @package WSO\Auth
 */

declare( strict_types = 1 );

namespace WSO\Auth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replaces the username-specific login errors with one neutral message.
 *
 * WordPress answers "unknown username" and "wrong password" differently, which lets
 * anyone enumerate the accounts of a shop by trying names.
 */
final class LoginErrors {

	/**
	 * Error codes that identify which of the two fields was wrong.
	 *
	 * @var string[]
	 */
	private const REVEALING = array( 'invalid_username', 'invalid_email', 'incorrect_password' );

	/**
	 * Attaches the filter. Called once, at load time.
	 */
	public static function boot(): void {
		add_filter( 'wp_login_errors', array( self::class, 'neutralise' ), 10, 1 );
		add_filter( 'authenticate', array( self::class, 'neutraliseAuthError' ), 99, 1 );
	}

	/**
	 * Rewrites the errors shown above the login form.
	 *
	 * @param mixed $errors Whatever the previous filter left.
	 * @return mixed
	 */
	public static function neutralise( mixed $errors ): mixed {
		if ( ! $errors instanceof \WP_Error ) {
			return $errors;
		}

		$revealing = false;

		foreach ( self::REVEALING as $code ) {
			if ( $errors->get_error_message( $code ) ) {
				$errors->remove( $code );
				$revealing = true;
			}
		}

		if ( $revealing ) {
			$errors->add(
				'wso_login_failed',
				__( 'The username or password is incorrect.', 'woocommerce-smart-order' )
			);
		}

		return $errors;
	}

	/**
	 * Rewrites the error returned by the authentication stack.
	 *
	 * @param mixed $user Whatever the previous filter left.
	 * @return mixed
	 */
	public static function neutraliseAuthError( mixed $user ): mixed {
		if ( ! $user instanceof \WP_Error ) {
			return $user;
		}

		$codes = $user->get_error_codes();

		foreach ( self::REVEALING as $code ) {
			if ( in_array( $code, $codes, true ) ) {
				return new \WP_Error(
					'wso_login_failed',
					__( 'The username or password is incorrect.', 'woocommerce-smart-order' )
				);
			}
		}

		return $user;
	}

	/**
	 * Keeps the class uninstantiable.
	 */
	private function __construct() {}
}

LoginErrors::boot();
