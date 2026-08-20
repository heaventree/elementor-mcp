<?php
/**
 * Reading and writing Elementor documents.
 *
 * @package Elementor_MCP_Bridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Document access layer.
 *
 * Writes go through `Elementor\Core\Base\Document::save()` wherever possible.
 * That is the same path the editor uses, so it runs Elementor's own
 * normalisation, honours `unfiltered_html`, drops the stale post CSS and fires
 * the hooks other plugins listen for. Writing `_elementor_data` directly skips
 * all of that, so it is only used as a fallback for post types Elementor
 * refuses to build a document for.
 */
class EMCP_Documents {

	/**
	 * Postmeta key holding the element tree.
	 */
	const DATA_META = '_elementor_data';

	/**
	 * Postmeta key marking a post as Elementor-built.
	 */
	const EDIT_MODE_META = '_elementor_edit_mode';

	/**
	 * Postmeta key holding page-level settings.
	 */
	const PAGE_SETTINGS_META = '_elementor_page_settings';

	/**
	 * Postmeta key holding the document template type.
	 */
	const TEMPLATE_TYPE_META = '_elementor_template_type';

	/**
	 * Postmeta key holding the Elementor version a document was saved with.
	 */
	const VERSION_META = '_elementor_version';

	/**
	 * Fetch the Elementor document object for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return \Elementor\Core\Base\Document|WP_Error
	 */
	public static function document( $post_id ) {
		$ready = EMCP_Guard::require_elementor();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$post = get_post( (int) $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'emcp_post_not_found',
				sprintf(
					/* translators: %d: post id */
					__( 'No post with ID %d.', 'elementor-mcp-bridge' ),
					(int) $post_id
				),
				array( 'status' => 404 )
			);
		}

		$document = \Elementor\Plugin::$instance->documents->get( (int) $post_id, false );

		if ( ! $document ) {
			return new WP_Error(
				'emcp_not_a_document',
				sprintf(
					/* translators: 1: post id, 2: post type */
					__( 'Post %1$d (type "%2$s") is not an Elementor document. Enable Elementor for this post type, or call documents/convert first.', 'elementor-mcp-bridge' ),
					(int) $post_id,
					$post->post_type
				),
				array( 'status' => 400 )
			);
		}

