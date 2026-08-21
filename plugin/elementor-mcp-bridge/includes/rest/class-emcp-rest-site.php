<?php
/**
 * Site, widget and schema discovery routes.
 *
 * @package Elementor_MCP_Bridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Discovery controller.
 */
class EMCP_REST_Site extends EMCP_REST_Base {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$this->route(
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'can_read' ),
			)
		);

		$this->route(
			'/widgets',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'widgets' ),
				'permission_callback' => array( $this, 'can_read' ),
				'args'                => array(
					'search'        => array(
						'type'        => 'string',
						'description' => __( 'Filter by name, title or keyword.', 'elementor-mcp-bridge' ),
					),
					'category'      => array(
						'type'        => 'string',
						'description' => __( 'Filter by panel category slug.', 'elementor-mcp-bridge' ),
					),
					'includeHidden' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Include widgets hidden from the editor panel.', 'elementor-mcp-bridge' ),
					),
				),
			)
		);

		$this->route(
			'/widgets/(?P<name>[\w-]+)/schema',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'widget_schema' ),
				'permission_callback' => array( $this, 'can_read' ),
				'args'                => array(
					'name'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'compact' => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => __( 'Trim CSS selector maps and render hints from each control.', 'elementor-mcp-bridge' ),
					),
				),
			)
		);

		$this->route(
			'/elements',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'elements' ),
				'permission_callback' => array( $this, 'can_read' ),
			)
		);

		$this->route(
			'/elements/(?P<name>[\w-]+)/schema',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'element_schema' ),
				'permission_callback' => array( $this, 'can_read' ),
				'args'                => array(
					'name'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'compact' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);

		$this->route(
			'/categories',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'categories' ),
				'permission_callback' => array( $this, 'can_read' ),
			)
		);

		$this->route(
			'/dynamic-tags',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'dynamic_tags' ),
				'permission_callback' => array( $this, 'can_read' ),
			)
		);
	}

	/**
	 * Environment report: what is installed, what the bridge can do here.
	 *
	 * @return WP_REST_Response
	 */
	public function status() {
		$elementor_active = did_action( 'elementor/loaded' ) && class_exists( '\Elementor\Plugin' );

		$payload = array(
			'bridgeVersion'   => EMCP_VERSION,
			'restNamespace'   => EMCP_REST_NAMESPACE,
			'wordpress'       => get_bloginfo( 'version' ),
			'php'             => PHP_VERSION,
			'siteUrl'         => get_site_url(),
			'elementorActive' => $elementor_active,
			'elementorVersion' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
			'elementorPro'    => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : null,
			'destructiveEnabled' => EMCP_Guard::destructive_enabled(),
			'user'            => array(
				'id'           => get_current_user_id(),
				'canEditPosts' => current_user_can( 'edit_posts' ),
				'canManageGlobals' => EMCP_Guard::can_manage_globals(),
				'canUploadFiles'   => current_user_can( 'upload_files' ),
			),
			'nativeMcp'       => $this->native_mcp_state(),
			'oauth'           => array(
				'issuer'               => EMCP_OAuth::issuer(),
				'authorizationEndpoint' => EMCP_OAuth::issuer() . '/authorize',
				'tokenEndpoint'         => EMCP_OAuth::issuer() . '/token',
				'allowedRedirectUris'   => EMCP_OAuth::allowed_redirect_uris(),
			),
		);

		if ( $elementor_active ) {
			$kit = \Elementor\Plugin::$instance->kits_manager->get_active_id();

			$payload['activeKitId'] = $kit ? (int) $kit : null;

			$widgets = \Elementor\Plugin::$instance->widgets_manager->get_widget_types();
			$payload['widgetCount'] = is_array( $widgets ) ? count( $widgets ) : 0;

			$types = EMCP_Schema::registered_types();

			$payload['structuralElements'] = $types['elements'];

			// Ground truth for layout: Elementor's Container is gated behind an
			// experiment that defaults to inactive on sites installed before
			// 3.16, so a current Elementor can still have no container element.
			$payload['containerAvailable'] = in_array( 'container', $types['elements'], true );
			$payload['containerExperiment'] = $this->experiment_state( 'container' );

			if ( ! $payload['containerAvailable'] ) {
				$payload['layoutNote'] = __( 'This site has no container element. Build layouts with section and column, or enable Elementor > Settings > Features > Container to use flexbox containers.', 'elementor-mcp-bridge' );
			}
		}

		return $this->respond( $payload );
	}

	/**
	 * Report whether Elementor's own MCP module can actually serve a call.
	 *
	 * The module's is_active() needs the MCP adapter, the Abilities API and the
	 * shared registry all present. The proxy route registers regardless, so a
	 * call can succeed even when abilities are not registered — report both
	 * rather than collapsing them into one flag.
	 *
	 * @return array
	 */
	private function native_mcp_state() {
		$module_class = class_exists( '\Elementor\Modules\Mcp\Module' );
		$abilities    = function_exists( 'wp_register_ability' );
		$adapter      = class_exists( '\WP\MCP\Core\McpAdapter' );
		$registry     = class_exists( '\Elementor\MCP\Composer\Mcp\Registry' );

		return array(
			'moduleClass'    => $module_class,
			'abilitiesApi'   => $abilities,
			'mcpAdapter'     => $adapter,
			'sharedRegistry' => $registry,
			// Every dependency present: abilities are registered and proxyable.
			'fullyActive'    => $module_class && $abilities && $adapter && $registry,
			// The proxy route registers even when is_active() is false, so a
			// call is worth attempting whenever the module class exists.
			'proxyUsable'    => $module_class,
			'proxyRoute'     => '/wp-json/elementor/v1/mcp-proxy',
		);
	}

	/**
	 * Read an Elementor experiment's state, tolerating API changes.
	 *
	 * @param string $feature Experiment key.
	 * @return string|null
	 */
	private function experiment_state( $feature ) {
		if ( ! isset( \Elementor\Plugin::$instance->experiments ) ) {
			return null;
		}

		$manager = \Elementor\Plugin::$instance->experiments;

		if ( ! method_exists( $manager, 'is_feature_active' ) ) {
			return null;
		}

		return $manager->is_feature_active( $feature ) ? 'active' : 'inactive';
	}

	/**
	 * List widget types.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function widgets( $request ) {
		return $this->respond(
			EMCP_Schema::widgets(
				array(
					'search'        => $request->get_param( 'search' ),
					'category'      => $request->get_param( 'category' ),
					'includeHidden' => $request->get_param( 'includeHidden' ),
				)
			)
		);
	}

	/**
	 * Widget control schema.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function widget_schema( $request ) {
		return $this->respond(
			EMCP_Schema::widget_schema(
				(string) $request->get_param( 'name' ),
				(bool) $request->get_param( 'compact' )
			)
		);
	}

	/**
	 * List structural element types.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function elements() {
		return $this->respond( EMCP_Schema::elements() );
	}

	/**
	 * Structural element schema.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function element_schema( $request ) {
		return $this->respond(
			EMCP_Schema::element_schema(
				(string) $request->get_param( 'name' ),
				(bool) $request->get_param( 'compact' )
			)
		);
	}

	/**
	 * Widget panel categories.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function categories() {
		return $this->respond( EMCP_Schema::categories() );
	}

	/**
	 * Registered dynamic tags.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function dynamic_tags() {
		return $this->respond( EMCP_Schema::dynamic_tags() );
	}
}
