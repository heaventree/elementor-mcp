import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StdioClientTransport } from '@modelcontextprotocol/sdk/client/stdio.js';

const transport = new StdioClientTransport({
  command: process.execPath, args: ['dist/index.js'],
  env: { ...process.env, WORDPRESS_URL: 'http://127.0.0.1:18901',
         WORDPRESS_USERNAME: 'u', WORDPRESS_APP_PASSWORD: 'p' },
});
const client = new Client({ name: 'guard', version: '1.0.0' });
await client.connect(transport);

console.log('=== add_container with elType "container" (site has none) ===');
const bad = await client.callTool({
  name: 'elementor_add_container',
  arguments: { postId: 55, elType: 'container' },
});
console.log('  isError:', bad.isError === true);
console.log(bad.content[0].text.split('\n').map(l => '  ' + l).join('\n'));

console.log('\n=== add_container with elType "section" (site has it) ===');
const good = await client.callTool({
  name: 'elementor_add_container',
  arguments: { postId: 55, elType: 'section' },
});
console.log('  isError:', good.isError === true);
console.log('  ' + good.content[0].text.split('\n')[0]);

await client.close();
