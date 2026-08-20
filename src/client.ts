/**
 * HTTP client for a WordPress site.
 *
 * Talks to two REST namespaces: the bridge plugin's `elementor-mcp/v1` for
 * everything Elementor-specific, and core's `wp/v2` for ordinary post and media
 * work. Failures are translated into messages that tell the agent what to do
 * next rather than surfacing a bare status code.
 */

import { ElementorMcpError } from './util/errors.js';
import type { ServerConfig, SiteProfile } from './config.js';
import { resolveSite } from './config.js';

/** Options for a single request. */
interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'DELETE';
  query?: Record<string, unknown>;
  body?: unknown;
  /** Treat this call as a write, so read-only profiles can refuse it. */
  write?: boolean;
}

const BRIDGE_NAMESPACE = 'elementor-mcp/v1';
const CORE_NAMESPACE = 'wp/v2';

/** How many times to retry a request that failed for a transient reason. */
const MAX_RETRIES = 2;

function buildQuery(query: Record<string, unknown> | undefined): string {
  if (!query) return '';

  const params = new URLSearchParams();

  for (const [key, value] of Object.entries(query)) {
    if (value === undefined || value === null || value === '') continue;

    if (Array.isArray(value)) {
      // WordPress expects repeated bracketed keys for array query args.
      value.forEach((item) => params.append(`${key}[]`, String(item)));
      continue;
    }

    params.append(key, typeof value === 'boolean' ? String(value) : String(value));
  }

  const serialised = params.toString();

  return serialised ? `?${serialised}` : '';
}

/** What a site can actually do, read once from its /status route. */
export interface SiteCapabilities {
  elementorActive: boolean;
  elementorVersion: string | null;
  /** Structural element types registered here, e.g. section, column, container. */
  structuralElements: string[];
  /**
   * Whether the flexbox Container element exists. Elementor gates it behind an
   * experiment that defaults to inactive on any site installed before 3.16, so
   * a current Elementor is not a guarantee that containers are available.
   */
  containerAvailable: boolean;
  widgetCount: number;
}

/** A REST client bound to one site. */
export class WordPressClient {
  readonly site: SiteProfile;
  private readonly timeoutMs: number;
  private readonly globalReadOnly: boolean;
  private readonly authHeader: string;
  private capabilityCache: Promise<SiteCapabilities> | null = null;

  constructor(site: SiteProfile, timeoutMs: number, globalReadOnly: boolean) {
    this.site = site;
    this.timeoutMs = timeoutMs;
    this.globalReadOnly = globalReadOnly;
    this.authHeader = `Basic ${Buffer.from(`${site.username}:${site.appPassword}`).toString('base64')}`;
  }

  /** Call the bridge plugin. */
  async bridge<T = unknown>(path: string, options: RequestOptions = {}): Promise<T> {
    return this.request<T>(`${BRIDGE_NAMESPACE}/${path.replace(/^\/+/, '')}`, options);
  }

  /** Call core's REST API. */
  async core<T = unknown>(path: string, options: RequestOptions = {}): Promise<T> {
    return this.request<T>(`${CORE_NAMESPACE}/${path.replace(/^\/+/, '')}`, options);
  }

  /**
   * Read this site's capabilities, once per process.
   *
   * Cached because it gates element creation and would otherwise add a round
   * trip to every layout call. A site's registered element types do not change
   * within the lifetime of a session.
   */
  async capabilities(): Promise<SiteCapabilities> {
    if (!this.capabilityCache) {
      this.capabilityCache = this.bridge<Record<string, unknown>>('status')
        .then((status) => ({
          elementorActive: Boolean(status.elementorActive),
          elementorVersion: (status.elementorVersion as string | null) ?? null,
          structuralElements: Array.isArray(status.structuralElements)
            ? (status.structuralElements as string[])
            : [],
          containerAvailable: Boolean(status.containerAvailable),
          widgetCount: Number(status.widgetCount ?? 0),
        }))
        .catch((error: unknown) => {
          // Never let a capability probe be the thing that fails a call.
          this.capabilityCache = null;
          throw error;
        });
    }

    return this.capabilityCache;
  }

  /** Is this site writable? */
  assertWritable(): void {
    if (this.globalReadOnly) {
      throw new ElementorMcpError('This MCP server is running in read-only mode, so writes are refused.', {
        code: 'read_only',
        hint: 'Unset ELEMENTOR_MCP_READ_ONLY to allow writes.',
      });
    }

    if (this.site.readOnly) {
      throw new ElementorMcpError(`Site "${this.site.name}" is configured read-only, so writes are refused.`, {
        code: 'read_only',
        hint: 'Remove "readOnly": true from this site profile to allow writes.',
      });
    }
  }

