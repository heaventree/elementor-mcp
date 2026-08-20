/**
 * Helpers for shaping tool results.
 *
 * Tool output is context the model has to carry, so the default is a compact
 * summary rather than a raw dump. Anything genuinely large (a full element
 * tree) should be summarised here and fetched deliberately, not returned by
 * accident.
 */

import { ElementorMcpError } from './errors.js';

/** The content shape the MCP SDK expects back from a tool. */
export interface ToolResult {
  content: Array<{ type: 'text'; text: string }>;
  structuredContent?: Record<string, unknown>;
  isError?: boolean;
  [key: string]: unknown;
}

/** Pretty-print a value as JSON for the text channel. */
export function toJson(value: unknown): string {
  return JSON.stringify(value, null, 2);
}

/** A successful result carrying structured data. */
export function ok(data: unknown, summary?: string): ToolResult {
  const text = summary ? `${summary}\n\n${toJson(data)}` : toJson(data);

  const result: ToolResult = {
    content: [{ type: 'text', text }],
  };

  if (data && typeof data === 'object' && !Array.isArray(data)) {
    result.structuredContent = data as Record<string, unknown>;
  }

  return result;
}

/** A plain text result, for confirmations with nothing structured to say. */
export function text(message: string): ToolResult {
  return { content: [{ type: 'text', text: message }] };
}

/** An error result. Never throws — the model needs to read this. */
export function fail(error: unknown): ToolResult {
  if (error instanceof ElementorMcpError) {
    const parts = [error.toDisplay()];

    if (error.details !== undefined) {
      parts.push(`\nDetails: ${toJson(error.details)}`);
    }

    return {
      content: [{ type: 'text', text: parts.join('\n') }],
      isError: true,
      structuredContent: {
        error: {
          code: error.code,
          message: error.message,
          ...(error.hint ? { hint: error.hint } : {}),
          ...(error.status ? { status: error.status } : {}),
        },
      },
    };
  }

  const message = error instanceof Error ? error.message : String(error);

  return {
    content: [{ type: 'text', text: message }],
    isError: true,
    structuredContent: { error: { code: 'unexpected_error', message } },
  };
}

/**
 * Truncate a long list, telling the caller what was dropped.
 *
 * Silently returning the first N items reads as "that is everything", which
 * leads an agent to conclude a page or site is smaller than it is.
 */
export function limitList<T>(
  items: T[],
  limit: number,
): { items: T[]; truncated: boolean; note?: string } {
  if (items.length <= limit) return { items, truncated: false };

  return {
    items: items.slice(0, limit),
    truncated: true,
    note: `Showing ${limit} of ${items.length}. Narrow the query or raise the limit to see the rest.`,
  };
}
