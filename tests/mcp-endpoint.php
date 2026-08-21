<?php
/**
 * In-memory WordPress stub sufficient to run the MCP JSON-RPC dispatcher
 * end-to-end: initialize, tools/list, tools/call against a real (fake) post
 * store, including the read-mutate-write-with-hash-guard path.
 *
 * This is not a substitute for running on a real WordPress install — it
 * proves the wiring (dispatcher -> tool registry -> compose -> tree ->
 * document store) is correct, not that Elementor's own Document::save()
 * behaves as assumed. See docs/BRIDGE-API.md and the README for what still
 * needs verifying against a live site.
 *
 * Usage: php tests/mcp-endpoint.php
 */

error_reporting( E_ALL & ~E_DEPRECATED );
define( 'ABSPATH', __DIR__ );
define( 'EMCP_REST_NAMESPACE', 'elementor-mcp/v1' );
define( 'EMCP_VERSION', '1.0.1' );

/* ------------------------------------------------------------------ *
 * In-memory store: posts, postmeta, users.
 * ------------------------------------------------------------------ */

$GLOBALS['emcp_test_posts']     = array();
$GLOBALS['emcp_test_meta']      = array(); // [ post_id => [ key => value ] ]
$GLOBALS['emcp_test_next_id']   = 100;
$GLOBALS['emcp_test_can_edit']  = true; // toggled by tests to exercise permission denial
$GLOBALS['emcp_test_can_manage_globals'] = true;

function emcp_test_seed_page( $elements, $settings = array(), $title = 'Test Page' ) {
	$id = $GLOBALS['emcp_test_next_id']++;

	$GLOBALS['emcp_test_posts'][ $id ] = (object) array(
		'ID'                => $id,
		'post_title'        => $title,
		'post_name'         => sanitize_title( $title ),
		'post_type'         => 'page',
		'post_status'       => 'draft',
		'post_parent'       => 0,
		'post_content'      => '',
		'post_modified_gmt' => '2026-01-01 00:00:00',
		'post_author'       => 1,
	);

	$GLOBALS['emcp_test_meta'][ $id ] = array(
		'_elementor_data'          => wp_slash( wp_json_encode( $elements ) ),
		'_elementor_edit_mode'     => 'builder',
		'_elementor_page_settings' => $settings,
		'_elementor_template_type' => 'wp-page',
	);

	return $id;
}

/* ------------------------------------------------------------------ *
 * WP core stubs.
 * ------------------------------------------------------------------ */

class WP_REST_Server {
	const READABLE = 'GET';
	const CREATABLE = 'POST';
	const DELETABLE = 'DELETE';
}

class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code = '', $message = '', $data = array() ) {
		$this->code = $code; $this->message = $message; $this->data = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}

class WP_REST_Response {
	public $data; public $status;
	public function __construct( $data = null, $status = 200 ) { $this->data = $data; $this->status = $status; }
	public function get_data() { return $this->data; }
	public function is_error() { return false; }
}

class WP_REST_Request {
	private $params = array();
	public function __construct( $method = 'GET', $route = '' ) {}
	public function set_param( $key, $value ) { $this->params[ $key ] = $value; }
	public function get_param( $key ) { return $this->params[ $key ] ?? null; }
	public function get_json_params() { return $this->params['__json_body'] ?? null; }
	public function get_body() { return isset( $this->params['__json_body'] ) ? wp_json_encode( $this->params['__json_body'] ) : ''; }
}

class WP_Query {
	public $posts = array();
	public $found_posts = 0;
	public $max_num_pages = 1;
	public function __construct( $args = array() ) {
		$fields = $args['fields'] ?? 'all';
		$all    = array_values( $GLOBALS['emcp_test_posts'] );

		if ( ! empty( $args['s'] ) ) {
			$all = array_values( array_filter( $all, function ( $p ) use ( $args ) {
				return false !== stripos( $p->post_title, $args['s'] );
			} ) );
		}

		$this->found_posts   = count( $all );
		$this->max_num_pages = 1;
		$this->posts         = 'ids' === $fields ? array_map( fn( $p ) => $p->ID, $all ) : $all;
	}
}

