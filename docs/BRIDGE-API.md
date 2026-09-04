# Bridge REST API

The companion plugin exposes everything under `/wp-json/elementor-mcp/v1/`.
Authenticate with HTTP Basic using a WordPress application password.

```bash
curl -u 'user:abcd efgh ijkl mnop' https://example.com/wp-json/elementor-mcp/v1/status
```

The MCP server is the intended client, but the API is plain HTTP and documented
here for direct use and for debugging.

## Discovery

| Method | Route | Purpose |
| --- | --- | --- |
| GET | `/status` | Versions, widget count, active kit, user capabilities, native MCP availability |
| GET | `/widgets` | Registered widget types. Query: `search`, `category`, `includeHidden` |
| GET | `/widgets/{name}/schema` | Control schema grouped by tab and section. Query: `compact` |
| GET | `/elements` | Structural element types |
| GET | `/elements/{name}/schema` | Control schema for container, section or column |
| GET | `/categories` | Widget panel categories |
| GET | `/dynamic-tags` | Registered dynamic tags |

## Documents

| Method | Route | Purpose |
| --- | --- | --- |
| GET | `/documents` | List. Query: `search`, `type[]`, `status[]`, `elementorOnly`, `templateType`, `page`, `perPage` |
| POST | `/documents` | Create an Elementor-enabled post |
| GET | `/documents/{id}` | Metadata, census and hash. Query: `includeElements` |
| POST | `/documents/{id}` | Write `elements` and/or `settings` |
| DELETE | `/documents/{id}` | Trash, or delete permanently with `force` + `confirm` |
| GET | `/documents/{id}/outline` | Compact structure. Query: `maxDepth` |
| GET | `/documents/{id}/elements/{elementId}` | One element subtree |
| POST | `/documents/{id}/duplicate` | Copy to a new post with fresh element ids |
| POST | `/documents/{id}/convert` | Mark an existing post as Elementor-built |
| GET | `/documents/{id}/render` | Rendered HTML. Query: `elementId` |

### Writing safely

`POST /documents/{id}` accepts:

```json
{
  "elements": [ /* complete replacement tree */ ],
  "settings": { /* page settings to merge */ },
  "expectedHash": "the hash returned when you read it",
  "snapshot": true,
  "snapshotLabel": "why"
}
```

If `expectedHash` is present and no longer matches, the response is `409` with
both hashes in `data`, and nothing is written. Omitting it disables the check —
only do that when you know you are the sole writer.

The hash is `md5` of the server's JSON encoding of the tree. Compute it only on
the server: PHP and JavaScript do not serialise JSON identically, so a
client-side hash will not match. Always echo back the hash you were given.

## History

| Method | Route | Purpose |
| --- | --- | --- |
| GET | `/documents/{id}/snapshots` | List snapshots (payloads omitted) |
| POST | `/documents/{id}/snapshots` | Take one. Body: `label` |
| POST | `/documents/{id}/snapshots/restore` | Restore. Body: `snapshotId`, or omit for the latest |
| GET | `/documents/{id}/revisions` | WordPress revisions, flagged for Elementor data |
| POST | `/documents/{id}/revisions/restore` | Restore layout from a revision |

Snapshots are stored in a ring buffer in postmeta, 20 per document by default.
Change that with the `emcp_snapshot_limit` filter.

## Global design

| Method | Route | Purpose |
| --- | --- | --- |
| GET | `/kit` | Colours, typography, layout, custom CSS, raw settings |
| POST | `/kit` | Merge `settings` into the kit |
| POST | `/kit/colors` | Set one colour. Body: `id`, `value`, `title` |
| GET/POST | `/kit/custom-css` | Read or write site custom CSS |
| GET | `/global-classes` | Elementor v4 global classes |

## Templates

| Method | Route | Purpose |
| --- | --- | --- |
| GET | `/templates` | List. Query: `search`, `type`, `page`, `perPage` |
| POST | `/templates` | Save from `elements`, or from `sourceId` (+ `elementId`) |
| GET | `/templates/{id}` | Element tree. Query: `freshIds` |
| GET | `/templates/{id}/export` | Elementor-format export JSON |
| POST | `/templates/import` | Import. Body: `payload`, `title` |

## Tools

| Method | Route | Purpose |
| --- | --- | --- |
| POST | `/search` | Find text, widget types or setting keys across Elementor data |
| POST | `/replace` | Find and replace. `dryRun` defaults to true |
| GET | `/usage` | Site-wide widget census |
| POST | `/cache/flush` | Regenerate CSS. Body: `postId`, or 0 for the whole site |
| POST | `/media/sideload` | Pull a remote image into the media library |
| POST | `/native-mcp/proxy` | Call an Elementor native MCP ability |

## OAuth 2.0 authorization server

Every endpoint is scoped under this plugin's own slug so the bridge can share
a site with other MCP plugins (AI SEO MCP, AI Security MCP, Easy MCP AI…)
without fighting over the site root. The issuer is `<site>/elementor-mcp`;
per RFC 8414 §3.1 path insertion its metadata is published at
`/.well-known/oauth-authorization-server/elementor-mcp`, and the RFC 9728
protected-resource document is keyed on the MCP endpoint's own path:
`/.well-known/oauth-protected-resource/wp-json/elementor-mcp/v1/mcp`. A 401
from `/mcp` carries `WWW-Authenticate: Bearer resource_metadata="<that URL>"`,
which is how a compliant client finds all of this from the endpoint URL
alone.