  private async request<T>(path: string, options: RequestOptions): Promise<T> {
    if (options.write) this.assertWritable();

    const method = options.method ?? (options.body ? 'POST' : 'GET');
    const url = `${this.site.url}/wp-json/${path}${buildQuery(options.query)}`;

    let lastError: unknown;

    for (let attempt = 0; attempt <= MAX_RETRIES; attempt += 1) {
      const controller = new AbortController();
      const timer = setTimeout(() => controller.abort(), this.timeoutMs);

      try {
        const response = await fetch(url, {
          method,
          headers: {
            Authorization: this.authHeader,
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'User-Agent': 'elementor-mcp/1.0',
          },
          body: options.body === undefined ? undefined : JSON.stringify(options.body),
          signal: controller.signal,
        });

        const text = await response.text();
        let payload: unknown = null;

        if (text) {
          try {
            payload = JSON.parse(text);
          } catch {
            payload = text;
          }
        }

        if (!response.ok) {
          // 5xx and 429 are worth another try; everything else is a real answer.
          if ((response.status >= 500 || response.status === 429) && attempt < MAX_RETRIES) {
            lastError = payload;
            await new Promise((resolve) => setTimeout(resolve, 2 ** attempt * 500));
            continue;
          }

          throw this.describeFailure(response.status, payload, path);
        }

        return payload as T;
      } catch (error) {
        if (error instanceof ElementorMcpError) throw error;

        const isAbort = error instanceof Error && error.name === 'AbortError';

        if (attempt < MAX_RETRIES && !isAbort) {
          lastError = error;
          await new Promise((resolve) => setTimeout(resolve, 2 ** attempt * 500));
          continue;
        }

        if (isAbort) {
          throw new ElementorMcpError(
            `Request to ${this.site.name} timed out after ${this.timeoutMs}ms.`,
            {
              code: 'timeout',
              hint: 'The site may be slow or the page very large. Raise ELEMENTOR_MCP_TIMEOUT_MS, or fetch an outline instead of the full element tree.',
            },
          );
        }

        throw new ElementorMcpError(
          `Could not reach ${this.site.url}: ${error instanceof Error ? error.message : String(error)}`,
          {
            code: 'network_error',
            hint: 'Check the site URL is correct and reachable from this machine.',
            details: lastError,
          },
        );
      } finally {
        clearTimeout(timer);
      }
    }

    /* istanbul ignore next -- the loop always returns or throws. */
    throw new ElementorMcpError('Request failed after retries.', { code: 'network_error' });
  }

  /** Turn an HTTP failure into something the agent can act on. */
  private describeFailure(status: number, payload: unknown, path: string): ElementorMcpError {
    const body = (payload ?? {}) as { code?: string; message?: string; data?: Record<string, unknown> };
    const message = typeof body.message === 'string' ? body.message : `HTTP ${status}`;
    const isBridgeRoute = path.startsWith(BRIDGE_NAMESPACE);

    if (status === 401) {
      return new ElementorMcpError(`Authentication failed for ${this.site.name}: ${message}`, {
        code: 'unauthorized',
        status,
        hint:
          'Check the username and application password. Application passwords are created under ' +
          'Users > Profile > Application Passwords, and are distinct from the login password.',
      });
    }

    if (status === 403) {
      return new ElementorMcpError(`Permission denied on ${this.site.name}: ${message}`, {
        code: 'forbidden',
        status,
        hint: 'The authenticated WordPress user lacks the capability this action needs. Use an account with a higher role, or ask a site admin.',
      });
    }

    if (status === 404 && isBridgeRoute && body.code === 'rest_no_route') {
      return new ElementorMcpError(
        `The Elementor MCP Bridge plugin is not installed or not active on ${this.site.name}.`,
        {
          code: 'bridge_missing',
          status,
          hint:
            'Install the bridge plugin from the plugin/ directory of this repository, activate it in wp-admin, ' +
            'then retry. Core WordPress tools keep working without it, but no Elementor tool can.',
        },
      );
    }

    if (status === 409) {
      return new ElementorMcpError(message, {
        code: 'conflict',
        status,
        hint: 'Re-read the page, re-apply your change to the fresh copy, then write again.',
        details: body.data,
      });
    }

    if (status === 501) {
      return new ElementorMcpError(message, {
        code: body.code ?? 'not_supported',
        status,
        hint: 'This capability is not available on this site — usually because Elementor (or the feature version it needs) is not installed.',
      });
    }

    return new ElementorMcpError(`${message} (HTTP ${status})`, {
      code: body.code ?? 'request_failed',
      status,
      details: body.data,
    });
  }
}

/** Cache clients so repeated tool calls reuse one instance per site. */
export class ClientRegistry {
  private readonly config: ServerConfig;
  private readonly clients = new Map<string, WordPressClient>();

  constructor(config: ServerConfig) {
    this.config = config;
  }

  /** Get the client for a named site, or the default one. */
  get(name?: string): WordPressClient {
    const site = resolveSite(this.config, name);
    let client = this.clients.get(site.name);

    if (!client) {
      client = new WordPressClient(site, this.config.timeoutMs, this.config.readOnly);
      this.clients.set(site.name, client);
    }

    return client;
  }

  /** All configured site profiles, credentials omitted. */
  list(): Array<{ name: string; url: string; description?: string; readOnly: boolean; isDefault: boolean }> {
    return this.config.sites.map((site) => {
      const entry: { name: string; url: string; description?: string; readOnly: boolean; isDefault: boolean } = {
        name: site.name,
        url: site.url,
        readOnly: Boolean(site.readOnly || this.config.readOnly),
        isDefault: site.name === this.config.defaultSite,
      };

      if (site.description) entry.description = site.description;

      return entry;
    });
  }
}
