<?php
/**
 * Site-wide design routes.
 *
 * @package Elementor_MCP_Bridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Globals controller.
 */
class EMCP_REST_Globals extends EMCP_REST_Base {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$this->route(
			'/kit',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'show' ),
					'permission_callback' => array( $this, 'can_read' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'can_manage_globals' ),
					'args'                => array(
						'settings' => array(
							'type'        => 'object',
							'required'    => true,
							'description' => __( 'Kit settings to merge, e.g. { "system_colors": [ ... ] }.', 'elementor-mcp-bridge' ),
						),
					),
				),
			)
		);

		$this->route(
			'/kit/colors',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'set_color' ),
				'permission_callback' => array( $this, 'can_manage_globals' ),
				'args'                => array(
					'id'    => array(
						'type'        => 'string',
						'required'    => true,
						'description' => __( 'Global colour ID (primary, secondary, text, accent) or its title.', 'elementor-mcp-bridge' ),
					),
					'value' => array(
						'type'        => 'string',
						'required'    => true,
						'description' => __( 'Hex colour, e.g. #1A73E8.', 'elementor-mcp-bridge' ),
					),
					'title' => array( 'type' => 'string' ),
				),
			)
		);

		$this->route(
			'/kit/custom-css',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'custom_css' ),
					'permission_callback' => array( $this, 'can_read' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'set_custom_css' ),
					'permission_callback' => array( $this, 'can_manage_globals' ),
					'args'                => array(
						'css' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);

		$this->route(
			'/global-classes',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'global_classes' ),
				'permission_callback' => array( $this, 'can_read' ),
			)
		);
	}

	/**
	 * Read the kit.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function show() {
		return $this->respond( EMCP_Globals::read() );
	}

	/**
	 * Merge settings into the kit.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update( $request ) {
		$settings = $request->get_param( 'settings' );

		if ( ! is_array( $settings ) ) {
			return new WP_Error(
				'emcp_bad_settings',
				__( '"settings" must be an object.', 'elementor-mcp-bridge' ),
				array( 'status' => 400 )
			);
		}

		return $this->respond( EMCP_Globals::update( $settings ) );
	}

	/**
	 * Set one global colour.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_color( $request ) {
		return $this->respond(
			EMCP_Globals::set_color(
				(string) $request->get_param( 'id' ),
				(string) $request->get_param( 'value' ),
				(string) $request->get_param( 'title' )
			)
		);
	}

	/**
	 * Read site custom CSS.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function custom_css() {
		return $this->respond( EMCP_Globals::read_custom_css() );
	}

	/**
	 * Write site custom CSS.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_custom_css( $request ) {
		$result = EMCP_Globals::update( array( 'custom_css' => (string) $request->get_param( 'css' ) ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->respond( EMCP_Globals::read_custom_css() );
	}

	/**
	 * Read v4 global classes.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function global_classes() {
		return $this->respond( EMCP_Globals::read_global_classes() );
	}
}
