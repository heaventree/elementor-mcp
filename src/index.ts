#!/usr/bin/env node
/**
 * Elementor MCP server.
 *
 * Connects an MCP client to one or more WordPress sites running Elementor and
 * the companion bridge plugin, exposing page structure, widget schemas, global
 * design tokens, templates and history as tools.
 */

import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';

import { ClientRegistry } from './client.js';
import { loadConfig } from './config.js';
import { ElementorMcpError } from './util/errors.js';
import type { ToolContext } from './tools/context.js';
import { registerContentTools } from './tools/content.js';
import { registerDesignTools } from './tools/design.js';
import { registerElementTools } from './tools/elements.js';
import { registerHistoryTools } from './tools/history.js';
import { registerPageTools } from './tools/pages.js';
import { registerSiteTools } from './tools/sites.js';
import { registerTemplateTools } from './tools/templates.js';
import { registerWidgetTools } from './tools/widgets.js';
import { registerPrompts } from './prompts.js';

const SERVER_NAME = 'elementor-mcp';
const SERVER_VERSION = '1.0.1';

/** Build a configured server instance. */
export function createServer(context: ToolContext): McpServer {
  const server = new McpServer(
    { name: SERVER_NAME, version: SERVER_VERSION },
    {
      instructions:
        'Tools for reading and editing Elementor pages on WordPress sites.\n\n' +
        'Working order that avoids the usual mistakes:\n' +
        '1. elementor_site_status once per site, to see what Elementor version and widgets are available.\n' +
        '2. elementor_get_outline to get element ids — do not fetch whole element trees unless you need them.\n' +
        '3. elementor_get_widget_schema before setting unfamiliar widget settings; control names are not guessable.\n' +
        '4. elementor_batch_edit for multi-step changes, so the page cannot be left half-edited.\n\n' +
        'Layout: check containerAvailable in elementor_site_status before building. Elementor gates the ' +
        'flexbox container behind an experiment that is off by default on sites installed before 3.16, ' +
        'so a current Elementor does not guarantee containers exist. Where it is false, build with ' +
        'section and column instead.\n\n' +
        'Every write snapshots the page first; elementor_restore_snapshot undoes the last change. ' +
        'Writes carry the hash the page was read at and are rejected if it changed underneath you. ' +
        'Site-wide replace defaults to a dry run — read the report before committing.',
    },
  );

  registerSiteTools(server, context);
  registerPageTools(server, context);
  registerElementTools(server, context);
  registerWidgetTools(server, context);
  registerDesignTools(server, context);
  registerTemplateTools(server, context);
  registerHistoryTools(server, context);
  registerContentTools(server, context);
  registerPrompts(server, context);

  return server;
}

/** Entry point. */
async function main(): Promise<void> {
  const config = loadConfig();
  const context: ToolContext = { config, clients: new ClientRegistry(config) };

  const server = createServer(context);
  const transport = new StdioServerTransport();

  await server.connect(transport);

  // stdout carries the protocol, so all logging goes to stderr.
  process.stderr.write(
    `${SERVER_NAME} ${SERVER_VERSION} ready — ${config.sites.length} site(s), default "${config.defaultSite}"` +
      `${config.readOnly ? ' (read-only)' : ''}\n`,
  );
}

const isDirectRun = process.argv[1] && import.meta.url === `file://${process.argv[1]}`;

if (isDirectRun) {
  main().catch((error: unknown) => {
    if (error instanceof ElementorMcpError) {
      process.stderr.write(`${SERVER_NAME}: ${error.toDisplay()}\n`);
    } else {
      process.stderr.write(`${SERVER_NAME}: ${error instanceof Error ? error.stack ?? error.message : String(error)}\n`);
    }

    process.exit(1);
  });
}
