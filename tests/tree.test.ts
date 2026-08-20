import { describe, expect, it } from 'vitest';

import {
  applyOperations,
  census,
  collectIds,
  describe as describeNode,
  duplicate,
  findNode,
  generateId,
  insert,
  locate,
  makeContainer,
  makeWidget,
  move,
  outline,
  regenerateIds,
  remove,
  reorder,
  updateSettings,
  validate,
  wrap,
} from '../src/elementor/tree.js';
import type { ElementNode } from '../src/elementor/types.js';

/** A small but representative page: two containers, three widgets. */
function samplePage(): ElementNode[] {
  return [
    {
      id: 'aaa0001',
      elType: 'container',
      settings: { flex_direction: 'row' },
      elements: [
        {
          id: 'bbb0001',
          elType: 'widget',
          widgetType: 'heading',
          settings: { title: 'Welcome home' },
          elements: [],
        },
        {
          id: 'bbb0002',
          elType: 'widget',
          widgetType: 'button',
          settings: { text: 'Get started' },
          elements: [],
        },
      ],
    },
    {
      id: 'aaa0002',
      elType: 'container',
      settings: {},
      elements: [
        {
          id: 'ccc0001',
          elType: 'widget',
          widgetType: 'image',
          settings: { image: { id: 12, url: 'https://example.test/a.png' } },
          elements: [],
        },
      ],
    },
  ];
}

describe('generateId', () => {
  it('produces 7-character lowercase hex', () => {
    for (let i = 0; i < 200; i += 1) {
      expect(generateId()).toMatch(/^[0-9a-f]{7}$/);
    }
  });

  it('never repeats an id it has already handed out', () => {
    const taken = new Set<string>();
    const ids = Array.from({ length: 500 }, () => generateId(taken));

    expect(new Set(ids).size).toBe(500);
  });
});

describe('locate and findNode', () => {
  it('finds a nested node with its parent and index', () => {
    const found = locate(samplePage(), 'bbb0002');

    expect(found).not.toBeNull();
    expect(found!.index).toBe(1);
    expect(found!.parent?.id).toBe('aaa0001');
    expect(found!.ancestors).toEqual(['aaa0001']);
  });

  it('returns null for an unknown id', () => {
    expect(findNode(samplePage(), 'nope')).toBeNull();
  });
});

describe('insert', () => {
  it('appends to the page root when no target is given', () => {
    const next = insert(samplePage(), makeWidget('spacer'));

    expect(next).toHaveLength(3);
    expect(next[2]!.widgetType).toBe('spacer');
  });

  it('prepends at the root', () => {
    const next = insert(samplePage(), makeWidget('spacer'), { position: 'prepend' });

    expect(next[0]!.widgetType).toBe('spacer');
  });

  it('appends inside a container', () => {
    const next = insert(samplePage(), makeWidget('divider'), { targetId: 'aaa0002' });

    expect(next[1]!.elements).toHaveLength(2);
    expect(next[1]!.elements![1]!.widgetType).toBe('divider');
  });

  it('places a sibling before a widget', () => {
    const next = insert(samplePage(), makeWidget('divider'), {
      targetId: 'bbb0002',
      position: 'before',
    });

    expect(next[0]!.elements!.map((n) => n.widgetType)).toEqual(['heading', 'divider', 'button']);
  });

  it('refuses to nest an element inside a widget', () => {
    expect(() => insert(samplePage(), makeWidget('divider'), { targetId: 'bbb0001' })).toThrow(
      /widgets do not take children/i,
    );
  });

  it('rekeys an incoming subtree whose ids already exist', () => {
    const clash: ElementNode = {
      id: 'bbb0001',
      elType: 'widget',
      widgetType: 'heading',
      settings: {},
      elements: [],
    };

    const next = insert(samplePage(), clash);
    const ids = collectIds(next);

    expect(new Set(ids).size).toBe(ids.length);
  });

  it('does not mutate the tree it was given', () => {
    const original = samplePage();
    insert(original, makeWidget('spacer'));

    expect(original).toHaveLength(2);
  });

  it('reports an unknown target with an actionable error', () => {
    expect(() => insert(samplePage(), makeWidget('spacer'), { targetId: 'missing' })).toThrow(
      /No element with id "missing"/,
    );
  });
});

