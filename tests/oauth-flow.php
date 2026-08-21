<?php
/**
 * End-to-end test of the OAuth 2.0 authorization-code + PKCE flow against a
 * stubbed WordPress, driving EMCP_OAuth's private handlers directly via
 * reflection (so the real `exit` in its request router is never reached).
 *
 * Usage: php tests/oauth-flow.php
 */

error_reporting( E_ALL & ~E_DEPRECATED );
define( 'ABSPATH', __DIR__ );
define( 'MINUTE_IN_SECONDS', 60 );

/* ------------------------------------------------------------------ *
 * Minimal WordPress stub: just enough for EMCP_OAuth to run.
 * ------------------------------------------------------------------ */

$GLOBALS['emcp_test_transients']   = array(); // [ key => value ] — expiry not modelled except where a test deletes directly.
$GLOBALS['emcp_test_current_user'] = null;    // null = logged out; otherwise a WP_User-like object.
$GLOBALS['emcp_test_users']        = array(); // [ id => WP_User ]
$GLOBALS['emcp_test_headers']      = array(); // recorded header() calls
$GLOBALS['emcp_test_last_status']  = null;
$GLOBALS['emcp_test_last_redirect'] = null;   // [ 'fn' => 'safe'|'unsafe', 'url' => ... ]
$GLOBALS['emcp_test_last_output']  = '';
$GLOBALS['emcp_test_app_passwords_created'] = array();

class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $code = '', $message = '', $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }

class WP_User { public $ID; public $user_login; public function __construct( $id, $login ) { $this->ID = $id; $this->user_login = $login; } }

class WP_Application_Passwords {
	public static function create_new_application_password( $user_id, $args = array() ) {
		if ( empty( $args['name'] ) ) return new WP_Error( 'application_password_empty_name', 'name required' );
		$raw = 'raw' . bin2hex( random_bytes( 8 ) );
		$item = array( 'uuid' => 'uuid-' . $user_id, 'name' => $args['name'], 'created' => time() );
		$GLOBALS['emcp_test_app_passwords_created'][] = array( 'user_id' => $user_id, 'name' => $args['name'], 'raw' => $raw );
		return array( $raw, $item );
	}
}

function wp_is_application_passwords_available_for_user( $user ) { return true; }
function get_userdata( $id ) { return $GLOBALS['emcp_test_users'][ (int) $id ] ?? false; }
function wp_get_current_user() { return $GLOBALS['emcp_test_current_user'] ?: new WP_User( 0, '' ); }
function is_user_logged_in() { return null !== $GLOBALS['emcp_test_current_user']; }
function get_current_user_id() { return $GLOBALS['emcp_test_current_user'] ? $GLOBALS['emcp_test_current_user']->ID : 0; }
function current_user_can( $cap ) { return $GLOBALS['emcp_test_current_user'] ? ( $GLOBALS['emcp_test_current_user']->_can ?? true ) : false; }

function set_transient( $key, $value, $ttl ) { $GLOBALS['emcp_test_transients'][ $key ] = $value; return true; }
function get_transient( $key ) { return $GLOBALS['emcp_test_transients'][ $key ] ?? false; }
function delete_transient( $key ) { unset( $GLOBALS['emcp_test_transients'][ $key ] ); return true; }

function home_url( $path = '' ) { return 'https://example.test' . $path; }
function untrailingslashit( $s ) { return rtrim( $s, '/' ); }
function get_bloginfo( $show = '' ) { return 'Example Site'; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }

function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return $s; }
function esc_url_raw( $s ) { return $s; }
function apply_filters( $hook, $value ) { return $value; }

function add_query_arg( $args, $url ) {
	$sep = false === strpos( $url, '?' ) ? '?' : '&';
	return $url . $sep . http_build_query( $args );
}

function wp_login_url( $redirect = '' ) {
	$url = 'https://example.test/wp-login.php';
	return '' !== $redirect ? $url . '?redirect_to=' . urlencode( $redirect ) : $url;
}

function wp_create_nonce( $action ) { return substr( md5( 'nonce:' . $action ), 0, 10 ); }
function wp_verify_nonce( $nonce, $action ) { return hash_equals( wp_create_nonce( $action ), (string) $nonce ) ? 1 : false; }
function wp_nonce_field( $action, $name, $referer = true, $echo = true ) {
	$field = sprintf( '<input type="hidden" name="%s" value="%s">', $name, wp_create_nonce( $action ) );
	if ( $echo ) { echo $field; return null; }
	return $field;
}

