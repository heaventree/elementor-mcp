<?php
/**
 * Pure helpers for walking and validating an Elementor element tree.
 *
 * @package Elementor_MCP_Bridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Element tree utilities.
 *
 * An Elementor document is a list of nodes shaped like:
 *   { id, elType, settings, elements[], isInner?, widgetType? }
 *
 * These helpers never touch the database — they operate on decoded arrays so
 * they stay cheap and predictable.
 */
class EMCP_Tree {

	/**
	 * Element types that may contain children.
	 */
	const CONTAINER_TYPES = array( 'container', 'section', 'column' );

	/**
	 * Generate an Elementor-style element ID.
	 *
	 * Elementor itself uses `dechex( rand() )` (see Elementor\Utils), producing
	 * a short lowercase hex string. We match that shape at a fixed 7 characters.
	 *
	 * @return string
	 */
	public static function generate_id() {
		return substr( str_pad( dechex( wp_rand( 0, 0xFFFFFFF ) ), 7, '0', STR_PAD_LEFT ), 0, 7 );
	}

	/**
	 * Generate an ID that does not collide with anything already in the tree.
	 *
	 * @param array $elements Element tree.
	 * @return string
	 */
	public static function generate_unique_id( array $elements ) {
		$taken = array_flip( self::collect_ids( $elements ) );

		do {
			$id = self::generate_id();
		} while ( isset( $taken[ $id ] ) );

		return $id;
	}

	/**
	 * Collect every element ID in the tree, depth first.
	 *
	 * @param array $elements Element tree.
	 * @return string[]
	 */
	public static function collect_ids( array $elements ) {
		$ids = array();

		self::walk(
			$elements,
			static function ( $node ) use ( &$ids ) {
				if ( isset( $node['id'] ) ) {
					$ids[] = (string) $node['id'];
				}
			}
		);

		return $ids;
	}

