<?php
/**
 * Shared behaviour for the bridge REST controllers.
 *
 * @package Elementor_MCP_Bridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Base controller.
 */
abstract class EMCP_REST_Base {

	/**
	 * Register this controller's routes.
	 *
	 * @return void
	 */
	abstract public function register_routes();

	/**
	 * Register a route in the bridge namespace.
	 *
	 * @param string $route Route pattern.
	 * @param array  $args  Route arguments.
	 * @return void
	 */
	protected function route( $route, array $args ) {
		register_rest_route( EMCP_REST_NAMESPACE, $route, $args );
	}

	/**
	 * Permission callback for read-only routes.
	 *
	 * @return true|WP_Error
	 */
	public function can_read() {
		if ( ! EMCP_Guard::can_read() ) {
			return new WP_Error(
				'emcp_forbidden',
				__( 'You must be logged in as a user who can edit posts. Authenticate with a WordPress application password.', 'elementor-mcp-bridge' ),
				array( 'status' => is_user_logged_in() ? 403 : 401 )
			);
		}

		return true;
	}

	/**
	 * Permission callback for routes that write to a specific post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function can_write( $request ) {
		$post_id = (int) $request->get_param( 'id' );

		if ( $post_id && ! EMCP_Guard::can_edit_post( $post_id ) ) {
			return new WP_Error(
				'emcp_forbidden',
				__( 'You do not have permission to edit this post.', 'elementor-mcp-bridge' ),
				array( 'status' => is_user_logged_in() ? 403 : 401 )
			);
		}

		return $this->can_read();
	}

	/**
	 * Permission callback for site-wide design routes.
	 *
	 * @return true|WP_Error
	 */
	public function can_manage_globals() {
		if ( ! EMCP_Guard::can_manage_globals() ) {
			return new WP_Error(
				'emcp_forbidden',
				__( 'Changing site-wide design settings requires the edit_theme_options capability.', 'elementor-mcp-bridge' ),
				array( 'status' => is_user_logged_in() ? 403 : 401 )
			);
		}

		return true;
	}

	/**
	 * Turn a handler result into a REST response.
	 *
	 * @param mixed $result Handler result.
	 * @return WP_REST_Response|WP_Error
	 */
	protected function respond( $result ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Common pagination args.
	 *
	 * @return array
	 */
	protected function pagination_args() {
		return array(
			'page'    => array(
				'type'        => 'integer',
				'default'     => 1,
				'minimum'     => 1,
				'description' => __( 'Page of results to return.', 'elementor-mcp-bridge' ),
			),
			'perPage' => array(
				'type'        => 'integer',
				'default'     => 20,
				'minimum'     => 1,
				'maximum'     => 100,
				'description' => __( 'Results per page.', 'elementor-mcp-bridge' ),
			),
		);
	}
}