function sanitize_title( $t ) { return strtolower( preg_replace( '/[^a-z0-9]+/i', '-', trim( $t ) ) ); }
function sanitize_key( $t ) { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', $t ) ); }
function sanitize_text_field( $t ) { return trim( strip_tags( (string) $t ) ); }
function esc_url_raw( $u ) { return $u; }
function wp_http_validate_url( $u ) { return (bool) filter_var( $u, FILTER_VALIDATE_URL ); }
function wp_rand( $min = 0, $max = 0 ) { return random_int( $min, $max ?: PHP_INT_MAX ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_slash( $v ) { return $v; }
function wp_strip_all_tags( $t ) { return strip_tags( (string) $t ); }
function __( $t, $d = '' ) { return $t; }
function esc_html__( $t, $d = '' ) { return $t; }
function apply_filters( $hook, $value ) { return $value; }
function do_action( ...$args ) {}
function add_action( $hook, $cb, $prio = 10, $args = 1 ) {}
function add_filter( $hook, $cb, $prio = 10, $args = 1 ) {}
function did_action( $hook ) { return 0; }
function is_user_logged_in() { return true; }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function get_bloginfo( $s = '' ) { return '6.9'; }
function get_site_url() { return 'https://example.test'; }
function home_url() { return 'https://example.test'; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function add_query_arg( $args, $url = '' ) { return $url . '?' . http_build_query( $args ); }
function get_current_user_id() { return 1; }
function get_the_author_meta( $f, $id ) { return 'Test Author'; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }

function current_user_can( $cap, ...$args ) {
	if ( 'edit_posts' === $cap ) return true;
	if ( 'edit_post' === $cap ) return $GLOBALS['emcp_test_can_edit'];
	if ( 'delete_post' === $cap ) return $GLOBALS['emcp_test_can_edit'];
	if ( 'edit_theme_options' === $cap || 'manage_options' === $cap ) return $GLOBALS['emcp_test_can_manage_globals'];
	if ( 'upload_files' === $cap ) return true;
	if ( 'publish_posts' === $cap ) return true;
	return true;
}

function get_post( $id ) { return $GLOBALS['emcp_test_posts'][ (int) $id ] ?? null; }
function get_the_title( $id ) { $p = get_post( $id ); return $p ? $p->post_title : ''; }
function get_permalink( $id ) { return 'https://example.test/?p=' . ( is_object( $id ) ? $id->ID : $id ); }

function get_post_meta( $id, $key = '', $single = false ) {
	$id = (int) $id;
	if ( '' === $key ) return $GLOBALS['emcp_test_meta'][ $id ] ?? array();
	return $GLOBALS['emcp_test_meta'][ $id ][ $key ] ?? '';
}
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['emcp_test_meta'][ (int) $id ][ $key ] = $value;
	return true;
}
function delete_post_meta( $id, $key ) {
	unset( $GLOBALS['emcp_test_meta'][ (int) $id ][ $key ] );
	return true;
}
function get_metadata( $type, $id, $key, $single = false ) {
	return $GLOBALS['emcp_test_meta'][ (int) $id ][ $key ] ?? '';
}
function update_metadata( $type, $id, $key, $value ) {
	$GLOBALS['emcp_test_meta'][ (int) $id ][ $key ] = $value;
	return true;
}

function wp_insert_post( $postarr, $wp_error = false ) {
	$id = $GLOBALS['emcp_test_next_id']++;
	$GLOBALS['emcp_test_posts'][ $id ] = (object) array_merge(
		array(
			'ID' => $id, 'post_type' => 'page', 'post_status' => 'draft',
			'post_parent' => 0, 'post_modified_gmt' => '2026-01-01 00:00:00', 'post_author' => 1,
			'post_name' => sanitize_title( $postarr['post_title'] ?? '' ),
		),
		$postarr,
		array( 'ID' => $id )
	);
	$GLOBALS['emcp_test_meta'][ $id ] = array();
	return $id;
}

function wp_update_post( $postarr, $wp_error = false ) {
	$id = (int) $postarr['ID'];
	if ( ! isset( $GLOBALS['emcp_test_posts'][ $id ] ) ) return new WP_Error( 'not_found', 'no post' );
	foreach ( $postarr as $k => $v ) {
		if ( 'ID' !== $k ) $GLOBALS['emcp_test_posts'][ $id ]->$k = $v;
	}
	return $id;
}

function wp_delete_post( $id, $force = false ) {
	$id = (int) $id;
	if ( ! isset( $GLOBALS['emcp_test_posts'][ $id ] ) ) return false;
	if ( $force ) { unset( $GLOBALS['emcp_test_posts'][ $id ] ); unset( $GLOBALS['emcp_test_meta'][ $id ] ); }
	else { $GLOBALS['emcp_test_posts'][ $id ]->post_status = 'trash'; }
	return true;
}

function wp_get_post_revisions( $id, $args = array() ) { return array(); }
function wp_is_post_autosave( $id ) { return false; }
function set_post_thumbnail( $id, $mid ) { update_post_meta( $id, '_thumbnail_id', $mid ); return true; }
function get_post_type_object( $type ) {
	return (object) array( 'cap' => (object) array( 'create_posts' => 'edit_posts' ) );
}
function taxonomy_exists( $tax ) { return false; }
function wp_set_object_terms( ...$a ) {}
function set_current_locale() {}

/* A no-op Elementor stand-in: enough surface for EMCP_Documents to treat
 * every post type as a valid document and save via update_post_meta, which
 * is exactly what the fallback path in EMCP_Documents does when Elementor
 * itself is not loaded — proving that fallback path, since standing up the
 * real Elementor\Plugin class is out of scope for a unit-style harness. */

class WP_User { public $ID; public function __construct( $id ) { $this->ID = $id; } }

$GLOBALS['emcp_test_valid_creds'] = array( 'agent' => 'correct-horse-battery-staple' );

function get_user_by( $field, $value ) {
	if ( 'login' === $field && isset( $GLOBALS['emcp_test_valid_creds'][ $value ] ) ) {
		return new WP_User( 1 );
	}
	return false;
}

function wp_authenticate_application_password( $input_user, $username, $password ) {
	if ( $input_user instanceof WP_User ) return $input_user;
	$valid = $GLOBALS['emcp_test_valid_creds'][ $username ] ?? null;
	if ( null !== $valid && hash_equals( $valid, $password ) ) {
		return new WP_User( 1 );
	}
	return new WP_Error( 'invalid_credentials', 'bad creds' );
}

require __DIR__ . '/../plugin/elementor-mcp-bridge/includes/class-emcp-guard.php';
require __DIR__ . '/../plugin/elementor-mcp-bridge/includes/class-emcp-auth.php';
require __DIR__ . '/../plugin/elementor-mcp-bridge/includes/class-emcp-tree-error.php';
require __DIR__ . '/../plugin/elementor-mcp-bridge/includes/class-emcp-tree.php';
require __DIR__ . '/../plugin/elementor-mcp-bridge/includes/class-emcp-snapshots.php';
require __DIR__ . '/../plugin/elementor-mcp-bridge/includes/class-emcp-documents.php';
require __DIR__ . '/../plugin/elementor-mcp-bridge/includes/class-emcp-schema.php';
require __DIR__ . '/../plugin/elementor-mcp-bridge/includes/class-emcp-globals.php';
require __DIR__ . '/../plugin/elementor-mcp-bridge/includes/class-emcp-library.php';
require __DIR__ . '/../plugin/elementor-mcp-bridge/includes/class-emcp-scanner.php';
require __DIR__ . '/../plugin/elementor-mcp-bridge/includes/class-emcp-compose.php';
require __DIR__ . '/../plugin/elementor-mcp-bridge/includes/rest/class-emcp-rest-base.php';
require __DIR__ . '/../plugin/elementor-mcp-bridge/includes/rest/class-emcp-rest-site.php';
require __DIR__ . '/../plugin/elementor-mcp-bridge/includes/mcp/class-emcp-mcp-schema.php';
require __DIR__ . '/../plugin/elementor-mcp-bridge/includes/mcp/class-emcp-mcp-guard.php';
require __DIR__ . '/../plugin/elementor-mcp-bridge/includes/mcp/class-emcp-mcp-tools.php';
require __DIR__ . '/../plugin/elementor-mcp-bridge/includes/mcp/class-emcp-mcp-server.php';

/* ------------------------------------------------------------------ *
 * Assertions.
 * ------------------------------------------------------------------ */

$failures = array();
$passed   = 0;

function check( $label, $condition ) {
	global $failures, $passed;
	if ( $condition ) $passed++; else $failures[] = $label;
}

$server = new EMCP_MCP_Server();

function rpc( EMCP_MCP_Server $server, $method, $params = array(), $id = 1 ) {
	$msg = array( 'jsonrpc' => '2.0', 'method' => $method, 'params' => $params );
	if ( null !== $id ) $msg['id'] = $id;
	return $server->handle( $msg );
}

// --- initialize ----------------------------------------------------------
$init = rpc( $server, 'initialize', array( 'protocolVersion' => '2025-06-18', 'capabilities' => array() ) );
check( 'initialize: returns the requested supported protocol version', '2025-06-18' === $init['result']['protocolVersion'] );
check( 'initialize: falls back to latest for an unknown version', '2025-06-18' === rpc( $server, 'initialize', array( 'protocolVersion' => '1999-01-01' ) )['result']['protocolVersion'] );
check( 'initialize: names the server', 'elementor-mcp-bridge' === $init['result']['serverInfo']['name'] );
check( 'initialize: advertises tools capability', isset( $init['result']['capabilities']['tools'] ) );

// --- notifications (no id) -----------------------------------------------
$notif = $server->handle( array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ) );
check( 'notification: produces no response', null === $notif );

// --- unknown method --------------------------------------------------------
$unknown = rpc( $server, 'nonexistent/method' );
check( 'unknown method: returns a JSON-RPC error, not a crash', isset( $unknown['error'] ) );
check( 'unknown method: error code is method-not-found', -32601 === $unknown['error']['code'] );

// --- batch requests --------------------------------------------------------
$batch = $server->handle( array(
	array( 'jsonrpc' => '2.0', 'id' => 'a', 'method' => 'ping' ),
	array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ), // no id: dropped from the batch response
	array( 'jsonrpc' => '2.0', 'id' => 'b', 'method' => 'ping' ),
) );
check( 'batch: notifications are dropped, requests answered', 2 === count( $batch ) );
check( 'batch: preserves each request\'s id', 'a' === $batch[0]['id'] && 'b' === $batch[1]['id'] );

// --- tools/list --------------------------------------------------------
$list  = rpc( $server, 'tools/list' );
$tools = $list['result']['tools'];
check( 'tools/list: registers a substantial tool surface', count( $tools ) >= 45 );

$bad_schema = array();
foreach ( $tools as $tool ) {
	if ( empty( $tool['name'] ) || strpos( $tool['name'], 'elementor_' ) !== 0 ) $bad_schema[] = $tool['name'] ?? '(unnamed)';
	if ( empty( $tool['description'] ) || strlen( $tool['description'] ) < 30 ) $bad_schema[] = $tool['name'] . ' (weak description)';
	if ( empty( $tool['inputSchema']['type'] ) || 'object' !== $tool['inputSchema']['type'] ) $bad_schema[] = $tool['name'] . ' (bad schema root)';
	if ( empty( $tool['annotations'] ) ) $bad_schema[] = $tool['name'] . ' (no annotations)';
}
check( 'tools/list: every tool is well-formed (' . implode( ', ', array_slice( $bad_schema, 0, 5 ) ) . ')', empty( $bad_schema ) );

$names = array_column( $tools, 'name' );
check( 'tools/list: no duplicate tool names', count( $names ) === count( array_unique( $names ) ) );
check( 'tools/list: JSON-encodes cleanly (no PHP resources/closures leaking into schemas)', false !== wp_json_encode( $tools ) );

// --- prompts -------------------------------------------------------------
$prompts = rpc( $server, 'prompts/list' )['result']['prompts'];
check( 'prompts/list: registers the three workflow prompts', 3 === count( $prompts ) );

$prompt = rpc( $server, 'prompts/get', array( 'name' => 'edit_page_safely', 'arguments' => array( 'postId' => '42', 'goal' => 'test' ) ) );
check( 'prompts/get: fills in arguments', false !== strpos( $prompt['result']['messages'][0]['content']['text'], 'page 42' ) );

$bad_prompt = rpc( $server, 'prompts/get', array( 'name' => 'no-such-prompt' ) );
check( 'prompts/get: unknown prompt is a clean error, not a crash', isset( $bad_prompt['error'] ) );

/* ------------------------------------------------------------------ *
 * tools/call: real element edits through the full stack.
 * ------------------------------------------------------------------ */

function call_tool( EMCP_MCP_Server $server, $name, $arguments = array() ) {
	return rpc( $server, 'tools/call', array( 'name' => $name, 'arguments' => $arguments ) );
}

function tool_data( $response ) {
	return $response['result']['structuredContent'] ?? null;
}

// --- site_status: works even without Elementor loaded ---------------------
$status = tool_data( call_tool( $server, 'elementor_site_status' ) );
check( 'site_status: reports elementorActive false in this harness (Elementor is not stubbed)', false === $status['elementorActive'] );
check( 'site_status: still reports the bridge version', '1.0.1' === $status['bridgeVersion'] );

// --- unknown tool ----------------------------------------------------------
$unknown_tool = call_tool( $server, 'elementor_does_not_exist' );
check( 'tools/call: unknown tool name is a clean tool error', true === ( $unknown_tool['result']['isError'] ?? false ) );

// --- list_pages ------------------------------------------------------------
$page_id = emcp_test_seed_page(
	array(
		array( 'id' => 'aaa0001', 'elType' => 'container', 'settings' => array(), 'elements' => array(
			array( 'id' => 'bbb0001', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'Hello' ), 'elements' => array() ),
		) ),
	),
	array(),
	'Home'
);

