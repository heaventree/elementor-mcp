/**
 * Prompts encoding the workflows that keep Elementor edits safe.
 */

import { z } from 'zod';
import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import type { ToolContext } from './tools/context.js';

export function registerPrompts(server: McpServer, _context: ToolContext): void {
  server.registerPrompt(
    'edit_page_safely',
    {
      title: 'Edit an Elementor page safely',
      description: 'The read-inspect-change-verify loop for changing an existing page.',
      argsSchema: {
        postId: z.string().describe('The page ID to edit.'),
        goal: z.string().describe('What you want to change.'),
      },
    },
    ({ postId, goal }) => ({
      messages: [
        {
          role: 'user' as const,
          content: {
            type: 'text' as const,
            text:
              `Change page ${postId}: ${goal}\n\n` +
              'Work in this order:\n' +
              `1. elementor_get_outline on ${postId} to see the structure and get element ids.\n` +
              '2. elementor_get_element on the specific elements involved, to read their current settings.\n' +
              '3. elementor_get_widget_schema for any widget whose settings you are unsure of — do not guess ' +
              'control names, and check whether the setting is responsive.\n' +
              '4. elementor_get_globals if this touches colour or typography, so you use the site\'s existing ' +
              'design tokens rather than hard-coding values.\n' +
              '5. Make the change with elementor_batch_edit if it is more than one step.\n' +
              '6. elementor_render_page on the affected element to confirm the result.\n\n' +
              'If anything looks wrong, elementor_restore_snapshot rolls the page back.',
          },
        },
      ],
    }),
  );

  server.registerPrompt(
    'build_page',
    {
      title: 'Build a new Elementor page',
      description: 'Compose a new page from containers and widgets without breaking a live site.',
      argsSchema: {
        brief: z.string().describe('What the page is for and what should be on it.'),
      },
    },
    ({ brief }) => ({
      messages: [
        {
          role: 'user' as const,
          content: {
            type: 'text' as const,
            text:
              `Build a new Elementor page: ${brief}\n\n` +
              'Before writing anything:\n' +
              '- elementor_site_status to confirm the Elementor version and whether Pro widgets are available.\n' +
              '- elementor_list_widgets to see what this site actually has; do not assume a widget exists.\n' +
              '- elementor_get_globals to pick up the site\'s colour and typography tokens.\n' +
              '- elementor_list_templates in case a suitable layout already exists worth reusing.\n\n' +
              'Then elementor_create_page as a draft, and build it up with elementor_batch_edit. ' +
              'Use container elements for layout. Bind colours and fonts to global tokens rather than ' +
              'hard-coding hex values, so the page stays consistent if the palette changes. ' +
              'Finally elementor_render_page to check the result, and only publish once it looks right.',
          },
        },
      ],
    }),
  );

  server.registerPrompt(
    'audit_site',
    {
      title: 'Audit Elementor usage across a site',
      description: 'Survey what a site is built from before changing or migrating it.',
      argsSchema: {
        focus: z.string().optional().describe('Optional area to concentrate on.'),
      },
    },
    ({ focus }) => ({
      messages: [
        {
          role: 'user' as const,
          content: {
            type: 'text' as const,
            text:
              'Audit this site\'s Elementor usage' +
              (focus ? `, focusing on ${focus}` : '') +
              '.\n\n' +
              'Use elementor_site_status for versions and configuration, elementor_widget_usage to see which ' +
              'widgets are actually in use and which pages are heaviest, elementor_list_pages with ' +
              'elementorOnly to separate builder pages from the rest, and elementor_get_globals to review the ' +
              'design system. Report what you find and flag anything that would break on an Elementor upgrade ' +
              '— legacy section/column layouts, widgets from addons, and pages with no global tokens applied.',
          },
        },
      ],
    }),
  );
}
