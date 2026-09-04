<?php
/**
 * OAuth 2.0 authorization server for MCP clients.
 *
 * @package Elementor_MCP_Bridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * A minimal, PKCE-only OAuth 2.0 authorization server.
 *
 * Some MCP clients — confirmed for claude.ai's custom connector, on both web
 * and desktop — do not send credentials as a header. They run a full
 * authorization-code-with-PKCE flow (RFC 6749 + RFC 7636) against the
 * connector's own origin: a browser redirect to the authorization endpoint,
 * then a server-to-server POST to the token endpoint. Without both present,
 * the authorize redirect 404s before the user ever sees a consent screen —
 * which is what happened here.
 *
 * This intentionally does not implement dynamic client registration
 * (RFC 7591) or a client_secret. The request we observed from claude.ai
 * carried no client_secret, only a code_challenge — a public client, which
 * is what PKCE exists for. client_id is therefore treated as a label, not a
 * trust boundary; the real security boundary is (1) the visitor must
 * authenticate as a real WordPress user before consenting, and (2) the
 * redirect_uri must be on an explicit allow-list, so a crafted link cannot
 * exfiltrate an authorization code to somewhere the user did not approve.
 *
 * An access token issued here is not a bespoke credential: it is a real
 * WordPress application password, minted through
 * WP_Application_Passwords::create_new_application_password() the moment
 * consent is given, then handed back as base64(username:password) — the
 * exact shape EMCP_Auth::authenticate_bearer() already validates. That
 * means every issued token is individually visible and revocable from
 * Users > Profile > Application Passwords like any other, with no parallel
 * token store to keep secure or to leak.
 *
 * PATH SCOPING (1.3.0). Every endpoint lives under this plugin's own slug:
 * the issuer is `<site>/elementor-mcp`, so per RFC 8414 §3.1 path insertion
 * its metadata is at `/.well-known/oauth-authorization-server/elementor-mcp`,
 * the protected-resource document (RFC 9728 §3.1) is keyed on the MCP
 * endpoint's own path, and authorize / token / revoke sit under
 * `/elementor-mcp/`. 1.2.0 claimed `/authorize`, `/token` and the *generic*
 * well-known paths at the site root on `init` priority 1 — a first-come
 * collision with any sibling MCP plugin (AI SEO MCP, AI Security MCP, Easy
 * MCP AI…) that does the same, decided by plugin load order, with the loser's
 * connector silently authorising against the wrong server. The generic
 * well-known paths are now claimed only when no known competing plugin is
 * active (see may_claim_generic_wellknown()), so a solo install still
 * answers a client that probes the bare path, but we never steal it.
 *
 * Three further fixes carried over from the same class of bug found live on
 * the sibling plugins: discovery and token responses send nocache_headers()
 * (a full-page cache serving a stale discovery document is a real,
 * previously-hit failure mode), CORS headers are sent on discovery, token
 * and revoke (claude.ai's browser-side JS can hit these cross-origin and
 * discards the response without them), and the application password minted
 * per token exchange is named deterministically per client so a
 * reconnecting client replaces its previous credential instead of leaving an
 * ever-growing pile of "Claude MCP (OAuth, <timestamp>)" entries behind.
 */
class EMCP_OAuth {

	/**
	 * How long an unused authorization code is valid for.
	 */
	const CODE_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * The path segment every endpoint of this authorization server lives under.
	 */
	const SLUG = 'elementor-mcp';