The generic, un-scoped `/.well-known/oauth-authorization-server` and
`/.well-known/oauth-protected-resource` are answered **only when no known
competing MCP/OAuth plugin is active** (`emcp_oauth_competing_oauth_plugins`
lists them; `emcp_oauth_claim_generic_wellknown` overrides the decision), so
a solo install still satisfies a client that probes the bare path, and a
shared install never steals a sibling's connection.

Endpoints: `GET/POST /elementor-mcp/authorize`, `POST /elementor-mcp/token`,
`POST /elementor-mcp/revoke`. PKCE (RFC 7636) is mandatory;
`code_challenge_method` must be `S256`. Discovery, token and revoke send
`nocache_headers()` and CORS headers (`Access-Control-Allow-Origin: *`, with
a 204 answer to `OPTIONS`) — a client's browser-side JS may fetch them
cross-origin, and a page cache must never hold on to them.

There is no dynamic client registration and no `client_secret` — this is a
public-client, PKCE-only design, matching what real requests to this server
actually send. `client_id` is accepted as an opaque label, not validated;
the security boundary is (1) the visitor must be an authenticated WordPress
user with `edit_posts` to reach the consent screen at all, and (2)
`redirect_uri` must match an explicit allow-list
(`emcp_oauth_allowed_redirect_uris` filter) checked before any code is
issued — every redirect back to the client happens only after that check,
so this cannot become an open redirect.

```
GET /elementor-mcp/authorize
  ?response_type=code
  &client_id=<opaque>
  &redirect_uri=<must be on the allow-list>
  &code_challenge=<base64url(sha256(code_verifier))>
  &code_challenge_method=S256
  &state=<opaque, passed through unchanged>
```

Not logged in → redirected to `wp-login.php` with a `redirect_to` that
returns here with the same params. Logged in without `edit_posts` → a plain
403 page. Otherwise → a consent screen naming the account and the
`redirect_uri`, with Approve/Deny buttons (CSRF-protected via a WordPress
nonce). Approve issues a single-use code (5 minute TTL, stored as a
transient) and redirects to `redirect_uri?code=...&state=...`. Deny
redirects with `error=access_denied`.

```
POST /elementor-mcp/token
  grant_type=authorization_code
  &code=<from /elementor-mcp/authorize>
  &redirect_uri=<must match the authorize request exactly>
  &code_verifier=<the PKCE verifier the challenge was derived from>
```

On success:

```json
{ "access_token": "base64(username:app_password)", "token_type": "Bearer", "scope": "elementor-mcp" }
```

The access token is not a bespoke credential — it's a real WordPress
application password, minted via
`WP_Application_Passwords::create_new_application_password()` the instant
consent is given, named `Elementor MCP (<client_id>)`. The name is
deterministic per client, and any existing password with that name is
deleted first, so a client that reconnects replaces its credential rather
than leaving a new one behind every time. It's visible and individually
revocable from **Users → Profile → Application Passwords** like any other,
and needs no code on the resource-server side beyond the bearer-token shim
every other route already uses. There is no refresh token grant — the
underlying application password does not expire, so none is needed; a
client that requests one gets `unsupported_grant_type` rather than a silent
failure.

```
POST /elementor-mcp/revoke
  token=<the access token>
```

RFC 7009 revocation: the matching application password is deleted and the
token stops working immediately. Always answers `200 { "revoked": true }`,
including for an unknown or already-revoked token — the caller's goal is
met either way, and a distinguishable error would only confirm to a third
party whether a guessed token had ever been valid.

Errors from `/elementor-mcp/token` follow RFC 6749 §5.2: `{ "error": "...", "error_description": "..." }`
with `invalid_request`, `invalid_grant` (unknown/expired/replayed code,
`redirect_uri` mismatch, or a `code_verifier` that doesn't match the
original `code_challenge`), or `unsupported_grant_type`.

## MCP endpoint

`POST /mcp` speaks JSON-RPC 2.0 over a single request/response — the
"stateless Streamable HTTP" shape of the MCP specification. No session, no
SSE stream; every call is self-contained, which is what makes it a natural
fit for PHP's one-process-per-request model.

```bash
curl -u 'user:app password' \
  -X POST https://example.com/wp-json/elementor-mcp/v1/mcp \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"elementor_get_outline","arguments":{"postId":42}}}'
```

Supported methods: `initialize`, `ping`, `tools/list`, `tools/call`,
`prompts/list`, `prompts/get`. `GET` and `DELETE` on the same route return a
405 explaining the endpoint is stateless — there is no stream to resume or
session to close.

Batches (a JSON array of request objects) are supported; notifications (a
request with no `id`) get no reply and are dropped from a batch response, per
JSON-RPC 2.0.

Authentication is the same application password as every other route,
sent as HTTP Basic or, for clients that only offer one token field, as a
bearer token whose value is the same `base64(username:app_password)`. See
the README's Authentication section.

The 49 tools exposed here mirror the standalone `elementor-mcp` Node
server's tool surface, minus the `site` argument — this endpoint only ever
addresses the site it runs on. `docs/TOOLS.md` documents each one; the
descriptions and argument shapes are the same on both transports.

## Errors

Errors follow the WordPress REST shape:

```json
{ "code": "emcp_conflict", "message": "...", "data": { "status": 409 } }
```

| Code | Meaning |
| --- | --- |
| `emcp_elementor_missing` | Elementor is not active (501) |
| `emcp_not_a_document` | Post type is not Elementor-enabled (400) |
| `emcp_conflict` | `expectedHash` no longer matches (409) |
| `emcp_invalid_tree` | Tree failed validation; `data.errors` lists why (400) |
| `emcp_destructive_disabled` | Site has not opted into destructive operations (403) |
| `emcp_confirm_required` | Destructive call needs `confirm: true` (400) |
| `rest_no_route` | The bridge plugin is not installed or active (404) |
