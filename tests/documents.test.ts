import { describe, expect, it, vi } from 'vitest';

import { editDocument, loadDocument } from '../src/elementor/documents.js';
import { updateSettings } from '../src/elementor/tree.js';
import { ElementorMcpError } from '../src/util/errors.js';
import type { WordPressClient } from '../src/client.js';
import type { ElementNode } from '../src/elementor/types.js';

function page(title: string): ElementNode[] {
  return [
    {
      id: 'aaa0001',
      elType: 'container',
      settings: {},
      elements: [
        { id: 'bbb0001', elType: 'widget', widgetType: 'heading', settings: { title }, elements: [] },
      ],
    },
  ];
}

/** A stand-in for the REST client that records what it was asked to do. */
function fakeClient(script: {
  reads: Array<{ hash: string; elements: ElementNode[] }>;
  writeResults: Array<'conflict' | { hash: string }>;
}) {
  const writes: Array<Record<string, unknown>> = [];
  let readIndex = 0;
  let writeIndex = 0;

  const bridge = vi.fn(async (path: string, options: Record<string, unknown> = {}) => {
    if (options.method === 'POST') {
      const body = options.body as Record<string, unknown>;
      writes.push(body);

      const outcome = script.writeResults[writeIndex++];

      if (outcome === 'conflict') {
        throw new ElementorMcpError('changed underneath', { code: 'conflict', status: 409 });
      }

      return {
        id: 7,
        hash: (outcome as { hash: string }).hash,
        nodeCount: 2,
        editUrl: 'https://example.test/edit',
      };
    }

    const read = script.reads[Math.min(readIndex++, script.reads.length - 1)]!;

    return {
      id: 7,
      title: 'Page',
      hash: read.hash,
      nodeCount: 2,
      elements: read.elements,
    };
  });

  return { client: { bridge } as unknown as WordPressClient, writes, bridge };
}

describe('loadDocument', () => {
  it('returns the tree and the server-issued hash', async () => {
    const { client } = fakeClient({ reads: [{ hash: 'h1', elements: page('One') }], writeResults: [] });
    const loaded = await loadDocument(client, 7);

    expect(loaded.hash).toBe('h1');
    expect(loaded.elements).toHaveLength(1);
    // The tree is not duplicated into the metadata block.
    expect(loaded.meta).not.toHaveProperty('elements');
  });

  it('fails clearly when the bridge is too old to return a hash', async () => {
    const bridge = vi.fn(async () => ({ id: 7, title: 'Page', elements: [] }));
    const client = { bridge } as unknown as WordPressClient;

    await expect(loadDocument(client, 7)).rejects.toThrow(/did not return a content hash/);

    const error = await loadDocument(client, 7).catch((caught: unknown) => caught);
    expect((error as ElementorMcpError).hint).toMatch(/Update the Elementor MCP Bridge plugin/);
  });
});

describe('editDocument', () => {
  it('writes the mutated tree back with the hash it read', async () => {
    const { client, writes } = fakeClient({
      reads: [{ hash: 'h1', elements: page('Before') }],
      writeResults: [{ hash: 'h2' }],
    });

    const outcome = await editDocument(
      client,
      7,
      (elements) => ({ elements: updateSettings(elements, 'bbb0001', { title: 'After' }) }),
      'test edit',
    );

    expect(writes).toHaveLength(1);
    expect(writes[0]!.expectedHash).toBe('h1');
    expect(writes[0]!.snapshotLabel).toBe('test edit');
    expect(outcome.result.hash).toBe('h2');
    expect(outcome.before.nodeCount).toBe(2);
    expect(outcome.after.nodeCount).toBe(2);
  });

  it('retries once against fresh data after a conflict', async () => {
    const { client, writes } = fakeClient({
      reads: [
        { hash: 'h1', elements: page('First read') },
        { hash: 'h9', elements: page('Someone else edited this') },
      ],
      writeResults: ['conflict', { hash: 'h10' }],
    });

    const outcome = await editDocument(client, 7, (elements) => ({
      elements: updateSettings(elements, 'bbb0001', { align: 'center' }),
    }));

    expect(writes).toHaveLength(2);
    // The retry re-reads and uses the newer hash rather than forcing the stale one.
    expect(writes[1]!.expectedHash).toBe('h9');
    expect(outcome.result.hash).toBe('h10');
  });

  it('surfaces a second conflict instead of looping', async () => {
    const { client, writes } = fakeClient({
      reads: [{ hash: 'h1', elements: page('One') }],
      writeResults: ['conflict', 'conflict'],
    });

    await expect(
      editDocument(client, 7, (elements) => ({ elements })),
    ).rejects.toThrow(/changed underneath/);

    expect(writes).toHaveLength(2);
  });

  it('does not write when the mutation throws', async () => {
    const { client, writes } = fakeClient({
      reads: [{ hash: 'h1', elements: page('One') }],
      writeResults: [{ hash: 'h2' }],
    });

    await expect(
      editDocument(client, 7, (elements) => ({
        elements: updateSettings(elements, 'missing-id', {}),
      })),
    ).rejects.toThrow(/No element with id "missing-id"/);

    expect(writes).toHaveLength(0);
  });

  it('refuses to write a tree that would corrupt the page', async () => {
    const { client, writes } = fakeClient({
      reads: [{ hash: 'h1', elements: page('One') }],
      writeResults: [{ hash: 'h2' }],
    });

    await expect(
      editDocument(client, 7, () => ({
        elements: [
          { id: 'dup', elType: 'container', settings: {}, elements: [] },
          { id: 'dup', elType: 'container', settings: {}, elements: [] },
        ],
      })),
    ).rejects.toThrow(/not valid Elementor data/);

    expect(writes).toHaveLength(0);
  });
});