	/**
	 * Register the request interception.
	 *
	 * Paths are handled on the `init` hook by matching the raw request URI:
	 * no rewrite rule to register, no permalinks to flush, and no dependency
	 * on a matching page or post existing at that slug. Priority 1 so a theme
	 * or plugin that hooks `init` to render something at these paths never
	 * gets the chance.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'init', array( __CLASS__, 'maybe_handle_request' ), 1 );
	}

	/**
	 * Redirect URIs consent is permitted to complete to.
	 *
	 * Deliberately narrow: only the one redirect_uri actually observed from a
	 * real claude.ai connector attempt. A crafted authorize link pointing
	 * anywhere else is refused before a code is ever issued, which is what
	 * makes an unvalidated client_id safe to accept. Extend via the filter if
	 * another client (e.g. Claude Desktop, if it turns out to use a
	 * different callback) needs adding.
	 *
	 * @return string[]
	 */
	public static function allowed_redirect_uris() {
		return (array) apply_filters(
			'emcp_oauth_allowed_redirect_uris',
			array( 'https://claude.ai/api/mcp/auth_callback' )
		);
	}

	/**
	 * Is $uri on the allow-list?
	 *
	 * @param string $uri Redirect URI to check.
	 * @return bool
	 */
	private static function is_allowed_redirect_uri( $uri ) {
		return in_array( $uri, self::allowed_redirect_uris(), true );
	}

	/* ------------------------------------------------------------------ *
	 * URLs. Everything a client, a doc, or a sibling class needs to know
	 * about where this server lives comes from here, so a path change is
	 * a one-place edit.
	 * ------------------------------------------------------------------ */

	/**
	 * The site's own origin (scheme + host + any subdirectory), no trailing slash.
	 *
	 * @return string
	 */
	public static function site_origin() {
		return untrailingslashit( home_url() );
	}

	/**
	 * This authorization server's issuer identifier.
	 *
	 * Carries the plugin slug as a path component so that RFC 8414 §3.1
	 * path insertion makes its metadata URL unique to this plugin.
	 *
	 * @return string
	 */
	public static function issuer() {
		return self::site_origin() . '/' . self::SLUG;
	}

	/**
	 * The MCP endpoint's path relative to the site origin, no leading slash.
	 *
	 * @return string
	 */
	public static function resource_path() {
		return 'wp-json/' . EMCP_REST_NAMESPACE . '/mcp';
	}

	/**
	 * The protected resource (the MCP endpoint) as an absolute URL.
	 *
	 * @return string
	 */
	public static function resource_url() {
		return self::site_origin() . '/' . self::resource_path();
	}

	/**
	 * RFC 8414 authorization-server metadata URL, path-inserted for this issuer.
	 *
	 * @return string
	 */
	public static function authorization_server_metadata_url() {
		return self::site_origin() . '/.well-known/oauth-authorization-server/' . self::SLUG;
	}

	/**
	 * RFC 9728 protected-resource metadata URL, keyed on the resource path.
	 *
	 * @return string
	 */
	public static function protected_resource_metadata_url() {
		return self::site_origin() . '/.well-known/oauth-protected-resource/' . self::resource_path();
	}

	/**
	 * @return string
	 */
	public static function authorize_url() {
		return self::issuer() . '/authorize';
	}

	/**
	 * @return string
	 */
	public static function token_url() {
		return self::issuer() . '/token';
	}

	/**
	 * @return string
	 */
	public static function revoke_url() {
		return self::issuer() . '/revoke';
	}

	/* ------------------------------------------------------------------ *
	 * Request routing.
	 * ------------------------------------------------------------------ */

	/**
	 * Plugins that also publish OAuth discovery at the generic well-known
	 * paths. If any is active we leave those paths alone.
	 *
	 * @return string[] Plugin basenames.
	 */
	public static function competing_oauth_plugins() {
		return (array) apply_filters(
			'emcp_oauth_competing_oauth_plugins',
			array(
				'ai-seo-mcp/ai-seo-mcp.php',
				'ai-security-mcp/ai-security-mcp.php',
				'easy-mcp-ai/easy-mcp-ai.php',
				'royal-mcp/royal-mcp.php',
				'mcp-adapter/mcp-adapter.php',
			)
		);
	}