	/**
	 * Depth-first walk. The callback receives ( $node, $depth, $path ).
	 *
	 * @param array    $elements Element tree.
	 * @param callable $callback Visitor.
	 * @param int      $depth    Current depth.
	 * @param array    $path     Index path to the current node.
	 * @return void
	 */
	public static function walk( array $elements, callable $callback, $depth = 0, array $path = array() ) {
		foreach ( $elements as $index => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$node_path = array_merge( $path, array( $index ) );

			$callback( $node, $depth, $node_path );

			if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
				self::walk( $node['elements'], $callback, $depth + 1, $node_path );
			}
		}
	}

	/**
	 * Find a node by ID.
	 *
	 * @param array  $elements Element tree.
	 * @param string $id       Element ID.
	 * @return array|null The node, or null when absent.
	 */
	public static function find( array $elements, $id ) {
		$found = null;

		self::walk(
			$elements,
			static function ( $node ) use ( $id, &$found ) {
				if ( null === $found && isset( $node['id'] ) && (string) $node['id'] === (string) $id ) {
					$found = $node;
				}
			}
		);

		return $found;
	}

	/**
	 * Re-key every element in the tree with fresh IDs.
	 *
	 * Required whenever a subtree is duplicated or imported, otherwise two
	 * elements share an ID and Elementor's CSS targeting breaks.
	 *
	 * @param array $elements Element tree.
	 * @return array
	 */
	public static function regenerate_ids( array $elements ) {
		foreach ( $elements as &$node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$node['id'] = self::generate_id();

			if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
				$node['elements'] = self::regenerate_ids( $node['elements'] );
			}
		}

		return $elements;
	}

	/**
	 * A compact outline of the tree: structure without the settings payload.
	 *
	 * This is what an agent should read first — a 400 KB document usually
	 * outlines to a few kilobytes.
	 *
	 * @param array $elements Element tree.
	 * @param int   $max_depth Maximum depth to descend, 0 for unlimited.
	 * @return array
	 */
	public static function outline( array $elements, $max_depth = 0 ) {
		$out = array();

		foreach ( $elements as $node ) {
			if ( ! is_array( $node ) || ! isset( $node['elType'] ) ) {
				continue;
			}

			$entry = array(
				'id'     => isset( $node['id'] ) ? (string) $node['id'] : '',
				'elType' => (string) $node['elType'],
			);

			if ( isset( $node['widgetType'] ) ) {
				$entry['widgetType'] = (string) $node['widgetType'];
			}

			$label = self::describe( $node );
			if ( '' !== $label ) {
				$entry['label'] = $label;
			}

			$children = ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) ? $node['elements'] : array();

			if ( $children ) {
				if ( $max_depth > 0 && 1 === $max_depth ) {
					$entry['childCount'] = count( $children );
				} else {
					$entry['elements'] = self::outline( $children, $max_depth > 0 ? $max_depth - 1 : 0 );
				}
			}

			$out[] = $entry;
		}

		return $out;
	}

	/**
	 * Best-effort human label for a node, drawn from its most identifying
	 * setting (heading text, button text, image alt, and so on).
	 *
	 * @param array $node Element node.
	 * @return string
	 */
	public static function describe( array $node ) {
		$settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array();

		$candidates = array( 'title', 'text', 'editor', 'heading', 'title_text', 'description_text', 'html', 'caption', '_title' );

		foreach ( $candidates as $key ) {
			if ( empty( $settings[ $key ] ) || ! is_string( $settings[ $key ] ) ) {
				continue;
			}

			$text = wp_strip_all_tags( $settings[ $key ] );
			$text = trim( preg_replace( '/\s+/', ' ', $text ) );

			if ( '' !== $text ) {
				return mb_substr( $text, 0, 120 );
			}
		}

		return '';
	}

	/**
	 * Validate the shape of an element tree before it is written back.
	 *
	 * Catches the mistakes that silently corrupt a page: duplicate IDs, widgets
	 * with no widgetType, children on a widget, and non-list element arrays.
	 *
	 * @param array $elements Element tree.
	 * @return true|WP_Error
	 */
	public static function validate( array $elements ) {
		$seen   = array();
		$errors = array();

		$check = static function ( $nodes, $depth, $trail ) use ( &$check, &$seen, &$errors ) {
			if ( ! self::is_list( $nodes ) ) {
				$errors[] = sprintf( 'Element list at %s must be a JSON array, not an object.', $trail ?: 'root' );
				return;
			}

			foreach ( $nodes as $index => $node ) {
				$here = ( $trail ? $trail . '.' : '' ) . $index;

				if ( ! is_array( $node ) ) {
					$errors[] = sprintf( 'Element at %s is not an object.', $here );
					continue;
				}

				if ( empty( $node['elType'] ) || ! is_string( $node['elType'] ) ) {
					$errors[] = sprintf( 'Element at %s is missing a string "elType".', $here );
					continue;
				}

				if ( empty( $node['id'] ) || ! is_string( $node['id'] ) ) {
					$errors[] = sprintf( 'Element at %s is missing a string "id".', $here );
				} elseif ( isset( $seen[ $node['id'] ] ) ) {
					$errors[] = sprintf( 'Duplicate element id "%s" at %s (first seen at %s).', $node['id'], $here, $seen[ $node['id'] ] );
				} else {
					$seen[ $node['id'] ] = $here;
				}

				if ( 'widget' === $node['elType'] && empty( $node['widgetType'] ) ) {
					$errors[] = sprintf( 'Widget at %s is missing "widgetType".', $here );
				}

				if ( isset( $node['settings'] ) && ! is_array( $node['settings'] ) ) {
					$errors[] = sprintf( 'Element at %s has a non-object "settings".', $here );
				}

				if ( ! empty( $node['elements'] ) ) {
					if ( 'widget' === $node['elType'] ) {
						$errors[] = sprintf( 'Widget at %s cannot contain child elements.', $here );
						continue;
					}

					$check( $node['elements'], $depth + 1, $here . '.elements' );
				}
			}
		};

		$check( $elements, 0, '' );

		if ( $errors ) {
			return new WP_Error(
				'emcp_invalid_tree',
				__( 'The element tree is not valid Elementor data.', 'elementor-mcp-bridge' ),
				array(
					'status' => 400,
					'errors' => array_slice( $errors, 0, 25 ),
				)
			);
		}

		return true;
	}

	/**
	 * Is this a zero-indexed sequential array (a JSON list)?
	 *
	 * @param mixed $value Value to test.
	 * @return bool
	 */
	public static function is_list( $value ) {
		if ( ! is_array( $value ) ) {
			return false;
		}

		return array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * Stable content hash of a tree, used for optimistic locking so two agents
	 * cannot silently overwrite each other.
	 *
	 * @param array $elements Element tree.
	 * @return string
	 */
	public static function hash( array $elements ) {
		return md5( (string) wp_json_encode( $elements ) );
	}

	/**
	 * Count nodes by element/widget type.
	 *
	 * @param array $elements Element tree.
	 * @return array<string,int>
	 */
	public static function census( array $elements ) {
		$counts = array();

		self::walk(
			$elements,
			static function ( $node ) use ( &$counts ) {
				if ( empty( $node['elType'] ) ) {
					return;
				}

				$key = 'widget' === $node['elType'] && ! empty( $node['widgetType'] )
					? (string) $node['widgetType']
					: (string) $node['elType'];

				$counts[ $key ] = isset( $counts[ $key ] ) ? $counts[ $key ] + 1 : 1;
			}
		);

		arsort( $counts );

		return $counts;
	}
}
