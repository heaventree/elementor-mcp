<?php
/**
 * MCP JSON-RPC 2.0 dispatcher.
 *
 * @package Elementor_MCP_Bridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles one MCP request and returns a JSON-RPC 2.0 response.
 *
 * This implements the "stateless Streamable HTTP" shape of the MCP
 * specification: every call is a single POST carrying one JSON-RPC message,
 * answered with a single JSON-RPC response — no persistent session, no
 * server-sent-events stream. That fits how PHP actually runs (one process per
 * request, nothing held open between them) and is fully spec-compliant: a
 * server that has no need to push messages mid-call is not required to use
 * SSE, only permitted to.
 *
 * Every method here requires the caller already be an authenticated
 * WordPress user — WordPress's own Application Passwords feature validates
 * the request's Authorization header before this class is ever reached, the
 * same as it does for every other route this plugin registers.
 */
class EMCP_MCP_Server {

	/**
	 * Protocol versions this server understands, newest first.
	 */
	const SUPPORTED_PROTOCOL_VERSIONS = array( '2025-06-18', '2025-03-26', '2024-11-05' );

	/**
	 * Handle a decoded JSON-RPC message (or batch of them).
	 *
	 * @param array $body Decoded JSON body: one JSON-RPC request, or a list of them.
	 * @return array|null Response body to send, or null for a notification (no reply expected).
	 */
	public function handle( array $body ) {
		// A batch is a JSON array of individual JSON-RPC request objects.
		if ( self::is_list( $body ) ) {
			$responses = array();

			foreach ( $body as $message ) {
				$response = $this->handle_one( is_array( $message ) ? $message : array() );

				if ( null !== $response ) {
					$responses[] = $response;
				}
			}

			return $responses ? $responses : null;
		}

		return $this->handle_one( $body );
	}

