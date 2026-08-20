<?php
/**
 * Introspection of Elementor's widget, element and control registries.
 *
 * @package Elementor_MCP_Bridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Schema reader.
 *
 * Elementor widgets declare their settings as "controls". Reading those
 * controls straight out of the live registry means the bridge describes
 * whatever the site actually has installed — core widgets, Elementor Pro, and
 * any third-party addon pack — instead of a hard-coded list that goes stale.
 */
class EMCP_Schema {

	/**
	 * Control keys kept in compact mode.
	 *
	 * Full control definitions carry CSS selector maps and render hints that
	 * are large and rarely useful when deciding what to set.
	 */
	const COMPACT_KEYS = array(
		'name',
		'label',
		'type',
		'default',
		'options',
		'placeholder',
		'description',
		'section',
		'tab',
		'condition',
		'conditions',
		'responsive',
		'min',
		'max',
		'step',
		'size_units',
		'multiple',
		'label_block',
		'separator',
		'dynamic',
		'groupType',
		'groupPrefix',
	);

	/**
	 * List every registered widget type.
	 *
	 * @param array $args Filters: search, category, includeHidden.
	 * @return array|WP_Error
	 */
	public static function widgets( array $args = array() ) {
		$ready = EMCP_Guard::require_elementor();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$search   = isset( $args['search'] ) ? strtolower( (string) $args['search'] ) : '';
		$category = isset( $args['category'] ) ? (string) $args['category'] : '';
		$hidden   = ! empty( $args['includeHidden'] );

		$widgets = \Elementor\Plugin::$instance->widgets_manager->get_widget_types();
		$items   = array();

		foreach ( (array) $widgets as $name => $widget ) {
			if ( ! $hidden && method_exists( $widget, 'show_in_panel' ) && ! $widget->show_in_panel() ) {
				continue;
			}

			$categories = method_exists( $widget, 'get_categories' ) ? (array) $widget->get_categories() : array();

			if ( '' !== $category && ! in_array( $category, $categories, true ) ) {
				continue;
			}

			$title    = method_exists( $widget, 'get_title' ) ? (string) $widget->get_title() : (string) $name;
			$keywords = method_exists( $widget, 'get_keywords' ) ? (array) $widget->get_keywords() : array();

			if ( '' !== $search ) {
				$haystack = strtolower( $name . ' ' . $title . ' ' . implode( ' ', $keywords ) );

				if ( false === strpos( $haystack, $search ) ) {
					continue;
				}
			}

			$items[] = array(
				'name'       => (string) $name,
				'title'      => $title,
				'icon'       => method_exists( $widget, 'get_icon' ) ? (string) $widget->get_icon() : '',
				'categories' => array_values( $categories ),
				'keywords'   => array_values( $keywords ),
				'isPro'      => self::looks_like_pro( $widget ),
			);
		}

		usort(
			$items,
			static function ( $a, $b ) {
				return strcmp( $a['name'], $b['name'] );
			}
		);

		return array(
			'total' => count( $items ),
			'items' => $items,
		);
	}

	/**
	 * Widget panel categories (General, Basic, Pro, third-party packs...).
	 *
	 * @return array|WP_Error
	 */
	public static function categories() {
		$ready = EMCP_Guard::require_elementor();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$raw   = \Elementor\Plugin::$instance->elements_manager->get_categories();
		$items = array();

		foreach ( (array) $raw as $slug => $category ) {
			$items[] = array(
				'slug'  => (string) $slug,
				'title' => isset( $category['title'] ) ? (string) $category['title'] : (string) $slug,
			);
		}

		return array( 'items' => $items );
	}

	/**
	 * Full control schema for one widget, grouped by tab and section.
	 *
	 * @param string $name    Widget name.
	 * @param bool   $compact Whether to trim heavy control keys.
	 * @return array|WP_Error
	 */
	public static function widget_schema( $name, $compact = true ) {
		$ready = EMCP_Guard::require_elementor();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$widget = \Elementor\Plugin::$instance->widgets_manager->get_widget_types( (string) $name );

		if ( ! $widget ) {
			return new WP_Error(
				'emcp_widget_not_found',
				sprintf(
					/* translators: %s: widget name */
					__( 'No widget type "%s" is registered on this site. Call widgets/list to see what is available.', 'elementor-mcp-bridge' ),
					$name
				),
				array( 'status' => 404 )
			);
		}

		return self::describe_stack( $widget, $compact, array( 'name' => (string) $name ) );
	}

	/**
	 * Control schema for a structural element (container, section, column).
	 *
	 * @param string $name    Element name.
	 * @param bool   $compact Whether to trim heavy control keys.
	 * @return array|WP_Error
	 */
	public static function element_schema( $name, $compact = true ) {
		$ready = EMCP_Guard::require_elementor();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$element = \Elementor\Plugin::$instance->elements_manager->get_element_types( (string) $name );

		if ( ! $element ) {
			return new WP_Error(
				'emcp_element_not_found',
				sprintf(
					/* translators: %s: element name */
					__( 'No element type "%s" is registered. Known types are container, section and column.', 'elementor-mcp-bridge' ),
					$name
				),
				array( 'status' => 404 )
			);
		}

		return self::describe_stack( $element, $compact, array( 'name' => (string) $name ) );
	}

