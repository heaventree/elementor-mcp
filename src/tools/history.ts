/**
 * Snapshots, revisions and undo.
 *
 * WordPress revisions only capture post content, and Elementor keeps its layout
 * in postmeta — so a revision alone does not always bring a page back. The
 * bridge snapshots the layout before every write, which is what makes "undo
 * that" a single reliable call.
 */

import { z } from 'zod';
import { defineTool, postIdArg, siteArg } from './context.js';
import type { ToolModule } from './context.js';
import { ok } from '../util/respond.js';

export const registerHistoryTools: ToolModule = (server, { clients }) => {
  defineTool(
    server,
    'elementor_list_snapshots',
    {
      title: 'List page snapshots',
      description:
        'List the layout snapshots stored for a page, newest first, with when each was taken and why. ' +
        'One is taken automatically before every write this server makes.',
      inputSchema: { site: siteArg, postId: postIdArg },
      annotations: { readOnlyHint: true, idempotentHint: true },
    },
    async (args) => {
      const client = clients.get(args.site);

      return ok(await client.bridge<Record<string, unknown>>(`documents/${args.postId}/snapshots`));
    },
  );

  defineTool(
    server,
    'elementor_create_snapshot',
    {
      title: 'Snapshot a page',
      description:
        'Take a named snapshot of a page\'s current layout. Worth doing before a large restructuring so you have ' +
        'one clearly labelled point to come back to.',
      inputSchema: {
        site: siteArg,
        postId: postIdArg,
        label: z.string().default('manual snapshot').describe('Why you are taking it.'),
      },
      annotations: { readOnlyHint: false, destructiveHint: false },
    },
    async (args) => {
      const client = clients.get(args.site);

      const result = await client.bridge<{ id: string }>(`documents/${args.postId}/snapshots`, {
        method: 'POST',
        write: true,
        body: { label: args.label },
      });

      return ok(result, `Snapshot ${result.id} taken for page ${args.postId}.`);
    },
  );

  defineTool(
    server,
    'elementor_restore_snapshot',
    {
      title: 'Undo: restore a page snapshot',
      description:
        'Roll a page back to a snapshot. With no snapshotId this restores the most recent one, which undoes the ' +
        'last change made through this server. The current state is snapshotted first, so a restore is itself ' +
        'reversible.',
      inputSchema: {
        site: siteArg,
        postId: postIdArg,
        snapshotId: z
          .string()
          .optional()
          .describe('Snapshot to restore. Omit to undo the most recent change.'),
      },
      annotations: { readOnlyHint: false, destructiveHint: true },
    },
    async (args) => {
      const client = clients.get(args.site);

      const result = await client.bridge<Record<string, unknown>>(
        `documents/${args.postId}/snapshots/restore`,
        {
          method: 'POST',
          write: true,
          body: { snapshotId: args.snapshotId },
        },
      );

      return ok(result, `Restored page ${args.postId}${args.snapshotId ? ` to snapshot ${args.snapshotId}` : ' to its previous state'}.`);
    },
  );

  defineTool(
    server,
    'elementor_list_revisions',
    {
      title: 'List WordPress revisions',
      description:
        'List a page\'s WordPress revisions, flagging which of them actually carry Elementor layout data. ' +
        'Revisions reach further back than snapshots but are less reliable for Elementor content.',
      inputSchema: { site: siteArg, postId: postIdArg },
      annotations: { readOnlyHint: true, idempotentHint: true },
    },
    async (args) => {
      const client = clients.get(args.site);

      return ok(await client.bridge<Record<string, unknown>>(`documents/${args.postId}/revisions`));
    },
  );

  defineTool(
    server,
    'elementor_restore_revision',
    {
      title: 'Restore a WordPress revision',
      description:
        'Restore a page\'s Elementor layout from a WordPress revision. Only works on revisions flagged as ' +
        'carrying Elementor data. A snapshot is taken first.',
      inputSchema: {
        site: siteArg,
        postId: postIdArg,
        revisionId: z.number().int().positive().describe('Revision id from elementor_list_revisions.'),
      },
      annotations: { readOnlyHint: false, destructiveHint: true },
    },
    async (args) => {
      const client = clients.get(args.site);

      const result = await client.bridge<Record<string, unknown>>(
        `documents/${args.postId}/revisions/restore`,
        {
          method: 'POST',
          write: true,
          body: { revisionId: args.revisionId },
        },
      );

      return ok(result, `Restored page ${args.postId} from revision ${args.revisionId}.`);
    },
  );
};
