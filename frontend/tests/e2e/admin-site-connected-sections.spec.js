import { test, expect } from '@playwright/test'

for (const width of [390, 1280]) {
  test(`sections connectées : tarifs, retrait et cadeau à ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 })
    await page.addInitScript(() => localStorage.setItem('todatempo.admin.centre-e2e', JSON.stringify({ admin: { name: 'Équipe', permissions: ['settings'] }, tenant: { id: 'centre-e2e', name: 'Institut', currency: 'EUR' } })))
    let price = 5000, removed = false, gifts = true
    const value = { id: 'a'.repeat(32), revision: 1, archived: false, draft: { title: 'Offres', slug: 'offres', seo: { title: '', description: '' }, document: { schemaVersion: 1, blocks: [
      { id: 'catalog', type: 'catalog', props: { title: 'Nos offres', mode: 'selection', category: 'prestations', codes: ['service_massage', 'physical_oil'], limit: 3, buttonLabel: 'Voir la boutique' } },
      { id: 'gift', type: 'giftCard', props: { title: 'Un cadeau', text: 'Faites plaisir', image: null, buttonLabel: 'Offrir maintenant' } },
    ] } }, published: null }
    await page.route('**/api/v2/**', async route => {
      expect(route.request().headers()['x-skybook-tenant']).toBe('centre-e2e')
      const path = new URL(route.request().url()).pathname
      let body = { member: [] }
      if (path.endsWith('/admin/site/pages')) body = { member: [value] }
      else if (path.includes('/admin/site/pages/')) body = value
      else if (path.endsWith('/shop/channels')) body = { member: [{ code: 'WEB', baseCurrency: { code: 'EUR' } }] }
      else if (path.includes('/shop/taxons/')) body = { description: '{}' }
      else if (path.endsWith('/shop/products')) body = { member: removed ? [] : [{ code: 'service_massage', name: 'Massage', defaultVariantData: { price }, images: [] }] }
      else if (path.endsWith('/shop/physical-products')) body = { member: [{ code: 'physical_oil', name: 'Huile', pickupEnabled: true, defaultVariantData: { price: 1200, onHand: 0, onHold: 0 }, images: [] }] }
      else if (path.endsWith('/gift-cards/offer')) body = { enabled: gifts, paymentMethods: [{ code: 'bank_transfer' }] }
      await route.fulfill({ json: body })
    })
    await page.goto(`/centre-e2e/admin/site/pages/${value.id}/edit`)
    const preview = page.getByRole('region', { name: 'Aperçu de la page' })
    await expect(preview).toContainText('50,00')
    await expect(preview.getByRole('link', { name: 'Offrir maintenant' })).toHaveAttribute('href', '/centre-e2e/gift-card')
    await expect(preview.getByRole('button', { name: 'Indisponible' })).toBeDisabled()
    price = 6500
    await page.reload()
    await expect(preview).toContainText('65,00')
    removed = true; gifts = false
    await page.reload()
    await expect(preview.getByRole('heading', { name: 'Massage' })).toHaveCount(0)
    await expect(preview.getByRole('link', { name: 'Offrir maintenant' })).toHaveCount(0)
    await expect(preview).toContainText('vente de cartes cadeaux est désactivée')
    await expect(page.getByText('Une offre sélectionnée est indisponible.')).toBeVisible()
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
  })
}
