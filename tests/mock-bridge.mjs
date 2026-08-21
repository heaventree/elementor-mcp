/**
 * A stand-in for the bridge plugin, for testing behaviour that only appears
 * when the server is talking to a real site.
 *
 * Everything interesting about this server is decided by the site, not by us:
 * which element types exist, what a document currently holds, whether a write
 * still matches the hash it was read at. Those are the branches that a unit
 * test over the tree module cannot reach, and that a live site reaches only if
 * you happen to own one configured the wrong way. So the mock implements the
 * three routes an edit cycle touches, faithfully enough that the server cannot
 * tell the difference, and lets a test dictate the site's configuration.
 *
 * It is deliberately not a WordPress emulator. Routes outside the ones below
 * answer exactly as WordPress does for an unregistered route, so the
 * "bridge not installed" path stays reachable too.
 */

import { createHash } from 'node:crypto';
import { createServer } from 'node:http';

const NAMESPACE = '/wp-json/elementor-mcp/v1';

/**
 * Hash an element tree the way the bridge does.
 *
 * The value only has to be stable and server-issued — the server never computes
 * one itself, precisely because PHP and JavaScript do not serialise JSON
 * identically. Mirroring the bridge's md5-of-encoded-tree keeps the shape
 * honest.
 */
function hashElements(elements) {
  return createHash('md5').update(JSON.stringify(elements)).digest('hex');
}

/** Walk every node of an element tree, children included. */
function walk(elements, visit) {
  for (const node of elements ?? []) {
    if (!node || typeof node !== 'object') continue;
    visit(node);
    walk(node.elements ?? [], visit);
  }
}

/** Every element id in a tree. */
function collectIds(elements) {
  const ids = [];
  walk(elements, (node) => {
    if (node.id) ids.push(node.id);
  });
  return ids;
}

/**
 * Element and widget types in this tree that the site cannot render.
 *
 * Mirrors EMCP_Schema::unsupported_types, including its bail-out: a site that
 * reports no registry at all makes no claim about what is missing.
 */
function unsupportedTypes(elements, registered) {
  if (!registered.elements.length && !registered.widgets.length) {
    return { elements: [], widgets: [] };
  }

  const missingElements = new Set();
  const missingWidgets = new Set();

  walk(elements, (node) => {
    if (!node.elType) return;

    if (node.elType === 'widget') {
      const type = String(node.widgetType ?? '');
      if (type && !registered.widgets.includes(type)) missingWidgets.add(type);
      return;
    }

    if (!registered.elements.includes(node.elType)) missingElements.add(node.elType);
  });

  return { elements: [...missingElements], widgets: [...missingWidgets] };
}

/** The warnings the bridge attaches to a save it cannot fully render. */
function warningsFor(unsupported) {
  const warnings = [];

  if (unsupported.elements.length) {
    warnings.push(
      `This document uses element types that are not registered on this site and will not render: ${unsupported.elements.join(', ')}. ` +
        'If "container" is listed, enable Elementor > Settings > Features > Container.',
    );
  }

  if (unsupported.widgets.length) {
    warnings.push(
      `This document uses widgets that are not registered on this site and will not render: ${unsupported.widgets.join(', ')}. ` +
        'The plugin providing them may be deactivated.',
    );
  }

  return warnings;
}

/**
 * Start a mock bridge.
 *
 * @param {object} [options]
 * @param {string[]} [options.structuralElements] Element types this site registers.
 * @param {string[]} [options.widgets] Widget types this site registers.
 * @param {Record<number, object>} [options.documents] Seed documents by post id.
 * @param {string} [options.username] Expected Basic auth user.
 * @param {string} [options.appPassword] Expected Basic auth password.
 * @param {string} [options.elementorVersion] Reported Elementor version.
 * @param {string} [options.wordpressVersion] Reported WordPress version.
 * @param {boolean} [options.bridgeInstalled] false answers every bridge route as an unregistered route.
 * @param {(doc: object) => void} [options.concurrentEdit] Applied once, just before the first write is
 *   validated, to model another editor saving between the server's read and its write.
 * @returns {Promise<{
 *   url: string,
 *   username: string,
 *   appPassword: string,
 *   writes: Array<{postId: number, body: object}>,
 *   documents: Map<number, object>,
 *   close: () => Promise<void>,
 * }>}
 */
