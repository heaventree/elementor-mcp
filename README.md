# Elementor MCP

An MCP server for working on Elementor-built WordPress sites — reading page
structure, editing widgets, managing global design tokens, and doing it without
breaking anything.

Elementor keeps its entire page layout as a JSON blob in a protected postmeta
key (`_elementor_data`). The WordPress REST API will not read or write it, which
is why generic WordPress MCP servers can list your pages but cannot touch what
is on them. This project closes that gap.

## What you get

- **50 tools** covering page structure, element surgery, widget schemas, global
  design, templates, history and site-wide search.
- **Live widget schemas.** Control definitions are read from the target site's
  own Elementor registry, so the schema you get describes exactly the widgets
  that site has installed — core, Pro, and any third-party addon pack — instead
  of a hard-coded list that goes stale.
- **Safe concurrent writes.** Every write carries the content hash the page was
  read at and is rejected if the page changed underneath. No silent overwrites.
- **Undo.** Every write snapshots the layout first. `elementor_restore_snapshot`
  rolls back the last change.
- **Atomic batches.** Multi-step restructuring applies as one operation, or not
  at all — a page is never left half-edited.
- **Multi-site.** One server can front many WordPress installs, each addressable
  by name, each independently markable read-only.

## Two ways to connect

The bridge plugin can be used two ways, and they are independent — pick
whichever fits how you work:

**1. WordPress serves MCP directly (recommended).** The plugin exposes a
JSON-RPC 2.0 endpoint at `/wp-json/elementor-mcp/v1/mcp` — the same
"Streamable HTTP" transport most remote MCP servers use, running statelessly
(no session, no persistent connection, which is exactly how a PHP request
already works). Point an MCP client — including a claude.ai custom
connector — at that URL and authenticate with a WordPress application
password. Nothing to install beyond the plugin itself, and it works from
claude.ai on the web, desktop or mobile.

```
MCP client  ──HTTPS──▶  WordPress
                         └── Elementor MCP Bridge (this repo)
                              ├── /mcp        (JSON-RPC 2.0, 49 tools)
                              ├── /documents   ┐
                              ├── /kit         │ plain REST, same logic
                              ├── /templates   │ the MCP endpoint calls
                              └── ...          ┘
```

**2. A local Node server (`src/`), talking to the bridge's REST API.** Useful
for development, for MCP clients that only support local stdio servers, or
when you would rather the element-tree logic run outside the PHP process.

```
MCP client  ──stdio──▶  elementor-mcp (Node)  ──REST──▶  WordPress
                                                          └── Elementor MCP Bridge
```

Both paths end up calling the same Elementor APIs — `Document::save()` (the
same path the editor itself uses, so sanitisation, revisions and CSS
regeneration all happen correctly) and the live widget registry for schemas.
The element-tree mutation logic (insert, move, duplicate, reorder, wrap,
batch) is implemented twice, once in each language, and kept in parity
deliberately: 236 PHP assertions and 41 TypeScript ones exercise the same
cases side by side, so a change to one is expected to show up as a matching
change in the other's test file.

## Install

### 1. The bridge plugin

Build an installable zip and upload it through **Plugins → Add New → Upload
Plugin** in wp-admin:

```bash
./scripts/package-plugin.sh
# build/elementor-mcp-bridge-1.2.0.zip
```

The script refuses to build if any file fails `php -l`, so a broken plugin
cannot be packaged.

Or install it directly:

```bash
rsync -a plugin/elementor-mcp-bridge/ user@host:/var/www/site/wp-content/plugins/elementor-mcp-bridge/
wp plugin activate elementor-mcp-bridge     # or activate in wp-admin
```

Requires WordPress 5.9+, PHP 7.4+ and Elementor 3.x or 4.x.

Confirm it is live:

```bash
curl -u 'user:app password' https://example.com/wp-json/elementor-mcp/v1/status
```

On Windows, `curl` is an alias for `Invoke-WebRequest` and does not accept
`-u`. Use the bundled diagnostic instead, which checks reachability, the REST
API, plugin activation and credentials in order so a failure tells you which
one broke:

