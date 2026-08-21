<?php
/**
 * Cross-cutting tools: search, replace, usage, cache, media, native proxy.
 *
 * @package Elementor_MCP_Bridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tools controller.
 */
class EMCP_REST_Tools extends EMCP_REST_Base {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$this->route(
			'/search',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'search' ),
				'permission_callback' => array( $this, 'can_read' ),
				'args'                => array(
					'text'          => array(
						'type'        => 'string',
						'description' => __( 'Text to find inside widget settings.', 'elementor-mcp-bridge' ),
					),
					'widgetType'    => array(
						'type'        => 'string',
						'description' => __( 'Only match this widget or element type.', 'elementor-mcp-bridge' ),
					),
					'settingKey'    => array(
						'type'        => 'string',
						'description' => __( 'Only match elements that define this settings key.', 'elementor-mcp-bridge' ),
					),
					'postIds'       => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
					'postTypes'     => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'caseSensitive' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'limit'         => array(
						'type'    => 'integer',
						'default' => 100,
						'minimum' => 1,
						'maximum' => 500,
					),
				),
			)
		);

		$this->route(
			'/replace',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'replace' ),
				'permission_callback' => array( $this, 'can_read' ),
				'args'                => array(
					'search'        => array(
						'type'     => 'string',
						'required' => true,
					),
					'replace'       => array(
						'type'    => 'string',
						'default' => '',
					),
					'dryRun'        => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => __( 'Defaults to true. Run a dry run first and read the report before committing.', 'elementor-mcp-bridge' ),
					),
					'confirm'       => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Required when dryRun is false.', 'elementor-mcp-bridge' ),
					),
					'postIds'       => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
					'postTypes'     => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'caseSensitive' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		$this->route(
			'/usage',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'usage' ),
				'permission_callback' => array( $this, 'can_read' ),
				'args'                => array(
					'postTypes' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
			)
		);

		$this->route(
			'/cache/flush',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'flush' ),
				'permission_callback' => array( $this, 'can_read' ),
				'args'                => array(
					'postId' => array(
						'type'        => 'integer',
						'default'     => 0,
						'description' => __( 'Flush one document, or 0 to regenerate everything.', 'elementor-mcp-bridge' ),
					),
				),
			)
		);

		$this->route(
			'/media/sideload',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'sideload' ),
				'permission_callback' => array( $this, 'can_upload' ),
				'args'                => array(
					'url'         => array(
						'type'     => 'string',
						'required' => true,
					),
					'title'       => array( 'type' => 'string' ),
					'altText'     => array( 'type' => 'string' ),
					'attachToPost' => array(
						'type'    => 'integer',
						'default' => 0,
					),
				),
			)
		);

		$this->route(
			'/native-mcp/proxy',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'native_proxy' ),
				'permission_callback' => array( $this, 'can_read' ),
				'args'                => array(
					'tool'  => array(
						'type'     => 'string',
						'required' => true,
					),
					'input' => array(
						'type'    => 'object',
						'default' => array(),
					),
				),
			)
		);
	}

	/**
	 * Permission callback for media uploads.
	 *
	 * @return true|WP_Error
	 */
	public function can_upload() {
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error(
				'emcp_forbidden',
				__( 'You do not have permission to upload files.', 'elementor-mcp-bridge' ),
				array( 'status' => is_user_logged_in() ? 403 : 401 )
			);
		}

		return true;
	}

	/**
	 * Search Elementor data.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function search( $request ) {
		return $this->respond(
			EMCP_Scanner::search(
				array(
					'text'          => $request->get_param( 'text' ),
					'widgetType'    => $request->get_param( 'widgetType' ),
					'settingKey'    => $request->get_param( 'settingKey' ),
					'postIds'       => $request->get_param( 'postIds' ),
					'postTypes'     => $request->get_param( 'postTypes' ),
					'caseSensitive' => $request->get_param( 'caseSensitive' ),
					'limit'         => $request->get_param( 'limit' ),
				)
			)
		);
	}

	/**
	 * Find and replace across Elementor data.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function replace( $request ) {
		$dry_run = (bool) $request->get_param( 'dryRun' );

		if ( ! $dry_run ) {
			$allowed = EMCP_Guard::check_destructive( (bool) $request->get_param( 'confirm' ) );

			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}
		}

		return $this->respond(
			EMCP_Scanner::replace(
				array(
					'search'        => $request->get_param( 'search' ),
					'replace'       => $request->get_param( 'replace' ),
					'dryRun'        => $dry_run,
					'postIds'       => $request->get_param( 'postIds' ),
					'postTypes'     => $request->get_param( 'postTypes' ),
					'caseSensitive' => $request->get_param( 'caseSensitive' ),
				)
			)
		);
	}

	/**
	 * Site-wide widget usage census.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function usage( $request ) {
		return $this->respond(
			EMCP_Scanner::usage( array( 'postTypes' => $request->get_param( 'postTypes' ) ) )
		);
	}

	/**
	 * Regenerate Elementor CSS.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function flush( $request ) {
		$ready = EMCP_Guard::require_elementor();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$post_id = (int) $request->get_param( 'postId' );

		EMCP_Documents::flush_css( $post_id );

		return $this->respond(
			array(
				'flushed' => true,
				'scope'   => $post_id > 0 ? 'document' : 'site',
				'postId'  => $post_id ?: null,
			)
		);
	}

	/**
	 * Pull a remote image into the media library.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function sideload( $request ) {
		$url = esc_url_raw( (string) $request->get_param( 'url' ) );

		if ( ! $url || ! wp_http_validate_url( $url ) ) {
			return new WP_Error(
				'emcp_bad_url',
				__( 'Provide an absolute http(s) URL to download.', 'elementor-mcp-bridge' ),
				array( 'status' => 400 )
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_sideload_image(
			$url,
			(int) $request->get_param( 'attachToPost' ),
			(string) $request->get_param( 'title' ),
			'id'
		);

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$alt = (string) $request->get_param( 'altText' );

		if ( '' !== $alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		}

		return $this->respond(
			array(
				'id'    => (int) $attachment_id,
				'url'   => wp_get_attachment_url( $attachment_id ),
				'alt'   => $alt,
				'title' => get_the_title( $attachment_id ),
				// Elementor image controls expect this exact shape.
				'imageControlValue' => array(
					'id'  => (int) $attachment_id,
					'url' => wp_get_attachment_url( $attachment_id ),
				),
			)
		);
	}

	/**
	 * Call one of Elementor's own MCP abilities, when the site has them.
	 *
	 * Elementor 4.3 ships an MCP module covering v4 atomic features (global
	 * classes, variables, components). Where it is available this route lets a
	 * client reach those abilities without a second connection.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function native_proxy( $request ) {
		if ( ! class_exists( '\Elementor\Modules\Mcp\Module' ) ) {
			return new WP_Error(
				'emcp_native_mcp_unavailable',
				__( 'This site does not have Elementor\'s built-in MCP module. It requires Elementor 4.3 or newer.', 'elementor-mcp-bridge' ),
				array( 'status' => 501 )
			);
		}

		$proxy = new WP_REST_Request( 'POST', '/elementor/v1/mcp-proxy' );
		$proxy->set_param( 'tool', (string) $request->get_param( 'tool' ) );
		$proxy->set_param( 'input', (array) $request->get_param( 'input' ) );

		$response = rest_do_request( $proxy );

		if ( $response->is_error() ) {
			return $response->as_error();
		}

		return $this->respond( $response->get_data() );
	}
}
