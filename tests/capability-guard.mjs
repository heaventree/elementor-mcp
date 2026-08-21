/**
 * End-to-end checks for the capability guard, against a mock of the site that
 * prompted it.
 *
 * A live install running Elementor 4.2.2 on WordPress 7.1 reported no container
 * element: Elementor registers Container only when its experiment is active,
 * and that experiment is off by default on any site installed before 3.16.
 * Adding a container there saves cleanly and then renders nothing, which is the
 * worst failure available — no error, no visible cause, just a blank band on
 * the page.
 *
 * So these drive the real server over stdio against a bridge configured exactly
 * that way. The mock's write log is what makes them worth having: a regression
 * that kept the wording but lost the guard would still satisfy a text
 * assertion, and cannot satisfy an empty write log.
 */

import assert from 'node:assert/strict';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StdioClientTransport } from '@modelcontextprotocol/sdk/client/stdio.js';
import { startMockBridge } from './mock-bridge.mjs';

/** Connect the built server to a mock site. */
async function connect(bridge) {
  const transport = new StdioClientTransport({
    command: process.execPath,
    args: ['dist/index.js'],
    env: {
      ...process.env,
      WORDPRESS_URL: bridge.url,
      WORDPRESS_USERNAME: bridge.username,
      WORDPRESS_APP_PASSWORD: bridge.appPassword,
    },
  });

  const client = new Client({ name: 'capability-guard', version: '1.0.0' });
  await client.connect(transport);

  return client;
}

/**
 * Run a check against a freshly configured mock site.
 *
 * Owns both the mock and the server subprocess, so an assertion that throws
 * part-way through still tears them down. Without this a single failure leaves
 * a live child process behind and the run never exits.
 */
async function withSite(options, run) {
  const bridge = await startMockBridge(options);
  let client;

  try {
    client = await connect(bridge);
    await run(client, bridge);
  } finally {
    if (client) await client.close().catch(() => {});
    await bridge.close();
  }
}

const errorCode = (result) => result.structuredContent?.error?.code;
const bodyText = (result) => result.content[0].text;

const checks = [];
const check = (name, run) => checks.push({ name, run });

/** The reporting site: classic section/column only, no container element. */
const classicSite = {
  structuralElements: ['section', 'column'],
  documents: { 55: { title: 'Home', elements: [] } },
};

check('a site without container refuses one, and writes nothing', () =>
  withSite(classicSite, async (client, bridge) => {
    const result = await client.callTool({
      name: 'elementor_add_container',
      arguments: { postId: 55, elType: 'container' },
    });

    assert.equal(result.isError, true, 'adding an unavailable container should fail');
    assert.equal(errorCode(result), 'element_type_unavailable');

    const text = bodyText(result);
    assert.match(text, /no "container" element registered/);
    // The refusal has to name a way forward, or the agent's only move is to
    // make the same call again.
    assert.match(text, /section/, 'the hint should name the elements this site does have');
    assert.match(text, /Elementor > Settings > Features > Container/);

    assert.deepEqual(bridge.writes, [], 'a refused element type must not reach the site');
    assert.deepEqual(bridge.documents.get(55).elements, [], 'the document must be untouched');
  }));

check('the same site accepts a section', () =>
  withSite(classicSite, async (client, bridge) => {
    const result = await client.callTool({
      name: 'elementor_add_container',
      arguments: { postId: 55, elType: 'section' },
    });

    assert.notEqual(result.isError, true, `adding a section should succeed: ${bodyText(result)}`);
    assert.equal(bridge.writes.length, 1, 'the section should have been written once');

    const [written] = bridge.documents.get(55).elements;
    assert.equal(written.elType, 'section');
    assert.equal(result.structuredContent.nodeCountAfter, 1);
    // Nothing unrenderable went in, so the site has nothing to warn about.
    assert.equal(result.structuredContent.warnings, undefined);
  }));

check('a site with container accepts one', () =>
  withSite(
    {
      structuralElements: ['section', 'column', 'container'],
      documents: { 55: { title: 'Home', elements: [] } },
    },
    async (client, bridge) => {
      const result = await client.callTool({
        name: 'elementor_add_container',
        arguments: { postId: 55, elType: 'container' },
      });

      assert.notEqual(result.isError, true, `container should be allowed here: ${bodyText(result)}`);
      assert.equal(bridge.documents.get(55).elements[0].elType, 'container');
    },
  ));

