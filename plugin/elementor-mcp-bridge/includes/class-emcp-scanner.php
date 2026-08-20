<?php
/**
 * Cross-site search over Elementor data.
 *
 * @package Elementor_MCP_Bridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Scanner.
 *
 * Elementor content lives inside a JSON blob in postmeta, so it is invisible to
 * normal WordPress search and to ordinary find-and-replace tooling. These
 * helpers walk the decoded tree instead of doing string surgery on serialised
 * data, which is what makes replace safe to run across a whole site.
 */
class EMCP_Scanner {

	/**
	 * Find text, widget types or setting values across Elementor documents.
	 *
	 * @param array $args text, widgetType, settingKey, postTypes, limit.
	 * @return array
	 */
	public static function search( array $args ) {
		$text        = isset( $args['text'] ) ? (string) $args['text'] : '';
		$widget_type = isset( $args['widgetType'] ) ? (string) $args['widgetType'] : '';
		$setting_key = isset( $args['settingKey'] ) ? (string) $args['settingKey'] : '';
		$limit       = isset( $args['limit'] ) ? min( 500, max( 1, (int) $args['limit'] ) ) : 100;
		$case        = ! empty( $args['caseSensitive'] );

		$post_ids = self::candidate_ids( $args );
		$matches  = array();

		foreach ( $post_ids as $post_id ) {
			$elements = EMCP_Documents::read_elements( $post_id );

			if ( is_wp_error( $elements ) || ! is_array( $elements ) ) {
				continue;
			}

			EMCP_Tree::walk(
				$elements,
				static function ( $node ) use ( $post_id, $text, $widget_type, $setting_key, $case, &$matches, $limit ) {
					if ( count( $matches ) >= $limit ) {
						return;
					}

					if ( '' !== $widget_type ) {
						$node_type = isset( $node['widgetType'] ) ? $node['widgetType'] : ( isset( $node['elType'] ) ? $node['elType'] : '' );

						if ( $node_type !== $widget_type ) {
							return;
						}
					}

					$settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array();

					if ( '' !== $setting_key && ! array_key_exists( $setting_key, $settings ) ) {
						return;
					}

					$hits = array();

					if ( '' !== $text ) {
						foreach ( $settings as $key => $value ) {
							if ( '' !== $setting_key && $key !== $setting_key ) {
								continue;
							}

							if ( ! is_string( $value ) ) {
								continue;
							}

							$found = $case
								? false !== strpos( $value, $text )
								: false !== stripos( $value, $text );

							if ( $found ) {
								$hits[] = array(
									'key'     => (string) $key,
									'excerpt' => self::excerpt( $value, $text, $case ),
								);
							}
						}

						if ( ! $hits ) {
							return;
						}
					}

					$matches[] = array(
						'postId'     => $post_id,
						'title'      => get_the_title( $post_id ),
						'elementId'  => isset( $node['id'] ) ? (string) $node['id'] : '',
						'elType'     => isset( $node['elType'] ) ? (string) $node['elType'] : '',
						'widgetType' => isset( $node['widgetType'] ) ? (string) $node['widgetType'] : null,
						'label'      => EMCP_Tree::describe( $node ),
						'hits'       => $hits,
					);
				}
			);

			if ( count( $matches ) >= $limit ) {
				break;
			}
		}

		return array(
			'total'    => count( $matches ),
			'limited'  => count( $matches ) >= $limit,
			'scanned'  => count( $post_ids ),
			'matches'  => $matches,
		);
	}

	/**
	 * Replace a string across Elementor documents.
	 *
	 * Always run this with dryRun first — it reports exactly what it would
	 * touch without writing anything.
	 *
	 * @param array $args search, replace, dryRun, postTypes, postIds, caseSensitive.
	 * @return array|WP_Error
	 */
	public static function replace( array $args ) {
		$search = isset( $args['search'] ) ? (string) $args['search'] : '';

		if ( '' === $search ) {
			return new WP_Error(
				'emcp_replace_needs_search',
				__( 'Provide a non-empty "search" string.', 'elementor-mcp-bridge' ),
				array( 'status' => 400 )
			);
		}

		$replace = isset( $args['replace'] ) ? (string) $args['replace'] : '';
		$dry_run = ! isset( $args['dryRun'] ) || (bool) $args['dryRun'];
		$case    = ! empty( $args['caseSensitive'] );

		$post_ids = self::candidate_ids( $args );
		$changed  = array();
		$total    = 0;

		foreach ( $post_ids as $post_id ) {
			if ( ! $dry_run && ! EMCP_Guard::can_edit_post( $post_id ) ) {
				continue;
			}

			$elements = EMCP_Documents::read_elements( $post_id );

			if ( is_wp_error( $elements ) || ! is_array( $elements ) ) {
				continue;
			}

			$count    = 0;
			$replaced = self::replace_in_tree( $elements, $search, $replace, $case, $count );

			if ( 0 === $count ) {
				continue;
			}

			$total += $count;

			$entry = array(
				'postId'       => $post_id,
				'title'        => get_the_title( $post_id ),
				'replacements' => $count,
			);

			if ( ! $dry_run ) {
				$written = EMCP_Documents::write( $post_id, $replaced, null, true );

				if ( is_wp_error( $written ) ) {
					$entry['error'] = $written->get_error_message();
				} else {
					$entry['hash'] = $written['hash'];
				}
			}

			$changed[] = $entry;
		}

		return array(
			'dryRun'       => $dry_run,
			'scanned'      => count( $post_ids ),
			'documents'    => count( $changed ),
			'replacements' => $total,
			'changes'      => $changed,
		);
	}

