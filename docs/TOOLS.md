# Tool reference

Generated from the server — 50 tools. Regenerate with:

```bash
npm run build && node tests/gen-tool-docs.mjs > docs/TOOLS.md
```

Every tool accepts an optional `site` argument naming a configured site profile;
omit it to use the default. Arguments marked **required** have no default.

## Sites

### `elementor_list_sites`

_read-only · idempotent_

List the WordPress sites this server can reach, with which one is the default. Call this first when you are unsure which site name to pass to other tools.

### `elementor_site_status`

_read-only · idempotent_

Report what a site is running: WordPress and Elementor versions, whether Elementor Pro is present, how many widget types are registered, the active kit id, the capabilities of the authenticated user, and whether the bridge plugin and destructive operations are enabled. Run this before a first editing session on an unfamiliar site.

### `elementor_native_mcp_call`


Invoke one of Elementor's own built-in MCP abilities through the bridge. Elementor 4.3+ ships abilities covering v4 atomic features (global classes, variables, components, compositions) that have no v3 equivalent. Only works where that module is active — check elementor_site_status first, under nativeMcp.

| Argument | Type | Notes |
| --- | --- | --- |
| `tool` | string | **required**. The native ability slug, e.g. "list-components". |
| `input` | object | default `{}`. Arguments for that ability. |

## Pages

### `elementor_list_pages`

_read-only · idempotent_

List WordPress pages, posts and templates, newest edited first, with whether each is built with Elementor. Use elementorOnly to skip content the page builder does not own.

| Argument | Type | Notes |
| --- | --- | --- |
| `search` | string | Match against title and content. |
| `type` | string[] | Post types to include. Defaults to page and post. |
| `status` | string[] | Post statuses to include. |
| `elementorOnly` | boolean | default `false`. Only return posts actually built with Elementor. |
| `templateType` | string | Filter by Elementor template type, e.g. wp-page, header, footer, popup. |
| `page` | integer | default `1` |
| `perPage` | integer | default `20` |

### `elementor_get_outline`

_read-only · idempotent_

Read the structure of an Elementor page: every element id, its type, and a short label taken from its most identifying setting. This is the tool to reach for first when editing a page — it is a small fraction of the size of the full element tree and gives you the ids every other element tool needs. It also returns the content hash used for safe concurrent writes.

| Argument | Type | Notes |
| --- | --- | --- |
| `postId` | integer | **required**. WordPress post ID of the Elementor page, post or template. |
| `maxDepth` | integer | default `0`. Levels to descend. 0 returns the whole tree; 1 returns only top-level sections. |

### `elementor_get_page`

_read-only · idempotent_

Read a page: metadata, a census of which widgets it uses, its content hash, and optionally the full element tree. Leave includeElements false unless you genuinely need every setting — element trees run to hundreds of kilobytes on real pages. Prefer elementor_get_outline, then elementor_get_element for the specific parts you care about.

| Argument | Type | Notes |
| --- | --- | --- |
| `postId` | integer | **required**. WordPress post ID of the Elementor page, post or template. |
| `includeElements` | boolean | default `false`. Include the complete element tree. Large; use sparingly. |

### `elementor_get_page_tree`

_read-only · idempotent_

Read a page's complete element tree including every setting. This is the largest thing this server returns — only call it when you need to inspect settings across many elements at once. For normal editing use elementor_get_outline plus elementor_get_element.

| Argument | Type | Notes |
| --- | --- | --- |
| `postId` | integer | **required**. WordPress post ID of the Elementor page, post or template. |
| `summaryOnly` | boolean | default `false`. Return counts and an outline instead of the tree itself. |

### `elementor_create_page`


Create a new Elementor-enabled page, post or template. Starts empty unless you pass an element tree. Created as a draft by default so nothing goes live unreviewed. For a full-width blank canvas set pageTemplate to elementor_canvas.

| Argument | Type | Notes |
| --- | --- | --- |
| `title` | string | **required**. Page title. |
| `type` | string | default `"page"`. Post type, e.g. page or post. |
| `status` | `draft` \| `publish` \| `pending` \| `private` | default `"draft"`. Post status. Defaults to draft. |
| `slug` | string |  |
| `parent` | integer | Parent post ID for hierarchical types. |
| `pageTemplate` | string | Theme template: elementor_canvas (blank), elementor_header_footer (no theme content), or a theme file. |
| `elements` | object[] | Optional starting element tree. |

