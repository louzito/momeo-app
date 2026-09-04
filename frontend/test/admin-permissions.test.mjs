import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

test('admin navigation and routes use server-provided permissions', () => {
  const layout = readFileSync(new URL('../src/views/admin/AdminLayout.vue', import.meta.url), 'utf8')
  const router = readFileSync(new URL('../src/router/index.js', import.meta.url), 'utf8')
  const store = readFileSync(new URL('../src/stores/admin.js', import.meta.url), 'utf8')

  for (const permission of ['agenda', 'clients', 'finances', 'catalog', 'settings']) {
    assert.match(layout, new RegExp(`permission: '${permission}'`))
  }
  assert.match(router, /!store\.can\(to\.meta\.permission\)/)
  assert.match(store, /admin\?\.permissions/)
})