describe('updateSettings', () => {
  it('merges by default, keeping untouched keys', () => {
    const next = updateSettings(samplePage(), 'bbb0001', { align: 'center' });
    const node = findNode(next, 'bbb0001')!;

    expect(node.settings).toEqual({ title: 'Welcome home', align: 'center' });
  });

  it('replaces the whole settings object when asked', () => {
    const next = updateSettings(samplePage(), 'bbb0001', { align: 'center' }, 'replace');

    expect(findNode(next, 'bbb0001')!.settings).toEqual({ align: 'center' });
  });
});

describe('move', () => {
  it('moves a widget into another container', () => {
    const next = move(samplePage(), 'bbb0001', { referenceId: 'aaa0002' });

    expect(findNode(next, 'aaa0001')!.elements).toHaveLength(1);
    expect(findNode(next, 'aaa0002')!.elements!.map((n) => n.id)).toEqual(['ccc0001', 'bbb0001']);
  });

  it('moves a node to the page root', () => {
    const next = move(samplePage(), 'bbb0001');

    expect(next).toHaveLength(3);
    expect(next[2]!.id).toBe('bbb0001');
  });

  it('refuses to move a container into its own descendant', () => {
    expect(() => move(samplePage(), 'aaa0001', { referenceId: 'bbb0001' })).toThrow(
      /its own descendants/i,
    );
  });

  it('refuses to move an element relative to itself', () => {
    expect(() => move(samplePage(), 'aaa0001', { referenceId: 'aaa0001' })).toThrow(
      /relative to itself/i,
    );
  });

  it('keeps every node when reordering across parents', () => {
    const before = collectIds(samplePage()).sort();
    const after = collectIds(move(samplePage(), 'ccc0001', { referenceId: 'bbb0001', position: 'before' })).sort();

    expect(after).toEqual(before);
  });
});

describe('duplicate', () => {
  it('places the copy directly after the original with fresh ids', () => {
    const { elements, newId } = duplicate(samplePage(), 'aaa0001');

    expect(elements).toHaveLength(3);
    expect(elements[1]!.id).toBe(newId);
    expect(newId).not.toBe('aaa0001');

    const ids = collectIds(elements);
    expect(new Set(ids).size).toBe(ids.length);
  });

  it('copies the whole subtree', () => {
    const { elements, newId } = duplicate(samplePage(), 'aaa0001');

    expect(findNode(elements, newId)!.elements).toHaveLength(2);
  });
});

describe('remove', () => {
  it('deletes a node and its children', () => {
    const next = remove(samplePage(), 'aaa0001');

    expect(next).toHaveLength(1);
    expect(findNode(next, 'bbb0001')).toBeNull();
  });
});

describe('reorder', () => {
  it('reorders direct children', () => {
    const next = reorder(samplePage(), 'aaa0001', ['bbb0002', 'bbb0001']);

    expect(findNode(next, 'aaa0001')!.elements!.map((n) => n.id)).toEqual(['bbb0002', 'bbb0001']);
  });

  it('leaves omitted children at the end in their original order', () => {
    const page = samplePage();
    page[0]!.elements!.push(makeWidget('divider', {}, 'ddd0001'));

    const next = reorder(page, 'aaa0001', ['ddd0001']);

    expect(findNode(next, 'aaa0001')!.elements!.map((n) => n.id)).toEqual([
      'ddd0001',
      'bbb0001',
      'bbb0002',
    ]);
  });

  it('rejects ids that are not direct children', () => {
    expect(() => reorder(samplePage(), 'aaa0001', ['ccc0001'])).toThrow(/not direct children/i);
  });
});

describe('wrap', () => {
  it('wraps a node in place', () => {
    const { elements, wrapperId } = wrap(samplePage(), 'aaa0001');

    expect(elements).toHaveLength(2);
    expect(elements[0]!.id).toBe(wrapperId);
    expect(elements[0]!.elType).toBe('container');
    expect(elements[0]!.elements![0]!.id).toBe('aaa0001');
  });

  it('uses the wrapper settings it is given', () => {
    const shell = makeContainer({ background_color: '#000' });
    const { elements } = wrap(samplePage(), 'bbb0001', shell);

    expect(findNode(elements, 'aaa0001')!.elements![0]!.settings).toEqual({ background_color: '#000' });
  });
});

