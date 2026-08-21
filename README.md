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

## Architecture

Two pieces, because neither works alone:

```
MCP client  ──stdio──▶  elementor-mcp (Node)  ──REST──▶  WordPress
                                                          ├── Elementor MCP Bridge (this repo)
                                                          └── Elementor
```

**`plugin/elementor-mcp-bridge/`** — a WordPress plugin exposing an
`elementor-mcp/v1` REST namespace. It reads and writes Elementor documents
through Elementor's own `Document::save()` (the same path the editor uses, so
sanitisation, revisions and CSS regeneration all happen correctly), and
introspects the live widget registry for schemas.

**`src/`** — the MCP server. It holds the element tree in its own process and
does the structural work there, so a 400 KB page can be edited without that JSON
ever entering the model's context.

That split is deliberate. Tree surgery lives in TypeScript where it is unit
tested against 60 cases; the PHP side stays thin and delegates to Elementor.

## Install

### 1. The bridge plugin

Build an installable zip and upload it through **Plugins → Add New → Upload
Plugin** in wp-admin:

```bash
./scripts/package-plugin.sh
# build/elementor-mcp-bridge-1.0.1.zip
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

### Connecting a client

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
npm run verify           # everything below except docs and packaging
npm run build            # compile
npm test                 # 60 unit tests
npm run typecheck        # source and tests
npm run smoke            # boot the server and exercise the MCP handshake
npm run test:guard       # drive the server against a mock site
npm run test:plugin      # load the plugin and register all 32 routes
npm run docs             # regenerate docs/TOOLS.md from the running server
./scripts/package-plugin.sh # build the installable zip
```

`tests/plugin-load.php` loads the bridge against stubbed WordPress functions
and asserts every route has a callable handler and permission callback. It is
not a substitute for a real site, but it catches the activation fatals that a
zip would otherwise hide until upload.

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
