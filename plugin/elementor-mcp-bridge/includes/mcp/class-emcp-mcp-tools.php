<?php
/**
 * The full MCP tool registry: definitions and handlers.
 *
 * @package Elementor_MCP_Bridge
 */

defined( 'ABSPATH' ) || exit;

class_alias( 'EMCP_MCP_Schema', 'EMCP_S' );

/**
 * Tool registry.
 *
 * Every tool's handler calls the same domain classes the REST controllers
 * call (EMCP_Documents, EMCP_Tree, EMCP_Schema, and so on) directly, in
 * process — there is no HTTP round trip to itself. Because that skips the
 * REST dispatch that would otherwise run each route's permission_callback,
 * every handler below opens with the equivalent EMCP_MCP_Guard check; see
 * that class for how each one maps back to includes/rest/*.php.
 *
 * This server has exactly one site: the WordPress install it runs on. There
 * is no "site" argument and no multi-site fan-out — unlike a standalone MCP
 * client that might front several installs, connecting through this plugin
 * always means this site.
 */
class EMCP_MCP_Tools {

	/**
	 * All tool definitions.
	 *
	 * @return array[]
	 */
	public static function definitions() {
		return array_merge(
			self::site_tools(),
			self::page_tools(),
			self::element_tools(),
			self::widget_tools(),
			self::design_tools(),
			self::template_tools(),
			self::history_tools(),
			self::content_tools()
		);
	}

	/**
	 * Standard args shared by most read tools.
	 *
	 * @param array $extra Extra properties to merge in.
	 * @param array $required Required property names.
	 * @return array
	 */
	private static function schema( array $extra = array(), array $required = array() ) {
		return EMCP_S::object( $extra, $required );
	}

	/* ==================================================================
	 * Sites
	 * ================================================================== */

	private static function site_tools() {
		return array(
			array(
				'name'        => 'elementor_site_status',
				'title'       => 'Check site status',
				'description' =>
					'Report what this site is running: WordPress and Elementor versions, whether Elementor Pro is ' .
					'present, how many widget types are registered, the active kit id, the capabilities of the ' .
					'authenticated user, whether the flexbox container element is available, and whether destructive ' .
					'operations are enabled. Run this once at the start of a session, before editing anything.',
				'inputSchema' => self::schema(),
				'annotations' => array( 'readOnlyHint' => true, 'idempotentHint' => true ),
				'handler'     => array( __CLASS__, 'site_status' ),
			),
			array(
				'name'        => 'elementor_native_mcp_call',
				'title'       => "Call an Elementor native MCP ability",
				'description' =>
					"Invoke one of Elementor's own built-in MCP abilities. Elementor 4.3+ ships abilities covering " .
					'v4 atomic features (global classes, variables, components, compositions) that have no v3 ' .
					'equivalent. Only works where that module is active — check elementor_site_status first, under ' .
					'nativeMcp.',
				'inputSchema' => self::schema(
					array(
						'tool'  => EMCP_S::string( 'The native ability slug, e.g. "list-components".' ),
						'input' => EMCP_S::free_object( 'Arguments for that ability.' ),
					),
					array( 'tool' )
				),
				'annotations' => array( 'openWorldHint' => true ),
				'handler'     => array( __CLASS__, 'native_mcp_call' ),
			),
		);
	}

	public static function site_status( array $args ) {
		$guard = EMCP_MCP_Guard::read();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		// Reuses the same payload the /status REST route builds, so the two
		// never drift, without going through an HTTP round trip to get it.
		$controller = new EMCP_REST_Site();
		$response   = $controller->status();

		return $response instanceof WP_REST_Response ? $response->get_data() : $response;
	}

	public static function native_mcp_call( array $args ) {
		$guard = EMCP_MCP_Guard::read();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		if ( ! class_exists( '\Elementor\Modules\Mcp\Module' ) ) {
			return new WP_Error(
				'emcp_native_mcp_unavailable',
				__( "This site does not have Elementor's built-in MCP module. It requires Elementor 4.3 or newer.", 'elementor-mcp-bridge' ),
				array( 'status' => 501 )
			);
		}

		$proxy = new WP_REST_Request( 'POST', '/elementor/v1/mcp-proxy' );
		$proxy->set_param( 'tool', (string) $args['tool'] );
		$proxy->set_param( 'input', isset( $args['input'] ) && is_array( $args['input'] ) ? $args['input'] : array() );

		$response = rest_do_request( $proxy );

		if ( $response->is_error() ) {
			return $response->as_error();
		}

		return array(
			'tool'   => $args['tool'],
			'result' => $response->get_data(),
		);
	}

	/* ==================================================================
	 * Pages
	 * ================================================================== */

	private static function page_tools() {
		return array(
			array(
				'name'        => 'elementor_list_pages',
				'title'       => 'List pages and posts',
				'description' =>
					'List WordPress pages, posts and templates, newest edited first, with whether each is built ' .
					'with Elementor. Use elementorOnly to skip content the page builder does not own.',
				'inputSchema' => self::schema(
					array(
						'search'        => EMCP_S::string( 'Match against title and content.' ),
						'type'          => EMCP_S::array_of( EMCP_S::string(), 'Post types to include. Defaults to page and post.' ),
						'status'        => EMCP_S::array_of( EMCP_S::string(), 'Post statuses to include.' ),
						'elementorOnly' => EMCP_S::boolean( 'Only return posts actually built with Elementor.', false ),
						'templateType'  => EMCP_S::string( 'Filter by Elementor template type, e.g. wp-page, header, footer, popup.' ),
						'page'          => EMCP_S::with_default( EMCP_S::integer(), 1 ),
						'perPage'       => EMCP_S::with_default( EMCP_S::integer(), 20 ),
					)
				),
				'annotations' => array( 'readOnlyHint' => true, 'idempotentHint' => true ),
				'handler'     => array( __CLASS__, 'list_pages' ),
			),
			array(
				'name'        => 'elementor_get_outline',
				'title'       => 'Get page structure outline',
				'description' =>
					'Read the structure of an Elementor page: every element id, its type, and a short label taken ' .
					'from its most identifying setting. This is the tool to reach for first when editing a page — ' .
					'it is a small fraction of the size of the full element tree and gives you the ids every other ' .
					'element tool needs. It also returns the content hash used for safe concurrent writes.',
				'inputSchema' => self::schema(
					array(
						'postId'   => EMCP_S::integer( 'WordPress post ID of the Elementor page.' ),
						'maxDepth' => EMCP_S::with_default( EMCP_S::integer( 'Levels to descend. 0 returns the whole tree; 1 returns only top-level sections.' ), 0 ),
					),
					array( 'postId' )
				),
				'annotations' => array( 'readOnlyHint' => true, 'idempotentHint' => true ),
				'handler'     => array( __CLASS__, 'get_outline' ),
			),
			array(
				'name'        => 'elementor_get_page',
				'title'       => 'Get a page',
				'description' =>
					'Read a page: metadata, a census of which widgets it uses, its content hash, and optionally ' .
					'the full element tree. Leave includeElements false unless you genuinely need every setting — ' .
					'element trees run to hundreds of kilobytes on real pages. Prefer elementor_get_outline, then ' .
					'elementor_get_element for the specific parts you care about.',
				'inputSchema' => self::schema(
					array(
						'postId'          => EMCP_S::integer(),
						'includeElements' => EMCP_S::boolean( 'Include the complete element tree. Large; use sparingly.', false ),
					),
					array( 'postId' )
				),
				'annotations' => array( 'readOnlyHint' => true, 'idempotentHint' => true ),
				'handler'     => array( __CLASS__, 'get_page' ),
			),
			array(
				'name'        => 'elementor_get_page_tree',
				'title'       => 'Get the full element tree',
				'description' =>
					"Read a page's complete element tree including every setting. This is the largest thing this " .
					'server returns — only call it when you need to inspect settings across many elements at once. ' .
					'For normal editing use elementor_get_outline plus elementor_get_element.',
				'inputSchema' => self::schema(
					array(
						'postId'      => EMCP_S::integer(),
						'summaryOnly' => EMCP_S::boolean( 'Return counts and an outline instead of the tree itself.', false ),
					),
					array( 'postId' )
				),
				'annotations' => array( 'readOnlyHint' => true, 'idempotentHint' => true ),
				'handler'     => array( __CLASS__, 'get_page_tree' ),
			),
			array(
				'name'        => 'elementor_create_page',
				'title'       => 'Create a page',
				'description' =>
					'Create a new Elementor-enabled page, post or template. Starts empty unless you pass an ' .
					'element tree. Created as a draft by default so nothing goes live unreviewed. For a full-width ' .
					'blank canvas set pageTemplate to elementor_canvas.',
				'inputSchema' => self::schema(
					array(
						'title'        => EMCP_S::string( 'Page title.' ),
						'type'         => EMCP_S::with_default( EMCP_S::string( 'Post type, e.g. page or post.' ), 'page' ),
						'status'       => EMCP_S::describe_enum_default( array( 'draft', 'publish', 'pending', 'private' ), 'draft', 'Post status. Defaults to draft.' ),
						'slug'         => EMCP_S::string(),
						'parent'       => EMCP_S::integer( 'Parent post ID for hierarchical types.' ),
						'pageTemplate' => EMCP_S::string( 'Theme template: elementor_canvas (blank), elementor_header_footer (no theme content), or a theme file.' ),
						'elements'     => EMCP_S::array_of( EMCP_S::free_object(), 'Optional starting element tree.' ),
					),
					array( 'title' )
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false ),
				'handler'     => array( __CLASS__, 'create_page' ),
			),
			array(
				'name'        => 'elementor_update_page_meta',
				'title'       => 'Update page metadata',
				'description' =>
					"Change a page's title, slug, status, parent or featured image. This edits WordPress post " .
					'fields, not the Elementor layout — use the element tools for that.',
				'inputSchema' => self::schema(
					array(
						'postId'          => EMCP_S::integer(),
						'title'           => EMCP_S::string(),
						'slug'            => EMCP_S::string(),
						'status'          => EMCP_S::enum( array( 'draft', 'publish', 'pending', 'private', 'future' ) ),
						'parent'          => EMCP_S::integer(),
						'featuredMediaId' => EMCP_S::integer( 'Attachment ID to use as featured image.' ),
					),
					array( 'postId' )
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true ),
				'handler'     => array( __CLASS__, 'update_page_meta' ),
			),
			array(
				'name'        => 'elementor_duplicate_page',
				'title'       => 'Duplicate a page',
				'description' =>
					"Copy a page, its Elementor layout and its page settings into a new draft, regenerating every " .
					'element id so the copy is independent. The usual way to iterate on a live page safely: ' .
					'duplicate it, edit the copy, then publish when it is right.',
				'inputSchema' => self::schema(
					array(
						'postId' => EMCP_S::integer(),
						'title'  => EMCP_S::string( 'Title for the copy. Defaults to the original plus "(copy)".' ),
						'status' => EMCP_S::describe_enum_default( array( 'draft', 'publish', 'pending', 'private' ), 'draft', '' ),
						'slug'   => EMCP_S::string(),
					),
					array( 'postId' )
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false ),
				'handler'     => array( __CLASS__, 'duplicate_page' ),
			),
			array(
				'name'        => 'elementor_delete_page',
				'title'       => 'Delete a page',
				'description' =>
					'Move a page to the trash, or delete it permanently with force. Permanent deletion additionally ' .
					'requires the site to have opted into destructive operations and confirm to be true.',
				'inputSchema' => self::schema(
					array(
						'postId'  => EMCP_S::integer(),
						'force'   => EMCP_S::boolean( 'Skip the trash and delete permanently. Cannot be undone.', false ),
						'confirm' => EMCP_S::boolean( 'Required when force is true.', false ),
					),
					array( 'postId' )
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true ),
				'handler'     => array( __CLASS__, 'delete_page' ),
			),
			array(
				'name'        => 'elementor_render_page',
				'title'       => 'Render page HTML',
				'description' =>
					'Render a page — or one element of it — to HTML as the front end would. Use this to check what ' .
					'an edit actually produced, especially for widgets whose output depends on dynamic data.',
				'inputSchema' => self::schema(
					array(
						'postId'    => EMCP_S::integer(),
						'elementId' => EMCP_S::element_id( 'Render just this element instead of the whole page.' ),
						'maxLength' => EMCP_S::with_default( EMCP_S::integer( 'Truncate the returned HTML at this many characters.' ), 20000 ),
					),
					array( 'postId' )
				),
				'annotations' => array( 'readOnlyHint' => true, 'idempotentHint' => true ),
				'handler'     => array( __CLASS__, 'render_page' ),
			),
		);
	}