describe('regenerateIds', () => {
  it('replaces every id in the tree', () => {
    const original = samplePage();
    const next = regenerateIds(original);

    expect(collectIds(next).some((id) => collectIds(original).includes(id))).toBe(false);
    expect(collectIds(next)).toHaveLength(collectIds(original).length);
  });
});

describe('applyOperations', () => {
  it('applies operations in sequence', () => {
    const { elements, log } = applyOperations(samplePage(), [
      { op: 'insert', node: makeWidget('divider', {}, 'ddd0001'), targetId: 'aaa0002' },
      { op: 'update', targetId: 'bbb0001', settings: { align: 'center' } },
      { op: 'delete', targetId: 'bbb0002' },
    ]);

    expect(log).toHaveLength(3);
    expect(findNode(elements, 'ddd0001')).not.toBeNull();
    expect(findNode(elements, 'bbb0001')!.settings!.align).toBe('center');
    expect(findNode(elements, 'bbb0002')).toBeNull();
  });

  it('abandons the whole batch when one operation fails', () => {
    const original = samplePage();

    expect(() =>
      applyOperations(original, [
        { op: 'update', targetId: 'bbb0001', settings: { align: 'center' } },
        { op: 'delete', targetId: 'does-not-exist' },
      ]),
    ).toThrow(/Batch failed at operation #2/);

    // The caller's tree is untouched, so nothing can be half-written.
    expect(findNode(original, 'bbb0001')!.settings!.align).toBeUndefined();
  });

  it('rejects an unknown operation name', () => {
    expect(() =>
      applyOperations(samplePage(), [{ op: 'explode' } as never]),
    ).toThrow(/Unknown operation/);
  });
});

describe('validate', () => {
  it('accepts a well-formed tree', () => {
    expect(validate(samplePage())).toEqual([]);
  });

  it('catches duplicate ids', () => {
    const page = samplePage();
    page[1]!.id = 'aaa0001';

    expect(validate(page).join(' ')).toMatch(/Duplicate element id "aaa0001"/);
  });

  it('catches a widget with children', () => {
    const page = samplePage();
    page[0]!.elements![0]!.elements = [makeWidget('spacer')];

    expect(validate(page).join(' ')).toMatch(/cannot contain child elements/);
  });

  it('catches a widget with no widgetType', () => {
    const page = samplePage();
    delete page[0]!.elements![0]!.widgetType;

    expect(validate(page).join(' ')).toMatch(/missing "widgetType"/);
  });

  it('catches a missing elType', () => {
    const page = [{ id: 'x' }] as unknown as ElementNode[];

    expect(validate(page).join(' ')).toMatch(/missing a string "elType"/);
  });
});

describe('outline, census and describe', () => {
  it('summarises structure without settings payloads', () => {
    const tree = outline(samplePage());

    expect(tree).toHaveLength(2);
    expect(tree[0]!.elements![0]).toMatchObject({
      id: 'bbb0001',
      elType: 'widget',
      widgetType: 'heading',
      label: 'Welcome home',
    });
    expect(tree[0]).not.toHaveProperty('settings');
  });

  it('collapses to child counts at the depth limit', () => {
    const tree = outline(samplePage(), 1);

    expect(tree[0]!.childCount).toBe(2);
    expect(tree[0]!.elements).toBeUndefined();
  });

  it('counts widgets by type', () => {
    expect(census(samplePage())).toEqual({
      container: 2,
      heading: 1,
      button: 1,
      image: 1,
    });
  });

  it('strips markup when labelling a node', () => {
    expect(describeNode({ id: 'x', elType: 'widget', settings: { title: '<b>Bold</b>  text' } })).toBe(
      'Bold text',
    );
  });

  it('returns an empty label when nothing identifying is set', () => {
    expect(describeNode({ id: 'x', elType: 'container', settings: { flex_direction: 'row' } })).toBe('');
  });
});
