/**
 * Site-wide design tools: the Elementor kit, global colours and typography.
 */

import { z } from 'zod';
import { defineTool, siteArg } from './context.js';
import type { ToolModule } from './context.js';
import { ok } from '../util/respond.js';

export const registerDesignTools: ToolModule = (server, { clients }) => {
  defineTool(
    server,
    'elementor_get_globals',
    {
      title: 'Get global design settings',
      description:
        'Read the site\'s Elementor kit: global colour and typography palettes with the ids widgets refer to them ' +
        'by, layout defaults such as container width and breakpoints, and the site custom CSS. ' +
        'Read this before styling anything — using an existing global token keeps a site consistent, and ' +
        'changing one restyles every element bound to it.',
      inputSchema: {
        site: siteArg,
        includeRaw: z
          .boolean()
          .default(false)
          .describe('Include the complete raw kit settings object as well as the friendly view.'),
      },
      annotations: { readOnlyHint: true, idempotentHint: true },
    },
    async (args) => {
      const client = clients.get(args.site);
      const kit = await client.bridge<Record<string, unknown>>('kit');

      if (!args.includeRaw) {
        const { settings: _raw, ...rest } = kit;
        return ok(rest);
      }

      return ok(kit);
    },
  );

  defineTool(
    server,
    'elementor_set_global_color',
    {
      title: 'Set a global colour',
      description:
        'Change one global colour by id (primary, secondary, text, accent) or by its title, or add a new custom ' +
        'colour if it does not exist. Every element bound to that token updates at once, and Elementor\'s CSS ' +
        'is regenerated. This is the right way to restyle a site\'s palette.',
      inputSchema: {
        site: siteArg,
        id: z.string().min(1).describe('Colour id such as "primary", or the colour\'s title.'),
        value: z.string().min(1).describe('Hex colour, e.g. "#1A73E8".'),
        title: z.string().optional().describe('Label, used when creating a new custom colour.'),
      },
      annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true },
    },
    async (args) => {
      const client = clients.get(args.site);

      const result = await client.bridge<{ colors?: unknown }>('kit/colors', {
        method: 'POST',
        write: true,
        body: { id: args.id, value: args.value, title: args.title },
      });

      return ok({ colors: result.colors }, `Set global colour "${args.id}" to ${args.value}.`);
    },
  );

  defineTool(
    server,
    'elementor_update_globals',
    {
      title: 'Update global design settings',
      description:
        'Merge settings into the Elementor kit — colour and typography palettes, container width, breakpoints, ' +
        'theme style defaults. This changes the whole site at once, so read elementor_get_globals first and ' +
        'change only the keys you mean to. A snapshot of the kit is taken before the write.',
      inputSchema: {
        site: siteArg,
        settings: z
          .record(z.string(), z.unknown())
          .describe(
            'Kit settings to merge, e.g. {"container_width":{"unit":"px","size":1200}} or a full ' +
              '"system_colors" array. Keys not passed are left alone.',
          ),
      },
      annotations: { readOnlyHint: false, destructiveHint: true },
    },
    async (args) => {
      const client = clients.get(args.site);

      const result = await client.bridge<Record<string, unknown>>('kit', {
        method: 'POST',
        write: true,
        body: { settings: args.settings },
      });

      const { settings: _raw, ...rest } = result;

      return ok(rest, `Updated ${Object.keys(args.settings).length} kit setting(s) and regenerated CSS.`);
    },
  );

  defineTool(
    server,
    'elementor_custom_css',
    {
      title: 'Read or write site custom CSS',
      description:
        'Read the site-wide custom CSS held on the Elementor kit, or replace it. Passing css writes it; ' +
        'omitting css just reads. Note that Elementor only renders this on Pro.',
      inputSchema: {
        site: siteArg,
        css: z.string().optional().describe('New CSS. Omit to read the current value.'),
      },
      annotations: { readOnlyHint: false, destructiveHint: true },
    },
    async (args) => {
      const client = clients.get(args.site);

      if (args.css === undefined) {
        return ok(await client.bridge<Record<string, unknown>>('kit/custom-css'));
      }

      const result = await client.bridge<Record<string, unknown>>('kit/custom-css', {
        method: 'POST',
        write: true,
        body: { css: args.css },
      });

      return ok(result, `Wrote ${args.css.length} characters of site custom CSS.`);
    },
  );

  defineTool(
    server,
    'elementor_get_global_classes',
    {
      title: 'Get v4 global classes',
      description:
        'Read Elementor v4 global classes — the reusable style classes used by the atomic/Editor One element ' +
        'model. Returns an empty list on sites that predate that feature.',
      inputSchema: { site: siteArg },
      annotations: { readOnlyHint: true, idempotentHint: true },
    },
    async (args) => {
      const client = clients.get(args.site);

      return ok(await client.bridge<Record<string, unknown>>('global-classes'));
    },
  );

  defineTool(
    server,
    'elementor_flush_css',
    {
      title: 'Regenerate Elementor CSS',
      description:
        'Clear Elementor\'s cached CSS so it regenerates on the next page load. The editing tools already do ' +
        'this after every write; call it manually when styles look stale after changes made outside this server, ' +
        'or after a theme or plugin update.',
      inputSchema: {
        site: siteArg,
        postId: z
          .number()
          .int()
          .optional()
          .describe('Flush one page. Omit to regenerate the whole site\'s CSS.'),
      },
      annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true },
    },
    async (args) => {
      const client = clients.get(args.site);

      const result = await client.bridge<Record<string, unknown>>('cache/flush', {
        method: 'POST',
        write: true,
        body: { postId: args.postId ?? 0 },
      });

      return ok(result, args.postId ? `Flushed CSS for page ${args.postId}.` : 'Flushed all Elementor CSS.');
    },
  );
};
