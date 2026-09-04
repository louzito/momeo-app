import test from 'node:test'
import assert from 'node:assert/strict'
import { fetchPublicCatalog } from '../src/api/publicCatalog.js'

function apiWith(overrides = {}) {
  return {
    getShopChannel: async () => ({ code: 'WEB', name: 'Institut réel', currency: 'EUR' }),
    getPublicShopConfig: async () => null,
    getJumpTypes: async () => [],
    getOptions: async () => [],
    ...overrides,
  }
}

test('retourne un catalogue vide sans fabriquer de prestations', async () => {
  const result = await fetchPublicCatalog(apiWith(), 'institut')
  assert.equal(result.tenant.name, 'Institut réel')
  assert.deepEqual(result.jumpTypes, [])
  assert.deepEqual(result.options, [])
})

test('propage une panne du catalogue au lieu de retourner des fixtures', async () => {
  const failure = new Error('API indisponible')
  const api = apiWith({ getJumpTypes: async () => { throw failure } })
  await assert.rejects(fetchPublicCatalog(api, 'institut'), failure)
})

test('propage une panne de configuration de la vitrine', async () => {
  const failure = new Error('Configuration indisponible')
  const api = apiWith({ getPublicShopConfig: async () => { throw failure } })
  await assert.rejects(fetchPublicCatalog(api, 'institut'), failure)
})

test('refuse un tenant sans canal de vente réel', async () => {
  const api = apiWith({ getShopChannel: async () => null })
  await assert.rejects(fetchPublicCatalog(api, 'inconnu'), /canal de vente/i)
})
