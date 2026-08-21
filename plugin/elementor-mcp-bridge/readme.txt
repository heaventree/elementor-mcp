=== Elementor MCP Bridge ===
Contributors: heaventree
Tags: elementor, mcp, ai, rest-api, page-builder
Requires at least: 5.9
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.1.0
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

= 1.1.0 =
* Added a full MCP server directly on this plugin: POST /wp-json/elementor-mcp/v1/mcp speaks JSON-RPC 2.0
  (stateless Streamable HTTP), so an MCP client — including a claude.ai custom connector — can point at
  this site directly. No separate Node process to install or keep running.
* Exposes 49 tools covering everything the standalone elementor-mcp server does: page and element CRUD,
  atomic element-tree operations (insert/move/duplicate/reorder/wrap/batch), widget schema introspection,
  global design tokens, templates, snapshots and revisions, and site-wide search/replace.
* Element-tree mutation logic is ported from the TypeScript server with matching semantics, verified
  against 236 parity assertions and a further 51 end-to-end JSON-RPC assertions covering the full
  read-mutate-write-with-hash-guard cycle, permission gating, and the destructive-operation confirm gate.
* Accepts a bearer token as an alternative to HTTP Basic auth, for MCP clients whose connector UI only
  offers a single token field: base64(username:app_password), the same value already used for Basic.
* The conflict check on document writes moved from the REST controller into EMCP_Documents::write()
  itself, so REST and MCP callers share one implementation instead of two that could drift.

= 1.0.1 =
* /status now reports structuralElements and containerAvailable, read from the
  live element registry. Elementor gates the flexbox Container behind an
  experiment that defaults to inactive on sites installed before 3.16, so an
  up-to-date Elementor does not guarantee containers exist.
* Writes now return warnings listing element and widget types the site cannot
  render, so an unrenderable layout is reported at save time rather than
  discovered as a blank section on the front end. Warnings never block a save:
  a page may legitimately contain widgets from a deactivated addon.
* Native MCP detection now checks all four of the module's dependencies and
  reports fullyActive and proxyUsable separately.

= 1.0.0 =
* Initial release.
