<?php
/**
 * Document routes: read, write, outline, render, snapshots, revisions.
 *
 * @package Elementor_MCP_Bridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Document controller.
 */
class EMCP_REST_Documents extends EMCP_REST_Base {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$this->route(
			'/documents',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'can_read' ),
					'args'                => array_merge(
						$this->pagination_args(),
						array(
							'search'        => array( 'type' => 'string' ),
							'type'          => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => __( 'Post types to include. Defaults to page and post.', 'elementor-mcp-bridge' ),
							),
							'status'        => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'elementorOnly' => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => __( 'Only return posts actually built with Elementor.', 'elementor-mcp-bridge' ),
							),
							'templateType'  => array( 'type' => 'string' ),
							'orderBy'       => array( 'type' => 'string' ),
							'order'         => array(
								'type' => 'string',
								'enum' => array( 'ASC', 'DESC' ),
							),
						)
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'can_read' ),
					'args'                => array(
						'title'        => array( 'type' => 'string' ),
						'type'         => array(
							'type'    => 'string',
							'default' => 'page',
						),
						'status'       => array(
							'type'    => 'string',
							'default' => 'draft',
						),
						'slug'         => array( 'type' => 'string' ),
						'parent'       => array( 'type' => 'integer' ),
						'templateType' => array( 'type' => 'string' ),
						'pageTemplate' => array(
							'type'        => 'string',
							'description' => __( 'Theme page template, e.g. elementor_canvas or elementor_header_footer.', 'elementor-mcp-bridge' ),
						),
						'elements'     => array( 'type' => 'array' ),
						'settings'     => array( 'type' => 'object' ),
					),
				),
			)
		);

		$this->route(
			'/documents/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'show' ),
					'permission_callback' => array( $this, 'can_read' ),
					'args'                => array(
						'id'           => array(
							'type'     => 'integer',
							'required' => true,
						),
						'includeElements' => array(
							'type'        => 'boolean',
							'default'     => true,
							'description' => __( 'Set false for metadata only. Element trees can be large.', 'elementor-mcp-bridge' ),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'can_write' ),
					'args'                => array(
						'id'           => array(
							'type'     => 'integer',
							'required' => true,
						),
						'elements'     => array(
							'type'        => 'array',
							'description' => __( 'Complete replacement element tree.', 'elementor-mcp-bridge' ),
						),
						'settings'     => array(
							'type'        => 'object',
							'description' => __( 'Page settings to merge.', 'elementor-mcp-bridge' ),
						),
						'expectedHash' => array(
							'type'        => 'string',
							'description' => __( 'Hash the caller last read. The write is rejected with 409 if the document changed since. This is what makes concurrent edits safe.', 'elementor-mcp-bridge' ),
						),
						'snapshot'     => array(
							'type'    => 'boolean',
							'default' => true,
						),
						'snapshotLabel' => array( 'type' => 'string' ),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'destroy' ),
					'permission_callback' => array( $this, 'can_write' ),
					'args'                => array(
						'id'      => array(
							'type'     => 'integer',
							'required' => true,
						),
						'force'   => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Bypass the trash and delete permanently.', 'elementor-mcp-bridge' ),
						),
						'confirm' => array( 'type' => 'boolean' ),
					),
				),
			)
		);

		$this->route(
			'/documents/(?P<id>\d+)/outline',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'outline' ),
				'permission_callback' => array( $this, 'can_read' ),
				'args'                => array(
					'id'       => array(
						'type'     => 'integer',
						'required' => true,
					),
					'maxDepth' => array(
						'type'        => 'integer',
						'default'     => 0,
						'description' => __( 'Levels to descend, 0 for the whole tree.', 'elementor-mcp-bridge' ),
					),
				),
			)
		);

		$this->route(
			'/documents/(?P<id>\d+)/elements/(?P<elementId>[A-Za-z0-9_-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'element' ),
				'permission_callback' => array( $this, 'can_read' ),
				'args'                => array(
					'id'        => array(
						'type'     => 'integer',
						'required' => true,
					),
					'elementId' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		$this->route(
			'/documents/(?P<id>\d+)/duplicate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'duplicate' ),
				'permission_callback' => array( $this, 'can_read' ),
				'args'                => array(
					'id'     => array(
						'type'     => 'integer',
						'required' => true,
					),
					'title'  => array( 'type' => 'string' ),
					'status' => array( 'type' => 'string' ),
					'slug'   => array( 'type' => 'string' ),
				),
			)
		);

		$this->route(
			'/documents/(?P<id>\d+)/convert',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'convert' ),
				'permission_callback' => array( $this, 'can_write' ),
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);

		$this->route(
			'/documents/(?P<id>\d+)/render',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'render' ),
				'permission_callback' => array( $this, 'can_read' ),
				'args'                => array(
					'id'        => array(
						'type'     => 'integer',
						'required' => true,
					),
					'elementId' => array(
						'type'        => 'string',
						'description' => __( 'Render just this element instead of the whole document.', 'elementor-mcp-bridge' ),
					),
				),
			)
		);

		$this->route(
			'/documents/(?P<id>\d+)/snapshots',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'snapshots' ),
					'permission_callback' => array( $this, 'can_read' ),
					'args'                => array(
						'id' => array(
							'type'     => 'integer',
							'required' => true,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'snapshot_create' ),
					'permission_callback' => array( $this, 'can_write' ),
					'args'                => array(
						'id'    => array(
							'type'     => 'integer',
							'required' => true,
						),
						'label' => array( 'type' => 'string' ),
					),
				),
			)
		);

		$this->route(
			'/documents/(?P<id>\d+)/snapshots/restore',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'snapshot_restore' ),
				'permission_callback' => array( $this, 'can_write' ),
				'args'                => array(
					'id'         => array(
						'type'     => 'integer',
						'required' => true,
					),
					'snapshotId' => array(
						'type'        => 'string',
						'description' => __( 'Snapshot to restore. Omit for the most recent one.', 'elementor-mcp-bridge' ),
					),
				),
			)
		);

		$this->route(
			'/documents/(?P<id>\d+)/revisions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'revisions' ),
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
			'/documents/(?P<id>\d+)/revisions/restore',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'revision_restore' ),
				'permission_callback' => array( $this, 'can_write' ),
				'args'                => array(
					'id'         => array(
						'type'     => 'integer',
						'required' => true,
					),
					'revisionId' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * List documents.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function index( $request ) {
		return $this->respond(
			EMCP_Documents::query(
				array(
					'search'        => $request->get_param( 'search' ),
					'type'          => $request->get_param( 'type' ),
					'status'        => $request->get_param( 'status' ),
					'elementorOnly' => $request->get_param( 'elementorOnly' ),
					'templateType'  => $request->get_param( 'templateType' ),
					'orderBy'       => $request->get_param( 'orderBy' ),
					'order'         => $request->get_param( 'order' ),
					'page'          => $request->get_param( 'page' ),
					'perPage'       => $request->get_param( 'perPage' ),
				)
			)
		);
	}

	/**
	 * Create a document.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create( $request ) {
		return $this->respond(
			EMCP_Documents::create(
				array(
					'title'        => $request->get_param( 'title' ),
					'type'         => $request->get_param( 'type' ),
					'status'       => $request->get_param( 'status' ),
					'slug'         => $request->get_param( 'slug' ),
					'parent'       => $request->get_param( 'parent' ),
					'templateType' => $request->get_param( 'templateType' ),
					'pageTemplate' => $request->get_param( 'pageTemplate' ),
					'elements'     => $request->get_param( 'elements' ),
					'settings'     => $request->get_param( 'settings' ),
				)
			)
		);
	}

	/**
	 * Read one document.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function show( $request ) {
		$post_id = (int) $request->get_param( 'id' );

		$meta = EMCP_Documents::describe( $post_id );

		if ( is_wp_error( $meta ) ) {
			return $meta;
		}

		if ( $request->get_param( 'includeElements' ) ) {
			$elements = EMCP_Documents::read_elements( $post_id );

			if ( is_wp_error( $elements ) ) {
				return $elements;
			}

			$meta['elements'] = $elements;
			$meta['settings'] = EMCP_Documents::read_settings( $post_id );
		}

		return $this->respond( $meta );
	}

	/**
	 * Write a document.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update( $request ) {
		$post_id  = (int) $request->get_param( 'id' );
		$elements = $request->get_param( 'elements' );
		$settings = $request->get_param( 'settings' );
		$expected = (string) $request->get_param( 'expectedHash' );

		if ( null !== $elements && ! is_array( $elements ) ) {
			return new WP_Error(
				'emcp_bad_elements',
				__( '"elements" must be an array of element nodes.', 'elementor-mcp-bridge' ),
				array( 'status' => 400 )
			);
		}

		// The conflict check itself lives in EMCP_Documents::write() so every
		// writer — this REST route and the MCP compose tools alike — shares one
		// implementation instead of two copies that could drift.
		return $this->respond(
			EMCP_Documents::write(
				$post_id,
				$elements,
				is_array( $settings ) ? $settings : null,
				(bool) $request->get_param( 'snapshot' ),
				(string) $request->get_param( 'snapshotLabel' ),
				'' !== $expected ? $expected : null
			)
		);
	}

	/**
	 * Trash or delete a document.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function destroy( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$force   = (bool) $request->get_param( 'force' );

		if ( ! EMCP_Guard::can_delete_post( $post_id ) ) {
			return new WP_Error(
				'emcp_forbidden',
				__( 'You do not have permission to delete this post.', 'elementor-mcp-bridge' ),
				array( 'status' => 403 )
			);
		}

		if ( $force ) {
			$allowed = EMCP_Guard::check_destructive( (bool) $request->get_param( 'confirm' ) );

			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}
		}

		$result = wp_delete_post( $post_id, $force );

		if ( ! $result ) {
			return new WP_Error(
				'emcp_delete_failed',
				__( 'WordPress refused to delete that post.', 'elementor-mcp-bridge' ),
				array( 'status' => 500 )
			);
		}

		return $this->respond(
			array(
				'id'      => $post_id,
				'deleted' => true,
				'forced'  => $force,
			)
		);
	}

	/**
	 * Compact structural outline.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function outline( $request ) {
		$post_id  = (int) $request->get_param( 'id' );
		$elements = EMCP_Documents::read_elements( $post_id );

		if ( is_wp_error( $elements ) ) {
			return $elements;
		}

		return $this->respond(
			array(
				'id'        => $post_id,
				'title'     => get_the_title( $post_id ),
				'hash'      => EMCP_Tree::hash( $elements ),
				'nodeCount' => count( EMCP_Tree::collect_ids( $elements ) ),
				'outline'   => EMCP_Tree::outline( $elements, (int) $request->get_param( 'maxDepth' ) ),
			)
		);
	}

	/**
	 * Read a single element subtree.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function element( $request ) {
		$post_id    = (int) $request->get_param( 'id' );
		$element_id = (string) $request->get_param( 'elementId' );

		$elements = EMCP_Documents::read_elements( $post_id );

		if ( is_wp_error( $elements ) ) {
			return $elements;
		}

		$node = EMCP_Tree::find( $elements, $element_id );

		if ( null === $node ) {
			return new WP_Error(
				'emcp_element_not_found',
				sprintf(
					/* translators: 1: element id, 2: post id */
					__( 'No element "%1$s" in document %2$d. Call the outline endpoint to see valid IDs.', 'elementor-mcp-bridge' ),
					$element_id,
					$post_id
				),
				array( 'status' => 404 )
			);
		}

		return $this->respond(
			array(
				'postId'  => $post_id,
				'element' => $node,
			)
		);
	}

	/**
	 * Duplicate a document.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function duplicate( $request ) {
		return $this->respond(
			EMCP_Documents::duplicate(
				(int) $request->get_param( 'id' ),
				array(
					'title'  => $request->get_param( 'title' ),
					'status' => $request->get_param( 'status' ),
					'slug'   => $request->get_param( 'slug' ),
				)
			)
		);
	}

	/**
	 * Flag an existing post as Elementor-built.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function convert( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'emcp_post_not_found',
				sprintf(
					/* translators: %d: post id */
					__( 'No post with ID %d.', 'elementor-mcp-bridge' ),
					$post_id
				),
				array( 'status' => 404 )
			);
		}

		EMCP_Documents::mark_built( $post_id, 'wp-' . $post->post_type );

		return $this->respond( EMCP_Documents::describe( $post_id ) );
	}

	/**
	 * Render a document or element.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function render( $request ) {
		return $this->respond(
			EMCP_Documents::render(
				(int) $request->get_param( 'id' ),
				(string) $request->get_param( 'elementId' )
			)
		);
	}

	/**
	 * List snapshots.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function snapshots( $request ) {
		$post_id = (int) $request->get_param( 'id' );

		return $this->respond(
			array(
				'id'    => $post_id,
				'items' => EMCP_Snapshots::index( $post_id ),
				'limit' => EMCP_Snapshots::limit(),
			)
		);
	}

	/**
	 * Take a snapshot.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function snapshot_create( $request ) {
		return $this->respond(
			EMCP_Snapshots::capture(
				(int) $request->get_param( 'id' ),
				(string) $request->get_param( 'label' )
			)
		);
	}

	/**
	 * Restore a snapshot.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function snapshot_restore( $request ) {
		return $this->respond(
			EMCP_Snapshots::restore(
				(int) $request->get_param( 'id' ),
				(string) $request->get_param( 'snapshotId' )
			)
		);
	}

	/**
	 * List WordPress revisions that carry Elementor data.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function revisions( $request ) {
		$post_id   = (int) $request->get_param( 'id' );
		$revisions = wp_get_post_revisions( $post_id, array( 'posts_per_page' => 25 ) );

		$items = array();

		foreach ( $revisions as $revision ) {
			$data = get_metadata( 'post', $revision->ID, EMCP_Documents::DATA_META, true );

			$items[] = array(
				'id'           => $revision->ID,
				'date'         => $revision->post_modified_gmt,
				'author'       => (int) $revision->post_author,
				'authorName'   => get_the_author_meta( 'display_name', $revision->post_author ),
				'isAutosave'   => wp_is_post_autosave( $revision->ID ) ? true : false,
				'hasElementorData' => ! empty( $data ),
			);
		}

		return $this->respond(
			array(
				'id'    => $post_id,
				'items' => $items,
			)
		);
	}

	/**
	 * Restore Elementor data from a WordPress revision.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function revision_restore( $request ) {
		$post_id     = (int) $request->get_param( 'id' );
		$revision_id = (int) $request->get_param( 'revisionId' );

		$revision = get_post( $revision_id );

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

		return $this->respond( EMCP_Documents::write( $post_id, $elements, null, true ) );
	}
}