### `elementor_update_page_meta`

_idempotent_

Change a page's title, slug, status, parent or featured image. This edits WordPress post fields, not the Elementor layout — use the element tools for that.

| Argument | Type | Notes |
| --- | --- | --- |
| `postId` | integer | **required**. WordPress post ID of the Elementor page, post or template. |
| `title` | string |  |
| `slug` | string |  |
| `status` | `draft` \| `publish` \| `pending` \| `private` \| `future` |  |
| `parent` | integer |  |
| `featuredMediaId` | integer | Attachment ID to use as featured image. |
| `postType` | string | default `"pages"`. REST base of the post type: "pages", "posts", or a custom type's rest_base. |

### `elementor_duplicate_page`


Copy a page, its Elementor layout and its page settings into a new draft, regenerating every element id so the copy is independent. The usual way to iterate on a live page safely: duplicate it, edit the copy, then publish when it is right.

| Argument | Type | Notes |
| --- | --- | --- |
| `postId` | integer | **required**. WordPress post ID of the Elementor page, post or template. |
| `title` | string | Title for the copy. Defaults to the original plus "(copy)". |
| `status` | `draft` \| `publish` \| `pending` \| `private` | default `"draft"` |
| `slug` | string |  |

### `elementor_delete_page`

_**destructive**_

Move a page to the trash, or delete it permanently with force. Permanent deletion additionally requires the site to have opted into destructive operations and confirm to be true.

| Argument | Type | Notes |
| --- | --- | --- |
| `postId` | integer | **required**. WordPress post ID of the Elementor page, post or template. |
| `force` | boolean | default `false`. Skip the trash and delete permanently. Cannot be undone. |
| `confirm` | boolean | default `false`. Required when force is true. |

### `elementor_render_page`

_read-only · idempotent_

Render a page — or one element of it — to HTML as the front end would. Use this to check what an edit actually produced, especially for widgets whose output depends on dynamic data.

| Argument | Type | Notes |
| --- | --- | --- |
| `postId` | integer | **required**. WordPress post ID of the Elementor page, post or template. |
| `elementId` | string | Render just this element instead of the whole page. |
| `maxLength` | integer | default `20000`. Truncate the returned HTML at this many characters. |

## Elements

### `elementor_get_element`

_read-only · idempotent_

Read a single element and its subtree, including every setting. Use this after elementor_get_outline to inspect just the part of a page you are about to change.

| Argument | Type | Notes |
| --- | --- | --- |
| `postId` | integer | **required**. WordPress post ID of the Elementor page, post or template. |
| `elementId` | string | **required**. Elementor element id, as returned by elementor_get_outline. |
| `settingsOnly` | boolean | default `false`. Return only this element's settings, not its children. |

### `elementor_find_elements`

_read-only · idempotent_

Search one page for elements by widget type, by text in their settings, or both, and get back their ids with a short label. Use this to locate what you want to edit without reading the whole tree.

| Argument | Type | Notes |
| --- | --- | --- |
| `postId` | integer | **required**. WordPress post ID of the Elementor page, post or template. |
| `widgetType` | string | Only match this widget or element type. |
| `text` | string | Only match elements whose settings contain this text. |
| `limit` | integer | default `50` |

### `elementor_add_widget`


Insert a new widget into a page. Pass the widget type (heading, image, button, text-editor, and so on — see elementor_list_widgets) and its settings. With no targetId the widget is appended to the page root; a widget must otherwise go inside a container or column, so use position before/after to place it beside an existing widget.

| Argument | Type | Notes |
| --- | --- | --- |
| `postId` | integer | **required**. WordPress post ID of the Elementor page, post or template. |
| `widgetType` | string | **required**. Widget type name, e.g. "heading". |
| `settings` | object | default `{}`. Elementor control values keyed by control name. Call elementor_get_widget_schema for the valid keys of a widget. Responsive variants use _tablet and _mobile suffixes, e.g. padding_tablet. |
| `targetId` | string | Element to place this relative to. Omit to append at the page root. |
| `position` | `append` \| `prepend` \| `before` \| `after` | default `"append"`. append/prepend place the element inside the target; before/after place it as a sibling of the target. |

### `elementor_add_container`


