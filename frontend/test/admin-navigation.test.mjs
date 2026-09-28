import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { adminNavigation, adminItemLocation, isAdminItemActive, settingsSection } from '../src/utils/adminNavigation.js'

// Resolve the real application routes without a browser or loading their views.
const source = (await readFile(new URL('../src/router/index.js', import.meta.url), 'utf8'))
  .replace('createWebHistory', 'createMemoryHistory as createWebHistory')
  .replace("from 'vue-router'", `from '${import.meta.resolve('vue-router')}'`)
  .replace("import { TENANT_SLUG, TENANT_ERROR, APP_BASE } from '@/api/config'", "const TENANT_SLUG = 'centre-test', TENANT_ERROR = '', APP_BASE = '/'")
const { default: router } = await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`)
const all = adminNavigation(router, () => true)

test('tous les écrans BO existants sont couverts sans liens fictifs', () => {
  const items = all.flatMap((group) => group.items)
  const accessible = new Set(items.flatMap((item) => [item.name, ...(item.details || [])]))
  for (const route of router.getRoutes().filter((route) => route.name && route.meta.requiresAdmin)) {
    assert.ok(accessible.has(route.name), `Écran absent du menu : ${route.name}`)
  }
  for (const item of items) {
    const route = router.resolve(adminItemLocation(item))
    assert.equal(route.name, item.name)
    assert.ok(isAdminItemActive(item, route))
  }
  assert.ok(items.some((item) => item.name === 'admin-physical-products'))
  assert.ok(items.some((item) => item.name === 'admin-plannings'))
})

test('les permissions des routes filtrent les entrées et les groupes vides', () => {
  const cases = [
    [[], ['dashboard']],
    [['agenda'], ['dashboard', 'appointments', 'settings']],
    [['catalog'], ['dashboard', 'catalog', 'settings']],
    [['finances'], ['dashboard', 'sales', 'settings']],
    [['clients'], ['dashboard', 'clients']],
    [['settings'], ['dashboard', 'site', 'settings']],
  ]
  for (const [permissions, expected] of cases) {
    const groups = adminNavigation(router, (permission) => permissions.includes(permission))
    assert.deepEqual(groups.map((group) => group.id), expected)
    for (const item of groups.flatMap((group) => group.items)) {
      const permission = router.resolve(adminItemLocation(item)).meta.permission
      assert.ok(!permission || permissions.includes(permission))
    }
  }
  const agendaSettings = adminNavigation(router, (p) => p === 'agenda').find((group) => group.id === 'settings')
  assert.deepEqual(agendaSettings.items.map((item) => item.name), ['admin-plannings'])
})

test('une seule sélection sur les détails, les rubriques et les URLs historiques', () => {
  for (const [url, groupId, label] of [
    ['/admin/products/new', 'catalog', 'Prestations'],
    ['/admin/products/123', 'catalog', 'Prestations'],
    ['/admin/options/new', 'catalog', 'Options et suppléments'],
    ['/admin/options/123', 'catalog', 'Options et suppléments'],
    ['/admin/orders/123/invoice', 'sales', 'Commandes et factures'],
    ['/admin/site/pages', 'site', 'Pages'],
    ['/admin/site/menus', 'site', 'Menus'],
    ['/admin/site/images', 'site', 'Images'],
    ['/admin/settings', 'settings', 'Établissement'],
    ['/admin/settings?section=inconnue', 'settings', 'Établissement'],
    ['/admin/settings?section=appearance', 'site', 'Apparence'],
    ['/admin/settings?section=home', 'site', 'Page d’accueil'],
    ['/admin/settings?section=emails', 'settings', 'Emails'],
  ]) {
    const route = router.resolve(url)
    const active = all.flatMap((group) => group.items.filter((item) => isAdminItemActive(item, route)).map((item) => [group.id, item.label]))
    assert.deepEqual(active, [[groupId, label]], url)
  }
  assert.equal(settingsSection({ section: ['home', 'terms'] }), 'general')
  assert.equal(settingsSection({ section: '__proto__' }), 'general')
})
