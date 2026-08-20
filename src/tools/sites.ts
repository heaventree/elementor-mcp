/**
 * Site discovery and health tools.
 */

import { z } from 'zod';
import { defineTool, siteArg } from './context.js';
import type { ToolModule } from './context.js';
import { ok } from '../util/respond.js';

export const registerSiteTools: ToolModule = (server, { clients }) => {
  defineTool(
    server,
    'elementor_list_sites',
    {
      title: 'List configured sites',
      description:
        'List the WordPress sites this server can reach, with which one is the default. ' +
        'Call this first when you are unsure which site name to pass to other tools.',
      annotations: { readOnlyHint: true, idempotentHint: true },
    },
    async () => ok({ sites: clients.list() }),
  );

  defineTool(
    server,
    'elementor_site_status',
    {
      title: 'Check site status',
      description:
        'Report what a site is running: WordPress and Elementor versions, whether Elementor Pro is present, ' +
        'how many widget types are registered, the active kit id, the capabilities of the authenticated user, ' +
        'and whether the bridge plugin and destructive operations are enabled. ' +
        'Run this before a first editing session on an unfamiliar site.',
      inputSchema: { site: siteArg },
      annotations: { readOnlyHint: true, idempotentHint: true },
    },
    async (args) => {
      const client = clients.get(args.site);
      const status = await client.bridge<Record<string, unknown>>('status');

      return ok({ site: client.site.name, ...status });
    },
  );

  defineTool(
    server,
    'elementor_native_mcp_call',
    {
      title: 'Call an Elementor native MCP ability',
      description:
        "Invoke one of Elementor's own built-in MCP abilities through the bridge. " +
        'Elementor 4.3+ ships abilities covering v4 atomic features (global classes, variables, components, ' +
        'compositions) that have no v3 equivalent. Only works where that module is active — ' +
        'check elementor_site_status first, under nativeMcp.',
      inputSchema: {
        site: siteArg,
        tool: z.string().min(1).describe('The native ability slug, e.g. "list-components".'),
        input: z
          .record(z.string(), z.unknown())
          .default({})
          .describe('Arguments for that ability.'),
      },
      annotations: { openWorldHint: true },
    },
    async (args) => {
      const client = clients.get(args.site);

      const result = await client.bridge<unknown>('native-mcp/proxy', {
        method: 'POST',
        write: true,
        body: { tool: args.tool, input: args.input ?? {} },
      });

      return ok({ tool: args.tool, result });
    },
  );
};
