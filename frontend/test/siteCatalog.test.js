import { test } from 'node:test'
import assert from 'node:assert/strict'
import { fetchSiteCatalog, selectSiteOffers } from '../src/api/siteCatalog.js'

test('published references follow price, name and availability changes without rewriting the page', async () => {
  let services = [{ id: 'massage', name: 'Massage', price: 50 }]
  const api = {
    getShopChannel: async () => ({ code: 'centre', currency: 'EUR' }),
    getPublicShopConfig: async () => ({}), getOptions: async () => [],
    getJumpTypes: async tenant => { assert.equal(tenant, 'workspace_centre'); return services },
    getPhysicalProducts: async tenant => { assert.equal(tenant, 'workspace_centre'); return [] },
  }
  const props = { mode: 'selection', codes: ['foreign', 'massage'], limit: 3 }
  const document = JSON.stringify(props)
  let offers = selectSiteOffers(props, await fetchSiteCatalog(api, 'centre'))
  assert.equal(offers.length, 1)
  assert.equal(offers[0].value.price, 50)
  services = [{ id: 'massage', name: 'Nouveau nom', price: 75 }]
  offers = selectSiteOffers(props, await fetchSiteCatalog(api, 'centre'))
  assert.equal(offers[0].value.price, 75)
  assert.equal(offers[0].value.name, 'Nouveau nom')
  services = []
  assert.deepEqual(selectSiteOffers(props, await fetchSiteCatalog(api, 'centre')), [])
  assert.equal(JSON.stringify(props), document)
})
test('category and selection preserve bounds and selected order', () => {
  const catalog = { jumpTypes: [{ id: 's' }], products: [{ id: 'a' }, { id: 'b' }] }
  assert.deepEqual(selectSiteOffers({ mode: 'category', category: 'produits', limit: 1 }, catalog), [{ kind: 'physical', value: { id: 'a' } }])
  assert.deepEqual(selectSiteOffers({ mode: 'selection', codes: ['b', 'removed', 's'], limit: 2 }, catalog).map(o => o.value.id), ['b', 's'])
})
