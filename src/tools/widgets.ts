/**
 * Widget and control schema discovery.
 *
 * These read Elementor's live registry on the target site, so they describe
 * whatever is actually installed there — core widgets, Elementor Pro, and any
 * third-party addon pack — rather than a fixed list.
 */

import { z } from 'zod';
import { defineTool, siteArg } from './context.js';
import type { ToolModule } from './context.js';
import { ok } from '../util/respond.js';

export const registerWidgetTools: ToolModule = (server, { clients }) => {
  defineTool(
    server,
    'elementor_list_widgets',
    {
      title: 'List available widgets',
      description:
        'List every widget type registered on the site, with its title, panel categories and whether it comes ' +
        'from Elementor Pro. Search by name or keyword to find the right one. Always check here before ' +
        'adding a widget — a site only has the widgets its installed plugins provide.',
      inputSchema: {
        site: siteArg,
        search: z.string().optional().describe('Filter by name, title or keyword, e.g. "form" or "slider".'),
        category: z.string().optional().describe('Filter by panel category slug, e.g. "basic" or "pro-elements".'),
        includeHidden: z
          .boolean()
          .default(false)
          .describe('Include widgets Elementor hides from the editor panel.'),
      },
      annotations: { readOnlyHint: true, idempotentHint: true },
    },
    async (args) => {
      const client = clients.get(args.site);

      const result = await client.bridge<Record<string, unknown>>('widgets', {
        query: {
          search: args.search,
          category: args.category,
          includeHidden: args.includeHidden,
        },
      });

      return ok(result);
    },
  );

  defineTool(
    server,
    'elementor_get_widget_schema',
    {
      title: 'Get a widget\'s settings schema',
      description:
        'Read the full control schema for a widget or structural element: every setting it accepts, grouped by ' +
        'panel tab and section, with types, defaults, allowed options and which settings are responsive. ' +
        'Read this before setting unfamiliar widget settings — guessing control names is the most common cause ' +
        'of an edit that saves without visible effect.',
      inputSchema: {
        site: siteArg,
        name: z.string().min(1).describe('Widget type (e.g. "heading") or element type (e.g. "container").'),
        kind: z
          .enum(['widget', 'element'])
          .default('widget')
          .describe('Use "element" for container, section and column.'),
        compact: z
          .boolean()
          .default(true)
          .describe('Trim CSS selector maps and render hints. Turn off only if you need raw control definitions.'),
        section: z
          .string()
          .optional()
          .describe('Return only this section of the schema, to keep the response small.'),
      },
      annotations: { readOnlyHint: true, idempotentHint: true },
    },
    async (args) => {
      const client = clients.get(args.site);
      const base = args.kind === 'element' ? 'elements' : 'widgets';

      const schema = await client.bridge<{
        sections?: Array<{ id: string; label: string; tab: string; controls: unknown[] }>;
        [key: string]: unknown;
      }>(`${base}/${encodeURIComponent(args.name)}/schema`, {
        query: { compact: args.compact },
      });

      if (args.section && Array.isArray(schema.sections)) {
        const wanted = schema.sections.filter(
          (entry) => entry.id === args.section || entry.label === args.section,
        );

        if (wanted.length === 0) {
          return ok({
            name: args.name,
            requestedSection: args.section,
            availableSections: schema.sections.map((entry) => ({ id: entry.id, label: entry.label, tab: entry.tab })),
            note: 'No section matched. Pick one of availableSections.',
          });
        }

        return ok({ ...schema, sections: wanted });
      }

      return ok(schema);
    },
  );

  defineTool(
    server,
    'elementor_list_element_types',
    {
      title: 'List structural element types',
      description:
        'List the structural element types available (container, section, column) and the widget panel ' +
        'categories the site defines.',
      inputSchema: { site: siteArg },
      annotations: { readOnlyHint: true, idempotentHint: true },
    },
    async (args) => {
      const client = clients.get(args.site);

      const [elements, categories] = await Promise.all([
        client.bridge<Record<string, unknown>>('elements'),
        client.bridge<Record<string, unknown>>('categories'),
      ]);

      return ok({ elements, categories });
    },
  );

  defineTool(
    server,
    'elementor_list_dynamic_tags',
    {
      title: 'List dynamic tags',
      description:
        'List the dynamic tags registered on the site — post title, ACF field, site logo and so on — which let a ' +
        'widget setting pull live data instead of holding a fixed value. Mostly an Elementor Pro feature.',
      inputSchema: { site: siteArg },
      annotations: { readOnlyHint: true, idempotentHint: true },
    },
    async (args) => {
      const client = clients.get(args.site);

      return ok(await client.bridge<Record<string, unknown>>('dynamic-tags'));
    },
  );
};