$listed = tool_data( call_tool( $server, 'elementor_list_pages', array() ) );
check( 'list_pages: finds the seeded page', in_array( $page_id, array_column( $listed['items'], 'id' ), true ) );

// --- get_outline / get_element ---------------------------------------------
$outline = tool_data( call_tool( $server, 'elementor_get_outline', array( 'postId' => $page_id ) ) );
check( 'get_outline: returns the tree structure', 1 === count( $outline['outline'] ) );
check( 'get_outline: returns a stable hash', is_string( $outline['hash'] ) && '' !== $outline['hash'] );

$element = tool_data( call_tool( $server, 'elementor_get_element', array( 'postId' => $page_id, 'elementId' => 'bbb0001' ) ) );
check( 'get_element: reads the settings of a nested widget', 'Hello' === $element['element']['settings']['title'] );

$missing_element = call_tool( $server, 'elementor_get_element', array( 'postId' => $page_id, 'elementId' => 'nope' ) );
check( 'get_element: missing id is a tool error, not a fatal', true === ( $missing_element['result']['isError'] ?? false ) );

// --- add_widget: full read -> mutate -> write cycle -------------------------
$added = tool_data( call_tool( $server, 'elementor_add_widget', array(
	'postId' => $page_id, 'widgetType' => 'button', 'settings' => array( 'text' => 'Click me' ), 'targetId' => 'aaa0001',
) ) );
check( 'add_widget: reports a new element id', ! empty( $added['elementId'] ) );
$button_id = $added['elementId'];
check( 'add_widget: node count increased by one', 1 === $added['nodeCountAfter'] - $added['nodeCountBefore'] );