Insert a structural element — a flexbox container (the modern default), or a section/column for pages built the classic way. Containers are what you put widgets inside. You can seed it with child elements in one call.

| Argument | Type | Notes |
| --- | --- | --- |
| `postId` | integer | **required**. WordPress post ID of the Elementor page, post or template. |
| `elType` | `container` \| `section` \| `column` | default `"container"`. container is the modern flexbox element; section/column are the legacy layout pair. |
| `settings` | object | default `{}`. Elementor control values keyed by control name. Call elementor_get_widget_schema for the valid keys of a widget. Responsive variants use _tablet and _mobile suffixes, e.g. padding_tablet. |
| `children` | object[] | Optional child element nodes to place inside it. |
| `targetId` | string |  |
| `position` | `append` \| `prepend` \| `before` \| `after` | default `"append"`. append/prepend place the element inside the target; before/after place it as a sibling of the target. |

### `elementor_update_element`

_idempotent_

Change an element's settings. In merge mode (the default) the keys you pass are applied over the existing settings and everything else is left alone; in replace mode the settings object is swapped wholesale, which discards any setting you do not include. Merge is almost always what you want.

| Argument | Type | Notes |
| --- | --- | --- |
| `postId` | integer | **required**. WordPress post ID of the Elementor page, post or template. |
| `elementId` | string | **required**. Elementor element id, as returned by elementor_get_outline. |
| `settings` | object | **required**. Elementor control values keyed by control name. Call elementor_get_widget_schema for the valid keys of a widget. Responsive variants use _tablet and _mobile suffixes, e.g. padding_tablet. |
| `mode` | `merge` \| `replace` | default `"merge"`. merge keeps existing settings; replace discards anything not passed. |

### `elementor_move_element`


Relocate an element within the page. Give a referenceId plus a position to say where it lands; omit referenceId to move it to the page root. Moving an element into its own descendant is refused.

| Argument | Type | Notes |
| --- | --- | --- |
| `postId` | integer | **required**. WordPress post ID of the Elementor page, post or template. |
| `elementId` | string | **required**. Elementor element id, as returned by elementor_get_outline. |
| `referenceId` | string | Element to position relative to. Omit to move to the page root. |
| `position` | `append` \| `prepend` \| `before` \| `after` | default `"append"`. append/prepend place the element inside the target; before/after place it as a sibling of the target. |

### `elementor_duplicate_element`


Copy an element and its whole subtree, placed immediately after the original, with fresh ids throughout. The quickest way to repeat a card, column or row you have already styled.

| Argument | Type | Notes |
| --- | --- | --- |
| `postId` | integer | **required**. WordPress post ID of the Elementor page, post or template. |
| `elementId` | string | **required**. Elementor element id, as returned by elementor_get_outline. |

### `elementor_delete_element`

_**destructive**_

Remove an element and everything inside it from a page. A snapshot is taken first, so elementor_restore_snapshot can undo it.

| Argument | Type | Notes |
| --- | --- | --- |
| `postId` | integer | **required**. WordPress post ID of the Elementor page, post or template. |
| `elementId` | string | **required**. Elementor element id, as returned by elementor_get_outline. |

### `elementor_reorder_children`

_idempotent_

Set the order of a container's direct children by listing their ids. Ids you leave out keep their relative order at the end, so you can move one item to the front without listing everything.

| Argument | Type | Notes |
| --- | --- | --- |
| `postId` | integer | **required**. WordPress post ID of the Elementor page, post or template. |
| `elementId` | string | **required**. Elementor element id, as returned by elementor_get_outline. |
| `order` | string[] | **required**. Child element ids in their new order. |

### `elementor_wrap_element`


Put a new container around an existing element, in place. Useful for adding a background, padding or width constraint around something without rebuilding it.

| Argument | Type | Notes |
| --- | --- | --- |
| `postId` | integer | **required**. WordPress post ID of the Elementor page, post or template. |
| `elementId` | string | **required**. Elementor element id, as returned by elementor_get_outline. |
| `wrapperSettings` | object | default `{}`. Settings for the new wrapping container. |
| `wrapperType` | `container` \| `section` | default `"container"` |

### `elementor_batch_edit`

_**destructive**_