```powershell
.\scripts\Test-Bridge.ps1 -SiteUrl https://example.com -Username admin -AppPassword 'abcd efgh ijkl mnop'
```

Or force real curl with `curl.exe` rather than the alias.

### 2. An application password

In wp-admin go to **Users → Profile → Application Passwords**, add one named
`elementor-mcp`, and copy the generated value. This is not the account's login
password; it can be revoked independently.

The bridge checks a real WordPress capability on every route, so the agent can
only do what that user could do by hand. Give it an account with the least role
that covers your use — Editor is usually enough, Administrator only if you need
to change site-wide design settings.

### 3. The server

```bash
npm install
npm run build
```

## Configure

Single site:

```bash
WORDPRESS_URL=https://example.com
WORDPRESS_USERNAME=your-user
WORDPRESS_APP_PASSWORD="abcd efgh ijkl mnop qrst uvwx"
```

Several sites — set `ELEMENTOR_MCP_SITES` to a JSON array:

```json
[
  { "name": "live",    "url": "https://example.com",         "username": "u", "appPassword": "..." },
  { "name": "staging", "url": "https://staging.example.com", "username": "u", "appPassword": "...", "readOnly": true }
]
```

| Variable | Purpose |
| --- | --- |
| `WORDPRESS_URL` / `WORDPRESS_USERNAME` / `WORDPRESS_APP_PASSWORD` | Single-site credentials |
| `ELEMENTOR_MCP_SITES` | JSON array or name-keyed object of site profiles |
| `ELEMENTOR_MCP_CONFIG` | Path to a JSON file holding the same structure |
| `ELEMENTOR_MCP_DEFAULT_SITE` | Which profile tools use when `site` is omitted |
| `ELEMENTOR_MCP_READ_ONLY` | `true` refuses every write, across all sites |
| `ELEMENTOR_MCP_TIMEOUT_MS` | Per-request timeout, default 30000 |

### Connecting a client to the WordPress MCP endpoint (recommended)

The endpoint is `https://example.com/wp-json/elementor-mcp/v1/mcp`.

