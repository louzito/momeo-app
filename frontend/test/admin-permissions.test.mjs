import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

test('admin navigation and routes use server-provided permissions', () => {
  const layout = readFileSync(new URL('../src/views/admin/AdminLayout.vue', import.meta.url), 'utf8')
  const router = readFileSync(new URL('../src/router/index.js', import.meta.url), 'utf8')
  const store = readFileSync(new URL('../src/stores/admin.js', import.meta.url), 'utf8')

  const navigation = readFileSync(new URL('../src/utils/adminNavigation.js', import.meta.url), 'utf8')
  assert.match(layout, /adminNavigation\(router, admin.can\)/)
  assert.match(navigation, /router.resolve\(.*\).meta.permission/)
  assert.match(navigation, /can\(permission\)/)

  for (const permission of ['agenda', 'clients', 'finances', 'catalog', 'settings']) {
    assert.match(router, new RegExp(`permission: '${permission}'`))
  }
  assert.match(router, /!store\.can\(to\.meta\.permission\)/)
  assert.match(store, /admin\?\.permissions/)
})
