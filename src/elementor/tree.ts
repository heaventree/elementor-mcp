/**
 * Pure element-tree surgery.
 *
 * Everything here operates on plain arrays and returns new trees, so the whole
 * editing model is testable without a WordPress site. The MCP server reads a
 * page, runs these functions in-process, and writes the result back — which is
 * why a large page can be edited without ever putting its full JSON in front of
 * the model.
 */

import { ElementNotFoundError, ElementorMcpError } from '../util/errors.js';
import type {
  ElementNode,
  InsertPosition,
  NodeLocation,
  OutlineNode,
  TreeOperation,
} from './types.js';

/** Element types that may contain children. */
export const CONTAINER_TYPES = ['container', 'section', 'column'] as const;

/** Settings keys checked, in order, when labelling a node. */
const LABEL_KEYS = [
  'title',
  'text',
  'editor',
  'heading',
  'title_text',
  'description_text',
  'html',
  'caption',
  '_title',
];

/**
 * Generate an Elementor-style element id.
 *
 * Elementor uses short lowercase hex (`dechex(rand())`); we match that shape at
 * a fixed seven characters and avoid ids already present in the tree.
 */
export function generateId(taken: Set<string> = new Set()): string {
  for (let attempt = 0; attempt < 1000; attempt += 1) {
    const id = Math.floor(Math.random() * 0xfffffff)
      .toString(16)
      .padStart(7, '0')
      .slice(0, 7);

    if (!taken.has(id)) {
      taken.add(id);
      return id;
    }
  }

  /* istanbul ignore next -- only reachable if the id space is exhausted. */
  throw new ElementorMcpError('Could not generate a unique element id.', {
    code: 'id_exhausted',
  });
}

/** Deep clone a tree so callers never mutate the copy they were handed. */
export function clone<T>(value: T): T {
  return structuredClone(value);
}

/** Visit every node depth-first. */
export function walk(
  elements: ElementNode[],
  visit: (node: ElementNode, depth: number, parent: ElementNode | null) => void,
  depth = 0,
  parent: ElementNode | null = null,
): void {
  for (const node of elements) {
    if (!node || typeof node !== 'object') continue;

    visit(node, depth, parent);

    if (Array.isArray(node.elements) && node.elements.length > 0) {
      walk(node.elements, visit, depth + 1, node);
    }
  }
}

/** Every element id in the tree. */
export function collectIds(elements: ElementNode[]): string[] {
  const ids: string[] = [];
  walk(elements, (node) => {
    if (typeof node.id === 'string') ids.push(node.id);
  });
  return ids;
}

/** Locate a node along with its parent and position. */
export function locate(elements: ElementNode[], id: string): NodeLocation | null {
  const search = (
    siblings: ElementNode[],
    parent: ElementNode | null,
    ancestors: string[],
  ): NodeLocation | null => {
    for (let index = 0; index < siblings.length; index += 1) {
      const node = siblings[index];
      if (!node) continue;

      if (node.id === id) {
        return { node, siblings, index, parent, ancestors };
      }

      if (Array.isArray(node.elements) && node.elements.length > 0) {
        const found = search(node.elements, node, [...ancestors, node.id]);
        if (found) return found;
      }
    }

    return null;
  };

  return search(elements, null, []);
}

/** Find a node by id, or null. */
export function findNode(elements: ElementNode[], id: string): ElementNode | null {
  return locate(elements, id)?.node ?? null;
}

/** Locate a node or throw an actionable error. */
function mustLocate(elements: ElementNode[], id: string): NodeLocation {
  const found = locate(elements, id);
  if (!found) throw new ElementNotFoundError(id);
  return found;
}

/** Can this node hold children? */
export function isContainerType(node: ElementNode): boolean {
  return node.elType !== 'widget';
}

