<?php
/**
 * Capability guards for MCP tool handlers.
 *
 * @package Elementor_MCP_Bridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * MCP-side guards.
 *
 * REST routes get their capability check from `permission_callback`, run by
 * WordPress before the route's handler is ever called. MCP tool handlers call
 * the same domain classes those REST handlers call, but skip the REST
 * dispatch entirely — so every check a permission_callback would have made
 * has to be made here instead, explicitly, at the top of each tool handler.
 * This class exists so that mapping is written once and is easy to audit
 * against includes/rest/*.php rather than re-derived per tool.
 */
class EMCP_MCP_Guard {

	/**
	 * The baseline read check every read-only tool uses (mirrors can_read()
	 * on every REST controller).
	 *
	 * @return true|WP_Error
	 */
	public static function read() {
		if ( ! EMCP_Guard::can_read() ) {
			return new WP_Error(
				'emcp_forbidden',
				__( 'This account cannot edit posts, so it cannot use this tool.', 'elementor-mcp-bridge' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * The check element-editing tools use (mirrors can_write() on the
	 * documents REST controller).
	 *
	 * @param int $post_id Post ID.
	 * @return true|WP_Error
	 */
	public static function write( $post_id ) {
		$read = self::read();

		if ( is_wp_error( $read ) ) {
			return $read;
		}

		if ( ! EMCP_Guard::can_edit_post( $post_id ) ) {
			return new WP_Error(
				'emcp_forbidden',
				__( 'This account cannot edit this post.', 'elementor-mcp-bridge' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * The check destructive post deletion uses (mirrors can_write() plus the
	 * destructive-operation gate the documents REST controller applies before
	 * a forced delete).
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $force   Whether this is a permanent delete.
	 * @param bool $confirm Whether the caller passed confirm: true.
	 * @return true|WP_Error
	 */
	public static function delete( $post_id, $force, $confirm ) {
		if ( ! EMCP_Guard::can_delete_post( $post_id ) ) {
			return new WP_Error(
				'emcp_forbidden',
				__( 'This account cannot delete this post.', 'elementor-mcp-bridge' ),
				array( 'status' => 403 )
			);
		}

		if ( $force ) {
			return EMCP_Guard::check_destructive( $confirm );
		}

		return true;
	}

	/**
	 * The check site-wide design tools use (mirrors can_manage_globals() on
	 * the globals REST controller — EMCP_Globals also self-checks this, so it
	 * is belt-and-braces here, matching the REST layer exactly).
	 *
	 * @return true|WP_Error
	 */
	public static function manage_globals() {
		$read = self::read();

		if ( is_wp_error( $read ) ) {
			return $read;
		}

		if ( ! EMCP_Guard::can_manage_globals() ) {
			return new WP_Error(
				'emcp_forbidden',
				__( 'Changing site-wide design settings requires the edit_theme_options capability.', 'elementor-mcp-bridge' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * The check the media upload tool uses (mirrors can_upload() on the tools
	 * REST controller).
	 *
	 * @return true|WP_Error
	 */
	public static function upload() {
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error(
				'emcp_forbidden',
				__( 'This account cannot upload files.', 'elementor-mcp-bridge' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}
}
