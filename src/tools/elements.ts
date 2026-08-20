/**
 * Element-level editing tools.
 *
 * Each of these reads the page, mutates the tree in memory, and writes it back
 * guarded by the hash it read — so a page is never left half-edited and two
 * agents editing at once cannot silently clobber one another.
 */

import { z } from 'zod';
import { defineTool, elementIdArg, positionArg, postIdArg, siteArg } from './context.js';
import type { ToolModule } from './context.js';
import { ok } from '../util/respond.js';
import { editDocument, loadDocument } from '../elementor/documents.js';
import type { EditOutcome } from '../elementor/documents.js';
import {
  applyOperations,
  describe as describeElement,
  duplicate,
  findNode,
  insert,
  makeContainer,
  makeWidget,
  move,
  remove,
  reorder,
  updateSettings,
  wrap,
} from '../elementor/tree.js';
import { ElementNotFoundError } from '../util/errors.js';
import type { ElementNode, TreeOperation } from '../elementor/types.js';

const settingsArg = z
  .record(z.string(), z.unknown())
  .describe(
    'Elementor control values keyed by control name. Call elementor_get_widget_schema for the valid keys ' +
      'of a widget. Responsive variants use _tablet and _mobile suffixes, e.g. padding_tablet.',
  );

/** Shape an edit outcome into a compact confirmation. */
function summarise<T>(outcome: EditOutcome<T>, message: string): ReturnType<typeof ok> {
  return ok(
    {
      ...(outcome.extra && typeof outcome.extra === 'object' ? outcome.extra : {}),
      postId: outcome.result.id,
      hash: outcome.result.hash,
      nodeCountBefore: outcome.before.nodeCount,
      nodeCountAfter: outcome.after.nodeCount,
      editUrl: outcome.result.editUrl,
      permalink: outcome.result.permalink,
    },
    message,
  );
}