function status_header( $code ) { $GLOBALS['emcp_test_last_status'] = $code; }
function nocache_headers() {}
// header() is a real PHP built-in and cannot be redeclared. EMCP_OAuth's own
// Content-Type/status header() calls execute as harmless no-ops under the
// CLI SAPI (there is no real connection to send them over). The only header
// this test needs to observe is Location:, which is only ever set through
// wp_redirect()/wp_safe_redirect() below — both real WordPress functions,
// safe to stub — so recording happens there directly instead of by
// intercepting header() itself.
function wp_redirect( $location, $status = 302 ) {
	$GLOBALS['emcp_test_headers'][]     = 'Location: ' . $location;
	$GLOBALS['emcp_test_last_redirect'] = array( 'url' => $location );
	return true;
}
function wp_safe_redirect( $location, $status = 302 ) {
	// Mirrors core's real behaviour closely enough for this test: only
	// same-host (or explicitly allowed) targets pass through unchanged.
	$target_host = wp_parse_url( $location, PHP_URL_HOST );
	$home_host   = wp_parse_url( home_url(), PHP_URL_HOST );
	if ( $target_host && $target_host !== $home_host ) {
		$location = home_url( '/' );
	}
	return wp_redirect( $location, $status );
}

function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }

require __DIR__ . '/../plugin/elementor-mcp-bridge/includes/class-emcp-oauth.php';

/* ------------------------------------------------------------------ *
 * Reflection helpers: call EMCP_OAuth's private static methods and
 * capture whatever they emit, without ever reaching the real `exit`
 * in maybe_handle_request().
 * ------------------------------------------------------------------ */

function call_private( $method, array $args = array() ) {
	$ref = new ReflectionMethod( 'EMCP_OAuth', $method );
	$ref->setAccessible( true );
	return $ref->invokeArgs( null, $args );
}

/** Run a private handler, capturing echoed output and reset per-call state. */
function run_capturing( $method, array $args = array() ) {
	$GLOBALS['emcp_test_headers']       = array();
	$GLOBALS['emcp_test_last_status']   = null;
	$GLOBALS['emcp_test_last_redirect'] = null;

	ob_start();
	$result = call_private( $method, $args );
	$output = ob_get_clean();

	return array( 'result' => $result, 'output' => $output, 'status' => $GLOBALS['emcp_test_last_status'], 'redirect' => $GLOBALS['emcp_test_last_redirect'] );
}

/** RFC 7636 S256, computed independently of EMCP_OAuth's own implementation. */
function pkce_challenge( $verifier ) {
	return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
}

$failures = array();
$passed   = 0;
function check( $label, $condition ) { global $failures, $passed; if ( $condition ) $passed++; else $failures[] = $label; }

$GLOBALS['emcp_test_users'][1] = new WP_User( 1, 'agent' );

$REDIRECT_URI = 'https://claude.ai/api/mcp/auth_callback';

function login_as_agent( $can = true ) {
	$user = new WP_User( 1, 'agent' );
	$user->_can = $can;
	$GLOBALS['emcp_test_current_user'] = $user;
}
function logout() { $GLOBALS['emcp_test_current_user'] = null; }

function pkce_pair() {
	$verifier = bin2hex( random_bytes( 32 ) );
	return array( $verifier, pkce_challenge( $verifier ) );
}

/** Run the full GET-prompt + POST-approve authorize sequence, returning the issued code. */
function authorize_and_approve( $redirect_uri, $challenge, $state = 'xyz', $client_id = 'test-client' ) {
	$params = array(
		'response_type'         => 'code',
		'client_id'              => $client_id,
		'redirect_uri'           => $redirect_uri,
		'code_challenge'         => $challenge,
		'code_challenge_method'  => 'S256',
		'state'                  => $state,
	);

	$_POST = array_merge( $params, array( 'emcp_approve' => '1', 'emcp_nonce' => wp_create_nonce( 'emcp_oauth_authorize' ) ) );
	$run   = run_capturing( 'handle_authorize_submit' );
	$_POST = array();

	if ( ! $run['redirect'] ) return null;

	$query = array();
	parse_str( (string) wp_parse_url( $run['redirect']['url'], PHP_URL_QUERY ), $query );

	return $query['code'] ?? null;
}

/* ==================================================================
 * Discovery metadata
 * ================================================================== */

