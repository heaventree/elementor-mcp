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
