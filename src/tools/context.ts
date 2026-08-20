/**
 * Shared plumbing for tool modules.
 */

import { z } from 'zod';
import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import type { ClientRegistry } from '../client.js';
import type { ServerConfig } from '../config.js';
import { fail } from '../util/respond.js';
import type { ToolResult } from '../util/respond.js';

/** What every tool handler is given. */
export interface ToolContext {
  clients: ClientRegistry;
  config: ServerConfig;
}

/** A module that registers a group of related tools. */
export type ToolModule = (server: McpServer, context: ToolContext) => void;

/**
 * The `site` argument every tool accepts.
 *
 * Optional, because the common case is one configured site.
 */
export const siteArg = z
  .string()
  .optional()
  .describe('Configured site name. Omit to use the default site.');

/** A WordPress post ID. */
export const postIdArg = z
  .number()
  .int()
  .positive()
  .describe('WordPress post ID of the Elementor page, post or template.');

/** An Elementor element ID. */
export const elementIdArg = z
  .string()
  .min(1)
  .describe('Elementor element id, as returned by elementor_get_outline.');

/** Where to place an element relative to a reference element. */
export const positionArg = z
  .enum(['append', 'prepend', 'before', 'after'])
  .default('append')
  .describe(
    'append/prepend place the element inside the target; before/after place it as a sibling of the target.',
  );

/**
 * Register a tool whose handler cannot throw.
 *
 * A thrown exception would surface to the model as a protocol error with no
 * guidance; routing everything through `fail` keeps the actionable hint intact.
 */
export function defineTool<Shape extends z.ZodRawShape>(
  server: McpServer,
  name: string,
  config: {
    title: string;
    description: string;
    inputSchema?: Shape;
    annotations?: {
      readOnlyHint?: boolean;
      destructiveHint?: boolean;
      idempotentHint?: boolean;
      openWorldHint?: boolean;
    };
  },
  handler: (args: z.output<z.ZodObject<Shape>>) => Promise<ToolResult>,
): void {
  server.registerTool(
    name,
    {
      title: config.title,
      description: config.description,
      ...(config.inputSchema ? { inputSchema: config.inputSchema } : {}),
      annotations: {
        openWorldHint: true,
        ...config.annotations,
      },
    } as never,
    (async (args: unknown) => {
      try {
        return await handler((args ?? {}) as z.output<z.ZodObject<Shape>>);
      } catch (error) {
        return fail(error);
      }
    }) as never,
  );
}