	/**
	 * Handle one JSON-RPC message.
	 *
	 * @param array $message Decoded message.
	 * @return array|null
	 */
	private function handle_one( array $message ) {
		$id     = array_key_exists( 'id', $message ) ? $message['id'] : null;
		$method = isset( $message['method'] ) ? (string) $message['method'] : '';
		$params = isset( $message['params'] ) && is_array( $message['params'] ) ? $message['params'] : array();

		// Notifications (no "id") get no response at all, per JSON-RPC 2.0 —
		// most importantly notifications/initialized, which every client sends
		// after initialize and which has nothing meaningful to reply with.
		$is_notification = ! array_key_exists( 'id', $message );

		if ( '' === $method ) {
			return $is_notification ? null : $this->error_response( $id, -32600, 'Invalid Request: missing "method".' );
		}

		try {
			$result = $this->dispatch( $method, $params );
		} catch ( EMCP_Tree_Error $error ) {
			$result = $error->to_wp_error();
		} catch ( Throwable $error ) {
			$result = new WP_Error( 'emcp_internal_error', $error->getMessage(), array( 'status' => 500 ) );
		}

		if ( $is_notification ) {
			return null;
		}

		if ( is_wp_error( $result ) ) {
			return $this->error_response( $id, self::error_code_for( $result ), $result->get_error_message(), $this->error_data_for( $result ) );
		}

		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	/**
	 * Route a method to its handler.
	 *
	 * @param string $method JSON-RPC method name.
	 * @param array  $params Method params.
	 * @return array|WP_Error
	 */
	private function dispatch( $method, array $params ) {
		switch ( $method ) {
			case 'initialize':
				return $this->initialize( $params );

			case 'ping':
				return new stdClass();

			case 'tools/list':
				return $this->tools_list();

			case 'tools/call':
				return $this->tools_call( $params );

			case 'prompts/list':
				return $this->prompts_list();

			case 'prompts/get':
				return $this->prompts_get( $params );

			case 'resources/list':
				return array( 'resources' => array() );

			case 'notifications/initialized':
				// Handled as a notification (no id) upstream; nothing to do.
				return new stdClass();

			default:
				return new WP_Error(
					'emcp_method_not_found',
					sprintf( 'Unknown method "%s".', $method ),
					array( 'status' => -32601 )
				);
		}
	}

	/**
	 * initialize: negotiate protocol version and describe this server.
	 *
	 * @param array $params Request params.
	 * @return array
	 */
	private function initialize( array $params ) {
		$requested = isset( $params['protocolVersion'] ) ? (string) $params['protocolVersion'] : '';
		$version   = in_array( $requested, self::SUPPORTED_PROTOCOL_VERSIONS, true ) ? $requested : self::SUPPORTED_PROTOCOL_VERSIONS[0];

		return array(
			'protocolVersion' => $version,
			'capabilities'    => array(
				'tools'   => new stdClass(),
				'prompts' => new stdClass(),
			),
			'serverInfo'      => array(
				'name'    => 'elementor-mcp-bridge',
				'title'   => 'Elementor (' . wp_parse_url( home_url(), PHP_URL_HOST ) . ')',
				'version' => EMCP_VERSION,
			),
			'instructions'    =>
				"Tools for reading and editing Elementor pages on this WordPress site.\n\n" .
				"Working order that avoids the usual mistakes:\n" .
				"1. elementor_site_status once per session, to see the Elementor version, widget count, and " .
				"whether the flexbox container element is available here.\n" .
				"2. elementor_get_outline to get element ids — do not fetch whole element trees unless you need them.\n" .
				"3. elementor_get_widget_schema before setting unfamiliar widget settings; control names are not guessable.\n" .
				"4. elementor_batch_edit for multi-step changes, so the page cannot be left half-edited.\n\n" .
				"Every write snapshots the page first; elementor_restore_snapshot undoes the last change. " .
				"Writes carry the hash the page was read at and are rejected if it changed underneath you, then " .
				"retried once automatically. Site-wide replace defaults to a dry run — read the report before committing.",
		);
	}

	/**
	 * tools/list.
	 *
	 * @return array
	 */
	private function tools_list() {
		$tools = array();

		foreach ( EMCP_MCP_Tools::definitions() as $definition ) {
			$tool = array(
				'name'        => $definition['name'],
				'description' => $definition['description'],
				'inputSchema' => isset( $definition['inputSchema'] ) ? $definition['inputSchema'] : array( 'type' => 'object', 'properties' => new stdClass() ),
			);

			if ( isset( $definition['title'] ) ) {
				$tool['title'] = $definition['title'];
			}

			if ( isset( $definition['annotations'] ) ) {
				$tool['annotations'] = $definition['annotations'];
			}

			$tools[] = $tool;
		}

		return array( 'tools' => $tools );
	}

	/**
	 * tools/call.
	 *
	 * @param array $params Request params: { name, arguments }.
	 * @return array
	 */
	private function tools_call( array $params ) {
		$name      = isset( $params['name'] ) ? (string) $params['name'] : '';
		$arguments = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();

		$definition = null;

		foreach ( EMCP_MCP_Tools::definitions() as $candidate ) {
			if ( $candidate['name'] === $name ) {
				$definition = $candidate;
				break;
			}
		}

		if ( null === $definition ) {
			return array(
				'content' => array( array( 'type' => 'text', 'text' => sprintf( 'Unknown tool "%s". Call tools/list to see what is available.', $name ) ) ),
				'isError' => true,
			);
		}

		try {
			$result = call_user_func( $definition['handler'], $arguments );
		} catch ( EMCP_Tree_Error $error ) {
			$result = $error->to_wp_error();
		} catch ( Throwable $error ) {
			$result = new WP_Error( 'emcp_internal_error', $error->getMessage(), array( 'status' => 500 ) );
		}

		if ( is_wp_error( $result ) ) {
			return array(
				'content'          => array( array( 'type' => 'text', 'text' => $this->format_tool_error( $result ) ) ),
				'isError'          => true,
				'structuredContent' => array(
					'error' => array_merge(
						array( 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ),
						$this->error_data_for( $result )
					),
				),
			);
		}

		return array(
			'content'           => array( array( 'type' => 'text', 'text' => wp_json_encode( $result, JSON_PRETTY_PRINT ) ) ),
			'structuredContent' => is_array( $result ) && ! self::is_list( $result ) ? $result : array( 'value' => $result ),
		);
	}

	/**
	 * A tool-facing error message, including the hint when the error carries one.
	 *
	 * @param WP_Error $error Error.
	 * @return string
	 */
	private function format_tool_error( WP_Error $error ) {
		$message = $error->get_error_message();
		$data    = $error->get_error_data();
		$hint    = is_array( $data ) && ! empty( $data['hint'] ) ? $data['hint'] : '';

		return $hint ? "{$message}\n\nNext step: {$hint}" : $message;
	}

	/**
	 * prompts/list: the same workflow prompts the standalone TypeScript server ships.
	 *
	 * @return array
	 */
	private function prompts_list() {
		return array(
			'prompts' => array(
				array(
					'name'        => 'edit_page_safely',
					'title'       => 'Edit an Elementor page safely',
					'description' => 'The read-inspect-change-verify loop for changing an existing page.',
					'arguments'   => array(
						array( 'name' => 'postId', 'description' => 'The page ID to edit.', 'required' => true ),
						array( 'name' => 'goal', 'description' => 'What you want to change.', 'required' => true ),
					),
				),
				array(
					'name'        => 'build_page',
					'title'       => 'Build a new Elementor page',
					'description' => 'Compose a new page from containers and widgets without breaking a live site.',
					'arguments'   => array(
						array( 'name' => 'brief', 'description' => 'What the page is for and what should be on it.', 'required' => true ),
					),
				),
				array(
					'name'        => 'audit_site',
					'title'       => 'Audit Elementor usage across a site',
					'description' => 'Survey what a site is built from before changing or migrating it.',
					'arguments'   => array(
						array( 'name' => 'focus', 'description' => 'Optional area to concentrate on.', 'required' => false ),
					),
				),
			),
		);
	}

	/**
	 * prompts/get.
	 *
	 * @param array $params Request params: { name, arguments }.
	 * @return array|WP_Error
	 */
	private function prompts_get( array $params ) {
		$name = isset( $params['name'] ) ? (string) $params['name'] : '';
		$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();

		$text = null;

		if ( 'edit_page_safely' === $name ) {
			$post_id = isset( $args['postId'] ) ? $args['postId'] : '{postId}';
			$goal    = isset( $args['goal'] ) ? $args['goal'] : '{goal}';

			$text =
				"Change page {$post_id}: {$goal}\n\n" .
				"Work in this order:\n" .
				"1. elementor_get_outline on {$post_id} to see the structure and get element ids.\n" .
				"2. elementor_get_element on the specific elements involved, to read their current settings.\n" .
				"3. elementor_get_widget_schema for any widget whose settings you are unsure of — do not guess " .
				"control names, and check whether the setting is responsive.\n" .
				"4. elementor_get_globals if this touches colour or typography, so you use the site's existing " .
				"design tokens rather than hard-coding values.\n" .
				"5. Make the change with elementor_batch_edit if it is more than one step.\n" .
				"6. elementor_render_page on the affected element to confirm the result.\n\n" .
				"If anything looks wrong, elementor_restore_snapshot rolls the page back.";
		} elseif ( 'build_page' === $name ) {
			$brief = isset( $args['brief'] ) ? $args['brief'] : '{brief}';

			$text =
				"Build a new Elementor page: {$brief}\n\n" .
				"Before writing anything:\n" .
				"- elementor_site_status to confirm the Elementor version and whether the flexbox container " .
				"element and Pro widgets are available.\n" .
				"- elementor_list_widgets to see what this site actually has; do not assume a widget exists.\n" .
				"- elementor_get_globals to pick up the site's colour and typography tokens.\n" .
				"- elementor_list_templates in case a suitable layout already exists worth reusing.\n\n" .
				"Then elementor_create_page as a draft, and build it up with elementor_batch_edit. Use container " .
				"where available, section plus column where not. Bind colours and fonts to global tokens rather " .
				"than hard-coding hex values, so the page stays consistent if the palette changes. Finally " .
				"elementor_render_page to check the result, and only publish once it looks right.";
		} elseif ( 'audit_site' === $name ) {
			$focus = ! empty( $args['focus'] ) ? ', focusing on ' . $args['focus'] : '';

			$text =
				"Audit this site's Elementor usage{$focus}.\n\n" .
				"Use elementor_site_status for versions and configuration, elementor_widget_usage to see which " .
				"widgets are actually in use and which pages are heaviest, elementor_list_pages with " .
				"elementorOnly to separate builder pages from the rest, and elementor_get_globals to review the " .
				"design system. Report what you find and flag anything that would break on an Elementor upgrade " .
				"— legacy section/column layouts, widgets from addons, and pages with no global tokens applied.";
		}

		if ( null === $text ) {
			return new WP_Error( 'emcp_prompt_not_found', sprintf( 'Unknown prompt "%s".', $name ), array( 'status' => 404 ) );
		}

		return array(
			'messages' => array(
				array(
					'role'    => 'user',
					'content' => array( 'type' => 'text', 'text' => $text ),
				),
			),
		);
	}

	/**
	 * A JSON-RPC 2.0 error envelope.
	 *
	 * @param mixed  $id   Request id.
	 * @param int    $code JSON-RPC error code.
	 * @param string $message Error message.
	 * @param array  $data Extra data.
	 * @return array
	 */
	private function error_response( $id, $code, $message, array $data = array() ) {
		$error = array(
			'code'    => $code,
			'message' => $message,
		);

		if ( $data ) {
			$error['data'] = $data;
		}

		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => $error,
		);
	}