Run a list of element operations against a page as one atomic change: insert, update, move, duplicate, delete, reorder and wrap. Operations apply in order, each seeing the result of the last. If any one fails the whole batch is abandoned and nothing is written. Prefer this over a series of single-element calls when building or restructuring a section — it is one read and one write instead of many, and it cannot leave the page half-changed.

| Argument | Type | Notes |
| --- | --- | --- |
| `postId` | integer | **required**. WordPress post ID of the Elementor page, post or template. |
| `operations` | object[] | **required**. Operations in order. Each is an object with "op" and its arguments: {"op":"insert","node":{...},"targetId":"abc","position":"append"}, {"op":"update","targetId":"abc","settings":{...},"mode":"merge"}, {"op":"move","targetId":"abc","referenceId":"def","position":"after"}, {"op":"duplicate","targetId":"abc"}, {"op":"delete","targetId":"abc"}, {"op":"reorder","targetId":"abc","order":["x","y"]}, {"op":"wrap","targetId":"abc","wrapper":{...}}. |
| `label` | string | Snapshot label describing this change. |

### `elementor_replace_page_tree`

_**destructive**_

Overwrite a page's whole element tree. This discards the existing layout completely, so reach for it when generating a page from scratch rather than to make a change. A snapshot is taken first. For edits to an existing page use elementor_batch_edit instead.

| Argument | Type | Notes |
| --- | --- | --- |
| `postId` | integer | **required**. WordPress post ID of the Elementor page, post or template. |
| `elements` | object[] | **required**. The complete new element tree. An empty array clears the page. |
| `confirm` | boolean | default `false`. Must be true — this replaces everything on the page. |
| `label` | string |  |

## Widgets and schemas

### `elementor_list_widgets`

_read-only · idempotent_

List every widget type registered on the site, with its title, panel categories and whether it comes from Elementor Pro. Search by name or keyword to find the right one. Always check here before adding a widget — a site only has the widgets its installed plugins provide.

| Argument | Type | Notes |
| --- | --- | --- |
| `search` | string | Filter by name, title or keyword, e.g. "form" or "slider". |
| `category` | string | Filter by panel category slug, e.g. "basic" or "pro-elements". |
| `includeHidden` | boolean | default `false`. Include widgets Elementor hides from the editor panel. |

### `elementor_get_widget_schema`

_read-only · idempotent_

Read the full control schema for a widget or structural element: every setting it accepts, grouped by panel tab and section, with types, defaults, allowed options and which settings are responsive. Read this before setting unfamiliar widget settings — guessing control names is the most common cause of an edit that saves without visible effect.

| Argument | Type | Notes |
| --- | --- | --- |
| `name` | string | **required**. Widget type (e.g. "heading") or element type (e.g. "container"). |
| `kind` | `widget` \| `element` | default `"widget"`. Use "element" for container, section and column. |
| `compact` | boolean | default `true`. Trim CSS selector maps and render hints. Turn off only if you need raw control definitions. |
| `section` | string | Return only this section of the schema, to keep the response small. |

### `elementor_list_element_types`

_read-only · idempotent_

List the structural element types available (container, section, column) and the widget panel categories the site defines.

### `elementor_list_dynamic_tags`

_read-only · idempotent_

List the dynamic tags registered on the site — post title, ACF field, site logo and so on — which let a widget setting pull live data instead of holding a fixed value. Mostly an Elementor Pro feature.

## Global design

### `elementor_get_globals`

_read-only · idempotent_

Read the site's Elementor kit: global colour and typography palettes with the ids widgets refer to them by, layout defaults such as container width and breakpoints, and the site custom CSS. Read this before styling anything — using an existing global token keeps a site consistent, and changing one restyles every element bound to it.

| Argument | Type | Notes |
| --- | --- | --- |
| `includeRaw` | boolean | default `false`. Include the complete raw kit settings object as well as the friendly view. |

### `elementor_set_global_color`

_idempotent_

Change one global colour by id (primary, secondary, text, accent) or by its title, or add a new custom colour if it does not exist. Every element bound to that token updates at once, and Elementor's CSS is regenerated. This is the right way to restyle a site's palette.

| Argument | Type | Notes |
| --- | --- | --- |
| `id` | string | **required**. Colour id such as "primary", or the colour's title. |
| `value` | string | **required**. Hex colour, e.g. "#1A73E8". |
| `title` | string | Label, used when creating a new custom colour. |

### `elementor_update_globals`

