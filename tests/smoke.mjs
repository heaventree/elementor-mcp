/**
 * Boots the built server over stdio and exercises the MCP handshake.
 */
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StdioClientTransport } from '@modelcontextprotocol/sdk/client/stdio.js';

const transport = new StdioClientTransport({
  command: process.execPath,
  args: ['dist/index.js'],
  env: {
    ...process.env,
    WORDPRESS_URL: 'https://example.test',
    WORDPRESS_USERNAME: 'agent',
    WORDPRESS_APP_PASSWORD: 'abcd efgh ijkl mnop',
  },
});

const client = new Client({ name: 'smoke', version: '1.0.0' });
await client.connect(transport);

const { tools } = await client.listTools();
const { prompts } = await client.listPrompts();

console.log(`tools: ${tools.length}`);
console.log(`prompts: ${prompts.length}`);

// A description is the only thing the model has to choose a tool by, so a thin
// one is a defect rather than a note. Collect the problems and fail at the end,
// so one run reports all of them.
const problems = [];

const missingDescription = tools.filter((t) => !t.description || t.description.length < 40);
if (missingDescription.length) {
  problems.push(`WEAK DESCRIPTIONS: ${missingDescription.map((t) => t.name).join(', ')}`);
}

// Every tool should declare annotations so clients can reason about safety.
const missingAnnotations = tools.filter((t) => !t.annotations);
if (missingAnnotations.length) {
  problems.push(`MISSING ANNOTATIONS: ${missingAnnotations.map((t) => t.name).join(', ')}`);
}

console.log('---- tool names ----');
for (const t of tools) {
  const a = t.annotations ?? {};
  const flags = [a.readOnlyHint ? 'read' : null, a.destructiveHint ? 'destructive' : null]
    .filter(Boolean)
    .join(',');
  console.log(`  ${t.name}${flags ? ` [${flags}]` : ''}`);
}

// A tool call against an unreachable site must return a helpful error, not crash.
const res = await client.callTool({ name: 'elementor_list_sites', arguments: {} });
console.log('---- elementor_list_sites ----');
console.log(res.content[0].text.slice(0, 300));

await client.close();

if (problems.length) {
  for (const problem of problems) console.log(problem);
  console.log('SMOKE FAILED');
  process.exit(1);
}

console.log('SMOKE OK');