	/**
	 * List structural element types.
	 *
	 * @return array|WP_Error
	 */
	public static function elements() {
		$ready = EMCP_Guard::require_elementor();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$items = array();

		foreach ( (array) \Elementor\Plugin::$instance->elements_manager->get_element_types() as $name => $element ) {
			$items[] = array(
				'name'  => (string) $name,
				'title' => method_exists( $element, 'get_title' ) ? (string) $element->get_title() : (string) $name,
			);
		}

		return array( 'items' => $items );
	}

	/**
	 * Turn a controls stack into a grouped, agent-readable schema.
	 *
	 * @param object $stack_owner Widget or element instance.
	 * @param bool   $compact     Whether to trim heavy control keys.
	 * @param array  $base        Base fields to merge into the result.
	 * @return array
	 */
	private static function describe_stack( $stack_owner, $compact, array $base ) {
		$stack    = method_exists( $stack_owner, 'get_stack' ) ? $stack_owner->get_stack() : array();
		$controls = isset( $stack['controls'] ) && is_array( $stack['controls'] ) ? $stack['controls'] : array();
		$tabs     = isset( $stack['tabs'] ) && is_array( $stack['tabs'] ) ? $stack['tabs'] : array();

		$sections   = array();
		$section_of = array();

		// First pass: find section headings so controls can be grouped under them.
		foreach ( $controls as $control_name => $control ) {
			if ( isset( $control['type'] ) && 'section' === $control['type'] ) {
				$sections[ $control_name ] = array(
					'id'       => (string) $control_name,
					'label'    => isset( $control['label'] ) ? (string) $control['label'] : (string) $control_name,
					'tab'      => isset( $control['tab'] ) ? (string) $control['tab'] : 'content',
					'controls' => array(),
				);
			}
		}

		$defaults = array();

		foreach ( $controls as $control_name => $control ) {
			if ( isset( $control['type'] ) && 'section' === $control['type'] ) {
				continue;
			}

			$section_id = isset( $control['section'] ) ? (string) $control['section'] : '';

			if ( '' === $section_id || ! isset( $sections[ $section_id ] ) ) {
				$section_id = '_ungrouped';

				if ( ! isset( $sections[ $section_id ] ) ) {
					$sections[ $section_id ] = array(
						'id'       => $section_id,
						'label'    => __( 'Other', 'elementor-mcp-bridge' ),
						'tab'      => 'content',
						'controls' => array(),
					);
				}
			}

			$described = self::describe_control( $control_name, $control, $compact );

			$sections[ $section_id ]['controls'][] = $described;
			$section_of[ $control_name ]           = $section_id;

			if ( array_key_exists( 'default', $control ) && null !== $control['default'] && '' !== $control['default'] ) {
				$defaults[ $control_name ] = $control['default'];
			}
		}

		// Drop sections that ended up with no controls.
		$sections = array_values(
			array_filter(
				$sections,
				static function ( $section ) {
					return ! empty( $section['controls'] );
				}
			)
		);

		return array_merge(
			$base,
			array(
				'title'        => method_exists( $stack_owner, 'get_title' ) ? (string) $stack_owner->get_title() : '',
				'elType'       => ( $stack_owner instanceof \Elementor\Widget_Base ) ? 'widget' : ( method_exists( $stack_owner, 'get_name' ) ? (string) $stack_owner->get_name() : '' ),
				'tabs'         => self::describe_tabs( $tabs ),
				'sections'     => $sections,
				'controlCount' => count( $section_of ),
				'defaults'     => $defaults,
			)
		);
	}

	/**
	 * Normalise the tab map into a list.
	 *
	 * @param array $tabs Raw tab map.
	 * @return array
	 */
	private static function describe_tabs( array $tabs ) {
		$items = array();

		foreach ( $tabs as $slug => $label ) {
			$items[] = array(
				'slug'  => (string) $slug,
				'label' => is_string( $label ) ? $label : (string) $slug,
			);
		}

		return $items;
	}