_**destructive**_

Merge settings into the Elementor kit — colour and typography palettes, container width, breakpoints, theme style defaults. This changes the whole site at once, so read elementor_get_globals first and change only the keys you mean to. A snapshot of the kit is taken before the write.

| Argument | Type | Notes |
| --- | --- | --- |
| `settings` | object | **required**. Kit settings to merge, e.g. {"container_width":{"unit":"px","size":1200}} or a full "system_colors" array. Keys not passed are left alone. |

### `elementor_custom_css`

_**destructive**_

Read the site-wide custom CSS held on the Elementor kit, or replace it. Passing css writes it; omitting css just reads. Note that Elementor only renders this on Pro.

| Argument | Type | Notes |
| --- | --- | --- |
| `css` | string | New CSS. Omit to read the current value. |

### `elementor_get_global_classes`

_read-only · idempotent_

Read Elementor v4 global classes — the reusable style classes used by the atomic/Editor One element model. Returns an empty list on sites that predate that feature.

### `elementor_flush_css`

_idempotent_

Clear Elementor's cached CSS so it regenerates on the next page load. The editing tools already do this after every write; call it manually when styles look stale after changes made outside this server, or after a theme or plugin update.

| Argument | Type | Notes |
| --- | --- | --- |
| `postId` | integer | Flush one page. Omit to regenerate the whole site's CSS. |

## Templates

### `elementor_list_templates`

_read-only · idempotent_

List the Elementor templates saved on this site — sections, containers, pages, headers, footers and popups. Reusing a proven template is cheaper and more consistent than rebuilding the same layout.

| Argument | Type | Notes |
| --- | --- | --- |
| `search` | string |  |
| `type` | string | Template type: container, section, page, header, footer, popup, single, archive. |
| `page` | integer | default `1` |
| `perPage` | integer | default `50` |

### `elementor_get_template`

_read-only · idempotent_

Read a template's element tree. By default ids are regenerated so the tree can be pasted into a page without colliding with anything already there.

| Argument | Type | Notes |
| --- | --- | --- |
| `templateId` | integer | **required** |
| `freshIds` | boolean | default `true`. Regenerate element ids so the tree is safe to paste. |

### `elementor_save_template`


Save a layout to the template library, either from an element tree you pass or by copying from an existing page (optionally just one element of it). Save a section once, reuse it everywhere.

| Argument | Type | Notes |
| --- | --- | --- |
| `title` | string | **required** |
| `type` | string | default `"container"`. Template type: container, section, page, header, footer, popup. |
| `elements` | object[] | The element tree to save. Omit if using sourcePostId. |
| `sourcePostId` | integer | Copy the layout from this page instead. |
| `sourceElementId` | string | With sourcePostId, save only this element and its subtree. |

### `elementor_apply_template`


Insert a saved template's layout into a page, with fresh element ids. Place it at the page root, or relative to an existing element with targetId and position. The page is snapshotted first.

| Argument | Type | Notes |
| --- | --- | --- |
| `postId` | integer | **required**. WordPress post ID of the Elementor page, post or template. |
| `templateId` | integer | **required** |
| `targetId` | string | Place relative to this element. Omit to append at the root. |
| `position` | `append` \| `prepend` \| `before` \| `after` | default `"append"`. append/prepend place the element inside the target; before/after place it as a sibling of the target. |

### `elementor_export_template`

_read-only · idempotent_

Export a template in Elementor's own JSON format, ready to import into another site with elementor_import_template or through the Elementor UI.

| Argument | Type | Notes |
| --- | --- | --- |
| `templateId` | integer | **required** |

### `elementor_import_template`


Import an Elementor template JSON payload as a new template on this site. Pass the decoded JSON object from an Elementor export or from elementor_export_template — which makes copying a layout between sites a two-call operation.

| Argument | Type | Notes |
| --- | --- | --- |
| `payload` | object | **required**. The decoded Elementor export object, containing "content" or "elements". |
| `title` | string | Override the imported template's title. |

## History and undo

### `elementor_list_snapshots`

_read-only · idempotent_

List the layout snapshots stored for a page, newest first, with when each was taken and why. One is taken automatically before every write this server makes.

| Argument | Type | Notes |
| --- | --- | --- |
| `postId` | integer | **required**. WordPress post ID of the Elementor page, post or template. |