	/**
	 * Map a WP_Error's HTTP-flavoured status onto a JSON-RPC error code.
	 *
	 * JSON-RPC only defines a handful of codes; anything domain-specific is
	 * mapped to the generic "server error" range and carries the real detail
	 * in `data`, where format_tool_error() and the client can still read it.
	 *
	 * @param WP_Error $error Error.
	 * @return int
	 */
	private static function error_code_for( WP_Error $error ) {
		$data = $error->get_error_data();

		if ( is_array( $data ) && isset( $data['status'] ) && is_int( $data['status'] ) && $data['status'] < 0 ) {
			// A small number of internal codes (method-not-found) are stashed as
			// negative "status" so they can reuse this one lookup path.
			return $data['status'];
		}

		return -32000;
	}

	/**
	 * Extra structured detail to attach to a JSON-RPC error's "data".
	 *
	 * @param WP_Error $error Error.
	 * @return array
	 */
	private function error_data_for( WP_Error $error ) {
		$data = $error->get_error_data();

		if ( ! is_array( $data ) ) {
			return array();
		}

		// The JSON-RPC error code already carries the negative internal-method
		// codes; a positive HTTP-flavoured status (403, 409, ...) is still
		// useful detail for the client, so it is kept under a distinct key
		// rather than dropped or confused with the JSON-RPC "code".
		if ( isset( $data['status'] ) && is_int( $data['status'] ) && $data['status'] > 0 ) {
			$data['httpStatus'] = $data['status'];
		}

		unset( $data['status'] );

		return $data;
	}

	/**
	 * Is this a zero-indexed sequential array (a JSON list, not an object)?
	 *
	 * @param mixed $value Value to test.
	 * @return bool
	 */
	private static function is_list( $value ) {
		if ( ! is_array( $value ) ) {
			return false;
		}

		return array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}
}
