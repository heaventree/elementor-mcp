=== Elementor MCP Bridge ===
Contributors: heaventree
Tags: elementor, mcp, ai, rest-api, page-builder
Requires at least: 5.9
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Exposes deep, capability-checked Elementor control over the WordPress REST API so an MCP client can read and edit page structure.

== Description ==

Elementor stores every page's layout as JSON in a protected postmeta key
(`_elementor_data`). The WordPress REST API will not read or write protected
meta, so tools built on core REST alone can list your pages but cannot see or
change what is on them.

This plugin adds an `elementor-mcp/v1` REST namespace that can:

* read and write Elementor documents through Elementor's own save path, so
  sanitisation, revisions and CSS regeneration all happen correctly
* return a compact structural outline of a page instead of its full JSON
* introspect the live widget registry for control schemas, covering core
  widgets, Elementor Pro and any third-party addon pack installed
* read and write the Elementor kit: global colours, typography and layout
* save, apply, export and import template library entries
* snapshot a layout before every write, and restore it afterwards
* search and replace text across every Elementor page on the site
* proxy Elementor 4.3's own MCP abilities where they are available

It is designed to be driven by the `elementor-mcp` MCP server, but the REST API
is plain HTTP and can be used by anything.

= Security =

Every route runs a real WordPress capability check for the authenticated user,
so this plugin never grants an account more power than it already has:

* reading requires `edit_posts`
* editing a document requires `edit_post` on that specific post
* changing site-wide design requires `edit_theme_options`
* uploading media requires `upload_files`

Destructive operations — permanent deletion and committed site-wide replace —
are disabled entirely unless the site opts in, and additionally require
`confirm: true` on the request:

    define( 'EMCP_ALLOW_DESTRUCTIVE', true );

Authenticate with a WordPress application password
(Users > Profile > Application Passwords), which can be revoked without
changing the account's login password.

= Concurrency =

Writes accept an `expectedHash` naming the state the caller last read. If the
document changed in between, the write is rejected with HTTP 409 rather than
silently discarding the other change.

== Installation ==

1. Copy the `elementor-mcp-bridge` directory into `wp-content/plugins/`.
2. Activate it in Plugins.
3. Create an application password under Users > Profile.
4. Confirm it is working: `GET /wp-json/elementor-mcp/v1/status`.

== Frequently Asked Questions ==

= Does this require Elementor Pro? =

No. Pro widgets are described and editable when Pro is installed, but the plugin
works on Elementor free.

= Does it work with Elementor 4 / Editor One? =

Yes. Classic sections, columns, containers and v4 atomic elements are all read
and written. Where Elementor's own MCP module is active, its abilities can be
reached through `/native-mcp/proxy`.

= What happens if Elementor is not installed? =

Routes still register, and `/status` reports what is missing rather than
returning a confusing 404.

== Changelog ==

= 1.0.0 =
* Initial release.