/** A short human label for a node, drawn from its most identifying setting. */
export function describe(node: ElementNode): string {
  const settings = (node.settings ?? {}) as Record<string, unknown>;

  for (const key of LABEL_KEYS) {
    const value = settings[key];
    if (typeof value !== 'string') continue;

    const text = value
      .replace(/<[^>]*>/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();

    if (text) return text.slice(0, 120);
  }

  return '';
}

/** A compact structural view: ids and types, no settings payload. */
export function outline(elements: ElementNode[], maxDepth = 0): OutlineNode[] {
  return elements
    .filter((node): node is ElementNode => Boolean(node) && typeof node.elType === 'string')
    .map((node) => {
      const entry: OutlineNode = { id: node.id, elType: node.elType };

      if (node.widgetType) entry.widgetType = node.widgetType;

      const label = describe(node);
      if (label) entry.label = label;

      const children = Array.isArray(node.elements) ? node.elements : [];

      if (children.length > 0) {
        if (maxDepth === 1) {
          entry.childCount = children.length;
        } else {
          entry.elements = outline(children, maxDepth > 0 ? maxDepth - 1 : 0);
        }
      }

      return entry;
    });
}

/** Count nodes by widget or element type. */
export function census(elements: ElementNode[]): Record<string, number> {
  const counts: Record<string, number> = {};

  walk(elements, (node) => {
    if (!node.elType) return;
    const key = node.elType === 'widget' && node.widgetType ? node.widgetType : node.elType;
    counts[key] = (counts[key] ?? 0) + 1;
  });

  return Object.fromEntries(Object.entries(counts).sort((a, b) => b[1] - a[1]));
}

/**
 * Give every node in a tree a fresh id.
 *
 * Required whenever a subtree is copied or imported: two elements sharing an id
 * breaks Elementor's per-element CSS targeting.
 */
export function regenerateIds(elements: ElementNode[], taken: Set<string> = new Set()): ElementNode[] {
  return elements.map((node) => {
    const next: ElementNode = { ...node, id: generateId(taken) };

    if (Array.isArray(node.elements)) {
      next.elements = regenerateIds(node.elements, taken);
    }

    return next;
  });
}

/** Build a widget node. */
export function makeWidget(
  widgetType: string,
  settings: Record<string, unknown> = {},
  id?: string,
): ElementNode {
  return {
    id: id ?? generateId(),
    elType: 'widget',
    widgetType,
    settings,
    elements: [],
  };
}

/** Build a structural node (container by default). */
export function makeContainer(
  settings: Record<string, unknown> = {},
  children: ElementNode[] = [],
  elType: string = 'container',
  id?: string,
): ElementNode {
  return {
    id: id ?? generateId(),
    elType,
    settings,
    elements: children,
  };
}

/**
 * Insert a node into the tree.
 *
 * With no `targetId` the node is appended to (or prepended at) the document
 * root. With one, `append`/`prepend` place the node inside the target and
 * `before`/`after` place it alongside.
 */
export function insert(
  elements: ElementNode[],
  node: ElementNode,
  options: { targetId?: string; position?: InsertPosition } = {},
): ElementNode[] {
  const next = clone(elements);
  const taken = new Set(collectIds(next));

  // Re-key the incoming subtree if anything in it would collide.
  const incomingIds = collectIds([node]);
  const collides = incomingIds.some((id) => taken.has(id)) || !node.id;
  const toInsert = collides ? regenerateIds([node], taken)[0]! : clone(node);

  const position = options.position ?? 'append';

  if (!options.targetId) {
    if (position === 'prepend' || position === 'before') {
      next.unshift(toInsert);
    } else {
      next.push(toInsert);
    }
    return next;
  }

  const target = mustLocate(next, options.targetId);

  if (position === 'before' || position === 'after') {
    const at = position === 'before' ? target.index : target.index + 1;
    target.siblings.splice(at, 0, toInsert);
    return next;
  }

  if (!isContainerType(target.node)) {
    throw new ElementorMcpError(
      `Cannot place an element inside widget "${target.node.widgetType ?? target.node.id}" — widgets do not take children.`,
      {
        code: 'invalid_parent',
        status: 400,
        hint: 'Use position "before" or "after" to place it as a sibling, or target the widget\'s parent container instead.',
      },
    );
  }

  target.node.elements = Array.isArray(target.node.elements) ? target.node.elements : [];

  if (position === 'prepend') {
    target.node.elements.unshift(toInsert);
  } else {
    target.node.elements.push(toInsert);
  }

  return next;
}

/** Merge (or replace) a node's settings. */
export function updateSettings(
  elements: ElementNode[],
  id: string,
  settings: Record<string, unknown>,
  mode: 'merge' | 'replace' = 'merge',
): ElementNode[] {
  const next = clone(elements);
  const target = mustLocate(next, id);

  target.node.settings =
    mode === 'replace' ? { ...settings } : { ...(target.node.settings ?? {}), ...settings };

  return next;
}

/** Move a node elsewhere in the tree. */
export function move(
  elements: ElementNode[],
  id: string,
  options: { referenceId?: string; position?: InsertPosition } = {},
): ElementNode[] {
  const next = clone(elements);
  const source = mustLocate(next, id);

  if (options.referenceId === id) {
    throw new ElementorMcpError('An element cannot be moved relative to itself.', {
      code: 'invalid_move',
      status: 400,
    });
  }

  // Moving a node into its own subtree would detach that subtree from the tree.
  if (options.referenceId) {
    const inside = locate([source.node], options.referenceId);
    if (inside) {
      throw new ElementorMcpError(
        `Cannot move element "${id}" into "${options.referenceId}" because that is one of its own descendants.`,
        {
          code: 'invalid_move',
          status: 400,
          hint: 'Pick a reference element outside the subtree you are moving.',
        },
      );
    }
  }

  const [detached] = source.siblings.splice(source.index, 1);
  if (!detached) throw new ElementNotFoundError(id);

  const position = options.position ?? 'append';

  if (!options.referenceId) {
    if (position === 'prepend' || position === 'before') {
      next.unshift(detached);
    } else {
      next.push(detached);
    }
    return next;
  }

  const reference = mustLocate(next, options.referenceId);

  if (position === 'before' || position === 'after') {
    const at = position === 'before' ? reference.index : reference.index + 1;
    reference.siblings.splice(at, 0, detached);
    return next;
  }

  if (!isContainerType(reference.node)) {
    throw new ElementorMcpError(
      `Cannot move an element inside widget "${reference.node.widgetType ?? reference.node.id}" — widgets do not take children.`,
      { code: 'invalid_parent', status: 400 },
    );
  }

  reference.node.elements = Array.isArray(reference.node.elements) ? reference.node.elements : [];

  if (position === 'prepend') {
    reference.node.elements.unshift(detached);
  } else {
    reference.node.elements.push(detached);
  }

  return next;
}

/** Copy a node in place, directly after the original. */
export function duplicate(elements: ElementNode[], id: string): { elements: ElementNode[]; newId: string } {
  const next = clone(elements);
  const target = mustLocate(next, id);
  const taken = new Set(collectIds(next));

  const copy = regenerateIds([clone(target.node)], taken)[0]!;
  target.siblings.splice(target.index + 1, 0, copy);

  return { elements: next, newId: copy.id };
}

/** Remove a node and its subtree. */
export function remove(elements: ElementNode[], id: string): ElementNode[] {
  const next = clone(elements);
  const target = mustLocate(next, id);

  target.siblings.splice(target.index, 1);

  return next;
}

/** Reorder a container's direct children by id. */
export function reorder(elements: ElementNode[], id: string, order: string[]): ElementNode[] {
  const next = clone(elements);
  const target = mustLocate(next, id);
  const children = Array.isArray(target.node.elements) ? target.node.elements : [];

  const byId = new Map(children.map((child) => [child.id, child]));
  const missing = order.filter((childId) => !byId.has(childId));

  if (missing.length > 0) {
    throw new ElementorMcpError(
      `These ids are not direct children of "${id}": ${missing.join(', ')}.`,
      {
        code: 'invalid_order',
        status: 400,
        hint: `Direct children are: ${children.map((child) => child.id).join(', ') || '(none)'}.`,
      },
    );
  }

  // Ids omitted from `order` keep their relative position at the end.
  const reordered = order.map((childId) => byId.get(childId)!);
  const remainder = children.filter((child) => !order.includes(child.id));

  target.node.elements = [...reordered, ...remainder];

  return next;
}

/** Wrap a node in a new parent container, in place. */
export function wrap(
  elements: ElementNode[],
  id: string,
  wrapper?: ElementNode,
): { elements: ElementNode[]; wrapperId: string } {
  const next = clone(elements);
  const target = mustLocate(next, id);
  const taken = new Set(collectIds(next));

  const shell: ElementNode = wrapper
    ? { ...clone(wrapper), id: generateId(taken), elements: [] }
    : makeContainer({}, [], 'container', generateId(taken));

  const [detached] = target.siblings.splice(target.index, 1);
  shell.elements = [detached!];
  target.siblings.splice(target.index, 0, shell);

  return { elements: next, wrapperId: shell.id };
}

/**
 * Apply a list of operations in order.
 *
 * Every operation runs against the result of the previous one, and the whole
 * batch either succeeds or throws — the caller only writes back on success, so
 * a page is never left half-edited.
 */
export function applyOperations(
  elements: ElementNode[],
  operations: TreeOperation[],
): { elements: ElementNode[]; log: string[] } {
  let current = clone(elements);
  const log: string[] = [];

  operations.forEach((operation, index) => {
    const step = `#${index + 1} ${operation.op}`;

    try {
      switch (operation.op) {
        case 'insert': {
          const options: { targetId?: string; position?: InsertPosition } = {};
          if (operation.targetId !== undefined) options.targetId = operation.targetId;
          if (operation.position !== undefined) options.position = operation.position;
          current = insert(current, operation.node, options);
          log.push(`${step}: added ${operation.node.widgetType ?? operation.node.elType}`);
          break;
        }
        case 'update': {
          current = updateSettings(current, operation.targetId, operation.settings, operation.mode ?? 'merge');
          log.push(`${step}: updated ${Object.keys(operation.settings).length} setting(s) on ${operation.targetId}`);
          break;
        }
        case 'move': {
          const options: { referenceId?: string; position?: InsertPosition } = {};
          if (operation.referenceId !== undefined) options.referenceId = operation.referenceId;
          if (operation.position !== undefined) options.position = operation.position;
          current = move(current, operation.targetId, options);
          log.push(`${step}: moved ${operation.targetId}`);
          break;
        }
        case 'duplicate': {
          const result = duplicate(current, operation.targetId);
          current = result.elements;
          log.push(`${step}: duplicated ${operation.targetId} as ${result.newId}`);
          break;
        }
        case 'delete': {
          current = remove(current, operation.targetId);
          log.push(`${step}: deleted ${operation.targetId}`);
          break;
        }
        case 'reorder': {
          current = reorder(current, operation.targetId, operation.order);
          log.push(`${step}: reordered children of ${operation.targetId}`);
          break;
        }
        case 'wrap': {
          const result = wrap(current, operation.targetId, operation.wrapper);
          current = result.elements;
          log.push(`${step}: wrapped ${operation.targetId} in ${result.wrapperId}`);
          break;
        }
        default: {
          const unknown = operation as { op: string };
          throw new ElementorMcpError(`Unknown operation "${unknown.op}".`, {
            code: 'unknown_operation',
            status: 400,
          });
        }
      }
    } catch (error) {
      const reason = error instanceof Error ? error.message : String(error);
      throw new ElementorMcpError(`Batch failed at operation ${step}: ${reason}`, {
        code: 'batch_failed',
        status: 400,
        hint: 'No changes were written. Fix this operation and resend the whole batch.',
        details: { failedAt: index, completed: log },
      });
    }
  });

  return { elements: current, log };
}

/**
 * Check a tree for the mistakes that silently corrupt a page.
 *
 * Returns a list of problems; an empty list means the tree is safe to write.
 */
export function validate(elements: ElementNode[]): string[] {
  const problems: string[] = [];
  const seen = new Map<string, string>();

  const check = (nodes: unknown, trail: string): void => {
    if (!Array.isArray(nodes)) {
      problems.push(`Element list at ${trail || 'root'} must be an array.`);
      return;
    }

    nodes.forEach((node, index) => {
      const here = trail ? `${trail}.${index}` : `${index}`;

      if (!node || typeof node !== 'object' || Array.isArray(node)) {
        problems.push(`Element at ${here} is not an object.`);
        return;
      }

      const element = node as ElementNode;

      if (typeof element.elType !== 'string' || !element.elType) {
        problems.push(`Element at ${here} is missing a string "elType".`);
        return;
      }

      if (typeof element.id !== 'string' || !element.id) {
        problems.push(`Element at ${here} is missing a string "id".`);
      } else if (seen.has(element.id)) {
        problems.push(
          `Duplicate element id "${element.id}" at ${here} (first seen at ${seen.get(element.id)}).`,
        );
      } else {
        seen.set(element.id, here);
      }

      if (element.elType === 'widget' && !element.widgetType) {
        problems.push(`Widget at ${here} is missing "widgetType".`);
      }

      if (element.settings !== undefined && (typeof element.settings !== 'object' || element.settings === null || Array.isArray(element.settings))) {
        problems.push(`Element at ${here} has a non-object "settings".`);
      }

      if (Array.isArray(element.elements) && element.elements.length > 0) {
        if (element.elType === 'widget') {
          problems.push(`Widget at ${here} cannot contain child elements.`);
          return;
        }

        check(element.elements, `${here}.elements`);
      }
    });
  };

  check(elements, '');

  return problems;
}

/** Throw if a tree is not safe to write. */
export function assertValid(elements: ElementNode[]): void {
  const problems = validate(elements);

  if (problems.length > 0) {
    throw new ElementorMcpError('The resulting element tree is not valid Elementor data.', {
      code: 'invalid_tree',
      status: 400,
      hint: 'Fix the listed problems and retry. Nothing was written.',
      details: { problems: problems.slice(0, 25) },
    });
  }
}
