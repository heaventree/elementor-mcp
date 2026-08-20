/**
 * Document read/modify/write service.
 *
 * The `editDocument` helper is the pattern every element-editing tool uses:
 * read the current tree, mutate it in memory, validate it, and write it back
 * with the hash we read it at. The hash comes from the server (PHP and
 * JavaScript do not serialise JSON identically, so it is never computed here) —
 * if anyone else changed the page in between, the write is rejected instead of
 * silently discarding their work.
 */

import type { WordPressClient } from '../client.js';
import { ElementorMcpError } from '../util/errors.js';
import { assertValid, census, collectIds } from './tree.js';
import type { DocumentMeta, ElementNode } from './types.js';

/** A document as read from the bridge. */
export interface LoadedDocument {
  meta: DocumentMeta;
  elements: ElementNode[];
  hash: string;
}

/** Result of a write. */
export interface WriteResult {
  id: number;
  hash: string;
  nodeCount: number;
  editUrl?: string;
  permalink?: string;
}

/** Read a document with its element tree. */
export async function loadDocument(client: WordPressClient, postId: number): Promise<LoadedDocument> {
  const meta = await client.bridge<DocumentMeta>(`documents/${postId}`, {
    query: { includeElements: true },
  });

  const elements = Array.isArray(meta.elements) ? meta.elements : [];

  if (typeof meta.hash !== 'string') {
    throw new ElementorMcpError(`The bridge did not return a content hash for document ${postId}.`, {
      code: 'missing_hash',
      hint: 'Update the Elementor MCP Bridge plugin on this site to the version shipped with this server.',
    });
  }

  const { elements: _dropped, ...rest } = meta;

  return { meta: rest, elements, hash: meta.hash };
}

/** Write an element tree back, guarded by the hash it was read at. */
export async function writeElements(
  client: WordPressClient,
  postId: number,
  elements: ElementNode[],
  expectedHash: string,
  snapshotLabel?: string,
): Promise<WriteResult> {
  assertValid(elements);

  return client.bridge<WriteResult>(`documents/${postId}`, {
    method: 'POST',
    write: true,
    body: {
      elements,
      expectedHash,
      snapshot: true,
      ...(snapshotLabel ? { snapshotLabel } : {}),
    },
  });
}

/** What an edit produced, ready to summarise for the model. */
export interface EditOutcome<T> {
  result: WriteResult;
  extra: T;
  before: { nodeCount: number; census: Record<string, number> };
  after: { nodeCount: number; census: Record<string, number> };
}

/**
 * Read a document, apply a mutation, and write it back atomically.
 *
 * On a hash conflict the whole cycle is retried once against fresh data, which
 * absorbs the common case of two edits landing close together. A second
 * conflict is surfaced, because by then something is genuinely contending.
 */
export async function editDocument<T = undefined>(
  client: WordPressClient,
  postId: number,
  mutate: (elements: ElementNode[]) => { elements: ElementNode[]; extra?: T },
  snapshotLabel?: string,
): Promise<EditOutcome<T>> {
  let lastError: unknown;

  for (let attempt = 0; attempt < 2; attempt += 1) {
    const loaded = await loadDocument(client, postId);
    const before = {
      nodeCount: collectIds(loaded.elements).length,
      census: census(loaded.elements),
    };

    const mutated = mutate(loaded.elements);

    try {
      const result = await writeElements(
        client,
        postId,
        mutated.elements,
        loaded.hash,
        snapshotLabel,
      );

      return {
        result,
        extra: mutated.extra as T,
        before,
        after: {
          nodeCount: collectIds(mutated.elements).length,
          census: census(mutated.elements),
        },
      };
    } catch (error) {
      const isConflict = error instanceof ElementorMcpError && error.code === 'conflict';

      if (!isConflict || attempt === 1) throw error;

      lastError = error;
    }
  }

  /* istanbul ignore next -- the loop returns or throws above. */
  throw lastError;
}
