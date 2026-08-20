<?php
/**
 * Site-wide Elementor design settings: the kit, global colours, global fonts,
 * theme style and v4 global classes.
 *
 * @package Elementor_MCP_Bridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Global design token access.
 *
 * Elementor keeps site-wide design in a "kit" — a hidden document whose page
 * settings hold the global colour and typography palettes that widgets refer to
 * by ID. Editing the kit is how you restyle a whole site in one call instead of
 * touching every widget.
 */
class EMCP_Globals {

	/**
	 * Postmeta key holding v4 global classes on the kit.
	 */
	const GLOBAL_CLASSES_META = '_elementor_global_classes';

	/**
	 * Get the active kit document.
	 *
	 * @return \Elementor\Core\Base\Document|WP_Error
	 */
	public static function kit() {
		$ready = EMCP_Guard::require_elementor();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();

		if ( ! $kit || ! $kit->get_main_id() ) {
			return new WP_Error(
				'emcp_no_kit',
				__( 'This site has no active Elementor kit. Open Elementor > Site Settings once to create one.', 'elementor-mcp-bridge' ),
				array( 'status' => 404 )
			);
		}

		return $kit;
	}

	/**
	 * Read the kit: raw settings plus a friendly view of the palettes.
	 *
	 * @return array|WP_Error
	 */
	public static function read() {
		$kit = self::kit();

		if ( is_wp_error( $kit ) ) {
			return $kit;
		}

		$kit_id   = $kit->get_main_id();
		$settings = get_post_meta( $kit_id, EMCP_Documents::PAGE_SETTINGS_META, true );
		$settings = is_array( $settings ) ? $settings : array();

		return array(
			'kitId'      => $kit_id,
			'title'      => get_the_title( $kit_id ),
			'colors'     => self::normalise_palette( $settings, 'system_colors', 'custom_colors' ),
			'typography' => self::normalise_palette( $settings, 'system_typography', 'custom_typography' ),
			'layout'     => self::pick(
				$settings,
				array(
					'container_width',
					'space_between_widgets',
					'page_title_selector',
					'viewport_mobile',
					'viewport_tablet',
					'active_breakpoints',
					'default_generic_fonts',
				)
			),
			'customCss'  => isset( $settings['custom_css'] ) ? $settings['custom_css'] : '',
			'settings'   => $settings,
		);
	}

	/**
	 * Merge new settings into the kit.
	 *
	 * @param array $settings Settings to merge.
	 * @return array|WP_Error
	 */
	public static function update( array $settings ) {
		if ( ! EMCP_Guard::can_manage_globals() ) {
			return new WP_Error(
				'emcp_cannot_manage_globals',
				__( 'You do not have permission to change site-wide design settings.', 'elementor-mcp-bridge' ),
				array( 'status' => 403 )
			);
		}

		$kit = self::kit();

		if ( is_wp_error( $kit ) ) {
			return $kit;
		}

		EMCP_Snapshots::capture( $kit->get_main_id(), 'auto: before kit update' );

		$kit->update_settings( $settings );

		// Global tokens feed every generated stylesheet on the site.
		EMCP_Documents::flush_css( 0 );

		return self::read();
	}

