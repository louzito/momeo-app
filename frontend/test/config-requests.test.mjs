import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'

const helper = new URL('../src/api/sharedRead.js', import.meta.url).href
async function loadClient(file) {
  const source = (await readFile(new URL(`../src/api/${file}`, import.meta.url), 'utf8'))
    .replace(/^import .*$/gm, '')
  const stubs = `
    import { shareInFlight } from '${helper}';
    const API_BASE = '/api/v2', JWT_AUTH_HEADER = 'Authorization', TENANT_SLUG = 'test';
    const tenantHeaders = h => h, displayImageUrl = p => p || '';
    const normalizeShopColors = c => c, normalizeSiteConfig = c => c;
    const readSiteConfigDocument = c => ({draft: c, published: c});
    const migrateLocalStorageKey = () => null;
    const isServiceProductCode = c => c.startsWith('service_');
    const sylius = {}, customerRequest = () => {};
  `
  return import(`data:text/javascript;base64,${Buffer.from(stubs + source).toString('base64')}`)
}

test('config sans logo : un GET admin et aucune lecture legacy ; produits partagés', async () => {
  const original = globalThis.fetch
  const paths = []
  globalThis.fetch = async (url) => {
    const path = new URL(String(url), 'http://localhost').pathname
    paths.push(path)
    assert.ok(!path.includes('skybook_config'))
    let body = {}
    if (path.endsWith('/channels')) body = { member: [{ code: 'WEB', baseCurrency: 'EUR' }] }
    if (path.endsWith('/todatempo_config')) body = { code: 'todatempo_config', translations: { en_US: { description: '{}' } }, description: '{}', images: [] }
    if (path.endsWith('/products')) body = { member: [] }
    return { ok: true, status: 200, text: async () => JSON.stringify(body) }
  }
  try {
    const admin = await loadClient('adminApi.js')
    await Promise.all([admin.getShopConfig(), admin.getShopConfig()])
    assert.equal(paths.filter(p => p.endsWith('/admin/taxons/todatempo_config')).length, 1)
    const { httpApi } = await loadClient('httpApi.js')
    await httpApi.getPublicShopConfig()
    await Promise.all([httpApi.getJumpTypes('test'), httpApi.getOptions('test')])
    assert.equal(paths.filter(p => p.endsWith('/shop/products')).length, 1)
    await httpApi.getJumpTypes('test')
    assert.equal(paths.filter(p => p.endsWith('/shop/products')).length, 2)
  } finally {
    globalThis.fetch = original
  }
})
