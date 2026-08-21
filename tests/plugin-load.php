<?php
/**
 * Loads the bridge plugin against stubbed WordPress functions and registers
 * every route, to catch anything that would fatal on activation.
 *
 * This is not a substitute for running on a real site — it stubs WordPress
 * rather than booting it — but it does prove the plugin parses, loads in the
 * right order, and that every route definition is well formed.
 *
 * Usage: php tests/plugin-load.php
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['emcp_routes']  = array();
$GLOBALS['emcp_actions'] = array();

// --- Minimal WordPress surface used at load and route-registration time. ---

class WP_REST_Server {
	const READABLE  = 'GET';
	const CREATABLE = 'POST';
	const EDITABLE  = 'POST, PUT, PATCH';
	const DELETABLE = 'DELETE';
}

class WP_Error {
	public $code;
	public $message;
	public $data;

	public function __construct( $code = '', $message = '', $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

class WP_REST_Response {
	public $data;
	public $status;

	public function __construct( $data = null, $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}
}

class WP_REST_Request {
	private $params = array();

	public function __construct( $method = 'GET', $route = '' ) {}

	public function set_param( $key, $value ) {
		$this->params[ $key ] = $value;
	}

	public function get_param( $key ) {
		return $this->params[ $key ] ?? null;
	}
}

function plugin_dir_path( $file ) {
	return dirname( $file ) . '/';
}

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['emcp_actions'][] = $hook;
}
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {}

function register_activation_hook( $file, $callback ) {}
function did_action( $hook ) { return 0; }
function is_user_logged_in() { return true; }
function current_user_can( $cap ) { return true; }
function apply_filters( $hook, $value ) { return $value; }
function get_bloginfo( $show = '' ) { return '6.9'; }
function get_site_url() { return 'https://example.test'; }
function get_current_user_id() { return 1; }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg( $args, $url = '' ) { return $url . '?' . http_build_query( $args ); }
function esc_html__( $text, $domain = '' ) { return $text; }
function __( $text, $domain = '' ) { return $text; }
function wp_rand( $min = 0, $max = 0 ) { return random_int( $min, $max ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_strip_all_tags( $text ) { return strip_tags( (string) $text ); }
function taxonomy_exists( $tax ) { return false; }
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function __return_true() { return true; }

function register_rest_route( $namespace, $route, $args ) {
	$GLOBALS['emcp_routes'][] = array(
		'namespace' => $namespace,
		'route'     => $route,
		'args'      => $args,
	);
}

// --- Load the plugin. ---

require __DIR__ . '/../plugin/elementor-mcp-bridge/elementor-mcp-bridge.php';

$failures = array();

if ( ! in_array( 'rest_api_init', $GLOBALS['emcp_actions'], true ) ) {
	$failures[] = 'The plugin did not hook rest_api_init.';
}

// Registering routes is where a malformed definition surfaces.
emcp_register_rest_routes();

$routes = $GLOBALS['emcp_routes'];

if ( count( $routes ) < 25 ) {
	$failures[] = sprintf( 'Only %d routes registered; expected at least 25.', count( $routes ) );
}

foreach ( $routes as $entry ) {
	$route = $entry['route'];

	if ( EMCP_REST_NAMESPACE !== $entry['namespace'] ) {
		$failures[] = sprintf( 'Route %s is in namespace %s, not %s.', $route, $entry['namespace'], EMCP_REST_NAMESPACE );
	}

	// A route is either one definition, or a list of them.
	$definitions = isset( $entry['args']['methods'] ) ? array( $entry['args'] ) : $entry['args'];

	foreach ( $definitions as $definition ) {
		if ( empty( $definition['methods'] ) ) {
			$failures[] = sprintf( 'Route %s has a definition with no methods.', $route );
			continue;
		}

		if ( empty( $definition['callback'] ) || ! is_callable( $definition['callback'] ) ) {
			$failures[] = sprintf( 'Route %s [%s] has a missing or uncallable callback.', $route, $definition['methods'] );
		}

		if ( empty( $definition['permission_callback'] ) || ! is_callable( $definition['permission_callback'] ) ) {
			$failures[] = sprintf( 'Route %s [%s] has no callable permission_callback.', $route, $definition['methods'] );
		}
	}
}

// Exercise the pure helpers, which need no WordPress at all.
$tree = array(
	array(
		'id'       => 'aaa0001',
		'elType'   => 'container',
		'settings' => array(),
		'elements' => array(
			array(
				'id'         => 'bbb0001',
				'elType'     => 'widget',
				'widgetType' => 'heading',
				'settings'   => array( 'title' => 'Hello <b>world</b>' ),
				'elements'   => array(),
			),
		),
	),
);

if ( ! preg_match( '/^[0-9a-f]{7}$/', EMCP_Tree::generate_id() ) ) {
	$failures[] = 'EMCP_Tree::generate_id did not produce 7-character lowercase hex.';
}

if ( true !== EMCP_Tree::validate( $tree ) ) {
	$failures[] = 'EMCP_Tree::validate rejected a valid tree.';
}

$dupe                = $tree;
$dupe[1]             = $tree[0];
if ( ! is_wp_error( EMCP_Tree::validate( $dupe ) ) ) {
	$failures[] = 'EMCP_Tree::validate accepted duplicate element ids.';
}

if ( 'Hello world' !== EMCP_Tree::describe( $tree[0]['elements'][0] ) ) {
	$failures[] = 'EMCP_Tree::describe did not strip markup.';
}

if ( array( 'container' => 1, 'heading' => 1 ) !== EMCP_Tree::census( $tree ) ) {
	$failures[] = 'EMCP_Tree::census returned unexpected counts.';
}

$outline = EMCP_Tree::outline( $tree );
if ( 'bbb0001' !== $outline[0]['elements'][0]['id'] || isset( $outline[0]['settings'] ) ) {
	$failures[] = 'EMCP_Tree::outline did not produce a settings-free structure.';
}

// --- Report. ---

printf( "routes registered: %d\n", count( $routes ) );
printf( "namespace: %s\n", EMCP_REST_NAMESPACE );
printf( "plugin version: %s\n", EMCP_VERSION );

if ( $failures ) {
	echo "\nFAILURES:\n";
	foreach ( $failures as $failure ) {
		echo "  - $failure\n";
	}
	exit( 1 );
}

echo "\nPLUGIN LOAD OK\n";