	/**
	 * Set or add a single global colour by ID or title.
	 *
	 * @param string $id    Colour ID (for example `primary`) or its title.
	 * @param string $value Hex colour.
	 * @param string $title Optional title when creating a new custom colour.
	 * @return array|WP_Error
	 */
	public static function set_color( $id, $value, $title = '' ) {
		$current = self::read();

		if ( is_wp_error( $current ) ) {
			return $current;
		}

		$settings = $current['settings'];

		foreach ( array( 'system_colors', 'custom_colors' ) as $bucket ) {
			if ( empty( $settings[ $bucket ] ) || ! is_array( $settings[ $bucket ] ) ) {
				continue;
			}

			foreach ( $settings[ $bucket ] as $index => $entry ) {
				$matches_id    = isset( $entry['_id'] ) && (string) $entry['_id'] === (string) $id;
				$matches_title = isset( $entry['title'] ) && strcasecmp( (string) $entry['title'], (string) $id ) === 0;

				if ( $matches_id || $matches_title ) {
					$settings[ $bucket ][ $index ]['color'] = (string) $value;

					if ( '' !== $title ) {
						$settings[ $bucket ][ $index ]['title'] = (string) $title;
					}

					return self::update( array( $bucket => $settings[ $bucket ] ) );
				}
			}
		}

		// Not found: append a new custom colour.
		$custom   = isset( $settings['custom_colors'] ) && is_array( $settings['custom_colors'] ) ? $settings['custom_colors'] : array();
		$custom[] = array(
			'_id'   => (string) $id,
			'title' => '' !== $title ? (string) $title : (string) $id,
			'color' => (string) $value,
		);

		return self::update( array( 'custom_colors' => $custom ) );
	}

	/**
	 * Read the site custom CSS held on the kit.
	 *
	 * @return array|WP_Error
	 */
	public static function read_custom_css() {
		$kit = self::read();

		if ( is_wp_error( $kit ) ) {
			return $kit;
		}

		return array(
			'kitId' => $kit['kitId'],
			'css'   => $kit['customCss'],
			'note'  => __( 'Site-wide custom CSS is an Elementor Pro feature; on Elementor free this value is stored but not rendered.', 'elementor-mcp-bridge' ),
		);
	}

	/**
	 * Read v4 global classes.
	 *
	 * @return array|WP_Error
	 */
	public static function read_global_classes() {
		$kit = self::kit();

		if ( is_wp_error( $kit ) ) {
			return $kit;
		}

		$raw = get_post_meta( $kit->get_main_id(), self::GLOBAL_CLASSES_META, true );

		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}

		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		return array(
			'kitId'     => $kit->get_main_id(),
			'available' => class_exists( '\Elementor\Modules\GlobalClasses\Global_Classes_Repository' ),
			'items'     => isset( $raw['items'] ) ? $raw['items'] : $raw,
			'order'     => isset( $raw['order'] ) ? $raw['order'] : array(),
		);
	}

	/**
	 * Flatten the system/custom halves of a palette into one addressable list.
	 *
	 * @param array  $settings   Kit settings.
	 * @param string $system_key System bucket key.
	 * @param string $custom_key Custom bucket key.
	 * @return array
	 */
	private static function normalise_palette( array $settings, $system_key, $custom_key ) {
		$out = array();

		foreach ( array( 'system' => $system_key, 'custom' => $custom_key ) as $kind => $key ) {
			if ( empty( $settings[ $key ] ) || ! is_array( $settings[ $key ] ) ) {
				continue;
			}

			foreach ( $settings[ $key ] as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}

				$id = isset( $entry['_id'] ) ? (string) $entry['_id'] : '';

				$item = array(
					'id'     => $id,
					'kind'   => $kind,
					'title'  => isset( $entry['title'] ) ? (string) $entry['title'] : $id,
					'bucket' => $key,
				);

				if ( isset( $entry['color'] ) ) {
					$item['color'] = (string) $entry['color'];
					// How a widget setting refers to this token.
					$item['reference'] = 'globals/colors?id=' . $id;
				} else {
					$item['typography'] = array_filter(
						$entry,
						static function ( $value, $entry_key ) {
							return 0 === strpos( $entry_key, 'typography_' ) && '' !== $value && null !== $value;
						},
						ARRAY_FILTER_USE_BOTH
					);
					$item['reference']  = 'globals/typography?id=' . $id;
				}

				$out[] = $item;
			}
		}

		return $out;
	}

	/**
	 * Pick a subset of keys from an array.
	 *
	 * @param array    $source Source array.
	 * @param string[] $keys   Keys to keep.
	 * @return array
	 */
	private static function pick( array $source, array $keys ) {
		$out = array();

		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $source ) ) {
				$out[ $key ] = $source[ $key ];
			}
		}

		return $out;
	}
}