	/**
	 * May this plugin answer the generic (un-scoped) well-known paths?
	 *
	 * A client that has only the MCP endpoint URL and no metadata pointer
	 * will probe `/.well-known/oauth-protected-resource` at the bare site
	 * root. On a site where this is the only MCP plugin, answering there is
	 * a convenience; on a site with a sibling, answering there steals the
	 * sibling's connection. So: answer only when uncontested, and let a
	 * site owner override either way with the filter.
	 *
	 * @return bool
	 */
	public static function may_claim_generic_wellknown() {
		$active    = (array) get_option( 'active_plugins', array() );
		$contested = (bool) array_intersect( $active, self::competing_oauth_plugins() );

		return (bool) apply_filters( 'emcp_oauth_claim_generic_wellknown', ! $contested );
	}

	/**
	 * Map a request path to the handler that owns it.
	 *
	 * Pure: no side effects, no exit, so the routing table is directly
	 * testable without ever hitting the real request interceptor.
	 *
	 * @param string $path Request path, no query string, no trailing slash.
	 * @return string|null One of authorize|token|revoke|as-metadata|rs-metadata, or null.
	 */
	public static function route_for_path( $path ) {
		$scoped = array(
			'/' . self::SLUG . '/authorize'                                => 'authorize',
			'/' . self::SLUG . '/token'                                    => 'token',
			'/' . self::SLUG . '/revoke'                                   => 'revoke',
			'/.well-known/oauth-authorization-server/' . self::SLUG        => 'as-metadata',
			'/.well-known/oauth-protected-resource/' . self::resource_path() => 'rs-metadata',
		);

		if ( isset( $scoped[ $path ] ) ) {
			return $scoped[ $path ];
		}

		$generic = array(
			'/.well-known/oauth-authorization-server' => 'as-metadata',
			'/.well-known/oauth-protected-resource'   => 'rs-metadata',
		);

		if ( isset( $generic[ $path ] ) && self::may_claim_generic_wellknown() ) {
			return $generic[ $path ];
		}

		return null;
	}

	/**
	 * Handle the request if it matches one of the OAuth paths, exiting
	 * immediately if so. A no-op for every other request.
	 *
	 * @return void
	 */
	public static function maybe_handle_request() {
		$route = self::route_for_path( self::request_path() );

		if ( null === $route ) {
			return;
		}

		// The browser-facing authorize flow is same-origin by nature (it is
		// a top-level navigation). Everything else may be fetched cross-origin
		// by a client's own JS and needs CORS, including a preflight answer.
		if ( 'authorize' !== $route ) {
			self::send_cors_headers();

			if ( 'OPTIONS' === self::request_method() ) {
				status_header( 204 );
				exit;
			}
		}

		switch ( $route ) {
			case 'authorize':
				self::handle_authorize();
				break;

			case 'token':
				self::handle_token();
				break;

			case 'revoke':
				self::handle_revoke();
				break;

			case 'as-metadata':
				self::handle_authorization_server_metadata();
				break;

			case 'rs-metadata':
				self::handle_protected_resource_metadata();
				break;
		}

		exit;
	}

	/**
	 * The request path, with no query string and no trailing slash.
	 *
	 * @return string
	 */
	private static function request_path() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		$path = is_string( $path ) ? $path : '';

		// Requests may arrive under a subdirectory install; compare against
		// the site's own path prefix rather than assuming site root.
		$home_path = (string) wp_parse_url( home_url(), PHP_URL_PATH );

		if ( '' !== $home_path && '/' !== $home_path && 0 === strpos( $path, $home_path ) ) {
			$path = substr( $path, strlen( $home_path ) );
		}

		$path = '/' . ltrim( $path, '/' );

