<?php
/**
 * Elementor template library access.
 *
 * @package Elementor_MCP_Bridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Local template library.
 *
 * Templates are `elementor_library` posts whose kind is stored both in a
 * taxonomy and in `_elementor_template_type`. Saving a proven layout as a
 * template and applying it elsewhere is far cheaper and safer than
 * regenerating the same element tree by hand on every page.
 */
class EMCP_Library {

	/**
	 * Template post type.
	 */
	const CPT = 'elementor_library';

	/**
	 * Template type taxonomy.
	 */
	const TAXONOMY = 'elementor_library_type';

	/**
	 * List saved templates.
	 *
	 * @param array $args Filters: type, search, page, perPage.
	 * @return array
	 */
	public static function query( array $args = array() ) {
		$query_args = array(
			'post_type'      => self::CPT,
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'posts_per_page' => isset( $args['perPage'] ) ? min( 100, max( 1, (int) $args['perPage'] ) ) : 50,
			'paged'          => isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);

		if ( ! empty( $args['search'] ) ) {
			$query_args['s'] = $args['search'];
		}

		if ( ! empty( $args['type'] ) ) {
			$query_args['meta_query'] = array(
				array(
					'key'     => EMCP_Documents::TEMPLATE_TYPE_META,
					'value'   => $args['type'],
					'compare' => '=',
				),
			);
		}

		$query = new WP_Query( $query_args );
		$items = array();

		foreach ( $query->posts as $post ) {
			$items[] = array(
				'id'       => $post->ID,
				'title'    => $post->post_title,
				'type'     => get_post_meta( $post->ID, EMCP_Documents::TEMPLATE_TYPE_META, true ),
				'status'   => $post->post_status,
				'modified' => $post->post_modified_gmt,
				'editUrl'  => EMCP_Documents::edit_url( $post->ID ),
			);
		}

		return array(
			'items'      => $items,
			'total'      => (int) $query->found_posts,
			'totalPages' => (int) $query->max_num_pages,
			'page'       => (int) $query_args['paged'],
		);
	}

	/**
	 * Save an element tree as a reusable template.
	 *
	 * @param array $args title, type, elements, or sourceId (+ optional elementId).
	 * @return array|WP_Error
	 */
	public static function save( array $args ) {
		if ( ! current_user_can( 'publish_posts' ) ) {
			return new WP_Error(
				'emcp_cannot_save_template',
				__( 'You do not have permission to create templates.', 'elementor-mcp-bridge' ),
				array( 'status' => 403 )
			);
		}

		$elements = isset( $args['elements'] ) && is_array( $args['elements'] ) ? $args['elements'] : null;

		if ( null === $elements && ! empty( $args['sourceId'] ) ) {
			$source = EMCP_Documents::read_elements( (int) $args['sourceId'] );

			if ( is_wp_error( $source ) ) {
				return $source;
			}

			if ( ! empty( $args['elementId'] ) ) {
				$node = EMCP_Tree::find( $source, (string) $args['elementId'] );

				if ( null === $node ) {
					return new WP_Error(
						'emcp_element_not_found',
						sprintf(
							/* translators: 1: element id, 2: post id */
							__( 'No element "%1$s" in document %2$d.', 'elementor-mcp-bridge' ),
							$args['elementId'],
							(int) $args['sourceId']
						),
						array( 'status' => 404 )
					);
				}

				$elements = array( $node );
			} else {
				$elements = $source;
			}
		}

		if ( ! is_array( $elements ) ) {
			return new WP_Error(
				'emcp_template_needs_content',
				__( 'Provide "elements", or a "sourceId" to copy from.', 'elementor-mcp-bridge' ),
				array( 'status' => 400 )
			);
		}

		$elements = EMCP_Tree::regenerate_ids( $elements );
		$type     = isset( $args['type'] ) ? sanitize_key( $args['type'] ) : 'container';

		$post_id = wp_insert_post(
			array(
				'post_type'   => self::CPT,
				'post_title'  => isset( $args['title'] ) ? sanitize_text_field( $args['title'] ) : __( 'Untitled template', 'elementor-mcp-bridge' ),
				'post_status' => 'publish',
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, EMCP_Documents::TEMPLATE_TYPE_META, $type );
		EMCP_Documents::mark_built( $post_id, $type );

		if ( taxonomy_exists( self::TAXONOMY ) ) {
			wp_set_object_terms( $post_id, $type, self::TAXONOMY );
		}

		$written = EMCP_Documents::write( $post_id, $elements, null, false );

		if ( is_wp_error( $written ) ) {
			return $written;
		}

		return array(
			'id'      => $post_id,
			'title'   => get_the_title( $post_id ),
			'type'    => $type,
			'editUrl' => EMCP_Documents::edit_url( $post_id ),
		);
	}

	/**
	 * Get a template's element tree, with fresh IDs so it can be pasted safely.
	 *
	 * @param int  $template_id Template post ID.
	 * @param bool $fresh_ids   Whether to regenerate element IDs.
	 * @return array|WP_Error
	 */
	public static function content( $template_id, $fresh_ids = true ) {
		$post = get_post( (int) $template_id );

		if ( ! $post ) {
			return new WP_Error(
				'emcp_template_not_found',
				sprintf(
					/* translators: %d: template id */
					__( 'No template with ID %d.', 'elementor-mcp-bridge' ),
					(int) $template_id
				),
				array( 'status' => 404 )
			);
		}

		$elements = EMCP_Documents::read_elements( $post->ID );

		if ( is_wp_error( $elements ) ) {
			return $elements;
		}

		return array(
			'id'       => $post->ID,
			'title'    => $post->post_title,
			'type'     => get_post_meta( $post->ID, EMCP_Documents::TEMPLATE_TYPE_META, true ),
			'elements' => $fresh_ids ? EMCP_Tree::regenerate_ids( $elements ) : $elements,
		);
	}

	/**
	 * Export a template in the shape Elementor's own JSON import expects.
	 *
	 * @param int $template_id Template post ID.
	 * @return array|WP_Error
	 */
	public static function export( $template_id ) {
		$content = self::content( $template_id, false );

		if ( is_wp_error( $content ) ) {
			return $content;
		}

		return array(
			'version'  => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '',
			'title'    => $content['title'],
			'type'     => $content['type'],
			'content'  => $content['elements'],
			'page_settings' => EMCP_Documents::read_settings( (int) $template_id ),
		);
	}

	/**
	 * Import an Elementor template JSON payload as a new template.
	 *
	 * @param array  $payload Decoded Elementor export JSON.
	 * @param string $title   Optional title override.
	 * @return array|WP_Error
	 */
	public static function import( array $payload, $title = '' ) {
		$elements = null;

		foreach ( array( 'content', 'elements' ) as $key ) {
			if ( isset( $payload[ $key ] ) && is_array( $payload[ $key ] ) ) {
				$elements = $payload[ $key ];
				break;
			}
		}

		if ( null === $elements ) {
			return new WP_Error(
				'emcp_bad_template_json',
				__( 'That payload has no "content" or "elements" array. Pass the full JSON exported by Elementor.', 'elementor-mcp-bridge' ),
				array( 'status' => 400 )
			);
		}

		return self::save(
			array(
				'title'    => '' !== $title ? $title : ( isset( $payload['title'] ) ? $payload['title'] : __( 'Imported template', 'elementor-mcp-bridge' ) ),
				'type'     => isset( $payload['type'] ) ? $payload['type'] : 'container',
				'elements' => $elements,
			)
		);
	}
}
