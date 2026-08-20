/**
 * Generates docs/TOOLS.md from the live server, so the reference cannot drift
 * away from what the server actually registers.
 *
 *   node tests/gen-tool-docs.mjs > docs/TOOLS.md
 */
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StdioClientTransport } from '@modelcontextprotocol/sdk/client/stdio.js';

const GROUPS = [
  ['Sites', ['list_sites', 'site_status', 'native_mcp_call']],
  ['Pages', ['list_pages', 'get_outline', 'get_page', 'get_page_tree', 'create_page', 'update_page_meta', 'duplicate_page', 'delete_page', 'render_page']],
  ['Elements', ['get_element', 'find_elements', 'add_widget', 'add_container', 'update_element', 'move_element', 'duplicate_element', 'delete_element', 'reorder_children', 'wrap_element', 'batch_edit', 'replace_page_tree']],
  ['Widgets and schemas', ['list_widgets', 'get_widget_schema', 'list_element_types', 'list_dynamic_tags']],
  ['Global design', ['get_globals', 'set_global_color', 'update_globals', 'custom_css', 'get_global_classes', 'flush_css']],
  ['Templates', ['list_templates', 'get_template', 'save_template', 'apply_template', 'export_template', 'import_template']],
  ['History and undo', ['list_snapshots', 'create_snapshot', 'restore_snapshot', 'list_revisions', 'restore_revision']],
  ['Content and media', ['search', 'replace_text', 'widget_usage', 'list_media', 'upload_image']],
];

const transport = new StdioClientTransport({
  command: process.execPath,
  args: ['dist/index.js'],
  env: {
    ...process.env,
    WORDPRESS_URL: 'https://example.test',
    WORDPRESS_USERNAME: 'agent',
    WORDPRESS_APP_PASSWORD: 'x',
  },
});

const client = new Client({ name: 'docgen', version: '1.0.0' });
await client.connect(transport);

const { tools } = await client.listTools();
const byName = new Map(tools.map((t) => [t.name, t]));

const out = [];
out.push('# Tool reference');
out.push('');
out.push(`Generated from the server — ${tools.length} tools. Regenerate with:`);
out.push('');
out.push('```bash');
out.push('npm run build && node tests/gen-tool-docs.mjs > docs/TOOLS.md');
out.push('```');
out.push('');
out.push('Every tool accepts an optional `site` argument naming a configured site profile;');
out.push('omit it to use the default. Arguments marked **required** have no default.');
out.push('');

function describeType(schema) {
  if (!schema) return 'any';
  if (schema.enum) return schema.enum.map((v) => `\`${v}\``).join(' \\| ');
  if (schema.type === 'array') return `${describeType(schema.items)}[]`;
  return schema.type ?? 'any';
}

const seen = new Set();

for (const [group, suffixes] of GROUPS) {
  out.push(`## ${group}`);
  out.push('');

  for (const suffix of suffixes) {
    const name = `elementor_${suffix}`;
    const tool = byName.get(name);
    if (!tool) {
      process.stderr.write(`WARNING: ${name} not registered\n`);
      continue;
    }
    seen.add(name);

    const a = tool.annotations ?? {};
    const badges = [];
    if (a.readOnlyHint) badges.push('read-only');
    if (a.destructiveHint) badges.push('**destructive**');
    if (a.idempotentHint) badges.push('idempotent');

    out.push(`### \`${name}\``);
    out.push('');
    if (badges.length) out.push(`_${badges.join(' · ')}_`);
    out.push('');
    out.push(tool.description ?? '');
    out.push('');

    const props = tool.inputSchema?.properties ?? {};
    const required = new Set(tool.inputSchema?.required ?? []);
    const keys = Object.keys(props).filter((k) => k !== 'site');

    if (keys.length) {
      out.push('| Argument | Type | Notes |');
      out.push('| --- | --- | --- |');
      for (const key of keys) {
        const p = props[key];
        const notes = [];
        if (required.has(key)) notes.push('**required**');
        if (p.default !== undefined) notes.push(`default \`${JSON.stringify(p.default)}\``);
        if (p.description) notes.push(p.description.replace(/\|/g, '\\|'));
        out.push(`| \`${key}\` | ${describeType(p)} | ${notes.join('. ')} |`);
      }
      out.push('');
    }
  }
}

const missed = tools.filter((t) => !seen.has(t.name));
if (missed.length) {
  out.push('## Ungrouped');
  out.push('');
  for (const t of missed) {
    out.push(`### \`${t.name}\``);
    out.push('');
    out.push(t.description ?? '');
    out.push('');
  }
  process.stderr.write(`WARNING: ungrouped tools: ${missed.map((t) => t.name).join(', ')}\n`);
}

await client.close();
process.stdout.write(out.join('\n'));