$as_meta = json_decode( run_capturing( 'handle_authorization_server_metadata' )['output'], true );
check( 'AS metadata: issuer matches the site', 'https://example.test' === $as_meta['issuer'] );
check( 'AS metadata: authorization_endpoint is /authorize', 'https://example.test/authorize' === $as_meta['authorization_endpoint'] );
check( 'AS metadata: token_endpoint is /token', 'https://example.test/token' === $as_meta['token_endpoint'] );
check( 'AS metadata: only S256 PKCE is advertised', array( 'S256' ) === $as_meta['code_challenge_methods_supported'] );
check( 'AS metadata: no client auth required (public client)', array( 'none' ) === $as_meta['token_endpoint_auth_methods_supported'] );

$rs_meta = json_decode( run_capturing( 'handle_protected_resource_metadata' )['output'], true );
check( 'RS metadata: points at the /mcp resource', 'https://example.test/wp-json/elementor-mcp/v1/mcp' === $rs_meta['resource'] );
check( 'RS metadata: names this site as the authorization server', array( 'https://example.test' ) === $rs_meta['authorization_servers'] );

/* ==================================================================
 * /authorize — GET (prompt)
 * ================================================================== */

logout();
$_GET = array(
	'response_type' => 'code', 'client_id' => 'c', 'redirect_uri' => $REDIRECT_URI,
	'code_challenge' => 'x', 'code_challenge_method' => 'S256', 'state' => 's1',
);
$run = run_capturing( 'handle_authorize_prompt' );
check( 'authorize (logged out): redirects to login, not the consent screen', $run['redirect'] && false !== strpos( $run['redirect']['url'], 'wp-login.php' ) );
check( 'authorize (logged out): preserves the original request in redirect_to', false !== strpos( urldecode( urldecode( $run['redirect']['url'] ) ), $REDIRECT_URI ) );

login_as_agent( false ); // logged in, but lacks edit_posts
$run = run_capturing( 'handle_authorize_prompt' );
check( 'authorize (no capability): refuses with 403, not a redirect', 403 === $run['status'] && null === $run['redirect'] );

login_as_agent( true );
$run = run_capturing( 'handle_authorize_prompt' );
check( 'authorize (valid): renders a consent screen', false !== strpos( $run['output'], 'Approve' ) && false !== strpos( $run['output'], 'Deny' ) );
check( 'authorize (valid): shows which account is granting access', false !== strpos( $run['output'], 'agent' ) );
check( 'authorize (valid): shows the redirect_uri for the user to verify', false !== strpos( $run['output'], $REDIRECT_URI ) );
check( 'authorize (valid): carries the client\'s state through as a hidden field', false !== strpos( $run['output'], 'value="s1"' ) );

$_GET['redirect_uri'] = 'https://evil.example/steal';
$run = run_capturing( 'handle_authorize_prompt' );
check( 'authorize (untrusted redirect_uri): rejected with an error PAGE, not a redirect', 400 === $run['status'] && null === $run['redirect'] );
check( 'authorize (untrusted redirect_uri): never echoes it into a Location header', empty( array_filter( $GLOBALS['emcp_test_headers'], fn( $h ) => false !== stripos( $h, 'evil.example' ) ) ) );

$_GET = array( 'response_type' => 'token', 'client_id' => 'c', 'redirect_uri' => $REDIRECT_URI, 'code_challenge' => 'x', 'code_challenge_method' => 'plain', 'state' => 's2' );
$run = run_capturing( 'handle_authorize_prompt' );
check( 'authorize (bad response_type + plain PKCE): redirects back to the client with invalid_request', $run['redirect'] && false !== strpos( $run['redirect']['url'], 'error=invalid_request' ) );
check( 'authorize (bad params): still carries state back', false !== strpos( $run['redirect']['url'], 'state=s2' ) );

$_GET = array();

/* ==================================================================
 * /authorize — POST (approve / deny)
 * ================================================================== */

logout();
list( $verifier, $challenge ) = pkce_pair();
$approve_params = array(
	'response_type' => 'code', 'client_id' => 'c', 'redirect_uri' => $REDIRECT_URI,
	'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'state' => 's3',
);
$_POST = array_merge( $approve_params, array( 'emcp_approve' => '1', 'emcp_nonce' => wp_create_nonce( 'emcp_oauth_authorize' ) ) );
$run = run_capturing( 'handle_authorize_submit' );
check( 'authorize submit (not logged in): refused, not silently approved', 403 === $run['status'] && null === $run['redirect'] );

login_as_agent( true );