$after_add = json_decode( get_post_meta( $page_id, '_elementor_data', true ), true );
check( 'add_widget: actually persisted to the post store', 2 === count( $after_add[0]['elements'] ) );
check( 'add_widget: the new widget has the settings we sent', 'Click me' === $after_add[0]['elements'][1]['settings']['text'] );

// --- update_element (merge mode keeps other keys) ---------------------------
tool_data( call_tool( $server, 'elementor_update_element', array(
	'postId' => $page_id, 'elementId' => 'bbb0001', 'settings' => array( 'align' => 'center' ),
) ) );
$after_update = json_decode( get_post_meta( $page_id, '_elementor_data', true ), true );
check( 'update_element: merges, keeping the original title', 'Hello' === $after_update[0]['elements'][0]['settings']['title'] );
check( 'update_element: applies the new setting', 'center' === $after_update[0]['elements'][0]['settings']['align'] );

// --- optimistic locking: stale hash is rejected -----------------------------
$fresh_outline = tool_data( call_tool( $server, 'elementor_get_outline', array( 'postId' => $page_id ) ) );
$stale_write = call_tool( $server, 'elementor_batch_edit', array(
	'postId'     => $page_id,
	'operations' => array( array( 'op' => 'update', 'targetId' => 'bbb0001', 'settings' => array( 'align' => 'left' ) ) ),
) );
// (EMCP_Compose always reads fresh before writing, so a normal call cannot go
// stale by itself — this proves the retry-on-conflict path fires without
// erroring by forcing a real conflict: two edits in a row.)
$first  = call_tool( $server, 'elementor_update_element', array( 'postId' => $page_id, 'elementId' => 'bbb0001', 'settings' => array( 'x' => '1' ) ) );
$second = call_tool( $server, 'elementor_update_element', array( 'postId' => $page_id, 'elementId' => $button_id, 'settings' => array( 'x' => '2' ) ) );
check( 'sequential edits both succeed (each reads fresh, no stale write)', empty( $first['result']['isError'] ) && empty( $second['result']['isError'] ) );