	/**
	 * Recursively replace inside every string setting of a tree.
	 *
	 * @param array  $elements Element tree.
	 * @param string $search   Needle.
	 * @param string $replace  Replacement.
	 * @param bool   $case     Case-sensitive matching.
	 * @param int    $count    Running replacement count, by reference.
	 * @return array
	 */
	private static function replace_in_tree( array $elements, $search, $replace, $case, &$count ) {
		foreach ( $elements as &$node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			if ( isset( $node['settings'] ) && is_array( $node['settings'] ) ) {
				$node['settings'] = self::replace_in_value( $node['settings'], $search, $replace, $case, $count );
			}

			if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
				$node['elements'] = self::replace_in_tree( $node['elements'], $search, $replace, $case, $count );
			}
		}

		return $elements;
	}

	/**
	 * Replace inside an arbitrarily nested settings value.
	 *
	 * @param mixed  $value   Value to walk.
	 * @param string $search  Needle.
	 * @param string $replace Replacement.
	 * @param bool   $case    Case-sensitive matching.
	 * @param int    $count   Running replacement count, by reference.
	 * @return mixed
	 */
	private static function replace_in_value( $value, $search, $replace, $case, &$count ) {
		if ( is_string( $value ) ) {
			$hits = 0;

			$result = $case
				? str_replace( $search, $replace, $value, $hits )
				: str_ireplace( $search, $replace, $value, $hits );

			$count += $hits;

			return $result;
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::replace_in_value( $item, $search, $replace, $case, $count );
			}
		}

		return $value;
	}

	/**
	 * Which posts should a scan look at?
	 *
	 * @param array $args postIds, postTypes, elementorOnly.
	 * @return int[]
	 */
	private static function candidate_ids( array $args ) {
		if ( ! empty( $args['postIds'] ) && is_array( $args['postIds'] ) ) {
			return array_values( array_filter( array_map( 'intval', $args['postIds'] ) ) );
		}

		$post_types = ! empty( $args['postTypes'] ) && is_array( $args['postTypes'] )
			? $args['postTypes']
			: array( 'page', 'post', EMCP_Library::CPT );

		$query = new WP_Query(
			array(
				'post_type'      => $post_types,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => EMCP_Documents::EDIT_MODE_META,
						'value'   => 'builder',
						'compare' => '=',
					),
				),
			)
		);

		return array_map( 'intval', $query->posts );
	}

	/**
	 * A short window of text around the first match.
	 *
	 * @param string $value  Full value.
	 * @param string $needle Search term.
	 * @param bool   $case   Case-sensitive matching.
	 * @return string
	 */
	private static function excerpt( $value, $needle, $case ) {
		$plain    = wp_strip_all_tags( $value );
		$position = $case ? strpos( $plain, $needle ) : stripos( $plain, $needle );

		if ( false === $position ) {
			return mb_substr( $plain, 0, 120 );
		}

		$start = max( 0, $position - 40 );

		return ( $start > 0 ? '…' : '' ) . mb_substr( $plain, $start, 160 ) . '…';
	}

	/**
	 * Site-wide census of which widgets are actually in use.
	 *
	 * @param array $args postTypes.
	 * @return array
	 */
	public static function usage( array $args = array() ) {
		$post_ids = self::candidate_ids( $args );
		$totals   = array();
		$by_post  = array();

		foreach ( $post_ids as $post_id ) {
			$elements = EMCP_Documents::read_elements( $post_id );

			if ( is_wp_error( $elements ) || ! is_array( $elements ) ) {
				continue;
			}

			$census = EMCP_Tree::census( $elements );

			foreach ( $census as $type => $count ) {
				$totals[ $type ] = isset( $totals[ $type ] ) ? $totals[ $type ] + $count : $count;
			}

			$by_post[] = array(
				'postId' => $post_id,
				'title'  => get_the_title( $post_id ),
				'nodes'  => array_sum( $census ),
			);
		}

		arsort( $totals );

		return array(
			'documents'   => count( $post_ids ),
			'widgets'     => $totals,
			'perDocument' => $by_post,
		);
	}
}
