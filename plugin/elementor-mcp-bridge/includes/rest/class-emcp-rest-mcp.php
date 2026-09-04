<?php
/**
 * The MCP endpoint itself: one JSON-RPC 2.0 route.
 *
 * @package Elementor_MCP_Bridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * MCP controller.
 *
 * Exposes the whole tool surface as a single Streamable-HTTP-compatible
 * endpoint at /wp-json/elementor-mcp/v1/mcp, so an MCP client — including a
 * claude.ai custom connector — can point at this site directly with no
 * separate server process to run or keep alive.
 */
class EMCP_REST_MCP extends EMCP_REST_Base {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$this->route(
			'/mcp',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle' ),
					'permission_callback' => array( $this, 'can_connect' ),
				),
				array(
					// GET and DELETE are part of the Streamable HTTP transport
					// (resuming a stream, closing a session) that only apply to
					// stateful servers. This one is stateless — every call is a
					// self-contained POST — so both simply say so, rather than
					// 404ing in a way that looks like the endpoint is missing.
					'methods'             => 'GET, DELETE',
					'callback'            => array( $this, 'stateless_notice' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Can this caller use the MCP endpoint at all?
	 *
	 * One gate for every JSON-RPC method, matching how Elementor's own
	 * mcp-proxy route is gated (current_user_can( 'edit_posts' )) — tool
	 * handlers still apply their own, finer-grained checks on top of this for
	 * anything beyond a baseline read.
	 *
	 * @return true|WP_Error
	 */
	public function can_connect() {
		$allowed = $this->can_read();

		if ( true !== $allowed ) {
			// RFC 6750 §3 (Bearer) points a client at how to authenticate;
			// resource_metadata is the MCP authorization spec's addition,
			// pointing at RFC 9728 discovery so a compliant client can find
			// this server's OAuth endpoints from a bare 401 rather than
			// probing the generic well-known path at the site root — which,
			// on a site with a sibling MCP plugin, may belong to that plugin.
			header(
				sprintf(
					'WWW-Authenticate: Bearer resource_metadata="%s"',
					esc_url_raw( EMCP_OAuth::protected_resource_metadata_url() )
				)
			);
		}

		return $allowed;
	}

	/**
	 * Handle a JSON-RPC request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle( $request ) {
		$body = $request->get_json_params();

		if ( null === $body && '' !== trim( $request->get_body() ) ) {
			return new WP_REST_Response(
				array(
					'jsonrpc' => '2.0',
					'id'      => null,
					'error'   => array( 'code' => -32700, 'message' => 'Parse error: invalid JSON.' ),
				),
				400
			);
		}

		if ( ! is_array( $body ) ) {
			return new WP_REST_Response(
				array(
					'jsonrpc' => '2.0',
					'id'      => null,
					'error'   => array( 'code' => -32600, 'message' => 'Invalid Request: expected a JSON-RPC object or batch array.' ),
				),
				400
			);
		}

		$server   = new EMCP_MCP_Server();
		$response = $server->handle( $body );

		// A lone notification (no "id") produces no response body; per the
		// Streamable HTTP spec that is a bare 202 Accepted.
		if ( null === $response ) {
			return new WP_REST_Response( null, 202 );
		}

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Explain why GET/DELETE do not apply here.
	 *
	 * @return WP_REST_Response
	 */
	public function stateless_notice() {
		return new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => null,
				'error'   => array(
					'code'    => -32000,
					'message' => 'This server is stateless: every call is a self-contained POST. There is no session to resume or close.',
				),
			),
			405
		);
	}
}
