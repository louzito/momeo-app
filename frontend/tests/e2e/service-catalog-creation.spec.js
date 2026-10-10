import { test, expect } from '@playwright/test'

for (const locale of ['fr_FR', 'en_US']) {
  test(`une prestation créée est visible dans le catalogue et la boutique (${locale})`, async ({ page }) => {
    await page.addInitScript(() => {
      localStorage.setItem('todatempo.admin.centre-e2e', JSON.stringify({ admin: { permissions: ['catalog'] }, tenant: { id: 'centre-e2e', currency: 'EUR' } }))
      localStorage.setItem('todatempo.sylius.jwt.centre-e2e', 'test-token')
    })
    let product, variant
    await page.route('**/api/v2/**', async route => {
      const request = route.request()
      const path = new URL(request.url()).pathname
      const method = request.method()
      let body = { member: [] }
      let status = 200
      if (path.endsWith('/admin/team/session')) body = { permissions: ['catalog'] }
      else if (path.endsWith('/admin/channels/INSTITUT_WEB')) body = { code: 'INSTITUT_WEB', defaultLocale: `/api/v2/admin/locales/${locale}` }
      else if (path.endsWith('/shop/channels')) body = { member: [{ code: 'INSTITUT_WEB', name: 'Institut', baseCurrency: { code: 'EUR' }, defaultLocale: `/api/v2/shop/locales/${locale}` }] }
      else if (path.includes('/shop/taxons/')) body = { description: '{}' }
      else if (path.endsWith('/admin/products') && method === 'POST') {
        product = request.postDataJSON()
        expect(product.translations[locale].name).toBe('Soin visage')
        expect(product.translations.en_US.name).toBe('Soin visage')
        expect(product.translations.en_US.slug).toBe('soin-visage')
        status = 201; body = product
      } else if (path.endsWith('/admin/product-variants') && method === 'POST') {
        variant = request.postDataJSON()
        expect(variant.translations[locale].name).toBe('Soin visage')
        expect(variant.translations.en_US.name).toBe('Soin visage')
        status = 201; body = variant
      } else if (path.endsWith('/admin/products/service_soin_visage') && method === 'GET') {
        body = { ...product, translations: { [locale]: { ...product.translations[locale], '@id': `/api/v2/admin/products/service_soin_visage/translations/${locale}` } } }
      } else if (path.endsWith('/admin/products/service_soin_visage') && method === 'PUT') {
        const patch = request.postDataJSON()
        if (patch.translations) {
          expect(patch.translations[locale]['@id']).toContain(`/translations/${locale}`)
          product.translations[locale] = patch.translations[locale]
        }
        body = product
      } else if (path.endsWith('/shop/products')) {
        // Reproduire le filtre de langue de la boutique.
        body = { member: product?.translations[locale] && variant?.translations[locale] ? [{ ...product, ...product.translations[locale], defaultVariantData: { price: 6500 } }] : [] }
      } else if (path.endsWith('/shop/products/service_soin_visage')) {
        body = { ...product, ...product.translations[locale], defaultVariantData: { price: 6500 } }
      } else if (path.endsWith('/admin/product-variants/service_soin_visage-variant')) body = { taxCategory: null }
      await route.fulfill({ status, json: body })
    })
    await page.goto('/centre-e2e/admin/products/new')
    await page.getByPlaceholder('Soin visage éclat').fill('Soin visage')
    await page.getByRole('button', { name: 'Enregistrer', exact: true }).click()
    await expect(page).toHaveURL(/\/admin\/products$/)
    await expect(page.getByRole('heading', { name: 'Soin visage', exact: true })).toBeVisible()
    await page.getByRole('link', { name: 'Modifier', exact: true }).click()
    await page.getByPlaceholder('Soin visage éclat').fill('Soin visage douceur')
    await page.getByRole('button', { name: 'Enregistrer', exact: true }).click()
    await expect(page.getByRole('heading', { name: 'Soin visage douceur', exact: true })).toBeVisible()
    await page.goto('/centre-e2e/shop')
    await expect(page.getByRole('heading', { name: 'Soin visage douceur', exact: true })).toBeVisible()
  })
}


test('le formulaire option affiche une erreur 422 et permet de réessayer', async ({ page }) => {
  await page.addInitScript(() => {
    localStorage.setItem('todatempo.admin.centre-e2e', JSON.stringify({ admin: { permissions: ['catalog'] }, tenant: { id: 'centre-e2e', currency: 'EUR' } }))
    localStorage.setItem('todatempo.sylius.jwt.centre-e2e', 'test-token')
  })
  await page.route('**/api/v2/**', async route => {
    const path = new URL(route.request().url()).pathname
    let body = { member: [] }
    let status = 200
    if (path.endsWith('/admin/team/session')) body = { permissions: ['catalog'] }
    else if (path.endsWith('/shop/channels')) body = { member: [{ code: 'INSTITUT_WEB', baseCurrency: { code: 'EUR' }, defaultLocale: { code: 'fr_FR' } }] }
    else if (path.includes('/shop/taxons/')) body = { description: '{}' }
    else if (path.endsWith('/admin/products') && route.request().method() === 'POST') {
      status = 422
      body = { detail: 'translations[en_US].name : Veuillez saisir le nom du produit.' }
    }
    await route.fulfill({ status, json: body })
  })
  await page.goto('/centre-e2e/admin/options/new')
  await page.getByPlaceholder('Massage du cuir chevelu').fill('Massage')
  await page.getByRole('button', { name: 'Enregistrer', exact: true }).click()
  await expect(page.getByRole('alert')).toContainText('Veuillez saisir le nom du produit.')
  await expect(page.getByRole('button', { name: 'Enregistrer', exact: true })).toBeEnabled()
  await expect(page).toHaveURL(/\/admin\/options\/new$/)
})
