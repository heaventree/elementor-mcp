/**
 * Cross-site content tools: search, replace, usage and media.
 */

import { z } from 'zod';
import { defineTool, siteArg } from './context.js';
import type { ToolModule } from './context.js';
import { ok } from '../util/respond.js';

export const registerContentTools: ToolModule = (server, { clients }) => {
  defineTool(
    server,
    'elementor_search',
    {
      title: 'Search Elementor content site-wide',
      description:
        'Search inside Elementor page data across the site — by text, by widget type, or by which elements ' +
        'define a given setting. Elementor stores its content as JSON in postmeta, so it is invisible to ' +
        'normal WordPress search; this is the way to find "which page has that old phone number on it".',
      inputSchema: {
        site: siteArg,
        text: z.string().optional().describe('Text to find inside element settings.'),
        widgetType: z.string().optional().describe('Only match this widget or element type.'),
        settingKey: z.string().optional().describe('Only match elements that define this settings key.'),
        postIds: z.array(z.number().int()).optional().describe('Restrict to these pages.'),
        postTypes: z.array(z.string()).optional().describe('Restrict to these post types.'),
        caseSensitive: z.boolean().default(false),
        limit: z.number().int().min(1).max(500).default(100),
      },
      annotations: { readOnlyHint: true, idempotentHint: true },
    },
    async (args) => {
      const client = clients.get(args.site);

      return ok(
        await client.bridge<Record<string, unknown>>('search', {
          method: 'POST',
          body: {
            text: args.text,
            widgetType: args.widgetType,
            settingKey: args.settingKey,
            postIds: args.postIds,
            postTypes: args.postTypes,
            caseSensitive: args.caseSensitive,
            limit: args.limit,
          },
        }),
      );
    },
  );

  defineTool(
    server,
    'elementor_replace_text',
    {
      title: 'Find and replace across Elementor content',
      description:
        'Replace a string everywhere it appears in Elementor page data — a phone number, an old brand name, a ' +
        'changed URL. Runs as a dry run by default and reports exactly which pages and how many occurrences it ' +
        'would touch; set dryRun false and confirm true to commit. Every page it edits is snapshotted first. ' +
        'Always read the dry run before committing.',
      inputSchema: {
        site: siteArg,
        search: z.string().min(1).describe('The exact string to find.'),
        replace: z.string().default('').describe('What to put in its place. Empty removes it.'),
        dryRun: z.boolean().default(true).describe('Report without writing. Leave true until you have read the report.'),
        confirm: z.boolean().default(false).describe('Required when dryRun is false.'),
        postIds: z.array(z.number().int()).optional().describe('Restrict to these pages.'),
        postTypes: z.array(z.string()).optional(),
        caseSensitive: z.boolean().default(false),
      },
      annotations: { readOnlyHint: false, destructiveHint: true },
    },
    async (args) => {
      const client = clients.get(args.site);

      const result = await client.bridge<{ dryRun: boolean; documents: number; replacements: number }>(
        'replace',
        {
          method: 'POST',
          write: !args.dryRun,
          body: {
            search: args.search,
            replace: args.replace,
            dryRun: args.dryRun,
            confirm: args.confirm,
            postIds: args.postIds,
            postTypes: args.postTypes,
            caseSensitive: args.caseSensitive,
          },
        },
      );

      const verb = result.dryRun ? 'Would replace' : 'Replaced';

      return ok(
        result,
        `${verb} ${result.replacements} occurrence(s) across ${result.documents} page(s).` +
          (result.dryRun ? ' Re-send with dryRun: false and confirm: true to apply.' : ''),
      );
    },
  );

  defineTool(
    server,
    'elementor_widget_usage',
    {
      title: 'Report site-wide widget usage',
      description:
        'Count which Elementor widgets are actually used across the site and how much each page contains. ' +
        'Useful before removing an addon plugin, or to find the heaviest pages.',
      inputSchema: {
        site: siteArg,
        postTypes: z.array(z.string()).optional(),
      },
      annotations: { readOnlyHint: true, idempotentHint: true },
    },
    async (args) => {
      const client = clients.get(args.site);

      return ok(
        await client.bridge<Record<string, unknown>>('usage', {
          query: { postTypes: args.postTypes },
        }),
      );
    },
  );

  defineTool(
    server,
    'elementor_list_media',
    {
      title: 'List media library items',
      description:
        'Search the WordPress media library and get back attachment ids and URLs. Elementor image controls need ' +
        'both, in the shape {"id":123,"url":"https://..."}.',
      inputSchema: {
        site: siteArg,
        search: z.string().optional(),
        mimeType: z.string().optional().describe('Filter by MIME type, e.g. "image".'),
        page: z.number().int().positive().default(1),
        perPage: z.number().int().min(1).max(100).default(20),
      },
      annotations: { readOnlyHint: true, idempotentHint: true },
    },
    async (args) => {
      const client = clients.get(args.site);

      const items = await client.core<Array<Record<string, unknown>>>('media', {
        query: {
          search: args.search,
          media_type: args.mimeType,
          page: args.page,
          per_page: args.perPage,
        },
      });

      return ok({
        items: (Array.isArray(items) ? items : []).map((item) => ({
          id: item.id,
          title: (item.title as { rendered?: string } | undefined)?.rendered,
          url: item.source_url,
          mimeType: item.mime_type,
          alt: item.alt_text,
          imageControlValue: { id: item.id, url: item.source_url },
        })),
      });
    },
  );

  defineTool(
    server,
    'elementor_upload_image',
    {
      title: 'Upload an image from a URL',
      description:
        'Download a remote image into the WordPress media library and return the attachment id and URL, plus a ' +
        'ready-made value for an Elementor image control. Use this before setting any image setting — Elementor ' +
        'image controls want a library attachment, not a remote URL.',
      inputSchema: {
        site: siteArg,
        url: z.string().url().describe('Absolute http(s) URL of the image to fetch.'),
        title: z.string().optional(),
        altText: z.string().optional().describe('Alt text. Worth setting for accessibility and SEO.'),
        attachToPostId: z.number().int().optional().describe('Attach the upload to this post.'),
      },
      annotations: { readOnlyHint: false, destructiveHint: false },
    },
    async (args) => {
      const client = clients.get(args.site);

      const result = await client.bridge<{ id: number; url: string }>('media/sideload', {
        method: 'POST',
        write: true,
        body: {
          url: args.url,
          title: args.title,
          altText: args.altText,
          attachToPost: args.attachToPostId ?? 0,
        },
      });

      return ok(result, `Uploaded image as attachment ${result.id}.`);
    },
  );
};
