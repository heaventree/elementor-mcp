/**
 * Page and document level tools.
 */

import { z } from 'zod';
import { defineTool, postIdArg, siteArg } from './context.js';
import type { ToolModule } from './context.js';
import { ok } from '../util/respond.js';
import { loadDocument } from '../elementor/documents.js';
import { outline } from '../elementor/tree.js';
import type { DocumentMeta, ElementNode } from '../elementor/types.js';

export const registerPageTools: ToolModule = (server, { clients }) => {
  defineTool(
    server,
    'elementor_list_pages',
    {
      title: 'List pages and posts',
      description:
        'List WordPress pages, posts and templates, newest edited first, with whether each is built with Elementor. ' +
        'Use elementorOnly to skip content the page builder does not own.',
      inputSchema: {
        site: siteArg,
        search: z.string().optional().describe('Match against title and content.'),
        type: z
          .array(z.string())
          .optional()
          .describe('Post types to include. Defaults to page and post.'),
        status: z.array(z.string()).optional().describe('Post statuses to include.'),
        elementorOnly: z
          .boolean()
          .default(false)
          .describe('Only return posts actually built with Elementor.'),
        templateType: z
          .string()
          .optional()
          .describe('Filter by Elementor template type, e.g. wp-page, header, footer, popup.'),
        page: z.number().int().positive().default(1),
        perPage: z.number().int().min(1).max(100).default(20),
      },
      annotations: { readOnlyHint: true, idempotentHint: true },
    },
    async (args) => {
      const client = clients.get(args.site);

      const result = await client.bridge<Record<string, unknown>>('documents', {
        query: {
          search: args.search,
          type: args.type,
          status: args.status,
          elementorOnly: args.elementorOnly,
          templateType: args.templateType,
          page: args.page,
          perPage: args.perPage,
        },
      });

      return ok(result);
    },
  );

  defineTool(
    server,
    'elementor_get_outline',
    {
      title: 'Get page structure outline',
      description:
        'Read the structure of an Elementor page: every element id, its type, and a short label taken from its ' +
        'most identifying setting. This is the tool to reach for first when editing a page — it is a small ' +
        'fraction of the size of the full element tree and gives you the ids every other element tool needs. ' +
        'It also returns the content hash used for safe concurrent writes.',
      inputSchema: {
        site: siteArg,
        postId: postIdArg,
        maxDepth: z
          .number()
          .int()
          .min(0)
          .default(0)
          .describe('Levels to descend. 0 returns the whole tree; 1 returns only top-level sections.'),
      },
      annotations: { readOnlyHint: true, idempotentHint: true },
    },
    async (args) => {
      const client = clients.get(args.site);

      const result = await client.bridge<Record<string, unknown>>(
        `documents/${args.postId}/outline`,
        { query: { maxDepth: args.maxDepth } },
      );

      return ok(result);
    },
  );

  defineTool(
    server,
    'elementor_get_page',
    {
      title: 'Get a page',
      description:
        'Read a page: metadata, a census of which widgets it uses, its content hash, and optionally the full ' +
        'element tree. Leave includeElements false unless you genuinely need every setting — element trees run ' +
        'to hundreds of kilobytes on real pages. Prefer elementor_get_outline, then elementor_get_element for ' +
        'the specific parts you care about.',
      inputSchema: {
        site: siteArg,
        postId: postIdArg,
        includeElements: z
          .boolean()
          .default(false)
          .describe('Include the complete element tree. Large; use sparingly.'),
      },
      annotations: { readOnlyHint: true, idempotentHint: true },
    },
    async (args) => {
      const client = clients.get(args.site);

      const result = await client.bridge<DocumentMeta>(`documents/${args.postId}`, {
        query: { includeElements: args.includeElements },
      });

      return ok(result);
    },
  );

  defineTool(
    server,
    'elementor_create_page',
    {
      title: 'Create a page',
      description:
        'Create a new Elementor-enabled page, post or template. Starts empty unless you pass an element tree. ' +
        'Created as a draft by default so nothing goes live unreviewed. ' +
        'For a full-width blank canvas set pageTemplate to elementor_canvas.',
      inputSchema: {
        site: siteArg,
        title: z.string().min(1).describe('Page title.'),
        type: z.string().default('page').describe('Post type, e.g. page or post.'),
        status: z
          .enum(['draft', 'publish', 'pending', 'private'])
          .default('draft')
          .describe('Post status. Defaults to draft.'),
        slug: z.string().optional(),
        parent: z.number().int().optional().describe('Parent post ID for hierarchical types.'),
        pageTemplate: z
          .string()
          .optional()
          .describe('Theme template: elementor_canvas (blank), elementor_header_footer (no theme content), or a theme file.'),
        elements: z
          .array(z.record(z.string(), z.unknown()))
          .optional()
          .describe('Optional starting element tree.'),
      },
      annotations: { readOnlyHint: false, destructiveHint: false },
    },
    async (args) => {
      const client = clients.get(args.site);

      const result = await client.bridge<DocumentMeta>('documents', {
        method: 'POST',
        write: true,
        body: {
          title: args.title,
          type: args.type,
          status: args.status,
          slug: args.slug,
          parent: args.parent,
          pageTemplate: args.pageTemplate,
          elements: (args.elements ?? []) as ElementNode[],
        },
      });

      return ok(result, `Created "${result.title}" (id ${result.id}) as ${args.status}.`);
    },
  );

  defineTool(
    server,
    'elementor_update_page_meta',
    {
      title: 'Update page metadata',
      description:
        'Change a page\'s title, slug, status, parent or featured image. This edits WordPress post fields, ' +
        'not the Elementor layout — use the element tools for that.',
      inputSchema: {
        site: siteArg,
        postId: postIdArg,
        title: z.string().optional(),
        slug: z.string().optional(),
        status: z.enum(['draft', 'publish', 'pending', 'private', 'future']).optional(),
        parent: z.number().int().optional(),
        featuredMediaId: z.number().int().optional().describe('Attachment ID to use as featured image.'),
        postType: z
          .string()
          .default('pages')
          .describe('REST base of the post type: "pages", "posts", or a custom type\'s rest_base.'),
      },
      annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true },
    },
    async (args) => {
      const client = clients.get(args.site);
      const body: Record<string, unknown> = {};

      if (args.title !== undefined) body.title = args.title;
      if (args.slug !== undefined) body.slug = args.slug;
      if (args.status !== undefined) body.status = args.status;
      if (args.parent !== undefined) body.parent = args.parent;
      if (args.featuredMediaId !== undefined) body.featured_media = args.featuredMediaId;

      if (Object.keys(body).length === 0) {
        return ok({ changed: false }, 'Nothing to change — pass at least one field.');
      }

      const result = await client.core<Record<string, unknown>>(`${args.postType}/${args.postId}`, {
        method: 'POST',
        write: true,
        body,
      });

      return ok({
        id: result.id,
        title: (result.title as { rendered?: string } | undefined)?.rendered,
        slug: result.slug,
        status: result.status,
        link: result.link,
      });
    },
  );

  defineTool(
    server,
    'elementor_duplicate_page',
    {
      title: 'Duplicate a page',
      description:
        'Copy a page, its Elementor layout and its page settings into a new draft, regenerating every element id ' +
        'so the copy is independent. The usual way to iterate on a live page safely: duplicate it, edit the copy, ' +
        'then publish when it is right.',
      inputSchema: {
        site: siteArg,
        postId: postIdArg,
        title: z.string().optional().describe('Title for the copy. Defaults to the original plus "(copy)".'),
        status: z.enum(['draft', 'publish', 'pending', 'private']).default('draft'),
        slug: z.string().optional(),
      },
      annotations: { readOnlyHint: false, destructiveHint: false },
    },
    async (args) => {
      const client = clients.get(args.site);

      const result = await client.bridge<DocumentMeta>(`documents/${args.postId}/duplicate`, {
        method: 'POST',
        write: true,
        body: { title: args.title, status: args.status, slug: args.slug },
      });

      return ok(result, `Duplicated ${args.postId} into "${result.title}" (id ${result.id}).`);
    },
  );

  defineTool(
    server,
    'elementor_delete_page',
    {
      title: 'Delete a page',
      description:
        'Move a page to the trash, or delete it permanently with force. Permanent deletion additionally requires ' +
        'the site to have opted into destructive operations and confirm to be true.',
      inputSchema: {
        site: siteArg,
        postId: postIdArg,
        force: z
          .boolean()
          .default(false)
          .describe('Skip the trash and delete permanently. Cannot be undone.'),
        confirm: z.boolean().default(false).describe('Required when force is true.'),
      },
      annotations: { readOnlyHint: false, destructiveHint: true },
    },
    async (args) => {
      const client = clients.get(args.site);

      const result = await client.bridge<Record<string, unknown>>(`documents/${args.postId}`, {
        method: 'DELETE',
        write: true,
        query: { force: args.force, confirm: args.confirm },
      });

      return ok(result, args.force ? `Permanently deleted ${args.postId}.` : `Moved ${args.postId} to trash.`);
    },
  );

  defineTool(
    server,
    'elementor_render_page',
    {
      title: 'Render page HTML',
      description:
        'Render a page — or one element of it — to HTML as the front end would. Use this to check what an edit ' +
        'actually produced, especially for widgets whose output depends on dynamic data.',
      inputSchema: {
        site: siteArg,
        postId: postIdArg,
        elementId: z
          .string()
          .optional()
          .describe('Render just this element instead of the whole page.'),
        maxLength: z
          .number()
          .int()
          .min(500)
          .max(200_000)
          .default(20_000)
          .describe('Truncate the returned HTML at this many characters.'),
      },
      annotations: { readOnlyHint: true, idempotentHint: true },
    },
    async (args) => {
      const client = clients.get(args.site);

      const result = await client.bridge<{ html?: string }>(`documents/${args.postId}/render`, {
        query: { elementId: args.elementId },
      });

      const html = result.html ?? '';
      const truncated = html.length > args.maxLength;

      return ok({
        postId: args.postId,
        elementId: args.elementId ?? null,
        length: html.length,
        truncated,
        ...(truncated ? { note: `HTML truncated at ${args.maxLength} of ${html.length} characters.` } : {}),
        html: truncated ? html.slice(0, args.maxLength) : html,
      });
    },
  );

  defineTool(
    server,
    'elementor_get_page_tree',
    {
      title: 'Get the full element tree',
      description:
        'Read a page\'s complete element tree including every setting. This is the largest thing this server ' +
        'returns — only call it when you need to inspect settings across many elements at once. For normal ' +
        'editing use elementor_get_outline plus elementor_get_element.',
      inputSchema: {
        site: siteArg,
        postId: postIdArg,
        summaryOnly: z
          .boolean()
          .default(false)
          .describe('Return counts and an outline instead of the tree itself.'),
      },
      annotations: { readOnlyHint: true, idempotentHint: true },
    },
    async (args) => {
      const client = clients.get(args.site);
      const loaded = await loadDocument(client, args.postId);

      if (args.summaryOnly) {
        return ok({
          ...loaded.meta,
          hash: loaded.hash,
          outline: outline(loaded.elements, 2),
        });
      }

      return ok({
        postId: args.postId,
        hash: loaded.hash,
        nodeCount: loaded.meta.nodeCount,
        elements: loaded.elements,
      });
    },
  );
};