export const registerElementTools: ToolModule = (server, { clients }) => {
  defineTool(
    server,
    'elementor_get_element',
    {
      title: 'Get one element',
      description:
        'Read a single element and its subtree, including every setting. Use this after elementor_get_outline ' +
        'to inspect just the part of a page you are about to change.',
      inputSchema: {
        site: siteArg,
        postId: postIdArg,
        elementId: elementIdArg,
        settingsOnly: z
          .boolean()
          .default(false)
          .describe('Return only this element\'s settings, not its children.'),
      },
      annotations: { readOnlyHint: true, idempotentHint: true },
    },
    async (args) => {
      const client = clients.get(args.site);
      const loaded = await loadDocument(client, args.postId);
      const node = findNode(loaded.elements, args.elementId);

      if (!node) throw new ElementNotFoundError(args.elementId, args.postId);

      if (args.settingsOnly) {
        return ok({
          postId: args.postId,
          elementId: node.id,
          elType: node.elType,
          widgetType: node.widgetType ?? null,
          settings: node.settings ?? {},
        });
      }

      return ok({ postId: args.postId, hash: loaded.hash, element: node });
    },
  );

  defineTool(
    server,
    'elementor_add_widget',
    {
      title: 'Add a widget',
      description:
        'Insert a new widget into a page. Pass the widget type (heading, image, button, text-editor, and so on — ' +
        'see elementor_list_widgets) and its settings. With no targetId the widget is appended to the page root; ' +
        'a widget must otherwise go inside a container or column, so use position before/after to place it ' +
        'beside an existing widget.',
      inputSchema: {
        site: siteArg,
        postId: postIdArg,
        widgetType: z.string().min(1).describe('Widget type name, e.g. "heading".'),
        settings: settingsArg.default({}),
        targetId: z
          .string()
          .optional()
          .describe('Element to place this relative to. Omit to append at the page root.'),
        position: positionArg,
      },
      annotations: { readOnlyHint: false, destructiveHint: false },
    },
    async (args) => {
      const client = clients.get(args.site);
      let newId = '';

      const outcome = await editDocument(
        client,
        args.postId,
        (elements) => {
          const widget = makeWidget(args.widgetType, args.settings ?? {});
          newId = widget.id;

          const options: { targetId?: string; position?: typeof args.position } = {
            position: args.position,
          };
          if (args.targetId) options.targetId = args.targetId;

          return { elements: insert(elements, widget, options), extra: { elementId: widget.id } };
        },
        `add ${args.widgetType}`,
      );

      return summarise(outcome, `Added a "${args.widgetType}" widget as element ${newId}.`);
    },
  );

  defineTool(
    server,
    'elementor_add_container',
    {
      title: 'Add a container or section',
      description:
        'Insert a structural element — a flexbox container (the modern default), or a section/column for pages ' +
        'built the classic way. Containers are what you put widgets inside. You can seed it with child elements ' +
        'in one call.',
      inputSchema: {
        site: siteArg,
        postId: postIdArg,
        elType: z
          .enum(['container', 'section', 'column'])
          .default('container')
          .describe('container is the modern flexbox element; section/column are the legacy layout pair.'),
        settings: settingsArg.default({}),
        children: z
          .array(z.record(z.string(), z.unknown()))
          .optional()
          .describe('Optional child element nodes to place inside it.'),
        targetId: z.string().optional(),
        position: positionArg,
      },
      annotations: { readOnlyHint: false, destructiveHint: false },
    },
    async (args) => {
      const client = clients.get(args.site);
      let newId = '';

      const outcome = await editDocument(
        client,
        args.postId,
        (elements) => {
          const node = makeContainer(
            args.settings ?? {},
            (args.children ?? []) as ElementNode[],
            args.elType,
          );
          newId = node.id;

          const options: { targetId?: string; position?: typeof args.position } = {
            position: args.position,
          };
          if (args.targetId) options.targetId = args.targetId;

          return { elements: insert(elements, node, options), extra: { elementId: node.id } };
        },
        `add ${args.elType}`,
      );

      return summarise(outcome, `Added a ${args.elType} as element ${newId}.`);
    },
  );

  defineTool(
    server,
    'elementor_update_element',
    {
      title: 'Update element settings',
      description:
        'Change an element\'s settings. In merge mode (the default) the keys you pass are applied over the ' +
        'existing settings and everything else is left alone; in replace mode the settings object is swapped ' +
        'wholesale, which discards any setting you do not include. Merge is almost always what you want.',
      inputSchema: {
        site: siteArg,
        postId: postIdArg,
        elementId: elementIdArg,
        settings: settingsArg,
        mode: z
          .enum(['merge', 'replace'])
          .default('merge')
          .describe('merge keeps existing settings; replace discards anything not passed.'),
      },
      annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true },
    },
    async (args) => {
      const client = clients.get(args.site);

      const outcome = await editDocument(
        client,
        args.postId,
        (elements) => ({
          elements: updateSettings(elements, args.elementId, args.settings, args.mode),
          extra: { elementId: args.elementId, keys: Object.keys(args.settings) },
        }),
        `update ${args.elementId}`,
      );

      return summarise(
        outcome,
        `Updated ${Object.keys(args.settings).length} setting(s) on element ${args.elementId}.`,
      );
    },
  );

  defineTool(
    server,
    'elementor_move_element',
    {
      title: 'Move an element',
      description:
        'Relocate an element within the page. Give a referenceId plus a position to say where it lands; omit ' +
        'referenceId to move it to the page root. Moving an element into its own descendant is refused.',
      inputSchema: {
        site: siteArg,
        postId: postIdArg,
        elementId: elementIdArg,
        referenceId: z
          .string()
          .optional()
          .describe('Element to position relative to. Omit to move to the page root.'),
        position: positionArg,
      },
      annotations: { readOnlyHint: false, destructiveHint: false },
    },
    async (args) => {
      const client = clients.get(args.site);

      const outcome = await editDocument(
        client,
        args.postId,
        (elements) => {
          const options: { referenceId?: string; position?: typeof args.position } = {
            position: args.position,
          };
          if (args.referenceId) options.referenceId = args.referenceId;

          return { elements: move(elements, args.elementId, options), extra: { elementId: args.elementId } };
        },
        `move ${args.elementId}`,
      );

      return summarise(outcome, `Moved element ${args.elementId}.`);
    },
  );

  defineTool(
    server,
    'elementor_duplicate_element',
    {
      title: 'Duplicate an element',
      description:
        'Copy an element and its whole subtree, placed immediately after the original, with fresh ids throughout. ' +
        'The quickest way to repeat a card, column or row you have already styled.',
      inputSchema: { site: siteArg, postId: postIdArg, elementId: elementIdArg },
      annotations: { readOnlyHint: false, destructiveHint: false },
    },
    async (args) => {
      const client = clients.get(args.site);
      let copyId = '';

      const outcome = await editDocument(
        client,
        args.postId,
        (elements) => {
          const result = duplicate(elements, args.elementId);
          copyId = result.newId;
          return { elements: result.elements, extra: { newElementId: result.newId } };
        },
        `duplicate ${args.elementId}`,
      );

      return summarise(outcome, `Duplicated ${args.elementId} as ${copyId}.`);
    },
  );

  defineTool(
    server,
    'elementor_delete_element',
    {
      title: 'Delete an element',
      description:
        'Remove an element and everything inside it from a page. A snapshot is taken first, so ' +
        'elementor_restore_snapshot can undo it.',
      inputSchema: { site: siteArg, postId: postIdArg, elementId: elementIdArg },
      annotations: { readOnlyHint: false, destructiveHint: true },
    },
    async (args) => {
      const client = clients.get(args.site);

      const outcome = await editDocument(
        client,
        args.postId,
        (elements) => ({
          elements: remove(elements, args.elementId),
          extra: { deletedElementId: args.elementId },
        }),
        `delete ${args.elementId}`,
      );

      return summarise(
        outcome,
        `Deleted element ${args.elementId} (${outcome.before.nodeCount - outcome.after.nodeCount} node(s) removed). ` +
          'Use elementor_restore_snapshot to undo.',
      );
    },
  );

  defineTool(
    server,
    'elementor_reorder_children',
    {
      title: 'Reorder child elements',
      description:
        'Set the order of a container\'s direct children by listing their ids. Ids you leave out keep their ' +
        'relative order at the end, so you can move one item to the front without listing everything.',
      inputSchema: {
        site: siteArg,
        postId: postIdArg,
        elementId: elementIdArg,
        order: z.array(z.string()).min(1).describe('Child element ids in their new order.'),
      },
      annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true },
    },
    async (args) => {
      const client = clients.get(args.site);

      const outcome = await editDocument(
        client,
        args.postId,
        (elements) => ({
          elements: reorder(elements, args.elementId, args.order),
          extra: { elementId: args.elementId, order: args.order },
        }),
        `reorder ${args.elementId}`,
      );

      return summarise(outcome, `Reordered the children of ${args.elementId}.`);
    },
  );

  defineTool(
    server,
    'elementor_wrap_element',
    {
      title: 'Wrap an element in a container',
      description:
        'Put a new container around an existing element, in place. Useful for adding a background, padding or ' +
        'width constraint around something without rebuilding it.',
      inputSchema: {
        site: siteArg,
        postId: postIdArg,
        elementId: elementIdArg,
        wrapperSettings: settingsArg.default({}).describe('Settings for the new wrapping container.'),
        wrapperType: z.enum(['container', 'section']).default('container'),
      },
      annotations: { readOnlyHint: false, destructiveHint: false },
    },
    async (args) => {
      const client = clients.get(args.site);
      let wrapperId = '';

      const outcome = await editDocument(
        client,
        args.postId,
        (elements) => {
          const shell = makeContainer(args.wrapperSettings ?? {}, [], args.wrapperType);
          const result = wrap(elements, args.elementId, shell);
          wrapperId = result.wrapperId;
          return { elements: result.elements, extra: { wrapperId: result.wrapperId } };
        },
        `wrap ${args.elementId}`,
      );

      return summarise(outcome, `Wrapped ${args.elementId} in new ${args.wrapperType} ${wrapperId}.`);
    },
  );

  defineTool(
    server,
    'elementor_batch_edit',
    {
      title: 'Apply many element edits at once',
      description:
        'Run a list of element operations against a page as one atomic change: insert, update, move, duplicate, ' +
        'delete, reorder and wrap. Operations apply in order, each seeing the result of the last. If any one ' +
        'fails the whole batch is abandoned and nothing is written. Prefer this over a series of single-element ' +
        'calls when building or restructuring a section — it is one read and one write instead of many, and it ' +
        'cannot leave the page half-changed.',
      inputSchema: {
        site: siteArg,
        postId: postIdArg,
        operations: z
          .array(z.record(z.string(), z.unknown()))
          .min(1)
          .describe(
            'Operations in order. Each is an object with "op" and its arguments: ' +
              '{"op":"insert","node":{...},"targetId":"abc","position":"append"}, ' +
              '{"op":"update","targetId":"abc","settings":{...},"mode":"merge"}, ' +
              '{"op":"move","targetId":"abc","referenceId":"def","position":"after"}, ' +
              '{"op":"duplicate","targetId":"abc"}, {"op":"delete","targetId":"abc"}, ' +
              '{"op":"reorder","targetId":"abc","order":["x","y"]}, ' +
              '{"op":"wrap","targetId":"abc","wrapper":{...}}.',
          ),
        label: z.string().optional().describe('Snapshot label describing this change.'),
      },
      annotations: { readOnlyHint: false, destructiveHint: true },
    },
    async (args) => {
      const client = clients.get(args.site);
      let log: string[] = [];

      const outcome = await editDocument(
        client,
        args.postId,
        (elements) => {
          const result = applyOperations(elements, args.operations as unknown as TreeOperation[]);
          log = result.log;
          return { elements: result.elements, extra: { operations: result.log } };
        },
        args.label ?? `batch of ${args.operations.length} operation(s)`,
      );

      return summarise(outcome, `Applied ${log.length} operation(s).`);
    },
  );

  defineTool(
    server,
    'elementor_replace_page_tree',
    {
      title: 'Replace a page\'s entire layout',
      description:
        'Overwrite a page\'s whole element tree. This discards the existing layout completely, so reach for it ' +
        'when generating a page from scratch rather than to make a change. A snapshot is taken first. ' +
        'For edits to an existing page use elementor_batch_edit instead.',
      inputSchema: {
        site: siteArg,
        postId: postIdArg,
        elements: z
          .array(z.record(z.string(), z.unknown()))
          .describe('The complete new element tree. An empty array clears the page.'),
        confirm: z
          .boolean()
          .default(false)
          .describe('Must be true — this replaces everything on the page.'),
        label: z.string().optional(),
      },
      annotations: { readOnlyHint: false, destructiveHint: true },
    },
    async (args) => {
      if (!args.confirm) {
        return ok(
          { written: false },
          'This replaces the page\'s entire layout. Re-send with confirm: true if that is what you want.',
        );
      }

      const client = clients.get(args.site);

      const outcome = await editDocument(
        client,
        args.postId,
        () => ({ elements: args.elements as unknown as ElementNode[], extra: undefined }),
        args.label ?? 'replace page tree',
      );

      return summarise(
        outcome,
        `Replaced the layout of page ${args.postId}: ${outcome.before.nodeCount} node(s) out, ${outcome.after.nodeCount} in.`,
      );
    },
  );

  defineTool(
    server,
    'elementor_find_elements',
    {
      title: 'Find elements within a page',
      description:
        'Search one page for elements by widget type, by text in their settings, or both, and get back their ids ' +
        'with a short label. Use this to locate what you want to edit without reading the whole tree.',
      inputSchema: {
        site: siteArg,
        postId: postIdArg,
        widgetType: z.string().optional().describe('Only match this widget or element type.'),
        text: z.string().optional().describe('Only match elements whose settings contain this text.'),
        limit: z.number().int().min(1).max(200).default(50),
      },
      annotations: { readOnlyHint: true, idempotentHint: true },
    },
    async (args) => {
      const client = clients.get(args.site);
      const loaded = await loadDocument(client, args.postId);
      const needle = args.text?.toLowerCase();

      const matches: Array<Record<string, unknown>> = [];

      const scan = (nodes: ElementNode[]): void => {
        for (const node of nodes) {
          const type = node.widgetType ?? node.elType;
          const typeMatches = !args.widgetType || type === args.widgetType;

          let textMatches = true;
          let matchedKeys: string[] = [];

          if (needle) {
            matchedKeys = Object.entries(node.settings ?? {})
              .filter(([, value]) => typeof value === 'string' && value.toLowerCase().includes(needle))
              .map(([key]) => key);
            textMatches = matchedKeys.length > 0;
          }

          if (typeMatches && textMatches && matches.length < args.limit) {
            matches.push({
              elementId: node.id,
              elType: node.elType,
              widgetType: node.widgetType ?? null,
              label: describeElement(node) || null,
              ...(matchedKeys.length ? { matchedSettings: matchedKeys } : {}),
            });
          }

          if (Array.isArray(node.elements)) scan(node.elements);
        }
      };

      scan(loaded.elements);

      return ok({
        postId: args.postId,
        hash: loaded.hash,
        total: matches.length,
        truncated: matches.length >= args.limit,
        matches,
      });
    },
  );
};
