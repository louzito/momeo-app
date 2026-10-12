import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'

const siteConfig = new URL('../src/utils/siteConfig.js', import.meta.url).href
const helper = new URL('../src/api/sharedRead.js', import.meta.url).href
async function loadClient(file) {
  const source = (await readFile(new URL(`../src/api/${file}`, import.meta.url), 'utf8'))
    .replace(/^import .*$/gm, '')
  const stubs = `
    import { shareInFlight } from '${helper}';
    const API_BASE = '/api/v2', JWT_AUTH_HEADER = 'Authorization', TENANT_SLUG = 'test';
    const tenantHeaders = h => h, displayImageUrl = p => p || '';
    const normalizeShopColors = c => c;
    import { normalizeSiteConfig, readSiteConfigDocument, publishSiteConfigDocument } from '${siteConfig}';
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

test('imports logo et prestation : multipart, chemin conservé et erreur serveur lisible', async () => {
  const original = globalThis.fetch
  let rejectUpload = false
  const file = new Blob(['image'], { type: 'image/png' })
  globalThis.fetch = async (url, options = {}) => {
    if (options.method === 'POST' && String(url).endsWith('/images')) {
      assert.ok(options.body instanceof FormData)
      assert.ok(options.body.get('file') instanceof Blob)
      assert.ok(['main', 'draft_logo'].includes(options.body.get('type')))
      assert.equal(options.headers['Content-Type'], undefined)
      return { ok: !rejectUpload, status: rejectUpload ? 422 : 201, json: async () => rejectUpload
        ? { error: 'invalid_image', message: 'Seules les images JPEG, PNG et WebP sont acceptees.' }
        : { id: 42, path: 'test/ab/cd/image.png' } }
    }
    return { ok: true, status: 200, text: async () => JSON.stringify({ code: 'todatempo_config', images: [] }) }
  }
  try {
    const admin = await loadClient('adminApi.js')
    assert.equal((await admin.uploadShopImage(file)).path, 'test/ab/cd/image.png')
    assert.equal((await admin.replaceProductImage('service_test', file)).path, 'test/ab/cd/image.png')
    rejectUpload = true
    await assert.rejects(admin.uploadShopImage(file), /JPEG, PNG et WebP/)
    await assert.rejects(admin.replaceProductImage('service_test', file), /JPEG, PNG et WebP/)
  } finally { globalThis.fetch = original }
})

test('logo apparence : endpoint dédié, multipart et chemin de stockage conservé', async () => {
  const original = globalThis.fetch
  globalThis.fetch = async (url, options) => {
    assert.equal(url, '/api/v2/admin/site/appearance/logo')
    assert.equal(options.method, 'POST')
    assert.ok(options.body instanceof FormData)
    assert.ok(options.body.get('file') instanceof Blob)
    assert.equal(options.headers['Content-Type'], undefined)
    return { ok: true, status: 201, text: async () => JSON.stringify({ path: 'test/new-logo.png' }) }
  }
  try {
    const admin = await loadClient('adminApi.js')
    assert.deepEqual(await admin.uploadSiteLogo(new Blob(['image'], { type: 'image/png' })), { path: 'test/new-logo.png' })
  } finally { globalThis.fetch = original }
})


test('enregistrer les réglages applique immédiatement configuration et canal', async () => {
  const original = globalThis.fetch
  const previous = { schemaVersion: 1, revision: 3, draft: { name: 'Brouillon' }, published: { name: 'Ancien' } }
  const writes = []
  globalThis.fetch = async (url, options = {}) => {
    const path = new URL(String(url), 'http://localhost').pathname
    if (options.method === 'PUT') writes.push({ path, data: JSON.parse(options.body) })
    const body = path.endsWith('/channels') ? { member: [{ code: 'WEB' }] }
      : { code: 'todatempo_config', translations: { en_US: { description: JSON.stringify(previous) } } }
    return { ok: true, status: 200, text: async () => JSON.stringify(body) }
  }
  try {
    const admin = await loadClient('adminApi.js')
    const cfg = { name: 'Nouveau', contactEmail: 'contact@example.org', shopOrder: ['service_test'], emails: { booking_confirmation: { subject: 'Nouveau sujet' } }, assets: { logo: 'tenant/logo.png' }, _publication: { revision: 3 } }
    const result = await admin.saveShopConfig(cfg)
    assert.equal(writes.length, 2)
    assert.equal(writes[0].data.name, cfg.name)
    const document = JSON.parse(writes[1].data.translations.en_US.description)
    assert.equal(document.revision, 4)
    assert.deepEqual(document.draft, document.published)
    for (const key of ['name', 'shopOrder', 'emails', 'assets']) assert.deepEqual(document.published[key], cfg[key])
    assert.equal(document.published._publication, undefined)
    assert.equal(result.revision, 4)
  } finally { globalThis.fetch = original }
})
