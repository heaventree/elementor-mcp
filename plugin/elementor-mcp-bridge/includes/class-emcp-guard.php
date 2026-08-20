<?php
/**
 * Capability checks and request guards.
 *
 * @package Elementor_MCP_Bridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Central place for "is this caller allowed to do this?".
 *
 * Every check maps onto a real WordPress capability, so the bridge can never
 * grant an account more power over a site than it already has in wp-admin.
 */
class EMCP_Guard {

	/**
	 * Filter name used to allow/deny destructive routes site-wide.
	 */
	const FILTER_ALLOW_DESTRUCTIVE = 'emcp_allow_destructive';

	/**
	 * Can the current user read Elementor documents?
	 *
	 * @return bool
	 */
	public static function can_read() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Can the current user edit this specific post?
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function can_edit_post( $post_id ) {
		return current_user_can( 'edit_post', (int) $post_id );
	}

	/**
	 * Can the current user delete this specific post?
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function can_delete_post( $post_id ) {
		return current_user_can( 'delete_post', (int) $post_id );
	}

	/**
	 * Can the current user change site-wide design (the Elementor kit)?
	 *
	 * @return bool
	 */
	public static function can_manage_globals() {
		return current_user_can( 'edit_theme_options' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Whether destructive operations (delete, site-wide replace) are permitted.
	 *
	 * Off unless a site owner opts in, so an agent cannot mass-delete content
	 * just because it holds valid credentials.
	 *
	 * @return bool
	 */
	public static function destructive_enabled() {
		$enabled = defined( 'EMCP_ALLOW_DESTRUCTIVE' ) ? (bool) EMCP_ALLOW_DESTRUCTIVE : false;

		/**
		 * Filters whether destructive bridge operations are allowed.
		 *
		 * @param bool $enabled Current setting.
		 */
		return (bool) apply_filters( self::FILTER_ALLOW_DESTRUCTIVE, $enabled );
	}

	/**
	 * Guard a destructive call: requires both the opt-in and an explicit
	 * `confirm: true` in the request body.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function check_destructive( $request ) {
		if ( ! self::destructive_enabled() ) {
			return new WP_Error(
				'emcp_destructive_disabled',
				__( 'Destructive operations are disabled. Define EMCP_ALLOW_DESTRUCTIVE as true in wp-config.php (or hook the emcp_allow_destructive filter) to enable them.', 'elementor-mcp-bridge' ),
				array( 'status' => 403 )
			);
		}

		if ( ! $request->get_param( 'confirm' ) ) {
			return new WP_Error(
				'emcp_confirm_required',
				__( 'This operation permanently changes content. Re-send the request with "confirm": true.', 'elementor-mcp-bridge' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Is Elementor loaded and usable?
	 *
	 * @return true|WP_Error
	 */
	public static function require_elementor() {
		if ( ! did_action( 'elementor/loaded' ) || ! class_exists( '\Elementor\Plugin' ) ) {
			return new WP_Error(
				'emcp_elementor_missing',
				__( 'Elementor is not active on this site. Install and activate Elementor, then retry.', 'elementor-mcp-bridge' ),
				array( 'status' => 501 )
			);
		}

		return true;
	}
}
