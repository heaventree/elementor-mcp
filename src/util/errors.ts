/**
 * Errors that carry a message an agent can act on.
 *
 * Every failure surfaced to the model should say what went wrong *and* what to
 * try next, because the model's only recovery path is another tool call.
 */
export class ElementorMcpError extends Error {
  readonly code: string;
  readonly status?: number;
  readonly hint?: string;
  readonly details?: unknown;

  constructor(
    message: string,
    options: { code?: string; status?: number; hint?: string; details?: unknown } = {},
  ) {
    super(message);
    this.name = 'ElementorMcpError';
    this.code = options.code ?? 'elementor_mcp_error';
    if (options.status !== undefined) this.status = options.status;
    if (options.hint !== undefined) this.hint = options.hint;
    if (options.details !== undefined) this.details = options.details;
  }

  /** A single human-readable string combining message and hint. */
  toDisplay(): string {
    return this.hint ? `${this.message}\n\nNext step: ${this.hint}` : this.message;
  }
}

/** Raised when the caller referenced an element that is not in the document. */
export class ElementNotFoundError extends ElementorMcpError {
  constructor(elementId: string, postId?: number) {
    super(
      postId === undefined
        ? `No element with id "${elementId}" in this tree.`
        : `No element with id "${elementId}" in document ${postId}.`,
      {
        code: 'element_not_found',
        status: 404,
        hint: 'Call elementor_get_outline for this page to list the element ids it actually contains.',
      },
    );
    this.name = 'ElementNotFoundError';
  }
}

/** Raised when a write would clobber a concurrent change. */
export class ConflictError extends ElementorMcpError {
  constructor(expected: string, actual: string) {
    super('The page changed since you last read it, so the write was rejected to avoid overwriting someone else.', {
      code: 'conflict',
      status: 409,
      hint: 'Re-read the page, re-apply your change to the fresh copy, and write again.',
      details: { expectedHash: expected, actualHash: actual },
    });
    this.name = 'ConflictError';
  }
}
