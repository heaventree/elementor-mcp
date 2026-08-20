import { describe, expect, it } from 'vitest';

import { loadConfig, resolveSite } from '../src/config.js';
import { ElementorMcpError } from '../src/util/errors.js';

/** Run a throwing call and hand back the typed error for inspection. */
function captureError(run: () => unknown): ElementorMcpError {
  try {
    run();
  } catch (error) {
    if (error instanceof ElementorMcpError) return error;
    throw error;
  }

  throw new Error('Expected the call to throw, but it returned.');
}

const single = {
  WORDPRESS_URL: 'https://example.test/',
  WORDPRESS_USERNAME: 'agent',
  WORDPRESS_APP_PASSWORD: 'abcd efgh',
} as NodeJS.ProcessEnv;

describe('loadConfig', () => {
  it('reads a single site from discrete variables', () => {
    const config = loadConfig(single);

    expect(config.sites).toHaveLength(1);
    expect(config.sites[0]!.name).toBe('default');
    expect(config.defaultSite).toBe('default');
  });

  it('strips a trailing slash and an accidental /wp-json suffix', () => {
    const config = loadConfig({
      ...single,
      WORDPRESS_URL: 'https://example.test/wp-json/',
    });

    expect(config.sites[0]!.url).toBe('https://example.test');
  });

  it('reads several sites from a JSON array', () => {
    const config = loadConfig({
      ELEMENTOR_MCP_SITES: JSON.stringify([
        { name: 'live', url: 'https://live.test', username: 'a', appPassword: 'p' },
        { name: 'staging', url: 'https://staging.test', username: 'b', appPassword: 'q', readOnly: true },
      ]),
      ELEMENTOR_MCP_DEFAULT_SITE: 'staging',
    });

    expect(config.sites.map((site) => site.name)).toEqual(['live', 'staging']);
    expect(config.defaultSite).toBe('staging');
    expect(config.sites[1]!.readOnly).toBe(true);
  });

  it('reads several sites from a JSON object keyed by name', () => {
    const config = loadConfig({
      ELEMENTOR_MCP_SITES: JSON.stringify({
        clientA: { url: 'https://a.test', username: 'a', appPassword: 'p' },
      }),
    });

    expect(config.sites[0]!.name).toBe('clientA');
  });

  it('falls back to the first site when the named default is unknown', () => {
    const config = loadConfig({ ...single, ELEMENTOR_MCP_DEFAULT_SITE: 'nope' });

    expect(config.defaultSite).toBe('default');
  });

  it('explains what to set when nothing is configured', () => {
    const error = captureError(() => loadConfig({}));

    expect(error.message).toMatch(/No WordPress sites are configured/);
    // The recovery instructions live in the hint, which is what reaches the model.
    expect(error.hint).toMatch(/WORDPRESS_URL/);
    expect(error.hint).toMatch(/application password/i);
  });

  it('names the site that is missing a field', () => {
    expect(() =>
      loadConfig({ ELEMENTOR_MCP_SITES: JSON.stringify([{ name: 'live', url: 'https://live.test' }]) }),
    ).toThrow(/Site "live" is missing "username"/);
  });

  it('rejects malformed site JSON with a readable message', () => {
    expect(() => loadConfig({ ELEMENTOR_MCP_SITES: '{not json' })).toThrow(/not valid JSON/);
  });

  it('honours the read-only kill switch', () => {
    expect(loadConfig({ ...single, ELEMENTOR_MCP_READ_ONLY: 'true' }).readOnly).toBe(true);
    expect(loadConfig(single).readOnly).toBe(false);
  });
});

describe('resolveSite', () => {
  const config = loadConfig({
    ELEMENTOR_MCP_SITES: JSON.stringify([
      { name: 'live', url: 'https://live.test', username: 'a', appPassword: 'p' },
      { name: 'staging', url: 'https://staging.test', username: 'b', appPassword: 'q' },
    ]),
  });

  it('returns the default when no name is given', () => {
    expect(resolveSite(config).name).toBe('live');
  });

  it('lists the valid names when asked for an unknown one', () => {
    const error = captureError(() => resolveSite(config, 'ghost'));

    expect(error.message).toMatch(/No configured site named "ghost"/);
    expect(error.hint).toBe('Configured sites: live, staging.');
  });
});