check('a warning from the site leads the confirmation', () =>
  withSite(
    {
      // A page already holding a widget from a deactivated addon. Editing it
      // must still go through — refusing would lock the page against every
      // other change — but the caller has to be told.
      structuralElements: ['section', 'column'],
      widgets: ['heading'],
      documents: {
        55: {
          title: 'Home',
          elements: [
            {
              id: 'aaa1111',
              elType: 'section',
              settings: {},
              elements: [
                {
                  id: 'bbb2222',
                  elType: 'column',
                  settings: {},
                  elements: [
                    {
                      id: 'ccc3333',
                      elType: 'widget',
                      widgetType: 'slider-pro',
                      settings: {},
                      elements: [],
                    },
                  ],
                },
              ],
            },
          ],
        },
      },
    },
    async (client) => {
      const result = await client.callTool({
        name: 'elementor_add_widget',
        arguments: {
          postId: 55,
          widgetType: 'heading',
          targetId: 'bbb2222',
          settings: { title: 'Hello' },
        },
      });

      assert.notEqual(result.isError, true, `the write should succeed: ${bodyText(result)}`);
      assert.match(bodyText(result), /^WARNING: /m, 'the warning should lead the summary');
      assert.match(bodyText(result), /slider-pro/);
      assert.equal(result.structuredContent.warnings.length, 1);
    },
  ));

check('a site reporting no registry at all is not second-guessed', () =>
  withSite(
    {
      // An older bridge answers /status without structuralElements. With no
      // ground truth to check against, guessing would block legitimate work,
      // so the guard has to stand down.
      structuralElements: [],
      documents: { 55: { title: 'Home', elements: [] } },
    },
    async (client, bridge) => {
      const result = await client.callTool({
        name: 'elementor_add_container',
        arguments: { postId: 55, elType: 'container' },
      });

      assert.notEqual(result.isError, true, `an unknown registry must not block: ${bodyText(result)}`);
      assert.equal(bridge.writes.length, 1);
    },
  ));

check('a write that races another edit is retried against fresh data', () =>
  withSite(
    {
      structuralElements: ['section', 'column'],
      documents: { 55: { title: 'Home', elements: [] } },
      // Someone hits Update in the Elementor editor between our read and our
      // write. The hash we carry is stale, so the site rejects the write.
      concurrentEdit: (doc) => {
        doc.elements = [{ id: 'zzz9999', elType: 'section', settings: {}, elements: [] }];
      },
    },
    async (client, bridge) => {
      const result = await client.callTool({
        name: 'elementor_add_container',
        arguments: { postId: 55, elType: 'section' },
      });

      assert.notEqual(result.isError, true, `the retry should succeed: ${bodyText(result)}`);
      assert.equal(bridge.writes.length, 1, 'only the second attempt should have been accepted');

      // The point of the retry is that the other editor's work survives: our
      // change is re-applied to their version rather than replacing it.
      const ids = bridge.documents.get(55).elements.map((element) => element.id);
      assert.ok(ids.includes('zzz9999'), 'the concurrent edit must not be clobbered');
      assert.equal(ids.length, 2);
      assert.equal(result.structuredContent.nodeCountBefore, 1, 'the retry should re-read first');
    },
  ));

check('a missing bridge plugin says so', () =>
  withSite({ bridgeInstalled: false }, async (client) => {
    const result = await client.callTool({
      name: 'elementor_get_outline',
      arguments: { postId: 55 },
    });

    assert.equal(result.isError, true);
    assert.equal(errorCode(result), 'bridge_missing');
    assert.match(bodyText(result), /not installed or not active/);
  }));

let failed = 0;

for (const { name, run } of checks) {
  try {
    await run();
    console.log(`  ok   ${name}`);
  } catch (error) {
    failed += 1;
    const message = error instanceof Error ? error.message : String(error);
    console.log(`  FAIL ${name}`);
    console.log(`       ${message.split('\n').join('\n       ')}`);
  }
}

console.log(`\n${checks.length - failed}/${checks.length} passed`);

if (failed) {
  console.log('CAPABILITY GUARD FAILED');
  process.exit(1);
}

console.log('CAPABILITY GUARD OK');
