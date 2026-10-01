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
      if (options.method === 'POST' && url.endsWith('/admin/products')) {
        // La validation Sylius accede aussi a la traduction de repli en_US.
        if (!body.translations.en_US?.name || !body.translations.en_US?.slug) {
          return { ok: false, status: 422, text: async () => JSON.stringify({ detail: 'translations[en_US].name: Veuillez saisir le nom du produit. translations[en_US].slug: Veuillez entrer le slug du produit.' }) }
        }
      }
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
      const locales = [...new Set([locale, 'en_US'])]
      assert.deepEqual(Object.keys(product.translations), locales)
      assert.deepEqual(Object.keys(variant.translations), locales)
      assert.equal(product.translations[locale].name, 'Soin')
      assert.deepEqual(product.translations.en_US, product.translations[locale])
      assert.equal(product.translations.en_US.slug, 'soin')
      assert.equal(variant.translations.en_US.name, 'Soin')
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
