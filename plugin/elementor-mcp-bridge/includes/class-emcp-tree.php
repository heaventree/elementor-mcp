<?php
/**
 * Pure helpers for walking and validating an Elementor element tree.
 *
 * @package Elementor_MCP_Bridge
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-emcp-tree-error.php';

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

	/* ------------------------------------------------------------------ *
	 * Mutators.
	 *
	 * Every mutator below is pure: given a tree, it returns a new tree and
	 * never touches the array it was handed (PHP arrays copy on write, so
	 * this falls out naturally rather than needing an explicit clone step
	 * the way the TypeScript server needs structuredClone).
	 *
	 * They all route through locate_and_splice(), which finds the array
	 * holding a target id and its index within that array, and hands both to
	 * a callback that returns the replacement array. That one primitive is
	 * enough to express insert, update, delete, reorder and wrap, and keeps
	 * the recursive descent/reassembly logic written exactly once.
	 * ------------------------------------------------------------------ */

	/**
	 * Can this node hold children?
	 *
	 * @param array $node Element node.
	 * @return bool
	 */
	public static function is_container_type( array $node ) {
		return 'widget' !== ( $node['elType'] ?? '' );
	}

	/**
	 * Find the array holding element $id and its index within that array,
	 * and replace that array with whatever $on_found returns.
	 *
	 * @param array    $elements Element tree.
	 * @param string   $id       Element id to locate.
	 * @param callable $on_found function( array $siblings, int $index ): array.
	 * @return array{0:array,1:bool} [ new tree, whether $id was found ].
	 */
	private static function locate_and_splice( array $elements, $id, callable $on_found ) {
		foreach ( $elements as $index => $node ) {
			if ( is_array( $node ) && isset( $node['id'] ) && (string) $node['id'] === (string) $id ) {
				return array( $on_found( $elements, $index ), true );
			}
		}

		foreach ( $elements as $index => $node ) {
			if ( ! is_array( $node ) || empty( $node['elements'] ) || ! is_array( $node['elements'] ) ) {
				continue;
			}

			list( $new_children, $found ) = self::locate_and_splice( $node['elements'], $id, $on_found );

			if ( $found ) {
				$elements[ $index ]['elements'] = $new_children;
				return array( $elements, true );
			}
		}

		return array( $elements, false );
	}

	/**
	 * Same as locate_and_splice(), but throws EMCP_Tree_Error::not_found() when
	 * $id is not in the tree, so every public mutator gets that check for free.
	 *
	 * @param array    $elements Element tree.
	 * @param string   $id       Element id.
	 * @param callable $on_found function( array $siblings, int $index ): array.
	 * @return array New tree.
	 * @throws EMCP_Tree_Error When $id is not found.
	 */
	private static function splice_or_fail( array $elements, $id, callable $on_found ) {
		list( $result, $found ) = self::locate_and_splice( $elements, $id, $on_found );

		if ( ! $found ) {
			throw EMCP_Tree_Error::not_found( $id );
		}

		return $result;
	}

	/**
	 * Build a widget node.
	 *
	 * @param string $widget_type Widget type name.
	 * @param array  $settings    Control values.
	 * @param string $id          Explicit id, or generated if omitted.
	 * @return array
	 */
	public static function make_widget( $widget_type, array $settings = array(), $id = null ) {
		return array(
			'id'         => $id ?: self::generate_id(),
			'elType'     => 'widget',
			'widgetType' => $widget_type,
			'settings'   => $settings,
			'elements'   => array(),
		);
	}

	/**
	 * Build a structural node.
	 *
	 * @param array  $settings Control values.
	 * @param array  $children Child nodes.
	 * @param string $el_type  container, section or column.
	 * @param string $id       Explicit id, or generated if omitted.
	 * @return array
	 */
	public static function make_container( array $settings = array(), array $children = array(), $el_type = 'container', $id = null ) {
		return array(
			'id'       => $id ?: self::generate_id(),
			'elType'   => $el_type,
			'settings' => $settings,
			'elements' => $children,
		);
	}

	/**
	 * Insert a node into the tree.
	 *
	 * With no $target_id the node is appended to (or prepended at) the
	 * document root. With one, append/prepend place the node inside the
	 * target; before/after place it alongside.
	 *
	 * @param array  $elements  Element tree.
	 * @param array  $node      Node to insert.
	 * @param string $target_id Element to place relative to, or '' for the root.
	 * @param string $position  append|prepend|before|after.
	 * @return array New tree.
	 * @throws EMCP_Tree_Error On an unknown target, or nesting inside a widget.
	 */
	public static function insert( array $elements, array $node, $target_id = '', $position = 'append' ) {
		$taken = array_flip( self::collect_ids( $elements ) );

		// Re-key the incoming subtree if anything in it would collide, or it
		// has no id of its own.
		$incoming_ids = self::collect_ids( array( $node ) );
		$collides     = empty( $node['id'] ) || array_intersect_key( array_flip( $incoming_ids ), $taken );
		$to_insert    = $collides ? self::regenerate_ids( array( $node ) )[0] : $node;

		if ( '' === $target_id ) {
			if ( 'prepend' === $position || 'before' === $position ) {
				array_unshift( $elements, $to_insert );
			} else {
				$elements[] = $to_insert;
			}

			return $elements;
		}

		if ( 'before' === $position || 'after' === $position ) {
			return self::splice_or_fail(
				$elements,
				$target_id,
				static function ( array $siblings, $index ) use ( $to_insert, $position ) {
					$at = 'before' === $position ? $index : $index + 1;
					array_splice( $siblings, $at, 0, array( $to_insert ) );
					return $siblings;
				}
			);
		}

		return self::splice_or_fail(
			$elements,
			$target_id,
			static function ( array $siblings, $index ) use ( $to_insert, $position ) {
				$target = $siblings[ $index ];

				if ( ! self::is_container_type( $target ) ) {
					throw new EMCP_Tree_Error(
						sprintf(
							'Cannot place an element inside widget "%s" — widgets do not take children.',
							$target['widgetType'] ?? $target['id']
						),
						'emcp_invalid_parent',
						"Use position \"before\" or \"after\" to place it as a sibling, or target the widget's parent container instead."
					);
				}

				$children = isset( $target['elements'] ) && is_array( $target['elements'] ) ? $target['elements'] : array();

				if ( 'prepend' === $position ) {
					array_unshift( $children, $to_insert );
				} else {
					$children[] = $to_insert;
				}

				$siblings[ $index ]['elements'] = $children;

				return $siblings;
			}
		);
	}

	/**
	 * Merge or replace a node's settings.
	 *
	 * @param array  $elements Element tree.
	 * @param string $id       Element id.
	 * @param array  $settings Settings to apply.
	 * @param string $mode     merge|replace.
	 * @return array New tree.
	 * @throws EMCP_Tree_Error When $id is not found.
	 */
	public static function update_settings( array $elements, $id, array $settings, $mode = 'merge' ) {
		return self::splice_or_fail(
			$elements,
			$id,
			static function ( array $siblings, $index ) use ( $settings, $mode ) {
				$siblings[ $index ]['settings'] = 'replace' === $mode
					? $settings
					: array_replace( isset( $siblings[ $index ]['settings'] ) && is_array( $siblings[ $index ]['settings'] ) ? $siblings[ $index ]['settings'] : array(), $settings );

				return $siblings;
			}
		);
	}

	/**
	 * Remove a node and detach it, for use by move().
	 *
	 * @param array  $elements Element tree.
	 * @param string $id       Element id.
	 * @return array{0:array,1:array} [ new tree, the detached node ].
	 * @throws EMCP_Tree_Error When $id is not found.
	 */
	private static function detach( array $elements, $id ) {
		$detached = null;

		$new_tree = self::splice_or_fail(
			$elements,
			$id,
			static function ( array $siblings, $index ) use ( &$detached ) {
				$detached = $siblings[ $index ];
				array_splice( $siblings, $index, 1 );
				return $siblings;
			}
		);

		return array( $new_tree, $detached );
	}

	/**
	 * Remove a node and its subtree.
	 *
	 * @param array  $elements Element tree.
	 * @param string $id       Element id.
	 * @return array New tree.
	 * @throws EMCP_Tree_Error When $id is not found.
	 */
	public static function remove( array $elements, $id ) {
		list( $new_tree, $detached ) = self::detach( $elements, $id );
		unset( $detached );
		return $new_tree;
	}

	/**
	 * Move a node elsewhere in the tree.
	 *
	 * @param array  $elements     Element tree.
	 * @param string $id           Element to move.
	 * @param string $reference_id Element to position relative to, or '' for the root.
	 * @param string $position     append|prepend|before|after.
	 * @return array New tree.
	 * @throws EMCP_Tree_Error On an unknown id, moving into itself, or into its own descendant.
	 */
	public static function move( array $elements, $id, $reference_id = '', $position = 'append' ) {
		if ( $reference_id === $id ) {
			throw new EMCP_Tree_Error( 'An element cannot be moved relative to itself.', 'emcp_invalid_move' );
		}

		if ( '' !== $reference_id ) {
			// Moving a node into its own subtree would detach that subtree.
			$node = self::find( $elements, $id );

			if ( $node && null !== self::find( array( $node ), $reference_id ) ) {
				throw new EMCP_Tree_Error(
					sprintf( 'Cannot move element "%1$s" into "%2$s" because that is one of its own descendants.', $id, $reference_id ),
					'emcp_invalid_move',
					'Pick a reference element outside the subtree you are moving.'
				);
			}
		}

		list( $new_tree, $detached ) = self::detach( $elements, $id );

		return self::insert( $new_tree, $detached, $reference_id, $position );
	}

	/**
	 * Copy a node in place, directly after the original, with fresh ids.
	 *
	 * @param array  $elements Element tree.
	 * @param string $id       Element to duplicate.
	 * @return array{0:array,1:string} [ new tree, the copy's id ].
	 * @throws EMCP_Tree_Error When $id is not found.
	 */
	public static function duplicate( array $elements, $id ) {
		$new_id = null;

		$new_tree = self::splice_or_fail(
			$elements,
			$id,
			static function ( array $siblings, $index ) use ( &$new_id ) {
				$taken = array_flip( self::collect_ids( $siblings ) );
				$copy  = self::regenerate_ids( array( $siblings[ $index ] ), $taken )[0];
				$new_id = $copy['id'];

				array_splice( $siblings, $index + 1, 0, array( $copy ) );

				return $siblings;
			}
		);

		return array( $new_tree, $new_id );
	}

	/**
	 * Reorder a container's direct children by id.
	 *
	 * Ids omitted from $order keep their relative position at the end.
	 *
	 * @param array    $elements Element tree.
	 * @param string   $id       Container element id.
	 * @param string[] $order    Child element ids in their new order.
	 * @return array New tree.
	 * @throws EMCP_Tree_Error When $id is not found, or $order names a non-child.
	 */
	public static function reorder( array $elements, $id, array $order ) {
		return self::splice_or_fail(
			$elements,
			$id,
			static function ( array $siblings, $index ) use ( $order ) {
				$children = isset( $siblings[ $index ]['elements'] ) && is_array( $siblings[ $index ]['elements'] )
					? $siblings[ $index ]['elements']
					: array();

				$by_id   = array();
				foreach ( $children as $child ) {
					if ( isset( $child['id'] ) ) {
						$by_id[ $child['id'] ] = $child;
					}
				}

				$missing = array_values( array_diff( $order, array_keys( $by_id ) ) );

				if ( $missing ) {
					throw new EMCP_Tree_Error(
						sprintf( 'These ids are not direct children of "%1$s": %2$s.', $siblings[ $index ]['id'], implode( ', ', $missing ) ),
						'emcp_invalid_order',
						sprintf( 'Direct children are: %s.', implode( ', ', array_keys( $by_id ) ) ? implode( ', ', array_keys( $by_id ) ) : '(none)' )
					);
				}

				$reordered = array();
				foreach ( $order as $child_id ) {
					$reordered[] = $by_id[ $child_id ];
				}

				$ordered_ids = array_flip( $order );
				foreach ( $children as $child ) {
					if ( isset( $child['id'] ) && ! isset( $ordered_ids[ $child['id'] ] ) ) {
						$reordered[] = $child;
					}
				}

				$siblings[ $index ]['elements'] = $reordered;

				return $siblings;
			}
		);
	}

	/**
	 * Wrap a node in a new parent container, in place.
	 *
	 * @param array      $elements Element tree.
	 * @param string     $id       Element to wrap.
	 * @param array|null $wrapper  Wrapper node template (id is regenerated). Defaults to a plain container.
	 * @return array{0:array,1:string} [ new tree, the wrapper's id ].
	 * @throws EMCP_Tree_Error When $id is not found.
	 */
	public static function wrap( array $elements, $id, ?array $wrapper = null ) {
		$wrapper_id = null;

		$new_tree = self::splice_or_fail(
			$elements,
			$id,
			static function ( array $siblings, $index ) use ( $wrapper, &$wrapper_id ) {
				$taken = array_flip( self::collect_ids( $siblings ) );

				$shell = $wrapper ? $wrapper : self::make_container();
				do {
					$shell['id'] = self::generate_id();
				} while ( isset( $taken[ $shell['id'] ] ) );

				$shell['elements'] = array( $siblings[ $index ] );
				$wrapper_id        = $shell['id'];
				$siblings[ $index ] = $shell;

				return $siblings;
			}
		);

		return array( $new_tree, $wrapper_id );
	}

	/**
	 * Apply a list of operations in order.
	 *
	 * Every operation runs against the result of the previous one. The whole
	 * batch either succeeds or throws — callers should only write back on
	 * success, so a document is never left half-edited.
	 *
	 * @param array $elements   Element tree.
	 * @param array $operations Each: array( 'op' => ..., ...args ).
	 * @return array{0:array,1:string[]} [ new tree, a log line per operation ].
	 * @throws EMCP_Tree_Error Naming which operation failed and why, with the log of what came before it.
	 */
	public static function apply_operations( array $elements, array $operations ) {
		$current = $elements;
		$log     = array();

		foreach ( $operations as $index => $operation ) {
			$op   = isset( $operation['op'] ) ? $operation['op'] : '';
			$step = sprintf( '#%d %s', $index + 1, $op );

			try {
				switch ( $op ) {
					case 'insert':
						$node = isset( $operation['node'] ) && is_array( $operation['node'] ) ? $operation['node'] : array();
						$current = self::insert(
							$current,
							$node,
							isset( $operation['targetId'] ) ? (string) $operation['targetId'] : '',
							isset( $operation['position'] ) ? (string) $operation['position'] : 'append'
						);
						$log[] = sprintf( '%s: added %s', $step, $node['widgetType'] ?? ( $node['elType'] ?? '?' ) );
						break;

					case 'update':
						$settings = isset( $operation['settings'] ) && is_array( $operation['settings'] ) ? $operation['settings'] : array();
						$current  = self::update_settings(
							$current,
							(string) $operation['targetId'],
							$settings,
							isset( $operation['mode'] ) ? (string) $operation['mode'] : 'merge'
						);
						$log[] = sprintf( '%s: updated %d setting(s) on %s', $step, count( $settings ), $operation['targetId'] );
						break;

					case 'move':
						$current = self::move(
							$current,
							(string) $operation['targetId'],
							isset( $operation['referenceId'] ) ? (string) $operation['referenceId'] : '',
							isset( $operation['position'] ) ? (string) $operation['position'] : 'append'
						);
						$log[] = sprintf( '%s: moved %s', $step, $operation['targetId'] );
						break;

					case 'duplicate':
						list( $current, $new_id ) = self::duplicate( $current, (string) $operation['targetId'] );
						$log[] = sprintf( '%s: duplicated %s as %s', $step, $operation['targetId'], $new_id );
						break;

					case 'delete':
						$current = self::remove( $current, (string) $operation['targetId'] );
						$log[]   = sprintf( '%s: deleted %s', $step, $operation['targetId'] );
						break;

					case 'reorder':
						$order   = isset( $operation['order'] ) && is_array( $operation['order'] ) ? $operation['order'] : array();
						$current = self::reorder( $current, (string) $operation['targetId'], $order );
						$log[]   = sprintf( '%s: reordered children of %s', $step, $operation['targetId'] );
						break;

					case 'wrap':
						$wrapper = isset( $operation['wrapper'] ) && is_array( $operation['wrapper'] ) ? $operation['wrapper'] : null;
						list( $current, $wrapper_id ) = self::wrap( $current, (string) $operation['targetId'], $wrapper );
						$log[] = sprintf( '%s: wrapped %s in %s', $step, $operation['targetId'], $wrapper_id );
						break;

					default:
						throw new EMCP_Tree_Error( sprintf( 'Unknown operation "%s".', $op ), 'emcp_unknown_operation' );
				}
			} catch ( EMCP_Tree_Error $error ) {
				throw new EMCP_Tree_Error(
					sprintf( 'Batch failed at operation %1$s: %2$s', $step, $error->getMessage() ),
					'emcp_batch_failed',
					'No changes were written. Fix this operation and resend the whole batch.',
					array(
						'failedAt'  => $index,
						'completed' => $log,
					)
				);
			}
		}

		return array( $current, $log );
	}
}
