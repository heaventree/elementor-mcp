/**
 * Site profiles and runtime configuration.
 *
 * A single MCP server can front several WordPress installs, which is the normal
 * case when an agency works across client sites. One profile is the default;
 * every tool takes an optional `site` argument to address the others.
 */

import { readFileSync } from 'node:fs';
import { ElementorMcpError } from './util/errors.js';

/** Credentials and metadata for one WordPress install. */
export interface SiteProfile {
  /** Short name used in the `site` tool argument. */
  name: string;
  /** Site root, e.g. https://example.com (no trailing /wp-json). */
  url: string;
  /** WordPress username. */
  username: string;
  /** A WordPress application password, not the account's login password. */
  appPassword: string;
  /** Human note shown in listings. */
  description?: string;
  /** Refuse every write against this site. */
  readOnly?: boolean;
}

/** Fully resolved server configuration. */
export interface ServerConfig {
  sites: SiteProfile[];
  defaultSite: string;
  /** Per-request timeout in milliseconds. */
  timeoutMs: number;
  /** Global kill switch for writes, regardless of per-site settings. */
  readOnly: boolean;
}

/** Strip a trailing slash and any accidental /wp-json suffix. */
function normaliseUrl(raw: string): string {
  return raw
    .trim()
    .replace(/\/+$/, '')
    .replace(/\/wp-json$/i, '');
}

function requireField(value: unknown, field: string, siteName: string): string {
  if (typeof value !== 'string' || value.trim() === '') {
    throw new ElementorMcpError(`Site "${siteName}" is missing "${field}".`, {
      code: 'bad_config',
      hint: 'Each site needs url, username and appPassword. See the README for the exact environment variables.',
    });
  }

  return value.trim();
}

function parseSite(raw: unknown, fallbackName: string): SiteProfile {
  if (!raw || typeof raw !== 'object') {
    throw new ElementorMcpError(`Site "${fallbackName}" is not an object.`, { code: 'bad_config' });
  }

  const record = raw as Record<string, unknown>;
  const name = typeof record.name === 'string' && record.name.trim() ? record.name.trim() : fallbackName;

  const profile: SiteProfile = {
    name,
    url: normaliseUrl(requireField(record.url, 'url', name)),
    username: requireField(record.username, 'username', name),
    appPassword: requireField(record.appPassword ?? record.password, 'appPassword', name),
  };

  if (typeof record.description === 'string') profile.description = record.description;
  if (record.readOnly === true) profile.readOnly = true;

  return profile;
}

/**
 * Build configuration from the environment.
 *
 * Two shapes are supported:
 *
 *  - Single site: `WORDPRESS_URL`, `WORDPRESS_USERNAME`, `WORDPRESS_APP_PASSWORD`.
 *  - Many sites: `ELEMENTOR_MCP_SITES` holding a JSON array (or a JSON object
 *    keyed by site name), or `ELEMENTOR_MCP_CONFIG` pointing at a JSON file.
 */
export function loadConfig(env: NodeJS.ProcessEnv = process.env): ServerConfig {
  const sites: SiteProfile[] = [];

  const configPath = env.ELEMENTOR_MCP_CONFIG?.trim();
  let rawSites: unknown;

  if (configPath) {
    try {
      const parsed = JSON.parse(readFileSync(configPath, 'utf8')) as Record<string, unknown>;
      rawSites = parsed.sites ?? parsed;
    } catch (error) {
      throw new ElementorMcpError(
        `Could not read the config file at ${configPath}: ${error instanceof Error ? error.message : String(error)}`,
        { code: 'bad_config' },
      );
    }
  } else if (env.ELEMENTOR_MCP_SITES?.trim()) {
    try {
      rawSites = JSON.parse(env.ELEMENTOR_MCP_SITES);
    } catch (error) {
      throw new ElementorMcpError(
        `ELEMENTOR_MCP_SITES is not valid JSON: ${error instanceof Error ? error.message : String(error)}`,
        { code: 'bad_config' },
      );
    }
  }

  if (Array.isArray(rawSites)) {
    rawSites.forEach((entry, index) => sites.push(parseSite(entry, `site-${index + 1}`)));
  } else if (rawSites && typeof rawSites === 'object') {
    for (const [name, entry] of Object.entries(rawSites as Record<string, unknown>)) {
      sites.push(parseSite(entry, name));
    }
  }

  if (sites.length === 0 && env.WORDPRESS_URL?.trim()) {
    sites.push(
      parseSite(
        {
          name: env.WORDPRESS_SITE_NAME ?? 'default',
          url: env.WORDPRESS_URL,
          username: env.WORDPRESS_USERNAME,
          appPassword: env.WORDPRESS_APP_PASSWORD ?? env.WORDPRESS_PASSWORD,
        },
        'default',
      ),
    );
  }

  if (sites.length === 0) {
    throw new ElementorMcpError('No WordPress sites are configured.', {
      code: 'no_sites',
      hint:
        'Set WORDPRESS_URL, WORDPRESS_USERNAME and WORDPRESS_APP_PASSWORD for a single site, ' +
        'or ELEMENTOR_MCP_SITES to a JSON array for several. Generate an application password ' +
        'under Users > Profile > Application Passwords in wp-admin.',
    });
  }

  const requestedDefault = env.ELEMENTOR_MCP_DEFAULT_SITE?.trim();
  const defaultSite =
    requestedDefault && sites.some((site) => site.name === requestedDefault)
      ? requestedDefault
      : sites[0]!.name;

  const timeout = Number.parseInt(env.ELEMENTOR_MCP_TIMEOUT_MS ?? '', 10);

  return {
    sites,
    defaultSite,
    timeoutMs: Number.isFinite(timeout) && timeout > 0 ? timeout : 30_000,
    readOnly: env.ELEMENTOR_MCP_READ_ONLY === 'true' || env.ELEMENTOR_MCP_READ_ONLY === '1',
  };
}

/** Look up a site profile by name, defaulting when none is given. */
export function resolveSite(config: ServerConfig, name?: string): SiteProfile {
  const wanted = name?.trim() || config.defaultSite;
  const site = config.sites.find((candidate) => candidate.name === wanted);

  if (!site) {
    throw new ElementorMcpError(`No configured site named "${wanted}".`, {
      code: 'unknown_site',
      hint: `Configured sites: ${config.sites.map((candidate) => candidate.name).join(', ')}.`,
    });
  }

  return site;
}