export async function startMockBridge(options = {}) {
  const structuralElements = options.structuralElements ?? ['section', 'column', 'container'];
  const widgets = options.widgets ?? ['heading', 'image', 'text-editor', 'button'];
  const username = options.username ?? 'agent';
  const appPassword = options.appPassword ?? 'secret';
  const bridgeInstalled = options.bridgeInstalled !== false;
  const registered = { elements: structuralElements, widgets };

  // Cloned, so a fixture shared between tests cannot be edited by one of them.
  const documents = new Map(
    Object.entries(options.documents ?? { 55: { title: 'Home', elements: [] } }).map(
      ([id, doc]) => [Number(id), structuredClone({ title: 'Untitled', elements: [], ...doc })],
    ),
  );

  /** Every accepted write, in order, for a test to assert against. */
  const writes = [];

  let concurrentEditPending = typeof options.concurrentEdit === 'function';

  const expectedAuth = `Basic ${Buffer.from(`${username}:${appPassword}`).toString('base64')}`;

  // Filled in once the ephemeral port is known; the handler only runs after that.
  let baseUrl = '';

  const server = createServer((req, res) => {
    const send = (status, payload) => {
      const body = JSON.stringify(payload);
      res.writeHead(status, {
        'Content-Type': 'application/json; charset=utf-8',
        'Content-Length': Buffer.byteLength(body),
      });
      res.end(body);
    };

    const url = new URL(req.url, 'http://127.0.0.1');
    const noRoute = () =>
      send(404, {
        code: 'rest_no_route',
        message: 'No route was found matching the URL and request method.',
        data: { status: 404 },
      });

    if (req.headers.authorization !== expectedAuth) {
      send(401, {
        code: 'rest_not_logged_in',
        message: 'Sorry, you are not allowed to do that.',
        data: { status: 401 },
      });
      return;
    }

    if (!bridgeInstalled || !url.pathname.startsWith(NAMESPACE)) {
      noRoute();
      return;
    }

    const route = url.pathname.slice(NAMESPACE.length);

    if (route === '/status' && req.method === 'GET') {
      const containerAvailable = structuralElements.includes('container');

      send(200, {
        bridgeVersion: '1.0.1',
        restNamespace: 'elementor-mcp/v1',
        wordpress: options.wordpressVersion ?? '7.1',
        php: '8.2.0',
        siteUrl: baseUrl,
        elementorActive: true,
        elementorVersion: options.elementorVersion ?? '4.2.2',
        elementorPro: null,
        destructiveEnabled: false,
        user: {
          id: 1,
          canEditPosts: true,
          canManageGlobals: true,
          canUploadFiles: true,
        },
        nativeMcp: {
          moduleClass: false,
          abilitiesApi: false,
          mcpAdapter: false,
          sharedRegistry: false,
          fullyActive: false,
          proxyUsable: false,
          proxyRoute: '/wp-json/elementor/v1/mcp-proxy',
        },
        activeKitId: 3,
        widgetCount: widgets.length,
        structuralElements,
        containerAvailable,
        containerExperiment: containerAvailable ? 'active' : 'inactive',
        ...(containerAvailable
          ? {}
          : {
              layoutNote:
                'This site has no container element. Build layouts with section and column, or enable ' +
                'Elementor > Settings > Features > Container to use flexbox containers.',
            }),
      });
      return;
    }

    const documentMatch = /^\/documents\/(\d+)$/.exec(route);

    if (!documentMatch) {
      noRoute();
      return;
    }

    const postId = Number(documentMatch[1]);
    const doc = documents.get(postId);

    if (!doc) {
      send(404, {
        code: 'emcp_post_not_found',
        message: `No post with ID ${postId}.`,
        data: { status: 404 },
      });
      return;
    }

    if (req.method === 'GET') {
      const includeElements = url.searchParams.get('includeElements') === 'true';

      send(200, {
        id: postId,
        title: doc.title,
        slug: doc.title.toLowerCase().replace(/\s+/g, '-'),
        status: 'publish',
        type: 'page',
        permalink: `${baseUrl}/?page_id=${postId}`,
        editUrl: `${baseUrl}/wp-admin/post.php?post=${postId}&action=elementor`,
        modified: '2026-08-21T00:00:00',
        builtWith: 'builder',
        isElementor: true,
        hash: hashElements(doc.elements),
        nodeCount: collectIds(doc.elements).length,
        snapshotCount: 0,
        ...(includeElements ? { elements: doc.elements } : {}),
      });
      return;
    }

    if (req.method !== 'POST') {
      noRoute();
      return;
    }

    let raw = '';
    req.on('data', (chunk) => {
      raw += chunk;
    });
    req.on('end', () => {
      let body;

      try {
        body = JSON.parse(raw || '{}');
      } catch {
        send(400, { code: 'rest_invalid_json', message: 'Invalid JSON body.', data: { status: 400 } });
        return;
      }

      // Model another editor saving in between: the document moves on after the
      // server read it, so the hash it carries no longer matches.
      if (concurrentEditPending) {
        concurrentEditPending = false;
        options.concurrentEdit(doc);
      }

      const currentHash = hashElements(doc.elements);

      if (body.expectedHash && body.expectedHash !== currentHash) {
        send(409, {
          code: 'emcp_conflict',
          message:
            'The page changed since you last read it, so the write was rejected to avoid overwriting someone else.',
          data: { status: 409, expectedHash: body.expectedHash, actualHash: currentHash },
        });
        return;
      }

      if (Array.isArray(body.elements)) doc.elements = body.elements;

      writes.push({ postId, body });

      const warnings = warningsFor(unsupportedTypes(doc.elements, registered));

      send(200, {
        id: postId,
        hash: hashElements(doc.elements),
        nodeCount: collectIds(doc.elements).length,
        editUrl: `${baseUrl}/wp-admin/post.php?post=${postId}&action=elementor`,
        permalink: `${baseUrl}/?page_id=${postId}`,
        ...(warnings.length ? { warnings } : {}),
      });
    });
  });

  // Port 0 so concurrent runs cannot collide on a fixed port.
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));

  baseUrl = `http://127.0.0.1:${server.address().port}`;

  return {
    url: baseUrl,
    username,
    appPassword,
    writes,
    documents,
    close: () =>
      new Promise((resolve) => {
        // A test that fails mid-check leaves the server's keep-alive socket
        // open, and close() would wait on it forever.
        server.closeAllConnections?.();
        server.close(resolve);
      }),
  };
}
