import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'

const source = await readFile(new URL('../src/views/admin/AdminSettings.vue', import.meta.url), 'utf8')
const loadingFunctions = source.slice(source.indexOf('async function loadTimezones()'), source.indexOf('function beforeUnload('))

function settings(api) {
  return new Function('api', `
    const cfg = { value: null }, timezones = { value: [] }, timezoneError = { value: '' };
    const loading = { value: true }, loadingTimezones = { value: false }, error = { value: '' };
    const lastSaved = { value: '' }, jumps = { value: [] }, admin = { tenantId: 'demo' };
    ${loadingFunctions}
    return { load, loadTimezones, cfg, timezones, timezoneError, loading, loadingTimezones, error, lastSaved, jumps };
  `)(api)
}

test('une 404 sur les fuseaux ne bloque pas les réglages de l’établissement', async () => {
  const config = { name: 'Institut', timezone: 'Europe/Paris' }
  let unavailable = true
  const page = settings({
    getShopConfig: async () => config,
    getTimezones: async () => {
      if (unavailable) throw Object.assign(new Error('404 Not Found'), { status: 404 })
      return ['Europe/Paris', 'UTC']
    },
    getJumpTypes: async () => [{ code: 'service_test' }],
  })
  await page.load()
  assert.equal(page.cfg.value, config)
  assert.equal(page.error.value, '')
  assert.equal(page.loading.value, false)
  assert.equal(page.loadingTimezones.value, false)
  assert.equal(page.lastSaved.value, JSON.stringify(config))
  assert.deepEqual(page.jumps.value, [{ code: 'service_test' }])
  assert.deepEqual(page.timezones.value, ['Europe/Paris'])
  assert.ok(page.timezoneError.value)

  config.name = 'Nouveau nom'
  unavailable = false
  await page.loadTimezones()
  assert.equal(page.cfg.value.name, 'Nouveau nom')
  assert.deepEqual(page.timezones.value, ['Europe/Paris', 'UTC'])
  assert.equal(page.timezoneError.value, '')
})

test('le chargement normal propose les fuseaux du serveur', async () => {
  const page = settings({
    getShopConfig: async () => ({ timezone: 'UTC' }),
    getTimezones: async () => ['Europe/Paris', 'UTC'],
    getJumpTypes: async () => [],
  })
  await page.load()
  assert.deepEqual(page.timezones.value, ['Europe/Paris', 'UTC'])
  assert.equal(page.timezoneError.value, '')
  assert.equal(page.error.value, '')
})

test('une erreur sur la configuration reste visible et ne déclenche pas les lectures secondaires', async () => {
  const page = settings({
    getShopConfig: async () => { throw new Error('Configuration indisponible') },
    getTimezones: async () => assert.fail('Lecture des fuseaux inattendue'),
    getJumpTypes: async () => assert.fail('Lecture du catalogue inattendue'),
  })
  await page.load()
  assert.equal(page.cfg.value, null)
  assert.equal(page.error.value, 'Configuration indisponible')
  assert.equal(page.loading.value, false)
})