$_POST = array_merge( $approve_params, array( 'emcp_approve' => '1', 'emcp_nonce' => 'wrong-nonce' ) );
$run = run_capturing( 'handle_authorize_submit' );
check( 'authorize submit (bad nonce): refused', 400 === $run['status'] && null === $run['redirect'] );
check( 'authorize submit (bad nonce): no code was issued', empty( $GLOBALS['emcp_test_transients'] ) );

$_POST = array_merge( $approve_params, array( 'emcp_nonce' => wp_create_nonce( 'emcp_oauth_authorize' ) ) ); // no emcp_approve => deny
$run = run_capturing( 'handle_authorize_submit' );
check( 'authorize submit (deny): redirects with access_denied', $run['redirect'] && false !== strpos( $run['redirect']['url'], 'error=access_denied' ) );
check( 'authorize submit (deny): issues no code', empty( $GLOBALS['emcp_test_transients'] ) );

$_POST = array_merge( $approve_params, array( 'emcp_approve' => '1', 'emcp_nonce' => wp_create_nonce( 'emcp_oauth_authorize' ) ) );
$run = run_capturing( 'handle_authorize_submit' );
check( 'authorize submit (approve): redirects to the client redirect_uri', $run['redirect'] && 0 === strpos( $run['redirect']['url'], $REDIRECT_URI ) );
check( 'authorize submit (approve): carries a code', $run['redirect'] && false !== strpos( $run['redirect']['url'], 'code=' ) );
check( 'authorize submit (approve): carries state through unchanged', false !== strpos( $run['redirect']['url'], 'state=s3' ) );
check( 'authorize submit (approve): stores exactly one pending code', 1 === count( $GLOBALS['emcp_test_transients'] ) );

$stored = array_values( $GLOBALS['emcp_test_transients'] )[0];
check( 'authorize submit: stored record ties the code to the approving user', 1 === $stored['user_id'] );
check( 'authorize submit: stored record keeps the PKCE challenge for later verification', $challenge === $stored['code_challenge'] );

$_POST = array();
$GLOBALS['emcp_test_transients'] = array();

/* ==================================================================
 * /token
 * ================================================================== */

logout(); // token exchange must not depend on any browser session

// Missing params.
$_POST = array( 'grant_type' => 'authorization_code' );
$run = run_capturing( 'handle_token' );
$body = json_decode( $run['output'], true );
check( 'token (missing params): 400 invalid_request', 400 === $run['status'] && 'invalid_request' === $body['error'] );

// Unsupported grant type — explicit, honest limitation.
$_POST = array( 'grant_type' => 'refresh_token', 'refresh_token' => 'whatever' );
$run = run_capturing( 'handle_token' );
$body = json_decode( $run['output'], true );
check( 'token (refresh_token grant): explicitly unsupported, not a silent failure', 'unsupported_grant_type' === $body['error'] );

// Unknown code.
$_POST = array( 'grant_type' => 'authorization_code', 'code' => 'nope', 'redirect_uri' => $REDIRECT_URI, 'code_verifier' => 'x' );
$run = run_capturing( 'handle_token' );
$body = json_decode( $run['output'], true );
check( 'token (unknown code): invalid_grant', 400 === $run['status'] && 'invalid_grant' === $body['error'] );

// Full valid round trip.
login_as_agent( true );
list( $verifier, $challenge ) = pkce_pair();
$code = authorize_and_approve( $REDIRECT_URI, $challenge, 'roundtrip' );
check( 'round trip: a code was actually issued', is_string( $code ) && '' !== $code );
logout();