		return $document;
	}

	/**
	 * Read the element tree for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array|WP_Error
	 */
	public static function read_elements( $post_id ) {
		$document = self::document( $post_id );

		if ( ! is_wp_error( $document ) ) {
			$elements = $document->get_elements_data();

			return is_array( $elements ) ? $elements : array();
		}

		// Fall back to raw meta so we can still read data for post types
		// Elementor will not hand us a document for.
		$raw = get_post_meta( (int) $post_id, self::DATA_META, true );

		if ( ! $raw ) {
			return is_wp_error( $document ) && 'emcp_post_not_found' === $document->get_error_code()
				? $document
				: array();
		}

		$decoded = json_decode( is_string( $raw ) ? $raw : wp_json_encode( $raw ), true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Read page-level Elementor settings (page layout, custom CSS, and so on).
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function read_settings( $post_id ) {
		$settings = get_post_meta( (int) $post_id, self::PAGE_SETTINGS_META, true );

		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Write elements and/or settings back to a document.
	 *
	 * @param int        $post_id  Post ID.
	 * @param array|null $elements Element tree, or null to leave untouched.
	 * @param array|null $settings Page settings to merge, or null.
	 * @param bool       $snapshot Whether to snapshot the prior state first.
	 * @param string     $label    Snapshot label.
	 * @return array|WP_Error Summary of the write.
	 */
	public static function write( $post_id, $elements = null, $settings = null, $snapshot = true, $label = '' ) {
		$post_id = (int) $post_id;

		if ( ! EMCP_Guard::can_edit_post( $post_id ) ) {
			return new WP_Error(
				'emcp_cannot_edit',
				__( 'You do not have permission to edit this post.', 'elementor-mcp-bridge' ),
				array( 'status' => 403 )
			);
		}

		if ( is_array( $elements ) ) {
			$valid = EMCP_Tree::validate( $elements );

			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
		}

		if ( $snapshot ) {
			EMCP_Snapshots::capture( $post_id, '' !== $label ? $label : 'auto: before write' );
		}

		$document = self::document( $post_id );
		$payload  = array();

		if ( is_array( $elements ) ) {
			$payload['elements'] = $elements;
		}

		if ( is_array( $settings ) ) {
			$payload['settings'] = $settings;
		}

		if ( ! $payload ) {
			return new WP_Error(
				'emcp_nothing_to_write',
				__( 'Provide "elements", "settings", or both.', 'elementor-mcp-bridge' ),
				array( 'status' => 400 )
			);
		}

		if ( ! is_wp_error( $document ) ) {
			$saved = $document->save( $payload );

			if ( ! $saved ) {
				return new WP_Error(
					'emcp_save_failed',
					__( 'Elementor refused the save. The most common cause is that the authenticated user cannot edit this document.', 'elementor-mcp-bridge' ),
					array( 'status' => 500 )
				);
			}
		} else {
			// Raw fallback: mirror what Document::save would have persisted.
			if ( is_array( $elements ) ) {
				update_post_meta( $post_id, self::DATA_META, wp_slash( (string) wp_json_encode( $elements ) ) );
			}

			if ( is_array( $settings ) ) {
				$existing = self::read_settings( $post_id );
				update_post_meta( $post_id, self::PAGE_SETTINGS_META, array_replace_recursive( $existing, $settings ) );
			}

			update_post_meta( $post_id, self::EDIT_MODE_META, 'builder' );

			if ( defined( 'ELEMENTOR_VERSION' ) ) {
				update_post_meta( $post_id, self::VERSION_META, ELEMENTOR_VERSION );
			}
		}

		self::flush_css( $post_id );

		$current = self::read_elements( $post_id );

		return array(
			'id'        => $post_id,
			'hash'      => is_array( $current ) ? EMCP_Tree::hash( $current ) : null,
			'nodeCount' => is_array( $current ) ? count( EMCP_Tree::collect_ids( $current ) ) : 0,
			'editUrl'   => self::edit_url( $post_id ),
			'permalink' => get_permalink( $post_id ),
		);
	}

	/**
	 * Drop cached CSS so the next front-end request regenerates it.
	 *
	 * Elementor caches per-post CSS in files/postmeta; without this a settings
	 * change saves fine but the page still renders with the old styles.
	 *
	 * @param int $post_id Post ID, or 0 to clear everything.
	 * @return void
	 */
	public static function flush_css( $post_id = 0 ) {
		if ( ! did_action( 'elementor/loaded' ) ) {
			return;
		}

		$post_id = (int) $post_id;

		if ( $post_id > 0 && class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
			\Elementor\Core\Files\CSS\Post::create( $post_id )->delete();

			return;
		}

		if ( isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
	}

	/**
	 * Elementor edit URL for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function edit_url( $post_id ) {
		return add_query_arg(
			array(
				'post'   => (int) $post_id,
				'action' => 'elementor',
			),
			admin_url( 'post.php' )
		);
	}

	/**
	 * Metadata describing a document, without the element payload.
	 *
	 * @param int $post_id Post ID.
	 * @return array|WP_Error
	 */
	public static function describe( $post_id ) {
		$post = get_post( (int) $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'emcp_post_not_found',
				sprintf(
					/* translators: %d: post id */
					__( 'No post with ID %d.', 'elementor-mcp-bridge' ),
					(int) $post_id
				),
				array( 'status' => 404 )
			);
		}

		$elements = self::read_elements( $post->ID );
		$elements = is_wp_error( $elements ) ? array() : $elements;

		return array(
			'id'              => $post->ID,
			'title'           => $post->post_title,
			'slug'            => $post->post_name,
			'status'          => $post->post_status,
			'type'            => $post->post_type,
			'permalink'       => get_permalink( $post ),
			'editUrl'         => self::edit_url( $post->ID ),
			'modified'        => $post->post_modified_gmt,
			'builtWith'       => get_post_meta( $post->ID, self::EDIT_MODE_META, true ),
			'templateType'    => get_post_meta( $post->ID, self::TEMPLATE_TYPE_META, true ),
			'elementorVersion' => get_post_meta( $post->ID, self::VERSION_META, true ),
			'isElementor'     => 'builder' === get_post_meta( $post->ID, self::EDIT_MODE_META, true ),
			'hash'            => EMCP_Tree::hash( $elements ),
			'nodeCount'       => count( EMCP_Tree::collect_ids( $elements ) ),
			'census'          => EMCP_Tree::census( $elements ),
			'snapshotCount'   => count( EMCP_Snapshots::all( $post->ID ) ),
		);
	}

	/**
	 * Mark an existing post as Elementor-built without touching its layout.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $type    Template type, defaults to a normal page.
	 * @return void
	 */
	public static function mark_built( $post_id, $type = 'wp-page' ) {
		$post_id = (int) $post_id;

		update_post_meta( $post_id, self::EDIT_MODE_META, 'builder' );

		if ( ! get_post_meta( $post_id, self::TEMPLATE_TYPE_META, true ) ) {
			update_post_meta( $post_id, self::TEMPLATE_TYPE_META, $type );
		}

		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			update_post_meta( $post_id, self::VERSION_META, ELEMENTOR_VERSION );
		}
	}

	/**
	 * Create a new Elementor-enabled post.
	 *
	 * @param array $args Creation arguments.
	 * @return array|WP_Error
	 */
	public static function create( array $args ) {
		$post_type = isset( $args['type'] ) ? sanitize_key( $args['type'] ) : 'page';

		$type_object = get_post_type_object( $post_type );

		if ( ! $type_object ) {
			return new WP_Error(
				'emcp_unknown_post_type',
				sprintf(
					/* translators: %s: post type */
					__( 'Unknown post type "%s".', 'elementor-mcp-bridge' ),
					$post_type
				),
				array( 'status' => 400 )
			);
		}

		if ( ! current_user_can( $type_object->cap->create_posts ) ) {
			return new WP_Error(
				'emcp_cannot_create',
				__( 'You do not have permission to create posts of this type.', 'elementor-mcp-bridge' ),
				array( 'status' => 403 )
			);
		}

		$postarr = array(
			'post_type'    => $post_type,
			'post_title'   => isset( $args['title'] ) ? sanitize_text_field( $args['title'] ) : __( 'Untitled', 'elementor-mcp-bridge' ),
			'post_status'  => isset( $args['status'] ) ? sanitize_key( $args['status'] ) : 'draft',
			'post_content' => '',
		);

		if ( ! empty( $args['slug'] ) ) {
			$postarr['post_name'] = sanitize_title( $args['slug'] );
		}

		if ( ! empty( $args['parent'] ) ) {
			$postarr['post_parent'] = (int) $args['parent'];
		}

		$post_id = wp_insert_post( $postarr, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		self::mark_built( $post_id, isset( $args['templateType'] ) ? sanitize_key( $args['templateType'] ) : 'wp-' . $post_type );

		if ( ! empty( $args['pageTemplate'] ) ) {
			update_post_meta( $post_id, '_wp_page_template', sanitize_text_field( $args['pageTemplate'] ) );
		}

		$elements = isset( $args['elements'] ) && is_array( $args['elements'] ) ? $args['elements'] : array();

		$written = self::write( $post_id, $elements, isset( $args['settings'] ) && is_array( $args['settings'] ) ? $args['settings'] : null, false );

		if ( is_wp_error( $written ) ) {
			return $written;
		}

		return self::describe( $post_id );
	}

	/**
	 * Copy a document into a new post.
	 *
	 * @param int   $post_id Source post ID.
	 * @param array $args    Overrides for the copy.
	 * @return array|WP_Error
	 */
	public static function duplicate( $post_id, array $args = array() ) {
		$source = get_post( (int) $post_id );

		if ( ! $source ) {
			return new WP_Error(
				'emcp_post_not_found',
				sprintf(
					/* translators: %d: post id */
					__( 'No post with ID %d.', 'elementor-mcp-bridge' ),
					(int) $post_id
				),
				array( 'status' => 404 )
			);
		}

		if ( ! EMCP_Guard::can_read() ) {
			return new WP_Error(
				'emcp_cannot_read',
				__( 'You do not have permission to read this post.', 'elementor-mcp-bridge' ),
				array( 'status' => 403 )
			);
		}

		$elements = self::read_elements( $source->ID );
		$elements = is_wp_error( $elements ) ? array() : EMCP_Tree::regenerate_ids( $elements );

		$created = self::create(
			array(
				'type'         => isset( $args['type'] ) ? $args['type'] : $source->post_type,
				'title'        => isset( $args['title'] ) ? $args['title'] : $source->post_title . ' (copy)',
				'status'       => isset( $args['status'] ) ? $args['status'] : 'draft',
				'slug'         => isset( $args['slug'] ) ? $args['slug'] : '',
				'parent'       => isset( $args['parent'] ) ? $args['parent'] : 0,
				'templateType' => get_post_meta( $source->ID, self::TEMPLATE_TYPE_META, true ),
				'elements'     => $elements,
			)
		);

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		// Carry over page-level settings and the theme page template.
		$settings = self::read_settings( $source->ID );

		if ( $settings ) {
			update_post_meta( $created['id'], self::PAGE_SETTINGS_META, $settings );
		}

		$page_template = get_post_meta( $source->ID, '_wp_page_template', true );

		if ( $page_template ) {
			update_post_meta( $created['id'], '_wp_page_template', $page_template );
		}

		self::flush_css( $created['id'] );

		return self::describe( $created['id'] );
	}

	/**
	 * Render a document, or one element of it, to HTML.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $element_id Optional element ID to render in isolation.
	 * @return array|WP_Error
	 */
	public static function render( $post_id, $element_id = '' ) {
		$document = self::document( $post_id );

		if ( is_wp_error( $document ) ) {
			return $document;
		}

		if ( '' === $element_id ) {
			return array(
				'id'   => (int) $post_id,
				'html' => $document->get_content( false ),
			);
		}

		$elements = self::read_elements( $post_id );
		$node     = is_wp_error( $elements ) ? null : EMCP_Tree::find( $elements, $element_id );

		if ( null === $node ) {
			return new WP_Error(
				'emcp_element_not_found',
				sprintf(
					/* translators: 1: element id, 2: post id */
					__( 'No element "%1$s" in document %2$d.', 'elementor-mcp-bridge' ),
					$element_id,
					(int) $post_id
				),
				array( 'status' => 404 )
			);
		}

		ob_start();
		$document->render_element( $node );
		$html = ob_get_clean();

		return array(
			'id'        => (int) $post_id,
			'elementId' => $element_id,
			'html'      => $html,
		);
	}

	/**
	 * Query Elementor documents.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public static function query( array $args ) {
		$query_args = array(
			'post_type'      => ! empty( $args['type'] ) ? $args['type'] : array( 'page', 'post' ),
			'post_status'    => ! empty( $args['status'] ) ? $args['status'] : array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page' => isset( $args['perPage'] ) ? min( 100, max( 1, (int) $args['perPage'] ) ) : 20,
			'paged'          => isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1,
			'orderby'        => ! empty( $args['orderBy'] ) ? $args['orderBy'] : 'modified',
			'order'          => ! empty( $args['order'] ) ? $args['order'] : 'DESC',
		);

		if ( ! empty( $args['search'] ) ) {
			$query_args['s'] = $args['search'];
		}

		if ( ! empty( $args['elementorOnly'] ) ) {
			$query_args['meta_query'] = array(
				array(
					'key'     => self::EDIT_MODE_META,
					'value'   => 'builder',
					'compare' => '=',
				),
			);
		}

		if ( ! empty( $args['templateType'] ) ) {
			$query_args['meta_query'][] = array(
				'key'     => self::TEMPLATE_TYPE_META,
				'value'   => $args['templateType'],
				'compare' => '=',
			);
		}

		$query = new WP_Query( $query_args );

		$items = array();

		foreach ( $query->posts as $post ) {
			$items[] = array(
				'id'           => $post->ID,
				'title'        => $post->post_title,
				'slug'         => $post->post_name,
				'type'         => $post->post_type,
				'status'       => $post->post_status,
				'modified'     => $post->post_modified_gmt,
				'permalink'    => get_permalink( $post ),
				'editUrl'      => self::edit_url( $post->ID ),
				'isElementor'  => 'builder' === get_post_meta( $post->ID, self::EDIT_MODE_META, true ),
				'templateType' => get_post_meta( $post->ID, self::TEMPLATE_TYPE_META, true ),
			);
		}

		return array(
			'items'      => $items,
			'total'      => (int) $query->found_posts,
			'totalPages' => (int) $query->max_num_pages,
			'page'       => (int) $query_args['paged'],
		);
	}
}
