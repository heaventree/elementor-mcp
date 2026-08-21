<?php
/**
 * Plugin Name: Elementor MCP Bridge
 * Plugin URI:  https://github.com/heaventree/elementor-mcp
 * Description: Exposes deep, capability-checked Elementor control over the WordPress REST API so an MCP client can read and edit page structure, widget settings, global design tokens and templates.
 * Version:     1.2.0
 * Author:      Heaventree
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: elementor-mcp-bridge
 * Requires PHP: 7.4
 * Requires at least: 5.9
 *
 * @package Elementor_MCP_Bridge
 */

defined( 'ABSPATH' ) || exit;

define( 'EMCP_VERSION', '1.2.0' );
define( 'EMCP_PLUGIN_FILE', __FILE__ );
define( 'EMCP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * REST namespace every route in this plugin lives under.
 */
define( 'EMCP_REST_NAMESPACE', 'elementor-mcp/v1' );

require_once EMCP_PLUGIN_DIR . 'includes/class-emcp-guard.php';
require_once EMCP_PLUGIN_DIR . 'includes/class-emcp-auth.php';
require_once EMCP_PLUGIN_DIR . 'includes/class-emcp-oauth.php';
require_once EMCP_PLUGIN_DIR . 'includes/class-emcp-tree.php';
require_once EMCP_PLUGIN_DIR . 'includes/class-emcp-snapshots.php';
require_once EMCP_PLUGIN_DIR . 'includes/class-emcp-documents.php';
require_once EMCP_PLUGIN_DIR . 'includes/class-emcp-schema.php';
require_once EMCP_PLUGIN_DIR . 'includes/class-emcp-globals.php';
require_once EMCP_PLUGIN_DIR . 'includes/class-emcp-library.php';
require_once EMCP_PLUGIN_DIR . 'includes/class-emcp-scanner.php';
require_once EMCP_PLUGIN_DIR . 'includes/class-emcp-compose.php';
require_once EMCP_PLUGIN_DIR . 'includes/rest/class-emcp-rest-base.php';
require_once EMCP_PLUGIN_DIR . 'includes/rest/class-emcp-rest-site.php';
require_once EMCP_PLUGIN_DIR . 'includes/rest/class-emcp-rest-documents.php';
require_once EMCP_PLUGIN_DIR . 'includes/rest/class-emcp-rest-globals.php';
require_once EMCP_PLUGIN_DIR . 'includes/rest/class-emcp-rest-library.php';
require_once EMCP_PLUGIN_DIR . 'includes/rest/class-emcp-rest-tools.php';
require_once EMCP_PLUGIN_DIR . 'includes/mcp/class-emcp-mcp-schema.php';
require_once EMCP_PLUGIN_DIR . 'includes/mcp/class-emcp-mcp-guard.php';
require_once EMCP_PLUGIN_DIR . 'includes/mcp/class-emcp-mcp-tools.php';
require_once EMCP_PLUGIN_DIR . 'includes/mcp/class-emcp-mcp-server.php';
require_once EMCP_PLUGIN_DIR . 'includes/rest/class-emcp-rest-mcp.php';

/**
 * Register every REST controller shipped by the bridge.
 *
 * Routes are registered even when Elementor is inactive so that a client can
 * call `/status` and be told exactly what is missing instead of getting a 404.
 */
function emcp_register_rest_routes() {
	$controllers = array(
		new EMCP_REST_Site(),
		new EMCP_REST_Documents(),
		new EMCP_REST_Globals(),
		new EMCP_REST_Library(),
		new EMCP_REST_Tools(),
		new EMCP_REST_MCP(),
	);

	foreach ( $controllers as $controller ) {
		$controller->register_routes();
	}
}
add_action( 'rest_api_init', 'emcp_register_rest_routes' );

EMCP_Auth::register();
EMCP_OAuth::register();

/**
 * Warn on the plugins screen when Elementor is missing, since every route
 * beyond `/status` depends on it.
 */
function emcp_admin_notice_missing_elementor() {
	if ( did_action( 'elementor/loaded' ) || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-warning"><p>%s</p></div>',
		esc_html__( 'Elementor MCP Bridge is active but Elementor was not found. Install and activate Elementor to enable the bridge endpoints.', 'elementor-mcp-bridge' )
	);
}
add_action( 'admin_notices', 'emcp_admin_notice_missing_elementor' );

register_activation_hook(
	__FILE__,
	static function () {
		// Snapshots live in their own table-free option store; make sure the
		// index exists so the first write does not race.
		EMCP_Snapshots::bootstrap();
	}
);