$_POST = array( 'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $REDIRECT_URI, 'code_verifier' => $verifier );
$run = run_capturing( 'handle_token' );
$token_response = json_decode( $run['output'], true );
check( 'token (valid): 200 with an access_token', 200 === $run['status'] && ! empty( $token_response['access_token'] ) );
check( 'token (valid): token_type is Bearer', 'Bearer' === ( $token_response['token_type'] ?? null ) );

$decoded = base64_decode( $token_response['access_token'] ?? '', true );
check( 'token (valid): access_token decodes to "username:apppassword"', $decoded && false !== strpos( $decoded, ':' ) );
list( $issued_user, $issued_pw ) = explode( ':', (string) $decoded, 2 );
check( 'token (valid): username matches the account that approved consent', 'agent' === $issued_user );
check( 'token (valid): a real application password was minted for that user', 1 === count( $GLOBALS['emcp_test_app_passwords_created'] ) && 1 === $GLOBALS['emcp_test_app_passwords_created'][0]['user_id'] );
check( 'token (valid): the raw password in the token matches what was minted', $issued_pw === $GLOBALS['emcp_test_app_passwords_created'][0]['raw'] );
check( 'token (valid): the application password is named for easy identification/revocation', false !== strpos( $GLOBALS['emcp_test_app_passwords_created'][0]['name'], 'Claude MCP' ) );

// Replay: the same code cannot be exchanged twice.
$_POST = array( 'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $REDIRECT_URI, 'code_verifier' => $verifier );
$run = run_capturing( 'handle_token' );
$body = json_decode( $run['output'], true );
check( 'token (replay): a used code is rejected', 'invalid_grant' === $body['error'] );

// Wrong PKCE verifier.
login_as_agent( true );
list( $verifier2, $challenge2 ) = pkce_pair();
$code2 = authorize_and_approve( $REDIRECT_URI, $challenge2, 's4' );
logout();
$_POST = array( 'grant_type' => 'authorization_code', 'code' => $code2, 'redirect_uri' => $REDIRECT_URI, 'code_verifier' => 'totally-the-wrong-verifier' );
$run = run_capturing( 'handle_token' );
$body = json_decode( $run['output'], true );
check( 'token (wrong PKCE verifier): rejected', 'invalid_grant' === $body['error'] );
$_POST['code_verifier'] = $verifier2; // now try the CORRECT verifier against the SAME (already-consumed) code
$run2 = run_capturing( 'handle_token' );
$body2 = json_decode( $run2['output'], true );
check( 'token (wrong PKCE verifier consumed the code): the correct verifier no longer works either', 'invalid_grant' === $body2['error'] );

// Mismatched redirect_uri.
login_as_agent( true );
list( $verifier3, $challenge3 ) = pkce_pair();
$code3 = authorize_and_approve( $REDIRECT_URI, $challenge3, 's5' );
logout();
$_POST = array( 'grant_type' => 'authorization_code', 'code' => $code3, 'redirect_uri' => 'https://claude.ai/somewhere/else', 'code_verifier' => $verifier3 );
$run = run_capturing( 'handle_token' );
$body = json_decode( $run['output'], true );
check( 'token (redirect_uri mismatch): rejected', 'invalid_grant' === $body['error'] );

$_POST = array();

/* ==================================================================
 * Closing the loop: the token this endpoint issues must be exactly what
 * EMCP_Auth::authenticate_bearer() already knows how to validate, with no
 * new code needed on that side.
 * ================================================================== */

function wp_authenticate_application_password( $input_user, $username, $password ) {
	if ( $input_user instanceof WP_User ) return $input_user;
	foreach ( $GLOBALS['emcp_test_app_passwords_created'] as $entry ) {
		$user = $GLOBALS['emcp_test_users'][ $entry['user_id'] ] ?? null;
		if ( $user && $user->user_login === $username && hash_equals( $entry['raw'], $password ) ) {
			return $user;
		}
	}
	return new WP_Error( 'invalid_credentials', 'bad creds' );
}
function get_user_by( $field, $value ) {
	foreach ( $GLOBALS['emcp_test_users'] as $user ) {
		if ( 'login' === $field && $user->user_login === $value ) return $user;
	}
	return false;
}

require __DIR__ . '/../plugin/elementor-mcp-bridge/includes/class-emcp-auth.php';

login_as_agent( true );
list( $verifier4, $challenge4 ) = pkce_pair();
$code4 = authorize_and_approve( $REDIRECT_URI, $challenge4, 's6' );
logout();

$_POST = array( 'grant_type' => 'authorization_code', 'code' => $code4, 'redirect_uri' => $REDIRECT_URI, 'code_verifier' => $verifier4 );
$final_token = json_decode( run_capturing( 'handle_token' )['output'], true )['access_token'] ?? null;
$_POST = array();

check( 'closing the loop: a token was issued to test against the resource server', ! empty( $final_token ) );

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $final_token;
$resolved = EMCP_Auth::authenticate_bearer( false );
unset( $_SERVER['HTTP_AUTHORIZATION'] );

check(
	'closing the loop: the OAuth-issued token authenticates through the existing bearer shim with zero new resource-server code',
	1 === $resolved
);

/* ------------------------------------------------------------------ *
 * Report.
 * ------------------------------------------------------------------ */

printf( "%d passed, %d failed\n", $passed, count( $failures ) );

if ( $failures ) {
	echo "\nFAILURES:\n";
	foreach ( $failures as $failure ) echo "  - $failure\n";
	exit( 1 );
}

echo "OAUTH FLOW OK\n";