**claude.ai (web or desktop):** **Settings → Connectors → Add custom
connector**, paste the URL, and continue — no token to generate or paste
anywhere. It runs an OAuth flow on its own: you're sent to your site to log
in (if needed) and approve the connection, then straight back to claude.ai,
connected. See [Authentication](#authentication) for what that mints behind
the scenes.

**Any other MCP client:** if it accepts a static token instead, use
`base64(username:app_password)` as either HTTP Basic or a bearer token:

```bash
echo -n 'your-user:abcd efgh ijkl mnop qrst uvwx' | base64
```

To check the endpoint directly, either way works for a plain `curl` test:

```bash
curl -u 'your-user:abcd efgh ijkl mnop qrst uvwx' \
  -X POST https://example.com/wp-json/elementor-mcp/v1/mcp \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

### Connecting a client to the local Node server

Claude Code:

```bash
claude mcp add elementor -- node /path/to/elementor-mcp/dist/index.js
```

Or by editing an MCP client config directly:

```json
{
  "mcpServers": {
    "elementor": {
      "command": "node",
      "args": ["/path/to/elementor-mcp/dist/index.js"],
      "env": {
        "WORDPRESS_URL": "https://example.com",
        "WORDPRESS_USERNAME": "your-user",
        "WORDPRESS_APP_PASSWORD": "abcd efgh ijkl mnop qrst uvwx"
      }
    }
  }
}
```

## Authentication

Two ways in, and which one applies depends on the client, not on you:

**Direct — HTTP Basic or bearer, with an application password.** Every
route — REST and MCP alike — accepts a WordPress application password
(**Users → Profile → Application Passwords**) sent as HTTP Basic:

```
Authorization: Basic base64(username:app_password)
```

That is native to WordPress; no code in this plugin handles it. The MCP
endpoint additionally accepts the same value as a bearer token —

```
Authorization: Bearer base64(username:app_password)
```

— for MCP clients whose connector UI only exposes a single "token" field.
Same credential, two header shapes; there is no separate "MCP token" to
generate. This is what the `curl` examples in this README use, and it is
enough for the local Node server, direct REST calls, and any MCP client that
accepts a static token.

**OAuth — for clients that require it.** claude.ai's custom connector, on
both web and desktop, does not accept a static token: it runs a full
authorization-code flow with PKCE (RFC 6749 + RFC 7636) against the
connector's own origin, the same as any other OAuth-only remote MCP server.
The plugin implements that flow — `GET/POST /authorize`, `POST /token`, plus
the RFC 8414 and RFC 9728 discovery documents — entirely to satisfy this
requirement; it does not add any capability beyond what the direct route
already offers, and the local Node server never needs it.

You do not generate anything for this path yourself. Add the custom
connector in claude.ai with the URL `https://example.com/wp-json/elementor-mcp/v1/mcp`
and it drives the flow on its own: a redirect to your site to log in (if not
already) and approve access, then straight back to claude.ai, connected.
What gets minted behind the scenes is, again, an ordinary application
password — named `Claude MCP (OAuth, <timestamp>)` — so it shows up
individually in **Users → Profile → Application Passwords** and can be
revoked from there like any other, with nothing extra to track.

The redirect target is checked against an allow-list
(`emcp_oauth_allowed_redirect_uris`, defaulting to claude.ai's confirmed
callback) before any code is issued, so this cannot be turned into an open
redirect by a crafted link.

### CGI/FastCGI

If direct authentication fails with a 401 and the credentials are correct,
the server is likely running under CGI/FastCGI, which strips the
`Authorization` header before PHP sees it. Add this to `.htaccess` above the
WordPress block:

```apache
RewriteCond %{HTTP:Authorization} ^(.*)
RewriteRule .* - [e=HTTP_AUTHORIZATION:%1]
```

## One site, or many

The WordPress-hosted MCP endpoint has exactly one site: the one it runs on.
Its 49 tools take no `site` argument — there is nothing to disambiguate.

The local Node server can front several WordPress installs from one process
(see [Configure](#configure) above), which is why its tools all accept an
optional `site` argument. If you only ever work on one site, this
distinction will not come up; it matters once you are managing more than one
install from a single MCP client.

## How to use it well

The tool descriptions steer toward this, but the short version:

1. `elementor_site_status` once per site — versions, widget count, what the
   authenticated user may do.
2. `elementor_get_outline` to get element ids. Do **not** reach for
   `elementor_get_page_tree` unless you genuinely need every setting; outlines
   are a fraction of the size.
3. `elementor_get_widget_schema` before setting unfamiliar widget settings.
   Control names are not guessable, and a wrong key saves silently with no
   visible effect — this is the single most common way Elementor automation goes
   wrong.
4. `elementor_batch_edit` for anything multi-step.
5. `elementor_render_page` to confirm the result.

Responsive settings use `_tablet` and `_mobile` suffixes (`padding`,
`padding_tablet`, `padding_mobile`); the schema marks which controls are
responsive and spells out the exact keys.

## Safety model

| Concern | How it is handled |
| --- | --- |
| Concurrent edits | Writes carry the hash read at load; a changed page returns 409 and the server retries once against fresh data |
| Mistakes | Every write snapshots the layout first; `elementor_restore_snapshot` undoes the last change |
| Half-finished edits | Batches apply in memory and validate before a single write; a failed operation writes nothing |
| Corrupt trees | Duplicate ids, widgets with children, and widgets missing `widgetType` are rejected before write |
| Destructive operations | Permanent deletion and site-wide replace need `EMCP_ALLOW_DESTRUCTIVE` on the site *and* `confirm: true` |
| Site-wide replace | Defaults to a dry run that reports every page it would touch |
| Permissions | Every route checks a real WordPress capability for the authenticated user |
| Stale CSS | Elementor's cached CSS is flushed after every write, so changes actually render |

To allow permanent deletes and committed site-wide replaces, add to
`wp-config.php`:

```php
define( 'EMCP_ALLOW_DESTRUCTIVE', true );
```

## Tools

Full reference in [docs/TOOLS.md](docs/TOOLS.md). By area:

- **Sites** — `elementor_list_sites`, `elementor_site_status`,
  `elementor_native_mcp_call`
- **Pages** — list, get, outline, create, duplicate, delete, render, update
  metadata, full tree
- **Elements** — get, find, add widget, add container, update, move, duplicate,
  delete, reorder, wrap, batch edit, replace tree
- **Widgets** — list, schema, element types, dynamic tags
- **Design** — globals, set global colour, update globals, custom CSS, global
  classes, flush CSS
- **Templates** — list, get, save, apply, export, import
- **History** — snapshots (list/create/restore), revisions (list/restore)
- **Content** — search, find and replace, widget usage, media list, image upload

## Elementor's own MCP module

Elementor 4.3 ships a built-in MCP module, gated behind the WordPress Abilities
API and focused on v4 atomic features — global classes, variables, components,
compositions. Where it is present, `elementor_native_mcp_call` proxies straight
through to it, so you get those abilities alongside everything here.

It is not a replacement for this server: it requires Elementor 4.3+ with
optional dependencies installed, and it does not cover the classic
section/column/widget model that the overwhelming majority of live Elementor
sites are built on. `elementor_site_status` reports whether it is available.

## Development

```bash
npm run verify               # every check below, except docs and packaging
npm run build                # compile the Node server
npm test                     # 60 TypeScript unit tests
npm run typecheck            # source and tests
npm run smoke                # boot the Node server and exercise the MCP handshake
npm run test:guard           # drive the Node server against a mock bridge
npm run docs                 # regenerate docs/TOOLS.md from the running Node server
php tests/plugin-load.php    # load the plugin, register all 33 REST routes
php tests/tree-mutators.php  # 236 assertions: PHP tree mutators against the same
                              # cases as tests/tree.test.ts
php tests/mcp-endpoint.php   # 51 assertions: full JSON-RPC round trips against an
                              # in-memory WordPress — initialize, tools/list, and
                              # real element edits through the hash-guarded write path
php tests/oauth-flow.php     # 46 assertions: the full authorization-code + PKCE
                              # round trip, driven directly against EMCP_OAuth
./scripts/package-plugin.sh  # build the installable zip
```

Four PHP harnesses, in order of what they prove:

- `tests/plugin-load.php` — the plugin loads and every route resolves to a
  callable handler and permission callback. Catches activation fatals before
  they reach a zip upload.
- `tests/tree-mutators.php` — the PHP element-tree mutators (insert, move,
  duplicate, reorder, wrap, batch) behave identically to the TypeScript
  ones, case for case.
- `tests/mcp-endpoint.php` — the JSON-RPC layer itself: protocol negotiation,
  tool discovery, and real tool calls against an in-memory post store,
  including permission denial, the destructive-operation confirm gate, and
  the snapshot-then-restore undo path. It stubs WordPress rather than
  Elementor, so it exercises the bridge's own fallback write path, not
  `Document::save()` — that half still needs a real site.
- `tests/oauth-flow.php` — discovery metadata; the not-logged-in-redirects-
  to-login case; consent approve and deny; PKCE accepted with the correct
  verifier and rejected with the wrong one; a used code rejected on replay;
  a mismatched `redirect_uri` rejected; an untrusted `redirect_uri` refused
  as a page rather than ever becoming a redirect; and the token this
  endpoint issues successfully authenticating through the existing
  bearer-token shim with no changes needed there. It calls `EMCP_OAuth`'s
  private handlers directly via reflection, since the real request router
  ends in `exit`.

`tests/capability-guard.mjs` runs the built server over stdio against
`tests/mock-bridge.mjs`, a stand-in bridge whose element registry, widget
registry and stored documents a test dictates. That reaches the behaviour that
depends on the site rather than on our code: refusing an element type the site
cannot render, surfacing a save warning, standing down when a site reports no
registry at all, and re-applying a write that raced another editor. It asserts
against the mock's write log rather than the wording, so a regression that
keeps the message and loses the guard still fails.

## Licence

MIT. See [LICENSE](LICENSE).