	public static function list_pages( array $args ) {
		$guard = EMCP_MCP_Guard::read();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		return EMCP_Documents::query( $args );
	}

	public static function get_outline( array $args ) {
		$guard = EMCP_MCP_Guard::read();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$post_id  = (int) $args['postId'];
		$elements = EMCP_Documents::read_elements( $post_id );

		if ( is_wp_error( $elements ) ) {
			return $elements;
		}

		return array(
			'id'        => $post_id,
			'title'     => get_the_title( $post_id ),
			'hash'      => EMCP_Tree::hash( $elements ),
			'nodeCount' => count( EMCP_Tree::collect_ids( $elements ) ),
			'outline'   => EMCP_Tree::outline( $elements, isset( $args['maxDepth'] ) ? (int) $args['maxDepth'] : 0 ),
		);
	}

	public static function get_page( array $args ) {
		$guard = EMCP_MCP_Guard::read();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$post_id = (int) $args['postId'];
		$meta    = EMCP_Documents::describe( $post_id );

		if ( is_wp_error( $meta ) ) {
			return $meta;
		}

		if ( ! empty( $args['includeElements'] ) ) {
			$elements = EMCP_Documents::read_elements( $post_id );

			if ( is_wp_error( $elements ) ) {
				return $elements;
			}

			$meta['elements'] = $elements;
			$meta['settings'] = EMCP_Documents::read_settings( $post_id );
		}

		return $meta;
	}

	public static function get_page_tree( array $args ) {
		$guard = EMCP_MCP_Guard::read();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$post_id  = (int) $args['postId'];
		$elements = EMCP_Documents::read_elements( $post_id );

		if ( is_wp_error( $elements ) ) {
			return $elements;
		}

		if ( ! empty( $args['summaryOnly'] ) ) {
			$meta = EMCP_Documents::describe( $post_id );

			if ( is_wp_error( $meta ) ) {
				return $meta;
			}

			$meta['outline'] = EMCP_Tree::outline( $elements, 2 );
			return $meta;
		}

		return array(
			'postId'    => $post_id,
			'hash'      => EMCP_Tree::hash( $elements ),
			'nodeCount' => count( EMCP_Tree::collect_ids( $elements ) ),
			'elements'  => $elements,
		);
	}

	public static function create_page( array $args ) {
		$guard = EMCP_MCP_Guard::read();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		return EMCP_Documents::create( $args );
	}

	public static function update_page_meta( array $args ) {
		$post_id = (int) $args['postId'];
		$guard   = EMCP_MCP_Guard::write( $post_id );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$postarr = array( 'ID' => $post_id );

		foreach ( array( 'title' => 'post_title', 'slug' => 'post_name', 'status' => 'post_status', 'parent' => 'post_parent' ) as $arg => $field ) {
			if ( isset( $args[ $arg ] ) ) {
				$postarr[ $field ] = $args[ $arg ];
			}
		}

		if ( 1 === count( $postarr ) && empty( $args['featuredMediaId'] ) ) {
			return array( 'changed' => false, 'note' => 'Nothing to change — pass at least one field.' );
		}

		if ( count( $postarr ) > 1 ) {
			$updated = wp_update_post( $postarr, true );

			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		}

		if ( ! empty( $args['featuredMediaId'] ) ) {
			set_post_thumbnail( $post_id, (int) $args['featuredMediaId'] );
		}

		$post = get_post( $post_id );

		return array(
			'id'     => $post->ID,
			'title'  => $post->post_title,
			'slug'   => $post->post_name,
			'status' => $post->post_status,
			'link'   => get_permalink( $post ),
		);
	}

	public static function duplicate_page( array $args ) {
		$post_id = (int) $args['postId'];
		$guard   = EMCP_MCP_Guard::read();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		return EMCP_Documents::duplicate( $post_id, $args );
	}

