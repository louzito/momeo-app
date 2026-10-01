import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const source = readFileSync(new URL('../src/api/adminApi.js', import.meta.url), 'utf8')
  .replace(/^import .*$/gm, '')
const stubs = `const API_BASE = '/api/v2', TENANT_SLUG = 'institut', JWT_AUTH_HEADER = 'Authorization';
const tenantHeaders = (headers) => headers;
const migrateLocalStorageKey = () => null;
`

for (const locale of ['fr_FR', 'en_US']) {
  test(`création et modification utilisent la langue du canal ${locale}`, async () => {
    const api = await import(`data:text/javascript;base64,${Buffer.from(stubs + source + `\n// ${locale}`).toString('base64')}`)
    const calls = []
    const originalFetch = globalThis.fetch
    globalThis.fetch = async (url, options) => {
      const body = options.body ? JSON.parse(options.body) : null
      calls.push({ url, method: options.method, body })
      const result = url.endsWith('/shop/channels')
        ? { member: [{ code: 'FASHION_WEB', defaultLocale: locale === 'fr_FR' ? { code: locale } : `/api/v2/shop/locales/${locale}` }] }
        : url.endsWith('/products/service_soin') && options.method === 'GET'
          ? { translations: { en_US: { name: 'Soin' } } }
          : {}
      return { ok: true, status: 200, text: async () => JSON.stringify(result) }
    }
    try {
      await api.createJump({ name: 'Soin', basePrice: 65 })
      const product = calls.find(c => c.url.endsWith('/products') && c.method === 'POST').body
      const variant = calls.find(c => c.url.endsWith('/product-variants') && c.method === 'POST').body
      assert.deepEqual(Object.keys(product.translations), [locale])
      assert.deepEqual(Object.keys(variant.translations), [locale])
      assert.equal(product.translations[locale].name, 'Soin')
      assert.equal(variant.channelPricings.FASHION_WEB.price, 6500)
      calls.length = 0
      await api.updateJump('service_soin', { name: 'Soin douceur' })
      const patch = calls.find(c => c.body?.translations).body.translations[locale]
      assert.equal(patch.name, 'Soin douceur')
      if (locale === 'fr_FR') assert.equal(patch['@id'], undefined, 'créer la traduction absente du produit historique')
      else assert.equal(patch['@id'], '/api/v2/admin/products/service_soin/translations/en_US')
    } finally {
      globalThis.fetch = originalFetch
    }
  })
}