### `elementor_create_snapshot`


Take a named snapshot of a page's current layout. Worth doing before a large restructuring so you have one clearly labelled point to come back to.

| Argument | Type | Notes |
| --- | --- | --- |
| `postId` | integer | **required**. WordPress post ID of the Elementor page, post or template. |
| `label` | string | default `"manual snapshot"`. Why you are taking it. |

### `elementor_restore_snapshot`

_**destructive**_

Roll a page back to a snapshot. With no snapshotId this restores the most recent one, which undoes the last change made through this server. The current state is snapshotted first, so a restore is itself reversible.

| Argument | Type | Notes |
| --- | --- | --- |
| `postId` | integer | **required**. WordPress post ID of the Elementor page, post or template. |
| `snapshotId` | string | Snapshot to restore. Omit to undo the most recent change. |

### `elementor_list_revisions`

_read-only · idempotent_

List a page's WordPress revisions, flagging which of them actually carry Elementor layout data. Revisions reach further back than snapshots but are less reliable for Elementor content.

| Argument | Type | Notes |
| --- | --- | --- |
| `postId` | integer | **required**. WordPress post ID of the Elementor page, post or template. |

### `elementor_restore_revision`

_**destructive**_

Restore a page's Elementor layout from a WordPress revision. Only works on revisions flagged as carrying Elementor data. A snapshot is taken first.

| Argument | Type | Notes |
| --- | --- | --- |
| `postId` | integer | **required**. WordPress post ID of the Elementor page, post or template. |
| `revisionId` | integer | **required**. Revision id from elementor_list_revisions. |

## Content and media

### `elementor_search`

_read-only · idempotent_

Search inside Elementor page data across the site — by text, by widget type, or by which elements define a given setting. Elementor stores its content as JSON in postmeta, so it is invisible to normal WordPress search; this is the way to find "which page has that old phone number on it".

| Argument | Type | Notes |
| --- | --- | --- |
| `text` | string | Text to find inside element settings. |
| `widgetType` | string | Only match this widget or element type. |
| `settingKey` | string | Only match elements that define this settings key. |
| `postIds` | integer[] | Restrict to these pages. |
| `postTypes` | string[] | Restrict to these post types. |
| `caseSensitive` | boolean | default `false` |
| `limit` | integer | default `100` |

### `elementor_replace_text`

_**destructive**_

Replace a string everywhere it appears in Elementor page data — a phone number, an old brand name, a changed URL. Runs as a dry run by default and reports exactly which pages and how many occurrences it would touch; set dryRun false and confirm true to commit. Every page it edits is snapshotted first. Always read the dry run before committing.

| Argument | Type | Notes |
| --- | --- | --- |
| `search` | string | **required**. The exact string to find. |
| `replace` | string | default `""`. What to put in its place. Empty removes it. |
| `dryRun` | boolean | default `true`. Report without writing. Leave true until you have read the report. |
| `confirm` | boolean | default `false`. Required when dryRun is false. |
| `postIds` | integer[] | Restrict to these pages. |
| `postTypes` | string[] |  |
| `caseSensitive` | boolean | default `false` |

### `elementor_widget_usage`

_read-only · idempotent_

Count which Elementor widgets are actually used across the site and how much each page contains. Useful before removing an addon plugin, or to find the heaviest pages.

| Argument | Type | Notes |
| --- | --- | --- |
| `postTypes` | string[] |  |

### `elementor_list_media`

_read-only · idempotent_

Search the WordPress media library and get back attachment ids and URLs. Elementor image controls need both, in the shape {"id":123,"url":"https://..."}.

| Argument | Type | Notes |
| --- | --- | --- |
| `search` | string |  |
| `mimeType` | string | Filter by MIME type, e.g. "image". |
| `page` | integer | default `1` |
| `perPage` | integer | default `20` |

### `elementor_upload_image`


Download a remote image into the WordPress media library and return the attachment id and URL, plus a ready-made value for an Elementor image control. Use this before setting any image setting — Elementor image controls want a library attachment, not a remote URL.

| Argument | Type | Notes |
| --- | --- | --- |
| `url` | string | **required**. Absolute http(s) URL of the image to fetch. |
| `title` | string |  |
| `altText` | string | Alt text. Worth setting for accessibility and SEO. |
| `attachToPostId` | integer | Attach the upload to this post. |
