import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

import viteConfig from '../vite.config.js'

const root = fileURLToPath(new URL('../..', import.meta.url))

test('normal Vite and Caddy configurations expose no command endpoint', async () => {
  const pluginNames = viteConfig.plugins.map((plugin) => plugin.name)
  assert.deepEqual(pluginNames, ['vite:vue', 'skybook-tenant-rewrite'])

  const configuredFiles = [
    'frontend/vite.config.js',
    'backend/src/Tenant/CaddyConfigDumper.php',
    'backend/caddy/Caddyfile',
  ]

  for (const relativePath of configuredFiles) {
    const contents = await readFile(`${root}/${relativePath}`, 'utf8')
    assert.doesNotMatch(contents, /__skybook_ops|skybook-ops/)
  }
})