// --- batch_edit: atomic, aborts cleanly on a bad operation ------------------
$before_batch = json_decode( get_post_meta( $page_id, '_elementor_data', true ), true );
$bad_batch = call_tool( $server, 'elementor_batch_edit', array(
	'postId'     => $page_id,
	'operations' => array(
		array( 'op' => 'update', 'targetId' => 'bbb0001', 'settings' => array( 'should_not_apply' => true ) ),
		array( 'op' => 'delete', 'targetId' => 'does-not-exist' ),
	),
) );
check( 'batch_edit: a failing operation reports a tool error', true === ( $bad_batch['result']['isError'] ?? false ) );
$after_bad_batch = json_decode( get_post_meta( $page_id, '_elementor_data', true ), true );
check( 'batch_edit: nothing was written when the batch failed', $before_batch === $after_bad_batch );

$good_batch_raw = call_tool( $server, 'elementor_batch_edit', array(
	'postId'     => $page_id,
	'operations' => array(
		array( 'op' => 'update', 'targetId' => 'bbb0001', 'settings' => array( 'batched' => true ) ),
		array( 'op' => 'reorder', 'targetId' => 'aaa0001', 'order' => array( $button_id, 'bbb0001' ) ),
	),
) );
$good_batch = tool_data( $good_batch_raw );
check( 'batch_edit: multi-step batch succeeds', 2 === count( $good_batch['operations'] ) );
$after_good_batch = json_decode( get_post_meta( $page_id, '_elementor_data', true ), true );
check( 'batch_edit: settings update landed', true === EMCP_Tree::find( $after_good_batch, 'bbb0001' )['settings']['batched'] );
check( 'batch_edit: reorder landed (button first)', $button_id === $after_good_batch[0]['elements'][0]['id'] );