		return '/' === $path ? $path : untrailingslashit( $path );
	}

	/**
	 * @return string Upper-cased request method, GET when unknown.
	 */
	private static function request_method() {
		return isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
	}

	/**
	 * CORS for the machine-facing endpoints.
	 *
	 * Discovery and token exchange can happen from a client's browser-side
	 * JS on its own domain. Without Access-Control-Allow-Origin the browser
	 * discards a perfectly successful response before the client sees it.
	 * Wildcard origin is correct here: none of these endpoints rely on a
	 * cookie, and the token endpoint is protected by PKCE, not by origin.
	 *
	 * @return void
	 */
	private static function send_cors_headers() {
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Content-Type, Authorization' );
	}

	/* ------------------------------------------------------------------ *
	 * Discovery metadata (RFC 8414 / RFC 9728).
	 * ------------------------------------------------------------------ */

	/**
	 * GET /.well-known/oauth-authorization-server/elementor-mcp
	 *
	 * @return void
	 */
	private static function handle_authorization_server_metadata() {
		self::send_json(
			array(
				'issuer'                                => self::issuer(),
				'authorization_endpoint'                => self::authorize_url(),
				'token_endpoint'                         => self::token_url(),
				'revocation_endpoint'                    => self::revoke_url(),
				'response_types_supported'              => array( 'code' ),
				'grant_types_supported'                  => array( 'authorization_code' ),
				'code_challenge_methods_supported'       => array( 'S256' ),
				'token_endpoint_auth_methods_supported'  => array( 'none' ),
				'revocation_endpoint_auth_methods_supported' => array( 'none' ),
				'scopes_supported'                       => array( self::SLUG ),
			)
		);
	}

	/**
	 * GET /.well-known/oauth-protected-resource/wp-json/elementor-mcp/v1/mcp
	 *
	 * @return void
	 */
	private static function handle_protected_resource_metadata() {
		self::send_json(
			array(
				'resource'                 => self::resource_url(),
				'authorization_servers'    => array( self::issuer() ),
				'bearer_methods_supported' => array( 'header' ),
				'scopes_supported'         => array( self::SLUG ),
			)
		);
	}

	/* ------------------------------------------------------------------ *
	 * /elementor-mcp/authorize
	 * ------------------------------------------------------------------ */

	/**
	 * GET or POST authorize.
	 *
	 * @return void
	 */
	private static function handle_authorize() {
		if ( 'POST' === self::request_method() ) {
			self::handle_authorize_submit();
			return;
		}

		self::handle_authorize_prompt();
	}

	/**
	 * Validate the incoming authorize params, common to both the initial GET
	 * and the resubmitted POST.
	 *
	 * @param array $params Query or POST params.
	 * @return array|null Normalised params, or null if invalid (response already sent).
	 */
	private static function validate_authorize_params( array $params ) {
		$response_type        = isset( $params['response_type'] ) ? (string) $params['response_type'] : '';
		$client_id            = isset( $params['client_id'] ) ? (string) $params['client_id'] : '';
		$redirect_uri         = isset( $params['redirect_uri'] ) ? (string) $params['redirect_uri'] : '';
		$code_challenge       = isset( $params['code_challenge'] ) ? (string) $params['code_challenge'] : '';
		$code_challenge_method = isset( $params['code_challenge_method'] ) ? (string) $params['code_challenge_method'] : '';
		$state                = isset( $params['state'] ) ? (string) $params['state'] : '';

		// A bad or missing redirect_uri can never safely redirect the error
		// back to the client — that is exactly the open-redirect case this
		// guards against — so it renders an explanatory page instead.
		if ( '' === $redirect_uri || ! self::is_allowed_redirect_uri( $redirect_uri ) ) {
			self::send_error_page(
				'This site only accepts an OAuth callback it already recognises. ' .
				( '' === $redirect_uri
					? 'No redirect_uri was provided.'
					: 'The redirect_uri provided (' . esc_html( $redirect_uri ) . ') is not on the allow-list.' ),
				400
			);
			return null;
		}

		$problems = array();

		if ( 'code' !== $response_type ) {
			$problems[] = 'response_type must be "code".';
		}

		if ( '' === $client_id ) {
			$problems[] = 'client_id is required.';
		}

		if ( '' === $code_challenge ) {
			$problems[] = 'code_challenge is required — this server requires PKCE.';
		}

		if ( 'S256' !== $code_challenge_method ) {
			$problems[] = 'code_challenge_method must be "S256" — plain PKCE is not accepted.';
		}

		if ( $problems ) {
			self::redirect_with_error( $redirect_uri, $state, 'invalid_request', implode( ' ', $problems ) );
			return null;
		}

		return array(
			'response_type'         => $response_type,
			'client_id'              => $client_id,
			'redirect_uri'           => $redirect_uri,
			'code_challenge'         => $code_challenge,
			'code_challenge_method'  => $code_challenge_method,
			'state'                  => $state,
		);
	}

	/**
	 * GET authorize: show the login-required redirect, or a consent screen.
	 *
	 * @return void
	 */
	private static function handle_authorize_prompt() {
		$params = self::validate_authorize_params( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( null === $params ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			$return_to = add_query_arg( $params, self::authorize_url() );
			wp_safe_redirect( wp_login_url( $return_to ) );
			return;
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			self::send_error_page(
				'This account does not have permission to use the Elementor MCP bridge, so it cannot approve this connection.',
				403
			);
			return;
		}

		self::render_consent_screen( $params );
	}

	/**
	 * POST authorize: process the user's approve/deny decision.
	 *
	 * @return void
	 */
	private static function handle_authorize_submit() {
		$params = self::validate_authorize_params( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( null === $params ) {
			return;
		}

		if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
			self::send_error_page( 'You must be logged in with permission to use this bridge to approve a connection.', 403 );
			return;
		}

		$nonce = isset( $_POST['emcp_nonce'] ) ? (string) $_POST['emcp_nonce'] : '';

		if ( ! wp_verify_nonce( $nonce, 'emcp_oauth_authorize' ) ) {
			self::send_error_page( 'This approval link has expired. Go back to your MCP client and try connecting again.', 400 );
			return;
		}

		if ( empty( $_POST['emcp_approve'] ) ) {
			self::redirect_with_error( $params['redirect_uri'], $params['state'], 'access_denied', 'The user declined to authorize this connection.' );
			return;
		}

		$code = bin2hex( random_bytes( 32 ) );

		set_transient(
			'emcp_oauth_code_' . $code,
			array(
				'user_id'                => get_current_user_id(),
				'client_id'               => $params['client_id'],
				'redirect_uri'            => $params['redirect_uri'],
				'code_challenge'          => $params['code_challenge'],
				'code_challenge_method'   => $params['code_challenge_method'],
			),
			self::CODE_TTL
		);

		$redirect = add_query_arg(
			array_filter(
				array(
					'code'  => $code,
					'state' => '' !== $params['state'] ? $params['state'] : null,
				)
			),
			$params['redirect_uri']
		);

		// Not wp_safe_redirect(): that restricts targets to the site's own
		// host, which would silently redirect this cross-origin callback back
		// to the homepage instead. redirect_uri was already checked against
		// self::allowed_redirect_uris() above — that allow-list is the actual
		// safety check for this redirect.
		wp_redirect( $redirect ); // phpcs:ignore WordPress.Security.SafeRedirect
	}

	/**
	 * Render the consent screen for a logged-in, capable user.
	 *
	 * @param array $params Validated authorize params.
	 * @return void
	 */
	private static function render_consent_screen( array $params ) {
		$user = wp_get_current_user();
		$site = esc_html( get_bloginfo( 'name' ) ?: self::site_origin() );

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );

		$hidden_fields = '';
		foreach ( array( 'response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'state' ) as $key ) {
			$hidden_fields .= sprintf(
				'<input type="hidden" name="%s" value="%s">',
				esc_attr( $key ),
				esc_attr( $params[ $key ] )
			);
		}

		printf(
			'<!doctype html><html><head><meta charset="utf-8"><title>Connect to %1$s</title>' .
			'<meta name="viewport" content="width=device-width, initial-scale=1">' .
			'<style>body{font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;max-width:480px;margin:64px auto;padding:0 24px;color:#1a1a1a}' .
			'.card{border:1px solid #ddd;border-radius:12px;padding:32px}h1{font-size:20px;margin:0 0 8px}' .
			'p{color:#555}.meta{background:#f6f6f7;border-radius:8px;padding:12px 16px;margin:16px 0;font-size:14px;word-break:break-all}' .
			'.meta div{margin:4px 0}.meta b{color:#333}button{font:inherit;font-weight:600;padding:12px 20px;border-radius:8px;' .
			'border:1px solid transparent;cursor:pointer;margin-right:8px}.approve{background:#1a1a1a;color:#fff}' .
			'.deny{background:#fff;border-color:#ccc;color:#1a1a1a}</style></head><body><div class="card">' .
			'<h1>Connect to %1$s</h1><p>An MCP client wants to access Elementor and WordPress on this site as ' .
			'<b>%2$s</b>. It will be able to do anything this account can do in Elementor.</p>' .
			'<div class="meta"><div><b>Will receive:</b> %3$s</div></div>' .
			'<form method="post" action="%4$s">%5$s%6$s' .
			'<button class="approve" type="submit" name="emcp_approve" value="1">Approve</button>' .
			'<button class="deny" type="submit">Deny</button></form></div></body></html>',
			$site,
			esc_html( $user->user_login ),
			esc_html( $params['redirect_uri'] ),
			esc_url( self::authorize_url() ),
			wp_nonce_field( 'emcp_oauth_authorize', 'emcp_nonce', true, false ),
			$hidden_fields
		);
	}

	/* ------------------------------------------------------------------ *
	 * /elementor-mcp/token
	 * ------------------------------------------------------------------ */

	/**
	 * POST token.
	 *
	 * @return void
	 */
	private static function handle_token() {
		$grant_type = isset( $_POST['grant_type'] ) ? (string) $_POST['grant_type'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( 'authorization_code' !== $grant_type ) {
			self::send_token_error(
				400,
				'unsupported_grant_type',
				'Only grant_type=authorization_code is supported. There is no refresh token grant — the ' .
				'issued access token does not expire, so none is needed.'
			);
			return;
		}

		$code          = isset( $_POST['code'] ) ? (string) $_POST['code'] : '';
		$redirect_uri  = isset( $_POST['redirect_uri'] ) ? (string) $_POST['redirect_uri'] : '';
		$code_verifier = isset( $_POST['code_verifier'] ) ? (string) $_POST['code_verifier'] : '';

		if ( '' === $code || '' === $redirect_uri || '' === $code_verifier ) {
			self::send_token_error( 400, 'invalid_request', 'code, redirect_uri and code_verifier are all required.' );
			return;
		}

		$record = get_transient( 'emcp_oauth_code_' . $code );

		if ( ! is_array( $record ) ) {
			self::send_token_error( 400, 'invalid_grant', 'That code is unknown, expired, or has already been used.' );
			return;
		}

		// Single use: consume it immediately, before any further validation,
		// so a request that fails a later check cannot be retried with the
		// same code.
		delete_transient( 'emcp_oauth_code_' . $code );

		if ( ! hash_equals( $record['redirect_uri'], $redirect_uri ) ) {
			self::send_token_error( 400, 'invalid_grant', 'redirect_uri does not match the one used to request this code.' );
			return;
		}

		if ( ! self::verify_pkce( $code_verifier, $record['code_challenge'] ) ) {
			self::send_token_error( 400, 'invalid_grant', 'code_verifier does not match the code_challenge from the authorization request.' );
			return;
		}

		$user = get_userdata( (int) $record['user_id'] );

		if ( ! $user ) {
			self::send_token_error( 400, 'invalid_grant', 'The account that approved this connection no longer exists.' );
			return;
		}

		if ( ! class_exists( '\WP_Application_Passwords' ) || ! function_exists( 'wp_is_application_passwords_available_for_user' ) || ! wp_is_application_passwords_available_for_user( $user ) ) {
			self::send_token_error( 500, 'server_error', 'Application passwords are not available for this account, so no token could be issued.' );
			return;
		}

		$name = self::application_password_name( isset( $record['client_id'] ) ? (string) $record['client_id'] : '' );

		// One live credential per (user, client). A client that reconnects
		// — claude.ai does this on every "reconnect" and on every fresh
		// browser — replaces its previous password rather than stacking a
		// new one next to it. Core also refuses a duplicate name outright,
		// so this is required for correctness, not just tidiness.
		self::revoke_application_passwords_named( $user->ID, $name );

		$created = \WP_Application_Passwords::create_new_application_password(
			$user->ID,
			array( 'name' => $name )
		);

		if ( is_wp_error( $created ) ) {
			self::send_token_error( 500, 'server_error', $created->get_error_message() );
			return;
		}

		list( $raw_password, ) = $created;

		self::send_json(
			array(
				'access_token' => base64_encode( $user->user_login . ':' . $raw_password ),
				'token_type'   => 'Bearer',
				'scope'        => self::SLUG,
			)
		);
	}

	/**
	 * The application password name for a given client.
	 *
	 * Deterministic per client_id so a reconnect replaces rather than
	 * accumulates (see handle_token()). client_id is client-chosen and has
	 * been observed in the wild as a long base64 blob, so it is reduced to
	 * a safe, bounded label — it is only ever a name shown in the profile
	 * screen, never a lookup key on its own.
	 *
	 * @param string $client_id Client identifier from the authorize request.
	 * @return string
	 */
	public static function application_password_name( $client_id ) {
		$label = preg_replace( '/[^A-Za-z0-9._-]+/', '-', (string) $client_id );
		$label = trim( (string) $label, '-' );

		if ( strlen( $label ) > 40 ) {
			$label = substr( $label, 0, 32 ) . '-' . substr( md5( (string) $client_id ), 0, 7 );
		}

		if ( '' === $label ) {
			$label = 'client';
		}

		return 'Elementor MCP (' . $label . ')';
	}

	/**
	 * Delete every application password of $user_id carrying exactly $name.
	 *
	 * @param int    $user_id User.
	 * @param string $name    Application password name.
	 * @return int Number deleted.
	 */
	private static function revoke_application_passwords_named( $user_id, $name ) {
		$deleted = 0;

		foreach ( (array) \WP_Application_Passwords::get_user_application_passwords( $user_id ) as $item ) {
			if ( isset( $item['name'], $item['uuid'] ) && $item['name'] === $name ) {
				$result = \WP_Application_Passwords::delete_application_password( $user_id, $item['uuid'] );

				if ( true === $result ) {
					$deleted++;
				}
			}
		}

		return $deleted;
	}

	/* ------------------------------------------------------------------ *
	 * /elementor-mcp/revoke (RFC 7009)
	 * ------------------------------------------------------------------ */

	/**
	 * POST revoke: `token=<the access token>`.
	 *
	 * The token is base64(username:app_password); the matching application
	 * password is found by checking the raw password against each of the
	 * user's stored hashes (the same check core itself performs on
	 * authentication) and deleted. Per RFC 7009 §2.2 an unknown or already
	 * revoked token still gets a 200 — the caller's goal (that the token no
	 * longer works) is met either way, and a distinguishable error would
	 * only tell a third party whether a guessed token was ever valid.
	 *
	 * @return void
	 */
	private static function handle_revoke() {
		$token = isset( $_POST['token'] ) ? trim( (string) $_POST['token'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( '' === $token ) {
			self::send_token_error( 400, 'invalid_request', 'token is required.' );
			return;
		}

		$decoded = base64_decode( $token, true );

		if ( false !== $decoded && false !== strpos( $decoded, ':' ) ) {
			list( $username, $raw_password ) = explode( ':', $decoded, 2 );
			$user = ( '' !== $username ) ? get_user_by( 'login', $username ) : false;

			if ( $user && '' !== $raw_password && class_exists( '\WP_Application_Passwords' ) ) {
				foreach ( (array) \WP_Application_Passwords::get_user_application_passwords( $user->ID ) as $item ) {
					if ( isset( $item['password'], $item['uuid'] ) && wp_check_password( $raw_password, $item['password'] ) ) {
						\WP_Application_Passwords::delete_application_password( $user->ID, $item['uuid'] );
						break;
					}
				}
			}
		}

		self::send_json( array( 'revoked' => true ) );
	}

	/**
	 * RFC 7636 S256 verification.
	 *
	 * @param string $verifier  The code_verifier from the token request.
	 * @param string $challenge The code_challenge stored from the authorize request.
	 * @return bool
	 */
	private static function verify_pkce( $verifier, $challenge ) {
		if ( '' === $verifier || '' === $challenge ) {
			return false;
		}

		$computed = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );

		return hash_equals( $challenge, $computed );
	}

	/* ------------------------------------------------------------------ *
	 * Response helpers.
	 * ------------------------------------------------------------------ */

	/**
	 * Redirect back to the client with an OAuth error, per RFC 6749 §4.1.2.1.
	 *
	 * Only ever called after redirect_uri has already been allow-listed, so
	 * this cannot be used to redirect anywhere untrusted.
	 *
	 * @param string $redirect_uri Client redirect URI.
	 * @param string $state        Client state, passed through unchanged.
	 * @param string $error        OAuth error code.
	 * @param string $description  Human-readable detail.
	 * @return void
	 */
	private static function redirect_with_error( $redirect_uri, $state, $error, $description ) {
		$redirect = add_query_arg(
			array_filter(
				array(
					'error'             => $error,
					'error_description' => $description,
					'state'             => '' !== $state ? $state : null,
				)
			),
			$redirect_uri
		);

		// See the comment in handle_authorize_submit(): this target is
		// cross-origin by design, and was already validated by the caller.
		wp_redirect( $redirect ); // phpcs:ignore WordPress.Security.SafeRedirect
	}

	/**
	 * A JSON error response from the token/revoke endpoints, per RFC 6749 §5.2.
	 *
	 * @param int    $status HTTP status.
	 * @param string $error  OAuth error code.
	 * @param string $description Human-readable detail.
	 * @return void
	 */
	private static function send_token_error( $status, $error, $description ) {
		status_header( $status );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( array( 'error' => $error, 'error_description' => $description ) );
	}

	/**
	 * A plain explanatory HTML page, for failures too early to redirect from
	 * (an untrusted or missing redirect_uri).
	 *
	 * @param string $message Message to show.
	 * @param int    $status  HTTP status.
	 * @return void
	 */
	private static function send_error_page( $message, $status ) {
		status_header( $status );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		printf(
			'<!doctype html><html><head><meta charset="utf-8"><title>Connection request rejected</title></head>' .
			'<body style="font:16px sans-serif;max-width:480px;margin:64px auto;padding:0 24px"><h1>Connection request rejected</h1><p>%s</p></body></html>',
			esc_html( $message )
		);
	}

	/**
	 * A 200 JSON response (discovery documents, token, revoke).
	 *
	 * Always uncacheable: a page cache that holds on to a discovery document
	 * keeps handing clients endpoints from whatever build was live when it
	 * was cached, and a cached token response would hand the same credential
	 * to the next requester.
	 *
	 * @param array $data Data to encode.
	 * @return void
	 */
	private static function send_json( array $data ) {
		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( $data, JSON_PRETTY_PRINT );
	}
}
