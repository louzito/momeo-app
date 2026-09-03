import test from 'node:test'
import assert from 'node:assert/strict'

const config = await import('../src/api/config.js')

test('resolveTenantSlug extrait et normalise le tenant canonique', () => {
  assert.equal(config.resolveTenantSlug('/centre-paris/admin'), 'centre-paris')
  assert.equal(config.resolveTenantSlug('/Centre-Paris/shop'), 'centre-paris')
})

test('resolveTenantSlug refuse un slug absent ou invalide sans fallback', () => {
  assert.equal(config.resolveTenantSlug('/'), null)
  assert.equal(config.resolveTenantSlug('/centre_invalide/shop'), null)
  assert.equal(config.resolveTenantSlug('/-centre/shop'), null)
})

test('le client cible directement API Platform', () => {
  assert.equal(config.API_BASE, '/api/v2')
})

test('les en-têtes API transmettent le tenant sans écraser les autres valeurs', () => {
  assert.deepEqual(config.buildTenantHeaders('centre-paris', { Accept: 'application/json' }), {
    Accept: 'application/json',
    'X-Skybook-Tenant': 'centre-paris',
  })
  assert.notDeepEqual(
    config.buildTenantHeaders('centre-paris'),
    config.buildTenantHeaders('centre-lyon'),
  )
  assert.throws(() => config.buildTenantHeaders('../autre-centre'), /invalide/)
})
