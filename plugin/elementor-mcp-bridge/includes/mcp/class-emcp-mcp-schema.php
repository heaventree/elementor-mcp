<?php
/**
 * Tiny JSON Schema builder, used only to keep the MCP tool registry readable.
 *
 * @package Elementor_MCP_Bridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Schema helpers.
 *
 * MCP tool input schemas are plain JSON Schema (draft 2020-12 subset) sent
 * straight to the client — there is no zod on the PHP side to generate them,
 * so these are hand-written. Keeping them declarative here is what keeps the
 * tool registry itself readable despite fifty tool definitions.
 */
class EMCP_MCP_Schema {

	/**
	 * @param string $description Field description.
	 * @return array
	 */
	public static function string( $description = '' ) {
		return self::describe( array( 'type' => 'string' ), $description );
	}

	/**
	 * @param string $description Field description.
	 * @return array
	 */
	public static function integer( $description = '' ) {
		return self::describe( array( 'type' => 'integer' ), $description );
	}

	/**
	 * @param string $description Field description.
	 * @param bool   $default_value Default value.
	 * @return array
	 */
	public static function boolean( $description = '', $default_value = null ) {
		$schema = array( 'type' => 'boolean' );

		if ( null !== $default_value ) {
			$schema['default'] = $default_value;
		}

		return self::describe( $schema, $description );
	}

	/**
	 * @param array  $values      Allowed values.
	 * @param string $description Field description.
	 * @return array
	 */
	public static function enum( array $values, $description = '' ) {
		return self::describe(
			array(
				'type' => 'string',
				'enum' => array_values( $values ),
			),
			$description
		);
	}

	/**
	 * An enum with a default value, e.g. z.enum([...]).default('draft').
	 *
	 * @param array  $values      Allowed values.
	 * @param string $default_value Default value.
	 * @param string $description Field description.
	 * @return array
	 */
	public static function describe_enum_default( array $values, $default_value, $description = '' ) {
		$schema = self::enum( $values, $description );
		$schema['default'] = $default_value;
		return $schema;
	}

	/**
	 * @param array  $items       Item schema.
	 * @param string $description Field description.
	 * @return array
	 */
	public static function array_of( array $items, $description = '' ) {
		return self::describe(
			array(
				'type'  => 'array',
				'items' => $items,
			),
			$description
		);
	}

	/**
	 * A free-form object, used for element settings and templates payloads
	 * whose shape depends on the widget or site.
	 *
	 * @param string $description Field description.
	 * @return array
	 */
	public static function free_object( $description = '' ) {
		return self::describe( array( 'type' => 'object' ), $description );
	}

	/**
	 * The "site" argument every tool accepts.
	 *
	 * @return array
	 */
	public static function site() {
		return self::string( 'Configured site name. Omit to use the default site — usually correct on a single-site connector.' );
	}

	/**
	 * The Elementor element id argument every element tool accepts.
	 *
	 * @param string $description Override description.
	 * @return array
	 */
	public static function element_id( $description = '' ) {
		return self::string( $description ?: 'Elementor element id, as returned by elementor_get_outline.' );
	}

	/**
	 * The position argument insert/move tools accept.
	 *
	 * @return array
	 */
	public static function position() {
		return self::describe(
			array(
				'type'    => 'string',
				'enum'    => array( 'append', 'prepend', 'before', 'after' ),
				'default' => 'append',
			),
			'append/prepend place the element inside the target; before/after place it as a sibling of the target.'
		);
	}

	/**
	 * Build an object schema from a properties map.
	 *
	 * @param array $properties Map of property name to schema.
	 * @param array $required   Required property names.
	 * @return array
	 */
	public static function object( array $properties, array $required = array() ) {
		$schema = array(
			'type'       => 'object',
			'properties' => $properties,
		);

		if ( $required ) {
			$schema['required'] = array_values( $required );
		}

		return $schema;
	}

	/**
	 * Attach a description and, optionally, a default to a schema fragment.
	 *
	 * @param array      $schema      Schema fragment.
	 * @param string     $description Description text.
	 * @param mixed|null $default_value Default value.
	 * @return array
	 */
	private static function describe( array $schema, $description, $default_value = null ) {
		if ( '' !== $description ) {
			$schema['description'] = $description;
		}

		if ( null !== $default_value ) {
			$schema['default'] = $default_value;
		}

		return $schema;
	}

	/**
	 * Set a default on an already-built schema fragment (fluent-ish helper for
	 * the common "z.number().default(20)" shape).
	 *
	 * @param array $schema Schema fragment.
	 * @param mixed $value  Default value.
	 * @return array
	 */
	public static function with_default( array $schema, $value ) {
		$schema['default'] = $value;
		return $schema;
	}
}
