/**
 * Template library tools.
 */

import { z } from 'zod';
import { defineTool, positionArg, postIdArg, siteArg } from './context.js';
import type { ToolModule } from './context.js';
import { ok } from '../util/respond.js';
import { editDocument } from '../elementor/documents.js';
import { insert, regenerateIds } from '../elementor/tree.js';
import type { ElementNode } from '../elementor/types.js';

export const registerTemplateTools: ToolModule = (server, { clients }) => {
  defineTool(
    server,
    'elementor_list_templates',
    {
      title: 'List saved templates',
      description:
        'List the Elementor templates saved on this site — sections, containers, pages, headers, footers and ' +
        'popups. Reusing a proven template is cheaper and more consistent than rebuilding the same layout.',
      inputSchema: {
        site: siteArg,
        search: z.string().optional(),
        type: z
          .string()
          .optional()
          .describe('Template type: container, section, page, header, footer, popup, single, archive.'),
        page: z.number().int().positive().default(1),
        perPage: z.number().int().min(1).max(100).default(50),
      },
      annotations: { readOnlyHint: true, idempotentHint: true },
    },
    async (args) => {
      const client = clients.get(args.site);

      return ok(
        await client.bridge<Record<string, unknown>>('templates', {
          query: {
            search: args.search,
            type: args.type,
            page: args.page,
            perPage: args.perPage,
          },
        }),
      );
    },
  );

  defineTool(
    server,
    'elementor_get_template',
    {
      title: 'Get a template',
      description:
        'Read a template\'s element tree. By default ids are regenerated so the tree can be pasted into a page ' +
        'without colliding with anything already there.',
      inputSchema: {
        site: siteArg,
        templateId: z.number().int().positive(),
        freshIds: z.boolean().default(true).describe('Regenerate element ids so the tree is safe to paste.'),
      },
      annotations: { readOnlyHint: true, idempotentHint: true },
    },
    async (args) => {
      const client = clients.get(args.site);

      return ok(
        await client.bridge<Record<string, unknown>>(`templates/${args.templateId}`, {
          query: { freshIds: args.freshIds },
        }),
      );
    },
  );

  defineTool(
    server,
    'elementor_save_template',
    {
      title: 'Save a template',
      description:
        'Save a layout to the template library, either from an element tree you pass or by copying from an ' +
        'existing page (optionally just one element of it). Save a section once, reuse it everywhere.',
      inputSchema: {
        site: siteArg,
        title: z.string().min(1),
        type: z
          .string()
          .default('container')
          .describe('Template type: container, section, page, header, footer, popup.'),
        elements: z
          .array(z.record(z.string(), z.unknown()))
          .optional()
          .describe('The element tree to save. Omit if using sourcePostId.'),
        sourcePostId: z.number().int().optional().describe('Copy the layout from this page instead.'),
        sourceElementId: z
          .string()
          .optional()
          .describe('With sourcePostId, save only this element and its subtree.'),
      },
      annotations: { readOnlyHint: false, destructiveHint: false },
    },
    async (args) => {
      const client = clients.get(args.site);

      const result = await client.bridge<{ id: number; title: string }>('templates', {
        method: 'POST',
        write: true,
        body: {
          title: args.title,
          type: args.type,
          elements: args.elements,
          sourceId: args.sourcePostId,
          elementId: args.sourceElementId,
        },
      });

      return ok(result, `Saved template "${result.title}" (id ${result.id}).`);
    },
  );

  defineTool(
    server,
    'elementor_apply_template',
    {
      title: 'Insert a template into a page',
      description:
        'Insert a saved template\'s layout into a page, with fresh element ids. Place it at the page root, or ' +
        'relative to an existing element with targetId and position. The page is snapshotted first.',
      inputSchema: {
        site: siteArg,
        postId: postIdArg,
        templateId: z.number().int().positive(),
        targetId: z.string().optional().describe('Place relative to this element. Omit to append at the root.'),
        position: positionArg,
      },
      annotations: { readOnlyHint: false, destructiveHint: false },
    },
    async (args) => {
      const client = clients.get(args.site);

      const template = await client.bridge<{ title: string; elements: ElementNode[] }>(
        `templates/${args.templateId}`,
        { query: { freshIds: true } },
      );

      const nodes = Array.isArray(template.elements) ? template.elements : [];

      if (nodes.length === 0) {
        return ok(
          { inserted: 0, templateId: args.templateId },
          `Template ${args.templateId} has no elements to insert.`,
        );
      }

      const outcome = await editDocument(
        client,
        args.postId,
        (elements) => {
          const taken = new Set<string>();
          let current = elements;

          // Insert in order so the template's own sequence is preserved.
          let anchor = args.targetId;
          let position = args.position;

          for (const node of regenerateIds(nodes, taken)) {
            const options: { targetId?: string; position?: typeof position } = { position };
            if (anchor) options.targetId = anchor;

            current = insert(current, node, options);

            // Subsequent nodes go after the one just placed.
            anchor = node.id;
            position = 'after';
          }

          return { elements: current, extra: { insertedRootNodes: nodes.length } };
        },
        `apply template ${args.templateId}`,
      );

      return ok(
        {
          postId: args.postId,
          templateId: args.templateId,
          insertedRootNodes: nodes.length,
          hash: outcome.result.hash,
          nodeCountAfter: outcome.after.nodeCount,
          editUrl: outcome.result.editUrl,
        },
        `Inserted template "${template.title}" into page ${args.postId}.`,
      );
    },
  );

  defineTool(
    server,
    'elementor_export_template',
    {
      title: 'Export a template as JSON',
      description:
        'Export a template in Elementor\'s own JSON format, ready to import into another site with ' +
        'elementor_import_template or through the Elementor UI.',
      inputSchema: { site: siteArg, templateId: z.number().int().positive() },
      annotations: { readOnlyHint: true, idempotentHint: true },
    },
    async (args) => {
      const client = clients.get(args.site);

      return ok(await client.bridge<Record<string, unknown>>(`templates/${args.templateId}/export`));
    },
  );

  defineTool(
    server,
    'elementor_import_template',
    {
      title: 'Import a template from JSON',
      description:
        'Import an Elementor template JSON payload as a new template on this site. Pass the decoded JSON object ' +
        'from an Elementor export or from elementor_export_template — which makes copying a layout between ' +
        'sites a two-call operation.',
      inputSchema: {
        site: siteArg,
        payload: z
          .record(z.string(), z.unknown())
          .describe('The decoded Elementor export object, containing "content" or "elements".'),
        title: z.string().optional().describe('Override the imported template\'s title.'),
      },
      annotations: { readOnlyHint: false, destructiveHint: false },
    },
    async (args) => {
      const client = clients.get(args.site);

      const result = await client.bridge<{ id: number; title: string }>('templates/import', {
        method: 'POST',
        write: true,
        body: { payload: args.payload, title: args.title },
      });

      return ok(result, `Imported template "${result.title}" (id ${result.id}).`);
    },
  );
};