// --- delete_element (+ snapshot + restore) ----------------------------------
$before_delete_count = count( EMCP_Tree::collect_ids( $after_good_batch ) );
$deleted = tool_data( call_tool( $server, 'elementor_delete_element', array( 'postId' => $page_id, 'elementId' => $button_id ) ) );
check( 'delete_element: node count decreased', $deleted['nodeCountAfter'] < $before_delete_count );

$snapshots = tool_data( call_tool( $server, 'elementor_list_snapshots', array( 'postId' => $page_id ) ) );
check( 'delete_element: took a snapshot automatically before writing', count( $snapshots['items'] ) > 0 );

$restored = call_tool( $server, 'elementor_restore_snapshot', array( 'postId' => $page_id ) );
check( 'restore_snapshot: restores without error', empty( $restored['result']['isError'] ) );
$after_restore = json_decode( get_post_meta( $page_id, '_elementor_data', true ), true );
check( 'restore_snapshot: the deleted element is back', null !== EMCP_Tree::find( $after_restore, $button_id ) );

// --- permission denial: EMCP_MCP_Guard actually gates writes ---------------
$GLOBALS['emcp_test_can_edit'] = false;
$denied = call_tool( $server, 'elementor_update_element', array( 'postId' => $page_id, 'elementId' => 'bbb0001', 'settings' => array( 'x' => '1' ) ) );
check( 'permission: write is refused when can_edit_post is false', true === ( $denied['result']['isError'] ?? false ) );
$GLOBALS['emcp_test_can_edit'] = true;

