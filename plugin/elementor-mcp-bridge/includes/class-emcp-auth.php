<?php
/**
 * Bearer-token compatibility for MCP clients that do not send Basic auth.
 *
 * @package Elementor_MCP_Bridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Auth shim.
 *
 * WordPress's built-in Application Passwords feature already authenticates
 * `Authorization: Basic base64(username:app_password)` on every REST route,
 * with no code needed here — that is how every other bridge route already
 * works. Some MCP clients, though, only offer a single opaque "token" field
 * and send it as `Authorization: Bearer <token>` instead of Basic.
 *
 * Rather than inventing a second credential type, this treats the bearer
 * token as being that same base64(username:app_password) value: a user
 * connecting an MCP client encodes it once and pastes the result into
 * whichever field their client offers. One credential, two header shapes.
 */
class EMCP_Auth {

	/**
	 * Register the shim.
	 *
	 * Priority 30 runs after WordPress's own Basic-auth application-password
	 * authenticator (core hooks that at priority 20), so this only ever
	 * fires when Basic auth did not already resolve a user.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'determine_current_user', array( __CLASS__, 'authenticate_bearer' ), 30 );
	}

	/**
	 * @param int|false $user_id Already-resolved user id, or false.
	 * @return int|false
	 */
	public static function authenticate_bearer( $user_id ) {
		if ( $user_id ) {
			return $user_id;
		}

		if ( empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			return $user_id;
		}

		if ( ! preg_match( '/^Bearer\s+(.+)$/i', trim( $_SERVER['HTTP_AUTHORIZATION'] ), $matches ) ) {
			return $user_id;
		}

		$decoded = base64_decode( trim( $matches[1] ), true );

		if ( false === $decoded || false === strpos( $decoded, ':' ) ) {
			return $user_id;
		}

		list( $username, $app_password ) = explode( ':', $decoded, 2 );

		if ( '' === $username || '' === $app_password || ! function_exists( 'wp_authenticate_application_password' ) ) {
			return $user_id;
		}

		// The exact function core's own determine_current_user hook
		// (wp_validate_application_password()) calls, normally fed from
		// $_SERVER['PHP_AUTH_USER']/PHP_AUTH_PW. Reusing it directly means
		// this shim gets every check WordPress itself applies — user lookup,
		// whether application passwords are enabled, the
		// application_password_failed_authentication and
		// application_password_did_authenticate hooks other plugins may rely
		// on — for free, rather than re-deriving any of it here.
		$authenticated = wp_authenticate_application_password( null, $username, $app_password );

		return ( $authenticated instanceof \WP_User ) ? $authenticated->ID : $user_id;
	}
}