	/**
	 * Reduce a single control definition to what a caller needs.
	 *
	 * @param string $name    Control name.
	 * @param array  $control Raw control definition.
	 * @param bool   $compact Whether to trim heavy keys.
	 * @return array
	 */
	private static function describe_control( $name, array $control, $compact ) {
		$control['name'] = (string) $name;

		if ( ! $compact ) {
			return $control;
		}

		$out = array();

		foreach ( self::COMPACT_KEYS as $key ) {
			if ( array_key_exists( $key, $control ) ) {
				$out[ $key ] = $control[ $key ];
			}
		}

		// `options` can be a large map; keep keys plus labels but drop nesting.
		if ( isset( $out['options'] ) && is_array( $out['options'] ) ) {
			$out['options'] = array_map(
				static function ( $option ) {
					if ( is_array( $option ) ) {
						return isset( $option['title'] ) ? $option['title'] : '';
					}

					return $option;
				},
				$out['options']
			);
		}

		// Responsive controls are addressed with _tablet / _mobile suffixes;
		// spell that out rather than making the caller infer it.
		if ( ! empty( $control['responsive'] ) ) {
			$out['responsiveKeys'] = array(
				'desktop' => (string) $name,
				'tablet'  => $name . '_tablet',
				'mobile'  => $name . '_mobile',
			);
		}

		return $out;
	}

	/**
	 * The element and widget type names actually registered on this site.
	 *
	 * This is ground truth for what can be built here. It matters most for
	 * structural elements: Elementor's Container is gated behind an experiment
	 * that defaults to inactive on any site installed before 3.16, so a site
	 * running a current Elementor may still have no container element at all.
	 *
	 * @return array{elements:string[],widgets:string[]}
	 */
	public static function registered_types() {
		if ( is_wp_error( EMCP_Guard::require_elementor() ) ) {
			return array(
				'elements' => array(),
				'widgets'  => array(),
			);
		}

		$elements = \Elementor\Plugin::$instance->elements_manager->get_element_types();
		$widgets  = \Elementor\Plugin::$instance->widgets_manager->get_widget_types();

		return array(
			'elements' => array_map( 'strval', array_keys( (array) $elements ) ),
			'widgets'  => array_map( 'strval', array_keys( (array) $widgets ) ),
		);
	}

	/**
	 * Find element and widget types used in a tree that this site cannot render.
	 *
	 * Reported as warnings rather than enforced as errors: a page may legitimately
	 * contain widgets from an addon that is currently deactivated, and refusing to
	 * save would then lock the page against every other edit.
	 *
	 * @param array $elements Element tree.
	 * @return array{elements:string[],widgets:string[]}
	 */
	public static function unsupported_types( array $elements ) {
		$known = self::registered_types();

		if ( ! $known['elements'] && ! $known['widgets'] ) {
			return array(
				'elements' => array(),
				'widgets'  => array(),
			);
		}

		$missing_elements = array();
		$missing_widgets  = array();

		EMCP_Tree::walk(
			$elements,
			static function ( $node ) use ( $known, &$missing_elements, &$missing_widgets ) {
				if ( empty( $node['elType'] ) ) {
					return;
				}

				if ( 'widget' === $node['elType'] ) {
					$type = isset( $node['widgetType'] ) ? (string) $node['widgetType'] : '';

					if ( '' !== $type && ! in_array( $type, $known['widgets'], true ) ) {
						$missing_widgets[ $type ] = true;
					}

					return;
				}

				$type = (string) $node['elType'];

				if ( ! in_array( $type, $known['elements'], true ) ) {
					$missing_elements[ $type ] = true;
				}
			}
		);

		return array(
			'elements' => array_keys( $missing_elements ),
			'widgets'  => array_keys( $missing_widgets ),
		);
	}

	/**
	 * Registered dynamic tags, so a caller can bind settings to live data.
	 *
	 * @return array|WP_Error
	 */
	public static function dynamic_tags() {
		$ready = EMCP_Guard::require_elementor();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		if ( ! isset( \Elementor\Plugin::$instance->dynamic_tags ) ) {
			return array( 'items' => array() );
		}

		$manager = \Elementor\Plugin::$instance->dynamic_tags;
		$items   = array();

		if ( method_exists( $manager, 'get_tags_config' ) ) {
			foreach ( (array) $manager->get_tags_config() as $name => $config ) {
				$items[] = array(
					'name'       => (string) $name,
					'title'      => isset( $config['title'] ) ? (string) $config['title'] : (string) $name,
					'group'      => isset( $config['group'] ) ? $config['group'] : null,
					'categories' => isset( $config['categories'] ) ? $config['categories'] : array(),
				);
			}
		}

		return array(
			'total' => count( $items ),
			'items' => $items,
			'usage' => __( 'Bind a setting to a tag by setting "__dynamic__" on the element settings, e.g. settings.__dynamic__.title = "[elementor-tag id=\\"abc\\" name=\\"post-title\\" settings=\\"%7B%7D\\"]".', 'elementor-mcp-bridge' ),
		);
	}

	/**
	 * Heuristic: does this widget come from Elementor Pro or an addon?
	 *
	 * @param object $widget Widget instance.
	 * @return bool
	 */
	private static function looks_like_pro( $widget ) {
		$class = get_class( $widget );

		return false !== stripos( $class, 'ElementorPro' ) || false !== stripos( $class, 'Elementor\\Pro' );
	}
}
