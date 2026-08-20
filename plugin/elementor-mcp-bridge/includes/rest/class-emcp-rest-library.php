<?php
/**
 * Template library routes.
 *
 * @package Elementor_MCP_Bridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Library controller.
 */
class EMCP_REST_Library extends EMCP_REST_Base {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$this->route(
			'/templates',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'can_read' ),
					'args'                => array_merge(
						$this->pagination_args(),
						array(
							'search' => array( 'type' => 'string' ),
							'type'   => array(
								'type'        => 'string',
								'description' => __( 'Template type, e.g. container, section, page, header, footer, popup.', 'elementor-mcp-bridge' ),
							),
						)
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'can_read' ),
					'args'                => array(
						'title'     => array( 'type' => 'string' ),
						'type'      => array(
							'type'    => 'string',
							'default' => 'container',
						),
						'elements'  => array( 'type' => 'array' ),
						'sourceId'  => array(
							'type'        => 'integer',
							'description' => __( 'Copy the layout from this document instead of passing elements.', 'elementor-mcp-bridge' ),
						),
						'elementId' => array(
							'type'        => 'string',
							'description' => __( 'With sourceId, save only this element subtree.', 'elementor-mcp-bridge' ),
						),
					),
				),
			)
		);

		$this->route(
			'/templates/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'show' ),
				'permission_callback' => array( $this, 'can_read' ),
				'args'                => array(
					'id'       => array(
						'type'     => 'integer',
						'required' => true,
					),
					'freshIds' => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => __( 'Regenerate element IDs so the tree can be pasted without collisions.', 'elementor-mcp-bridge' ),
					),
				),
			)
		);

		$this->route(
			'/templates/(?P<id>\d+)/export',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export' ),
				'permission_callback' => array( $this, 'can_read' ),
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);

		$this->route(
			'/templates/import',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import' ),
				'permission_callback' => array( $this, 'can_read' ),
				'args'                => array(
					'payload' => array(
						'type'        => 'object',
						'required'    => true,
						'description' => __( 'A decoded Elementor template export JSON object.', 'elementor-mcp-bridge' ),
					),
					'title'   => array( 'type' => 'string' ),
				),
			)
		);
	}

	/**
	 * List templates.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function index( $request ) {
		return $this->respond(
			EMCP_Library::query(
				array(
					'search'  => $request->get_param( 'search' ),
					'type'    => $request->get_param( 'type' ),
					'page'    => $request->get_param( 'page' ),
					'perPage' => $request->get_param( 'perPage' ),
				)
			)
		);
	}

	/**
	 * Save a template.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create( $request ) {
		return $this->respond(
			EMCP_Library::save(
				array(
					'title'     => $request->get_param( 'title' ),
					'type'      => $request->get_param( 'type' ),
					'elements'  => $request->get_param( 'elements' ),
					'sourceId'  => $request->get_param( 'sourceId' ),
					'elementId' => $request->get_param( 'elementId' ),
				)
			)
		);
	}

	/**
	 * Read a template's element tree.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function show( $request ) {
		return $this->respond(
			EMCP_Library::content(
				(int) $request->get_param( 'id' ),
				(bool) $request->get_param( 'freshIds' )
			)
		);
	}

	/**
	 * Export a template as Elementor JSON.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function export( $request ) {
		return $this->respond( EMCP_Library::export( (int) $request->get_param( 'id' ) ) );
	}

	/**
	 * Import an Elementor template JSON payload.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function import( $request ) {
		$payload = $request->get_param( 'payload' );

		if ( ! is_array( $payload ) ) {
			return new WP_Error(
				'emcp_bad_payload',
				__( '"payload" must be the decoded Elementor export object.', 'elementor-mcp-bridge' ),
				array( 'status' => 400 )
			);
		}

		return $this->respond(
			EMCP_Library::import( $payload, (string) $request->get_param( 'title' ) )
		);
	}
}