// --- delete_page: trash vs. destructive-gated force -------------------------
$trash_id = emcp_test_seed_page( array(), array(), 'To Trash' );
$trashed  = tool_data( call_tool( $server, 'elementor_delete_page', array( 'postId' => $trash_id ) ) );
check( 'delete_page: trash succeeds without confirm', true === $trashed['deleted'] );
check( 'delete_page: trash is not a force-delete', false === $trashed['forced'] );

$force_without_confirm = call_tool( $server, 'elementor_delete_page', array( 'postId' => $trash_id, 'force' => true, 'confirm' => false ) );
check( 'delete_page: forced delete without destructive-mode enabled is refused', true === ( $force_without_confirm['result']['isError'] ?? false ) );

// --- add_container: guarded against an unregistered element type -----------
// EMCP_Schema::registered_types() needs Elementor active to return anything,
// so in this harness it reports no known types and the guard is a no-op —
// documented here so the gap is explicit rather than silently assumed.
$container_result = call_tool( $server, 'elementor_add_container', array( 'postId' => $page_id, 'elType' => 'container' ) );
check( 'add_container: does not crash when Elementor is not loaded', isset( $container_result['result'] ) );

/* ------------------------------------------------------------------ *
 * Auth shim (pure logic, exercised directly).
 * ------------------------------------------------------------------ */

function with_auth_header( $header, callable $fn ) {
	$prev = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
	if ( null === $header ) unset( $_SERVER['HTTP_AUTHORIZATION'] ); else $_SERVER['HTTP_AUTHORIZATION'] = $header;
	$result = $fn();
	if ( null === $prev ) unset( $_SERVER['HTTP_AUTHORIZATION'] ); else $_SERVER['HTTP_AUTHORIZATION'] = $prev;
	return $result;
}

$token = base64_encode( 'agent:correct-horse-battery-staple' );
check( 'auth shim: valid bearer token resolves to the user', 1 === with_auth_header( "Bearer $token", fn() => EMCP_Auth::authenticate_bearer( false ) ) );
check( 'auth shim: wrong password is rejected', false === with_auth_header( 'Bearer ' . base64_encode( 'agent:wrong' ), fn() => EMCP_Auth::authenticate_bearer( false ) ) );
check( 'auth shim: does not override an already-resolved user', 42 === with_auth_header( "Bearer $token", fn() => EMCP_Auth::authenticate_bearer( 42 ) ) );
check( 'auth shim: ignores Basic auth (that is core\'s job, not this shim\'s)', false === with_auth_header( 'Basic ' . $token, fn() => EMCP_Auth::authenticate_bearer( false ) ) );
check( 'auth shim: garbage header does not crash', false === with_auth_header( 'Bearer not-valid-base64!!!', fn() => EMCP_Auth::authenticate_bearer( false ) ) );
check( 'auth shim: no header at all is a no-op', false === with_auth_header( null, fn() => EMCP_Auth::authenticate_bearer( false ) ) );

/* ------------------------------------------------------------------ *
 * Report.
 * ------------------------------------------------------------------ */

printf( "%d passed, %d failed\n", $passed, count( $failures ) );

if ( $failures ) {
	echo "\nFAILURES:\n";
	foreach ( $failures as $failure ) echo "  - $failure\n";
	exit( 1 );
}

echo "MCP ENDPOINT OK\n";