	public static function delete_page( array $args ) {
		$post_id = (int) $args['postId'];
		$force   = ! empty( $args['force'] );
		$confirm = ! empty( $args['confirm'] );

		$guard = EMCP_MCP_Guard::delete( $post_id, $force, $confirm );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$result = wp_delete_post( $post_id, $force );

		if ( ! $result ) {
			return new WP_Error(
				'emcp_delete_failed',
				__( 'WordPress refused to delete that post.', 'elementor-mcp-bridge' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'id'      => $post_id,
			'deleted' => true,
			'forced'  => $force,
		);
	}

	public static function render_page( array $args ) {
		$guard = EMCP_MCP_Guard::read();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$result = EMCP_Documents::render( (int) $args['postId'], isset( $args['elementId'] ) ? (string) $args['elementId'] : '' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$html       = isset( $result['html'] ) ? $result['html'] : '';
		$max_length = isset( $args['maxLength'] ) ? (int) $args['maxLength'] : 20000;
		$truncated  = strlen( $html ) > $max_length;

		return array_merge(
			$result,
			array(
				'length'    => strlen( $html ),
				'truncated' => $truncated,
				'html'      => $truncated ? substr( $html, 0, $max_length ) : $html,
			)
		);
	}

	/* ==================================================================
	 * Elements
	 * ================================================================== */

	private static function element_tools() {
		$settings_arg = EMCP_S::free_object(
			'Elementor control values keyed by control name. Call elementor_get_widget_schema for the valid keys ' .
			'of a widget. Responsive variants use _tablet and _mobile suffixes, e.g. padding_tablet.'
		);

		return array(
			array(
				'name'        => 'elementor_get_element',
				'title'       => 'Get one element',
				'description' =>
					'Read a single element and its subtree, including every setting. Use this after ' .
					'elementor_get_outline to inspect just the part of a page you are about to change.',
				'inputSchema' => self::schema(
					array(
						'postId'       => EMCP_S::integer(),
						'elementId'    => EMCP_S::element_id(),
						'settingsOnly' => EMCP_S::boolean( "Return only this element's settings, not its children.", false ),
					),
					array( 'postId', 'elementId' )
				),
				'annotations' => array( 'readOnlyHint' => true, 'idempotentHint' => true ),
				'handler'     => array( __CLASS__, 'get_element' ),
			),
			array(
				'name'        => 'elementor_find_elements',
				'title'       => 'Find elements within a page',
				'description' =>
					'Search one page for elements by widget type, by text in their settings, or both, and get ' .
					'back their ids with a short label. Use this to locate what you want to edit without reading ' .
					'the whole tree.',
				'inputSchema' => self::schema(
					array(
						'postId'     => EMCP_S::integer(),
						'widgetType' => EMCP_S::string( 'Only match this widget or element type.' ),
						'text'       => EMCP_S::string( 'Only match elements whose settings contain this text.' ),
						'limit'      => EMCP_S::with_default( EMCP_S::integer(), 50 ),
					),
					array( 'postId' )
				),
				'annotations' => array( 'readOnlyHint' => true, 'idempotentHint' => true ),
				'handler'     => array( __CLASS__, 'find_elements' ),
			),
			array(
				'name'        => 'elementor_add_widget',
				'title'       => 'Add a widget',
				'description' =>
					'Insert a new widget into a page. Pass the widget type (heading, image, button, text-editor, ' .
					'and so on — see elementor_list_widgets) and its settings. With no targetId the widget is ' .
					'appended to the page root; a widget must otherwise go inside a container or column, so use ' .
					'position before/after to place it beside an existing widget.',
				'inputSchema' => self::schema(
					array(
						'postId'     => EMCP_S::integer(),
						'widgetType' => EMCP_S::string( 'Widget type name, e.g. "heading".' ),
						'settings'   => $settings_arg,
						'targetId'   => EMCP_S::string( 'Element to place this relative to. Omit to append at the page root.' ),
						'position'   => EMCP_S::position(),
					),
					array( 'postId', 'widgetType' )
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false ),
				'handler'     => array( __CLASS__, 'add_widget' ),
			),
			array(
				'name'        => 'elementor_add_container',
				'title'       => 'Add a container or section',
				'description' =>
					'Insert a structural element — a flexbox container (the modern default, where available; see ' .
					'containerAvailable in elementor_site_status), or a section/column for pages built the classic ' .
					'way. Containers are what you put widgets inside. You can seed it with child elements in one call.',
				'inputSchema' => self::schema(
					array(
						'postId'   => EMCP_S::integer(),
						'elType'   => EMCP_S::describe_enum_default(
							array( 'container', 'section', 'column' ),
							'container',
							'container is the modern flexbox element; section/column are the legacy layout pair. Container ' .
							'is not available on every site — check containerAvailable in elementor_site_status first.'
						),
						'settings' => $settings_arg,
						'children' => EMCP_S::array_of( EMCP_S::free_object(), 'Optional child element nodes to place inside it.' ),
						'targetId' => EMCP_S::string(),
						'position' => EMCP_S::position(),
					),
					array( 'postId' )
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false ),
				'handler'     => array( __CLASS__, 'add_container' ),
			),
			array(
				'name'        => 'elementor_update_element',
				'title'       => 'Update element settings',
				'description' =>
					"Change an element's settings. In merge mode (the default) the keys you pass are applied over " .
					'the existing settings and everything else is left alone; in replace mode the settings object ' .
					'is swapped wholesale, which discards any setting you do not include. Merge is almost always ' .
					'what you want.',
				'inputSchema' => self::schema(
					array(
						'postId'    => EMCP_S::integer(),
						'elementId' => EMCP_S::element_id(),
						'settings'  => $settings_arg,
						'mode'      => EMCP_S::describe_enum_default( array( 'merge', 'replace' ), 'merge', 'merge keeps existing settings; replace discards anything not passed.' ),
					),
					array( 'postId', 'elementId', 'settings' )
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true ),
				'handler'     => array( __CLASS__, 'update_element' ),
			),
			array(
				'name'        => 'elementor_move_element',
				'title'       => 'Move an element',
				'description' =>
					'Relocate an element within the page. Give a referenceId plus a position to say where it ' .
					'lands; omit referenceId to move it to the page root. Moving an element into its own ' .
					'descendant is refused.',
				'inputSchema' => self::schema(
					array(
						'postId'      => EMCP_S::integer(),
						'elementId'   => EMCP_S::element_id(),
						'referenceId' => EMCP_S::string( 'Element to position relative to. Omit to move to the page root.' ),
						'position'    => EMCP_S::position(),
					),
					array( 'postId', 'elementId' )
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false ),
				'handler'     => array( __CLASS__, 'move_element' ),
			),
			array(
				'name'        => 'elementor_duplicate_element',
				'title'       => 'Duplicate an element',
				'description' =>
					'Copy an element and its whole subtree, placed immediately after the original, with fresh ids ' .
					'throughout. The quickest way to repeat a card, column or row you have already styled.',
				'inputSchema' => self::schema( array( 'postId' => EMCP_S::integer(), 'elementId' => EMCP_S::element_id() ), array( 'postId', 'elementId' ) ),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false ),
				'handler'     => array( __CLASS__, 'duplicate_element' ),
			),
			array(
				'name'        => 'elementor_delete_element',
				'title'       => 'Delete an element',
				'description' =>
					'Remove an element and everything inside it from a page. A snapshot is taken first, so ' .
					'elementor_restore_snapshot can undo it.',
				'inputSchema' => self::schema( array( 'postId' => EMCP_S::integer(), 'elementId' => EMCP_S::element_id() ), array( 'postId', 'elementId' ) ),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true ),
				'handler'     => array( __CLASS__, 'delete_element' ),
			),
			array(
				'name'        => 'elementor_reorder_children',
				'title'       => 'Reorder child elements',
				'description' =>
					"Set the order of a container's direct children by listing their ids. Ids you leave out keep " .
					'their relative order at the end, so you can move one item to the front without listing everything.',
				'inputSchema' => self::schema(
					array(
						'postId'    => EMCP_S::integer(),
						'elementId' => EMCP_S::element_id(),
						'order'     => EMCP_S::array_of( EMCP_S::string(), "Child element ids in their new order." ),
					),
					array( 'postId', 'elementId', 'order' )
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true ),
				'handler'     => array( __CLASS__, 'reorder_children' ),
			),
			array(
				'name'        => 'elementor_wrap_element',
				'title'       => 'Wrap an element in a container',
				'description' =>
					'Put a new container around an existing element, in place. Useful for adding a background, ' .
					'padding or width constraint around something without rebuilding it.',
				'inputSchema' => self::schema(
					array(
						'postId'           => EMCP_S::integer(),
						'elementId'        => EMCP_S::element_id(),
						'wrapperSettings'  => $settings_arg,
						'wrapperType'      => EMCP_S::describe_enum_default( array( 'container', 'section' ), 'container', '' ),
					),
					array( 'postId', 'elementId' )
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false ),
				'handler'     => array( __CLASS__, 'wrap_element' ),
			),
			array(
				'name'        => 'elementor_batch_edit',
				'title'       => 'Apply many element edits at once',
				'description' =>
					'Run a list of element operations against a page as one atomic change: insert, update, move, ' .
					'duplicate, delete, reorder and wrap. Operations apply in order, each seeing the result of the ' .
					'last. If any one fails the whole batch is abandoned and nothing is written. Prefer this over a ' .
					'series of single-element calls when building or restructuring a section — it is one read and ' .
					'one write instead of many, and it cannot leave the page half-changed.',
				'inputSchema' => self::schema(
					array(
						'postId'     => EMCP_S::integer(),
						'operations' => EMCP_S::array_of(
							EMCP_S::free_object(),
							'Operations in order. Each is an object with "op" and its arguments: ' .
							'{"op":"insert","node":{...},"targetId":"abc","position":"append"}, ' .
							'{"op":"update","targetId":"abc","settings":{...},"mode":"merge"}, ' .
							'{"op":"move","targetId":"abc","referenceId":"def","position":"after"}, ' .
							'{"op":"duplicate","targetId":"abc"}, {"op":"delete","targetId":"abc"}, ' .
							'{"op":"reorder","targetId":"abc","order":["x","y"]}, ' .
							'{"op":"wrap","targetId":"abc","wrapper":{...}}.'
						),
						'label'      => EMCP_S::string( 'Snapshot label describing this change.' ),
					),
					array( 'postId', 'operations' )
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true ),
				'handler'     => array( __CLASS__, 'batch_edit' ),
			),
			array(
				'name'        => 'elementor_replace_page_tree',
				'title'       => "Replace a page's entire layout",
				'description' =>
					"Overwrite a page's whole element tree. This discards the existing layout completely, so " .
					'reach for it when generating a page from scratch rather than to make a change. A snapshot is ' .
					'taken first. For edits to an existing page use elementor_batch_edit instead.',
				'inputSchema' => self::schema(
					array(
						'postId'   => EMCP_S::integer(),
						'elements' => EMCP_S::array_of( EMCP_S::free_object(), 'The complete new element tree. An empty array clears the page.' ),
						'confirm'  => EMCP_S::boolean( 'Must be true — this replaces everything on the page.', false ),
						'label'    => EMCP_S::string(),
					),
					array( 'postId', 'elements' )
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true ),
				'handler'     => array( __CLASS__, 'replace_page_tree' ),
			),
		);
	}

	/** Shape an EMCP_Compose::edit() outcome into a compact confirmation. */
	private static function summarise_edit( $outcome, $extra = array() ) {
		if ( is_wp_error( $outcome ) ) {
			return $outcome;
		}

		$write = $outcome['write'];

		$result = array_merge(
			$extra,
			array(
				'postId'          => $write['id'],
				'hash'            => $write['hash'],
				'nodeCountBefore' => $outcome['nodeCountBefore'],
				'nodeCountAfter'  => $outcome['nodeCountAfter'],
			)
		);

		if ( ! empty( $write['editUrl'] ) ) {
			$result['editUrl'] = $write['editUrl'];
		}

		if ( ! empty( $write['permalink'] ) ) {
			$result['permalink'] = $write['permalink'];
		}

		if ( ! empty( $write['warnings'] ) ) {
			$result['warnings'] = $write['warnings'];
		}

		return $result;
	}

	public static function get_element( array $args ) {
		$guard = EMCP_MCP_Guard::read();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$post_id  = (int) $args['postId'];
		$elements = EMCP_Documents::read_elements( $post_id );

		if ( is_wp_error( $elements ) ) {
			return $elements;
		}

		$node = EMCP_Tree::find( $elements, (string) $args['elementId'] );

		if ( null === $node ) {
			return new WP_Error(
				'emcp_element_not_found',
				sprintf(
					/* translators: 1: element id, 2: post id */
					__( 'No element "%1$s" in document %2$d. Call elementor_get_outline to see valid ids.', 'elementor-mcp-bridge' ),
					$args['elementId'],
					$post_id
				),
				array( 'status' => 404 )
			);
		}

		if ( ! empty( $args['settingsOnly'] ) ) {
			return array(
				'postId'     => $post_id,
				'elementId'  => $node['id'],
				'elType'     => $node['elType'],
				'widgetType' => isset( $node['widgetType'] ) ? $node['widgetType'] : null,
				'settings'   => isset( $node['settings'] ) ? $node['settings'] : array(),
			);
		}

		return array(
			'postId'  => $post_id,
			'hash'    => EMCP_Tree::hash( $elements ),
			'element' => $node,
		);
	}

	public static function find_elements( array $args ) {
		$guard = EMCP_MCP_Guard::read();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$post_id  = (int) $args['postId'];
		$elements = EMCP_Documents::read_elements( $post_id );

		if ( is_wp_error( $elements ) ) {
			return $elements;
		}

		$widget_type = isset( $args['widgetType'] ) ? (string) $args['widgetType'] : '';
		$needle      = isset( $args['text'] ) ? strtolower( (string) $args['text'] ) : '';
		$limit       = isset( $args['limit'] ) ? (int) $args['limit'] : 50;

		$matches = array();

		EMCP_Tree::walk(
			$elements,
			static function ( $node ) use ( $widget_type, $needle, $limit, &$matches ) {
				if ( count( $matches ) >= $limit ) {
					return;
				}

				$type = ! empty( $node['widgetType'] ) ? $node['widgetType'] : ( $node['elType'] ?? '' );

				if ( '' !== $widget_type && $type !== $widget_type ) {
					return;
				}

				$matched_keys = array();

				if ( '' !== $needle ) {
					foreach ( (array) ( $node['settings'] ?? array() ) as $key => $value ) {
						if ( is_string( $value ) && false !== stripos( $value, $needle ) ) {
							$matched_keys[] = $key;
						}
					}

					if ( ! $matched_keys ) {
						return;
					}
				}

				$entry = array(
					'elementId'  => $node['id'] ?? '',
					'elType'     => $node['elType'] ?? '',
					'widgetType' => isset( $node['widgetType'] ) ? $node['widgetType'] : null,
					'label'      => EMCP_Tree::describe( $node ) ?: null,
				);

				if ( $matched_keys ) {
					$entry['matchedSettings'] = $matched_keys;
				}

				$matches[] = $entry;
			}
		);

		return array(
			'postId'    => $post_id,
			'hash'      => EMCP_Tree::hash( $elements ),
			'total'     => count( $matches ),
			'truncated' => count( $matches ) >= $limit,
			'matches'   => $matches,
		);
	}

	public static function add_widget( array $args ) {
		$post_id = (int) $args['postId'];
		$guard   = EMCP_MCP_Guard::write( $post_id );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$new_id = null;

		$outcome = EMCP_Compose::edit(
			$post_id,
			static function ( array $elements ) use ( $args, &$new_id ) {
				$widget = EMCP_Tree::make_widget( (string) $args['widgetType'], isset( $args['settings'] ) ? $args['settings'] : array() );
				$new_id = $widget['id'];

				return EMCP_Tree::insert(
					$elements,
					$widget,
					isset( $args['targetId'] ) ? (string) $args['targetId'] : '',
					isset( $args['position'] ) ? (string) $args['position'] : 'append'
				);
			},
			'add ' . $args['widgetType']
		);

		return self::summarise_edit( $outcome, array( 'elementId' => $new_id ) );
	}

	public static function add_container( array $args ) {
		$post_id = (int) $args['postId'];
		$guard   = EMCP_MCP_Guard::write( $post_id );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$el_type = isset( $args['elType'] ) ? (string) $args['elType'] : 'container';

		// Elementor gates the flexbox Container behind an experiment that is
		// off by default on sites installed before 3.16. Writing an
		// unregistered element type saves cleanly and then renders nothing.
		$registered = EMCP_Schema::registered_types();

		if ( $registered['elements'] && ! in_array( $el_type, $registered['elements'], true ) ) {
			$available = implode( ', ', $registered['elements'] );

			return new WP_Error(
				'emcp_element_type_unavailable',
				sprintf(
					/* translators: %s: element type */
					__( 'This site has no "%s" element registered, so adding one would save but render nothing.', 'elementor-mcp-bridge' ),
					$el_type
				),
				array(
					'status' => 400,
					'hint'   => 'container' === $el_type
						? sprintf( 'Use elType "section" (with a "column" inside it) instead, or turn on Elementor > Settings > Features > Container. Available here: %s.', $available )
						: sprintf( 'Available structural elements here: %s.', $available ),
				)
			);
		}

		$new_id = null;

		$outcome = EMCP_Compose::edit(
			$post_id,
			static function ( array $elements ) use ( $args, $el_type, &$new_id ) {
				$node = EMCP_Tree::make_container(
					isset( $args['settings'] ) ? $args['settings'] : array(),
					isset( $args['children'] ) && is_array( $args['children'] ) ? $args['children'] : array(),
					$el_type
				);
				$new_id = $node['id'];

				return EMCP_Tree::insert(
					$elements,
					$node,
					isset( $args['targetId'] ) ? (string) $args['targetId'] : '',
					isset( $args['position'] ) ? (string) $args['position'] : 'append'
				);
			},
			'add ' . $el_type
		);

		return self::summarise_edit( $outcome, array( 'elementId' => $new_id ) );
	}

	public static function update_element( array $args ) {
		$post_id = (int) $args['postId'];
		$guard   = EMCP_MCP_Guard::write( $post_id );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$outcome = EMCP_Compose::edit(
			$post_id,
			static function ( array $elements ) use ( $args ) {
				return EMCP_Tree::update_settings(
					$elements,
					(string) $args['elementId'],
					isset( $args['settings'] ) ? $args['settings'] : array(),
					isset( $args['mode'] ) ? (string) $args['mode'] : 'merge'
				);
			},
			'update ' . $args['elementId']
		);

		return self::summarise_edit( $outcome, array( 'elementId' => $args['elementId'] ) );
	}

	public static function move_element( array $args ) {
		$post_id = (int) $args['postId'];
		$guard   = EMCP_MCP_Guard::write( $post_id );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$outcome = EMCP_Compose::edit(
			$post_id,
			static function ( array $elements ) use ( $args ) {
				return EMCP_Tree::move(
					$elements,
					(string) $args['elementId'],
					isset( $args['referenceId'] ) ? (string) $args['referenceId'] : '',
					isset( $args['position'] ) ? (string) $args['position'] : 'append'
				);
			},
			'move ' . $args['elementId']
		);

		return self::summarise_edit( $outcome, array( 'elementId' => $args['elementId'] ) );
	}

	public static function duplicate_element( array $args ) {
		$post_id = (int) $args['postId'];
		$guard   = EMCP_MCP_Guard::write( $post_id );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$new_id = null;

		$outcome = EMCP_Compose::edit(
			$post_id,
			static function ( array $elements ) use ( $args, &$new_id ) {
				list( $next, $new_id ) = EMCP_Tree::duplicate( $elements, (string) $args['elementId'] );
				return $next;
			},
			'duplicate ' . $args['elementId']
		);

		return self::summarise_edit( $outcome, array( 'newElementId' => $new_id ) );
	}

	public static function delete_element( array $args ) {
		$post_id = (int) $args['postId'];
		$guard   = EMCP_MCP_Guard::write( $post_id );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$outcome = EMCP_Compose::edit(
			$post_id,
			static function ( array $elements ) use ( $args ) {
				return EMCP_Tree::remove( $elements, (string) $args['elementId'] );
			},
			'delete ' . $args['elementId']
		);

		return self::summarise_edit( $outcome, array( 'deletedElementId' => $args['elementId'] ) );
	}

	public static function reorder_children( array $args ) {
		$post_id = (int) $args['postId'];
		$guard   = EMCP_MCP_Guard::write( $post_id );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$outcome = EMCP_Compose::edit(
			$post_id,
			static function ( array $elements ) use ( $args ) {
				return EMCP_Tree::reorder( $elements, (string) $args['elementId'], isset( $args['order'] ) ? $args['order'] : array() );
			},
			'reorder ' . $args['elementId']
		);

		return self::summarise_edit( $outcome, array( 'elementId' => $args['elementId'] ) );
	}

	public static function wrap_element( array $args ) {
		$post_id = (int) $args['postId'];
		$guard   = EMCP_MCP_Guard::write( $post_id );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$wrapper_id = null;

		$outcome = EMCP_Compose::edit(
			$post_id,
			static function ( array $elements ) use ( $args, &$wrapper_id ) {
				$shell = EMCP_Tree::make_container(
					isset( $args['wrapperSettings'] ) ? $args['wrapperSettings'] : array(),
					array(),
					isset( $args['wrapperType'] ) ? (string) $args['wrapperType'] : 'container'
				);

				list( $next, $wrapper_id ) = EMCP_Tree::wrap( $elements, (string) $args['elementId'], $shell );
				return $next;
			},
			'wrap ' . $args['elementId']
		);

		return self::summarise_edit( $outcome, array( 'wrapperId' => $wrapper_id ) );
	}

	public static function batch_edit( array $args ) {
		$post_id = (int) $args['postId'];
		$guard   = EMCP_MCP_Guard::write( $post_id );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$operations = isset( $args['operations'] ) && is_array( $args['operations'] ) ? $args['operations'] : array();
		$log        = array();

		$outcome = EMCP_Compose::edit(
			$post_id,
			static function ( array $elements ) use ( $operations, &$log ) {
				list( $next, $log ) = EMCP_Tree::apply_operations( $elements, $operations );
				return $next;
			},
			! empty( $args['label'] ) ? (string) $args['label'] : sprintf( 'batch of %d operation(s)', count( $operations ) )
		);

		return self::summarise_edit( $outcome, array( 'operations' => $log ) );
	}

	public static function replace_page_tree( array $args ) {
		if ( empty( $args['confirm'] ) ) {
			return array(
				'written' => false,
				'note'    => "This replaces the page's entire layout. Re-send with confirm: true if that is what you want.",
			);
		}

		$post_id = (int) $args['postId'];
		$guard   = EMCP_MCP_Guard::write( $post_id );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$new_elements = isset( $args['elements'] ) && is_array( $args['elements'] ) ? $args['elements'] : array();

		$outcome = EMCP_Compose::edit(
			$post_id,
			static function ( array $elements ) use ( $new_elements ) {
				return $new_elements;
			},
			! empty( $args['label'] ) ? (string) $args['label'] : 'replace page tree'
		);

		return self::summarise_edit( $outcome );
	}

	/* ==================================================================
	 * Widgets and schemas
	 * ================================================================== */

	private static function widget_tools() {
		return array(
			array(
				'name'        => 'elementor_list_widgets',
				'title'       => 'List available widgets',
				'description' =>
					'List every widget type registered on the site, with its title, panel categories and whether ' .
					'it comes from Elementor Pro. Search by name or keyword to find the right one. Always check ' .
					'here before adding a widget — a site only has the widgets its installed plugins provide.',
				'inputSchema' => self::schema(
					array(
						'search'        => EMCP_S::string( 'Filter by name, title or keyword, e.g. "form" or "slider".' ),
						'category'      => EMCP_S::string( 'Filter by panel category slug, e.g. "basic" or "pro-elements".' ),
						'includeHidden' => EMCP_S::boolean( 'Include widgets Elementor hides from the editor panel.', false ),
					)
				),
				'annotations' => array( 'readOnlyHint' => true, 'idempotentHint' => true ),
				'handler'     => array( __CLASS__, 'list_widgets' ),
			),
			array(
				'name'        => 'elementor_get_widget_schema',
				'title'       => "Get a widget's settings schema",
				'description' =>
					'Read the full control schema for a widget or structural element: every setting it accepts, ' .
					'grouped by panel tab and section, with types, defaults, allowed options and which settings ' .
					'are responsive. Read this before setting unfamiliar widget settings — guessing control names ' .
					'is the most common cause of an edit that saves without visible effect.',
				'inputSchema' => self::schema(
					array(
						'name'    => EMCP_S::string( 'Widget type (e.g. "heading") or element type (e.g. "container").' ),
						'kind'    => EMCP_S::describe_enum_default( array( 'widget', 'element' ), 'widget', 'Use "element" for container, section and column.' ),
						'compact' => EMCP_S::boolean( 'Trim CSS selector maps and render hints. Turn off only if you need raw control definitions.', true ),
						'section' => EMCP_S::string( 'Return only this section of the schema, to keep the response small.' ),
					),
					array( 'name' )
				),
				'annotations' => array( 'readOnlyHint' => true, 'idempotentHint' => true ),
				'handler'     => array( __CLASS__, 'get_widget_schema' ),
			),
			array(
				'name'        => 'elementor_list_element_types',
				'title'       => 'List structural element types',
				'description' =>
					'List the structural element types available (container, section, column) and the widget ' .
					'panel categories the site defines.',
				'inputSchema' => self::schema(),
				'annotations' => array( 'readOnlyHint' => true, 'idempotentHint' => true ),
				'handler'     => array( __CLASS__, 'list_element_types' ),
			),
			array(
				'name'        => 'elementor_list_dynamic_tags',
				'title'       => 'List dynamic tags',
				'description' =>
					'List the dynamic tags registered on the site — post title, ACF field, site logo and so on — ' .
					'which let a widget setting pull live data instead of holding a fixed value. Mostly an ' .
					'Elementor Pro feature.',
				'inputSchema' => self::schema(),
				'annotations' => array( 'readOnlyHint' => true, 'idempotentHint' => true ),
				'handler'     => array( __CLASS__, 'list_dynamic_tags' ),
			),
		);
	}

	public static function list_widgets( array $args ) {
		$guard = EMCP_MCP_Guard::read();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		return EMCP_Schema::widgets( $args );
	}

	public static function get_widget_schema( array $args ) {
		$guard = EMCP_MCP_Guard::read();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$compact = ! isset( $args['compact'] ) || $args['compact'];
		$schema  = 'element' === ( $args['kind'] ?? 'widget' )
			? EMCP_Schema::element_schema( (string) $args['name'], $compact )
			: EMCP_Schema::widget_schema( (string) $args['name'], $compact );

		if ( is_wp_error( $schema ) ) {
			return $schema;
		}

		if ( ! empty( $args['section'] ) && ! empty( $schema['sections'] ) ) {
			$wanted = array_values(
				array_filter(
					$schema['sections'],
					static function ( $section ) use ( $args ) {
						return $section['id'] === $args['section'] || $section['label'] === $args['section'];
					}
				)
			);

			if ( ! $wanted ) {
				return array(
					'name'              => $args['name'],
					'requestedSection'  => $args['section'],
					'availableSections' => array_map(
						static function ( $section ) {
							return array( 'id' => $section['id'], 'label' => $section['label'], 'tab' => $section['tab'] );
						},
						$schema['sections']
					),
					'note'              => 'No section matched. Pick one of availableSections.',
				);
			}

			$schema['sections'] = $wanted;
		}

		return $schema;
	}

	public static function list_element_types( array $args ) {
		$guard = EMCP_MCP_Guard::read();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$elements   = EMCP_Schema::elements();
		$categories = EMCP_Schema::categories();

		if ( is_wp_error( $elements ) ) {
			return $elements;
		}

		if ( is_wp_error( $categories ) ) {
			return $categories;
		}

		return array( 'elements' => $elements, 'categories' => $categories );
	}

	public static function list_dynamic_tags( array $args ) {
		$guard = EMCP_MCP_Guard::read();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		return EMCP_Schema::dynamic_tags();
	}

	/* ==================================================================
	 * Global design
	 * ================================================================== */

	private static function design_tools() {
		return array(
			array(
				'name'        => 'elementor_get_globals',
				'title'       => 'Get global design settings',
				'description' =>
					"Read the site's Elementor kit: global colour and typography palettes with the ids widgets " .
					'refer to them by, layout defaults such as container width and breakpoints, and the site ' .
					'custom CSS. Read this before styling anything — using an existing global token keeps a site ' .
					'consistent, and changing one restyles every element bound to it.',
				'inputSchema' => self::schema( array( 'includeRaw' => EMCP_S::boolean( 'Include the complete raw kit settings object as well as the friendly view.', false ) ) ),
				'annotations' => array( 'readOnlyHint' => true, 'idempotentHint' => true ),
				'handler'     => array( __CLASS__, 'get_globals' ),
			),
			array(
				'name'        => 'elementor_set_global_color',
				'title'       => 'Set a global colour',
				'description' =>
					'Change one global colour by id (primary, secondary, text, accent) or by its title, or add a ' .
					'new custom colour if it does not exist. Every element bound to that token updates at once, ' .
					"and Elementor's CSS is regenerated. This is the right way to restyle a site's palette.",
				'inputSchema' => self::schema(
					array(
						'id'    => EMCP_S::string( 'Colour id such as "primary", or the colour\'s title.' ),
						'value' => EMCP_S::string( 'Hex colour, e.g. "#1A73E8".' ),
						'title' => EMCP_S::string( 'Label, used when creating a new custom colour.' ),
					),
					array( 'id', 'value' )
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true ),
				'handler'     => array( __CLASS__, 'set_global_color' ),
			),
			array(
				'name'        => 'elementor_update_globals',
				'title'       => 'Update global design settings',
				'description' =>
					'Merge settings into the Elementor kit — colour and typography palettes, container width, ' .
					'breakpoints, theme style defaults. This changes the whole site at once, so read ' .
					'elementor_get_globals first and change only the keys you mean to. A snapshot of the kit is ' .
					'taken before the write.',
				'inputSchema' => self::schema(
					array(
						'settings' => EMCP_S::free_object(
							'Kit settings to merge, e.g. {"container_width":{"unit":"px","size":1200}} or a full ' .
							'"system_colors" array. Keys not passed are left alone.'
						),
					),
					array( 'settings' )
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true ),
				'handler'     => array( __CLASS__, 'update_globals' ),
			),
			array(
				'name'        => 'elementor_custom_css',
				'title'       => 'Read or write site custom CSS',
				'description' =>
					'Read the site-wide custom CSS held on the Elementor kit, or replace it. Passing css writes ' .
					'it; omitting css just reads. Note that Elementor only renders this on Pro.',
				'inputSchema' => self::schema( array( 'css' => EMCP_S::string( 'New CSS. Omit to read the current value.' ) ) ),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true ),
				'handler'     => array( __CLASS__, 'custom_css' ),
			),
			array(
				'name'        => 'elementor_get_global_classes',
				'title'       => 'Get v4 global classes',
				'description' =>
					'Read Elementor v4 global classes — the reusable style classes used by the atomic/Editor One ' .
					'element model. Returns an empty list on sites that predate that feature.',
				'inputSchema' => self::schema(),
				'annotations' => array( 'readOnlyHint' => true, 'idempotentHint' => true ),
				'handler'     => array( __CLASS__, 'get_global_classes' ),
			),
			array(
				'name'        => 'elementor_flush_css',
				'title'       => 'Regenerate Elementor CSS',
				'description' =>
					"Clear Elementor's cached CSS so it regenerates on the next page load. The editing tools " .
					'already do this after every write; call it manually when styles look stale after changes ' .
					'made outside this server, or after a theme or plugin update.',
				'inputSchema' => self::schema( array( 'postId' => EMCP_S::integer( 'Flush one page. Omit to regenerate the whole site\'s CSS.' ) ) ),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true ),
				'handler'     => array( __CLASS__, 'flush_css' ),
			),
		);
	}

	public static function get_globals( array $args ) {
		$guard = EMCP_MCP_Guard::read();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$kit = EMCP_Globals::read();

		if ( is_wp_error( $kit ) ) {
			return $kit;
		}

		if ( empty( $args['includeRaw'] ) ) {
			unset( $kit['settings'] );
		}

		return $kit;
	}

	public static function set_global_color( array $args ) {
		$guard = EMCP_MCP_Guard::manage_globals();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$result = EMCP_Globals::set_color( (string) $args['id'], (string) $args['value'], isset( $args['title'] ) ? (string) $args['title'] : '' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'colors' => $result['colors'] );
	}

	public static function update_globals( array $args ) {
		$guard = EMCP_MCP_Guard::manage_globals();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$result = EMCP_Globals::update( isset( $args['settings'] ) ? $args['settings'] : array() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		unset( $result['settings'] );

		return $result;
	}

	public static function custom_css( array $args ) {
		if ( ! isset( $args['css'] ) ) {
			$guard = EMCP_MCP_Guard::read();
			if ( is_wp_error( $guard ) ) {
				return $guard;
			}

			return EMCP_Globals::read_custom_css();
		}

		$guard = EMCP_MCP_Guard::manage_globals();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$result = EMCP_Globals::update( array( 'custom_css' => (string) $args['css'] ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return EMCP_Globals::read_custom_css();
	}

	public static function get_global_classes( array $args ) {
		$guard = EMCP_MCP_Guard::read();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		return EMCP_Globals::read_global_classes();
	}

	public static function flush_css( array $args ) {
		$guard = EMCP_MCP_Guard::read();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$ready = EMCP_Guard::require_elementor();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$post_id = isset( $args['postId'] ) ? (int) $args['postId'] : 0;
		EMCP_Documents::flush_css( $post_id );

		return array(
			'flushed' => true,
			'scope'   => $post_id > 0 ? 'document' : 'site',
			'postId'  => $post_id ?: null,
		);
	}

	/* ==================================================================
	 * Templates
	 * ================================================================== */

	private static function template_tools() {
		return array(
			array(
				'name'        => 'elementor_list_templates',
				'title'       => 'List saved templates',
				'description' =>
					'List the Elementor templates saved on this site — sections, containers, pages, headers, ' .
					'footers and popups. Reusing a proven template is cheaper and more consistent than rebuilding ' .
					'the same layout.',
				'inputSchema' => self::schema(
					array(
						'search'  => EMCP_S::string(),
						'type'    => EMCP_S::string( 'Template type: container, section, page, header, footer, popup, single, archive.' ),
						'page'    => EMCP_S::with_default( EMCP_S::integer(), 1 ),
						'perPage' => EMCP_S::with_default( EMCP_S::integer(), 50 ),
					)
				),
				'annotations' => array( 'readOnlyHint' => true, 'idempotentHint' => true ),
				'handler'     => array( __CLASS__, 'list_templates' ),
			),
			array(
				'name'        => 'elementor_get_template',
				'title'       => 'Get a template',
				'description' =>
					"A template's element tree. By default ids are regenerated so the tree can be pasted into a " .
					'page without colliding with anything already there.',
				'inputSchema' => self::schema(
					array(
						'templateId' => EMCP_S::integer(),
						'freshIds'   => EMCP_S::boolean( 'Regenerate element ids so the tree is safe to paste.', true ),
					),
					array( 'templateId' )
				),
				'annotations' => array( 'readOnlyHint' => true, 'idempotentHint' => true ),
				'handler'     => array( __CLASS__, 'get_template' ),
			),
			array(
				'name'        => 'elementor_save_template',
				'title'       => 'Save a template',
				'description' =>
					'Save a layout to the template library, either from an element tree you pass or by copying ' .
					'from an existing page (optionally just one element of it). Save a section once, reuse it ' .
					'everywhere.',
				'inputSchema' => self::schema(
					array(
						'title'           => EMCP_S::string(),
						'type'            => EMCP_S::with_default( EMCP_S::string( 'Template type: container, section, page, header, footer, popup.' ), 'container' ),
						'elements'        => EMCP_S::array_of( EMCP_S::free_object(), 'The element tree to save. Omit if using sourcePostId.' ),
						'sourcePostId'    => EMCP_S::integer( 'Copy the layout from this page instead.' ),
						'sourceElementId' => EMCP_S::string( 'With sourcePostId, save only this element and its subtree.' ),
					),
					array( 'title' )
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false ),
				'handler'     => array( __CLASS__, 'save_template' ),
			),
			array(
				'name'        => 'elementor_apply_template',
				'title'       => 'Insert a template into a page',
				'description' =>
					'Insert a saved template\'s layout into a page, with fresh element ids. Place it at the page ' .
					'root, or relative to an existing element with targetId and position. The page is ' .
					'snapshotted first.',
				'inputSchema' => self::schema(
					array(
						'postId'     => EMCP_S::integer(),
						'templateId' => EMCP_S::integer(),
						'targetId'   => EMCP_S::string( 'Place relative to this element. Omit to append at the root.' ),
						'position'   => EMCP_S::position(),
					),
					array( 'postId', 'templateId' )
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false ),
				'handler'     => array( __CLASS__, 'apply_template' ),
			),
			array(
				'name'        => 'elementor_export_template',
				'title'       => 'Export a template as JSON',
				'description' =>
					"Export a template in Elementor's own JSON format, ready to import elsewhere with " .
					'elementor_import_template or through the Elementor UI.',
				'inputSchema' => self::schema( array( 'templateId' => EMCP_S::integer() ), array( 'templateId' ) ),
				'annotations' => array( 'readOnlyHint' => true, 'idempotentHint' => true ),
				'handler'     => array( __CLASS__, 'export_template' ),
			),
			array(
				'name'        => 'elementor_import_template',
				'title'       => 'Import a template from JSON',
				'description' =>
					'Import an Elementor template JSON payload as a new template on this site. Pass the decoded ' .
					'JSON object from an Elementor export or from elementor_export_template.',
				'inputSchema' => self::schema(
					array(
						'payload' => EMCP_S::free_object( 'The decoded Elementor export object, containing "content" or "elements".' ),
						'title'   => EMCP_S::string( "Override the imported template's title." ),
					),
					array( 'payload' )
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false ),
				'handler'     => array( __CLASS__, 'import_template' ),
			),
		);
	}

	public static function list_templates( array $args ) {
		$guard = EMCP_MCP_Guard::read();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		return EMCP_Library::query( $args );
	}

	public static function get_template( array $args ) {
		$guard = EMCP_MCP_Guard::read();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		return EMCP_Library::content( (int) $args['templateId'], ! isset( $args['freshIds'] ) || $args['freshIds'] );
	}

	public static function save_template( array $args ) {
		$guard = EMCP_MCP_Guard::read();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		return EMCP_Library::save(
			array(
				'title'     => $args['title'],
				'type'      => isset( $args['type'] ) ? $args['type'] : 'container',
				'elements'  => isset( $args['elements'] ) ? $args['elements'] : null,
				'sourceId'  => isset( $args['sourcePostId'] ) ? $args['sourcePostId'] : null,
				'elementId' => isset( $args['sourceElementId'] ) ? $args['sourceElementId'] : null,
			)
		);
	}

	public static function apply_template( array $args ) {
		$post_id = (int) $args['postId'];
		$guard   = EMCP_MCP_Guard::write( $post_id );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$template = EMCP_Library::content( (int) $args['templateId'], true );

		if ( is_wp_error( $template ) ) {
			return $template;
		}

		$nodes = isset( $template['elements'] ) && is_array( $template['elements'] ) ? $template['elements'] : array();

		if ( ! $nodes ) {
			return array(
				'inserted'   => 0,
				'templateId' => $args['templateId'],
				'note'       => sprintf( 'Template %d has no elements to insert.', $args['templateId'] ),
			);
		}

		$outcome = EMCP_Compose::edit(
			$post_id,
			static function ( array $elements ) use ( $args, $nodes ) {
				$current = $elements;
				$anchor  = isset( $args['targetId'] ) ? (string) $args['targetId'] : '';
				$position = isset( $args['position'] ) ? (string) $args['position'] : 'append';

				// Insert in order so the template's own sequence is preserved,
				// each new node anchored after the one placed before it.
				foreach ( $nodes as $node ) {
					$current = EMCP_Tree::insert( $current, $node, $anchor, $position );
					$anchor   = $node['id'];
					$position = 'after';
				}

				return $current;
			},
			'apply template ' . $args['templateId']
		);

		$summary = self::summarise_edit( $outcome, array( 'templateId' => $args['templateId'], 'insertedRootNodes' => count( $nodes ) ) );

		if ( is_wp_error( $summary ) ) {
			return $summary;
		}

		$summary['templateTitle'] = $template['title'];

		return $summary;
	}

	public static function export_template( array $args ) {
		$guard = EMCP_MCP_Guard::read();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		return EMCP_Library::export( (int) $args['templateId'] );
	}

	public static function import_template( array $args ) {
		$guard = EMCP_MCP_Guard::read();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		return EMCP_Library::import( $args['payload'], isset( $args['title'] ) ? (string) $args['title'] : '' );
	}

	/* ==================================================================
	 * History and undo
	 * ================================================================== */

	private static function history_tools() {
		return array(
			array(
				'name'        => 'elementor_list_snapshots',
				'title'       => 'List page snapshots',
				'description' =>
					'List the layout snapshots stored for a page, newest first, with when each was taken and why. ' .
					'One is taken automatically before every write this server makes.',
				'inputSchema' => self::schema( array( 'postId' => EMCP_S::integer() ), array( 'postId' ) ),
				'annotations' => array( 'readOnlyHint' => true, 'idempotentHint' => true ),
				'handler'     => array( __CLASS__, 'list_snapshots' ),
			),
			array(
				'name'        => 'elementor_create_snapshot',
				'title'       => 'Snapshot a page',
				'description' =>
					"Take a named snapshot of a page's current layout. Worth doing before a large restructuring " .
					'so you have one clearly labelled point to come back to.',
				'inputSchema' => self::schema(
					array(
						'postId' => EMCP_S::integer(),
						'label'  => EMCP_S::with_default( EMCP_S::string( 'Why you are taking it.' ), 'manual snapshot' ),
					),
					array( 'postId' )
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false ),
				'handler'     => array( __CLASS__, 'create_snapshot' ),
			),
			array(
				'name'        => 'elementor_restore_snapshot',
				'title'       => 'Undo: restore a page snapshot',
				'description' =>
					'Roll a page back to a snapshot. With no snapshotId this restores the most recent one, which ' .
					'undoes the last change made through this server. The current state is snapshotted first, so ' .
					'a restore is itself reversible.',
				'inputSchema' => self::schema(
					array(
						'postId'     => EMCP_S::integer(),
						'snapshotId' => EMCP_S::string( 'Snapshot to restore. Omit to undo the most recent change.' ),
					),
					array( 'postId' )
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true ),
				'handler'     => array( __CLASS__, 'restore_snapshot' ),
			),
			array(
				'name'        => 'elementor_list_revisions',
				'title'       => 'List WordPress revisions',
				'description' =>
					"List a page's WordPress revisions, flagging which of them actually carry Elementor layout " .
					'data. Revisions reach further back than snapshots but are less reliable for Elementor content.',
				'inputSchema' => self::schema( array( 'postId' => EMCP_S::integer() ), array( 'postId' ) ),
				'annotations' => array( 'readOnlyHint' => true, 'idempotentHint' => true ),
				'handler'     => array( __CLASS__, 'list_revisions' ),
			),
			array(
				'name'        => 'elementor_restore_revision',
				'title'       => 'Restore a WordPress revision',
				'description' =>
					"Restore a page's Elementor layout from a WordPress revision. Only works on revisions flagged " .
					'as carrying Elementor data. A snapshot is taken first.',
				'inputSchema' => self::schema(
					array(
						'postId'     => EMCP_S::integer(),
						'revisionId' => EMCP_S::integer( 'Revision id from elementor_list_revisions.' ),
					),
					array( 'postId', 'revisionId' )
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true ),
				'handler'     => array( __CLASS__, 'restore_revision' ),
			),
		);
	}

	public static function list_snapshots( array $args ) {
		$guard = EMCP_MCP_Guard::read();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$post_id = (int) $args['postId'];

		return array(
			'id'    => $post_id,
			'items' => EMCP_Snapshots::index( $post_id ),
			'limit' => EMCP_Snapshots::limit(),
		);
	}

	public static function create_snapshot( array $args ) {
		$post_id = (int) $args['postId'];
		$guard   = EMCP_MCP_Guard::write( $post_id );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		return EMCP_Snapshots::capture( $post_id, isset( $args['label'] ) ? (string) $args['label'] : 'manual snapshot' );
	}

	public static function restore_snapshot( array $args ) {
		$post_id = (int) $args['postId'];
		$guard   = EMCP_MCP_Guard::write( $post_id );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		return EMCP_Snapshots::restore( $post_id, isset( $args['snapshotId'] ) ? (string) $args['snapshotId'] : '' );
	}

	public static function list_revisions( array $args ) {
		$guard = EMCP_MCP_Guard::read();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$post_id   = (int) $args['postId'];
		$revisions = wp_get_post_revisions( $post_id, array( 'posts_per_page' => 25 ) );
		$items     = array();

		foreach ( $revisions as $revision ) {
			$data = get_metadata( 'post', $revision->ID, EMCP_Documents::DATA_META, true );

			$items[] = array(
				'id'               => $revision->ID,
				'date'             => $revision->post_modified_gmt,
				'author'           => (int) $revision->post_author,
				'authorName'       => get_the_author_meta( 'display_name', $revision->post_author ),
				'isAutosave'       => (bool) wp_is_post_autosave( $revision->ID ),
				'hasElementorData' => ! empty( $data ),
			);
		}

		return array( 'id' => $post_id, 'items' => $items );
	}

	public static function restore_revision( array $args ) {
		$post_id = (int) $args['postId'];
		$guard   = EMCP_MCP_Guard::write( $post_id );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$revision_id = (int) $args['revisionId'];
		$revision    = get_post( $revision_id );

		if ( ! $revision || (int) $revision->post_parent !== $post_id ) {
			return new WP_Error(
				'emcp_revision_mismatch',
				__( 'That revision does not belong to this document.', 'elementor-mcp-bridge' ),
				array( 'status' => 400 )
			);
		}

		$raw = get_metadata( 'post', $revision_id, EMCP_Documents::DATA_META, true );

		if ( empty( $raw ) ) {
			return new WP_Error(
				'emcp_revision_empty',
				__( 'That revision has no Elementor data stored against it.', 'elementor-mcp-bridge' ),
				array( 'status' => 400 )
			);
		}

		$elements = json_decode( is_string( $raw ) ? $raw : wp_json_encode( $raw ), true );

		if ( ! is_array( $elements ) ) {
			return new WP_Error(
				'emcp_revision_corrupt',
				__( 'The Elementor data on that revision could not be decoded.', 'elementor-mcp-bridge' ),
				array( 'status' => 500 )
			);
		}

		return EMCP_Documents::write( $post_id, $elements, null, true, 'restore revision ' . $revision_id );
	}

	/* ==================================================================
	 * Content and media
	 * ================================================================== */

	private static function content_tools() {
		return array(
			array(
				'name'        => 'elementor_search',
				'title'       => 'Search Elementor content site-wide',
				'description' =>
					'Search inside Elementor page data across the site — by text, by widget type, or by which ' .
					'elements define a given setting. Elementor stores its content as JSON in postmeta, so it is ' .
					'invisible to normal WordPress search; this is the way to find "which page has that old phone ' .
					'number on it".',
				'inputSchema' => self::schema(
					array(
						'text'          => EMCP_S::string( 'Text to find inside element settings.' ),
						'widgetType'    => EMCP_S::string( 'Only match this widget or element type.' ),
						'settingKey'    => EMCP_S::string( 'Only match elements that define this settings key.' ),
						'postIds'       => EMCP_S::array_of( EMCP_S::integer(), 'Restrict to these pages.' ),
						'postTypes'     => EMCP_S::array_of( EMCP_S::string(), 'Restrict to these post types.' ),
						'caseSensitive' => EMCP_S::boolean( '', false ),
						'limit'         => EMCP_S::with_default( EMCP_S::integer(), 100 ),
					)
				),
				'annotations' => array( 'readOnlyHint' => true, 'idempotentHint' => true ),
				'handler'     => array( __CLASS__, 'search' ),
			),
			array(
				'name'        => 'elementor_replace_text',
				'title'       => 'Find and replace across Elementor content',
				'description' =>
					'Replace a string everywhere it appears in Elementor page data — a phone number, an old brand ' .
					'name, a changed URL. Runs as a dry run by default and reports exactly which pages and how ' .
					'many occurrences it would touch; set dryRun false and confirm true to commit. Every page it ' .
					'edits is snapshotted first. Always read the dry run before committing.',
				'inputSchema' => self::schema(
					array(
						'search'        => EMCP_S::string( 'The exact string to find.' ),
						'replace'       => EMCP_S::with_default( EMCP_S::string( 'What to put in its place. Empty removes it.' ), '' ),
						'dryRun'        => EMCP_S::boolean( 'Report without writing. Leave true until you have read the report.', true ),
						'confirm'       => EMCP_S::boolean( 'Required when dryRun is false.', false ),
						'postIds'       => EMCP_S::array_of( EMCP_S::integer(), 'Restrict to these pages.' ),
						'postTypes'     => EMCP_S::array_of( EMCP_S::string() ),
						'caseSensitive' => EMCP_S::boolean( '', false ),
					),
					array( 'search' )
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true ),
				'handler'     => array( __CLASS__, 'replace_text' ),
			),
			array(
				'name'        => 'elementor_widget_usage',
				'title'       => 'Report site-wide widget usage',
				'description' =>
					'Count which Elementor widgets are actually used across the site and how much each page ' .
					'contains. Useful before removing an addon plugin, or to find the heaviest pages.',
				'inputSchema' => self::schema( array( 'postTypes' => EMCP_S::array_of( EMCP_S::string() ) ) ),
				'annotations' => array( 'readOnlyHint' => true, 'idempotentHint' => true ),
				'handler'     => array( __CLASS__, 'widget_usage' ),
			),
			array(
				'name'        => 'elementor_list_media',
				'title'       => 'List media library items',
				'description' =>
					'Search the WordPress media library and get back attachment ids and URLs. Elementor image ' .
					'controls need both, in the shape {"id":123,"url":"https://..."}.',
				'inputSchema' => self::schema(
					array(
						'search'   => EMCP_S::string(),
						'mimeType' => EMCP_S::string( 'Filter by MIME type, e.g. "image".' ),
						'page'     => EMCP_S::with_default( EMCP_S::integer(), 1 ),
						'perPage'  => EMCP_S::with_default( EMCP_S::integer(), 20 ),
					)
				),
				'annotations' => array( 'readOnlyHint' => true, 'idempotentHint' => true ),
				'handler'     => array( __CLASS__, 'list_media' ),
			),
			array(
				'name'        => 'elementor_upload_image',
				'title'       => 'Upload an image from a URL',
				'description' =>
					'Download a remote image into the WordPress media library and return the attachment id and ' .
					'URL, plus a ready-made value for an Elementor image control. Use this before setting any ' .
					'image setting — Elementor image controls want a library attachment, not a remote URL.',
				'inputSchema' => self::schema(
					array(
						'url'           => EMCP_S::string( 'Absolute http(s) URL of the image to fetch.' ),
						'title'         => EMCP_S::string(),
						'altText'       => EMCP_S::string( 'Alt text. Worth setting for accessibility and SEO.' ),
						'attachToPostId' => EMCP_S::integer( 'Attach the upload to this post.' ),
					),
					array( 'url' )
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false ),
				'handler'     => array( __CLASS__, 'upload_image' ),
			),
		);
	}

	public static function search( array $args ) {
		$guard = EMCP_MCP_Guard::read();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		return EMCP_Scanner::search( $args );
	}

	public static function replace_text( array $args ) {
		$guard = EMCP_MCP_Guard::read();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$dry_run = ! isset( $args['dryRun'] ) || $args['dryRun'];

		if ( ! $dry_run ) {
			$allowed = EMCP_Guard::check_destructive( ! empty( $args['confirm'] ) );
			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}
		}

		$result = EMCP_Scanner::replace( array_merge( $args, array( 'dryRun' => $dry_run ) ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$verb           = $result['dryRun'] ? 'Would replace' : 'Replaced';
		$result['note'] = sprintf( '%s %d occurrence(s) across %d page(s).', $verb, $result['replacements'], $result['documents'] )
			. ( $result['dryRun'] ? ' Re-send with dryRun: false and confirm: true to apply.' : '' );

		return $result;
	}

	public static function widget_usage( array $args ) {
		$guard = EMCP_MCP_Guard::read();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		return EMCP_Scanner::usage( $args );
	}

	public static function list_media( array $args ) {
		$guard = EMCP_MCP_Guard::read();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$query_args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => isset( $args['perPage'] ) ? min( 100, max( 1, (int) $args['perPage'] ) ) : 20,
			'paged'          => isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1,
		);

		if ( ! empty( $args['search'] ) ) {
			$query_args['s'] = $args['search'];
		}

		if ( ! empty( $args['mimeType'] ) ) {
			$query_args['post_mime_type'] = $args['mimeType'];
		}

		$query = new WP_Query( $query_args );
		$items = array();

		foreach ( $query->posts as $post ) {
			$url = wp_get_attachment_url( $post->ID );

			$items[] = array(
				'id'                => $post->ID,
				'title'             => $post->post_title,
				'url'               => $url,
				'mimeType'          => $post->post_mime_type,
				'alt'               => get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
				'imageControlValue' => array( 'id' => $post->ID, 'url' => $url ),
			);
		}

		return array(
			'items'      => $items,
			'total'      => (int) $query->found_posts,
			'totalPages' => (int) $query->max_num_pages,
		);
	}

	public static function upload_image( array $args ) {
		$guard = EMCP_MCP_Guard::upload();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$url = esc_url_raw( (string) $args['url'] );

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
			isset( $args['attachToPostId'] ) ? (int) $args['attachToPostId'] : 0,
			isset( $args['title'] ) ? (string) $args['title'] : '',
			'id'
		);

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$alt = isset( $args['altText'] ) ? (string) $args['altText'] : '';

		if ( '' !== $alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		}

		$url = wp_get_attachment_url( $attachment_id );

		return array(
			'id'                => (int) $attachment_id,
			'url'               => $url,
			'alt'               => $alt,
			'title'             => get_the_title( $attachment_id ),
			'imageControlValue' => array( 'id' => (int) $attachment_id, 'url' => $url ),
		);
	}
}
