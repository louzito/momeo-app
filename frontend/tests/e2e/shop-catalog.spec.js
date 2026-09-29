import { test, expect } from '@playwright/test'

const json = (route, body, status = 200) => route.fulfill({ status, contentType: 'application/ld+json', body: JSON.stringify(body) })

async function catalog(page, settings = {}) {
  const requests = []
  await page.route('**/api/v2/**', (route) => {
    const request = route.request()
    const path = new URL(request.url()).pathname
    requests.push(request)
    if (path.endsWith('/shop/channels')) return json(route, { member: [{ code: 'WEB', name: 'Institut test', baseCurrency: { code: 'EUR' } }] })
    if (path.includes('/shop/taxons/')) return json(route, { description: JSON.stringify({
      name: 'Institut test', giftVouchersEnabled: settings.gifts !== false,
      shopOrder: ['service_second', 'service_first'], home: { featured: ['service_second'] },
    }) })
    if (path.endsWith('/shop/products')) return settings.failServices
      ? json(route, {}, 503)
      : json(route, { member: settings.empty ? [] : [
        { code: 'service_first', name: 'Massage', defaultVariantData: { price: 6500 } },
        { code: 'service_second', name: 'Soin visage', defaultVariantData: { price: 4500 } },
      ] })
    if (path.endsWith('/attributes')) return json(route, { member: [] })
    if (path.endsWith('/shop/physical-products')) return settings.failProducts
      ? json(route, {}, 503)
      : json(route, { member: settings.empty ? [] : [
        { code: 'physical_oil', name: 'Huile', pickupEnabled: false, deliveryEnabled: true, deliveryFee: 490, defaultVariantData: { price: 1250, onHand: 4, onHold: 1 } },
        { code: 'physical_soap', name: 'Savon', pickupEnabled: true, defaultVariantData: { price: 500, onHand: 1, onHold: 1 } },
        { code: 'physical_closed', name: 'Coffret', defaultVariantData: { price: 2000, onHand: 3 } },
      ] })
    if (path.endsWith('/shop/gift-cards/offer')) return settings.failGifts
      ? json(route, {}, 503)
      : json(route, { enabled: settings.offerEnabled !== false, shopName: 'Institut test', currency: 'EUR', minimum: 1000, maximum: 100000, validityMonths: 12, presets: [5000], paymentMethods: [{ code: 'bank_transfer', label: 'Virement' }] })
    return json(route, { member: [] })
  })
  return requests
}
const categories = (page) => page.getByRole('navigation', { name: 'Catégories de la boutique' })

for (const width of [360, 1280]) {
  test(`catalogue unifié et parcours à ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 844 })
    const requests = await catalog(page)
    await page.goto('/centre-e2e/shop?source=accueil')
    await expect(categories(page).getByRole('link')).toHaveCount(3)
    await expect(page.locator('#services-title + div h3')).toHaveText(['Soin visage', 'Massage'])
    await expect(page.getByText('À la une', { exact: true })).toBeVisible()
    await expect(page.getByText(/45,00/)).toBeVisible()
    await page.getByRole('link', { name: /Soin visage.*Découvrir/ }).click()
    await expect(page).toHaveURL(/services\/service_second/)
    await page.goBack()
    await categories(page).getByRole('link', { name: 'Produits', exact: true }).click()
    await expect(page).toHaveURL(/source=accueil&categorie=produits/)
    const oil = page.getByRole('article').filter({ has: page.getByRole('heading', { name: 'Huile', exact: true }) })
    await expect(oil).toContainText('12,50')
    await expect(oil).toContainText('3 en stock')
    await expect(oil).toContainText('4,90')
    await expect(page.getByText('Rupture de stock')).toBeVisible()
    await expect(page.getByRole('button', { name: 'Indisponible', exact: true })).toHaveCount(2)
    await page.reload()
    await expect(categories(page).getByRole('link', { name: 'Produits', exact: true })).toHaveAttribute('aria-current', 'page')
    await page.getByRole('link', { name: 'Choisir Huile' }).click()
    await expect(page).toHaveURL(/products\?produit=physical_oil/)
    await expect(page.getByRole('heading', { name: 'Huile', exact: true })).toBeVisible()
    await expect(page.getByRole('button', { name: 'Ajouter au panier' }).first()).toBeEnabled()
    await page.goBack()
    await categories(page).getByRole('link', { name: 'Cartes cadeaux' }).click()
    await expect(page.getByRole('article')).toContainText('10,00')
    await expect(page.getByRole('article')).toContainText('12 mois')
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
    await page.getByRole('link', { name: 'Choisir le montant' }).click()
    await expect(page).toHaveURL(/gift-card$/)
    await expect(page.getByRole('heading', { name: 'Offrir une carte cadeau' })).toBeVisible()
    expect(requests.every((request) => request.headers()['x-skybook-tenant'] === 'centre-e2e')).toBe(true)
    expect(requests.every((request) => request.method() === 'GET')).toBe(true)
  })
}

test('cadeaux désactivés et boutique vide', async ({ page }) => {
  const requests = await catalog(page, { empty: true, gifts: false })
  await page.goto('/centre-e2e/shop?categorie=cartes-cadeaux')
  await expect(page.getByText('La boutique est actuellement vide')).toBeVisible()
  await expect(categories(page).getByRole('link')).toHaveCount(2)
  await expect(page.getByRole('link', { name: /carte cadeau/i })).toHaveCount(0)
  expect(requests.some((request) => request.url().includes('/gift-cards/offer'))).toBe(false)
})

test('désactivation reçue de l’offre cadeau masque sa vente', async ({ page }) => {
  await catalog(page, { offerEnabled: false })
  await page.goto('/centre-e2e/shop?categorie=cartes-cadeaux')
  await expect(categories(page).getByRole('link')).toHaveCount(2)
  await expect(page.getByRole('heading', { name: 'Prestations', exact: true })).toBeVisible()
  await expect(page.getByRole('link', { name: 'Choisir le montant' })).toHaveCount(0)
})

test('erreur produits récupérable sans bloquer les autres catégories', async ({ page }) => {
  const settings = { failProducts: true }
  await catalog(page, settings)
  await page.goto('/centre-e2e/shop?categorie=produits')
  await expect(page.getByRole('alert')).toContainText('Impossible de charger les produits')
  settings.failProducts = false
  await page.getByRole('button', { name: 'Réessayer' }).click()
  await expect(page.getByRole('heading', { name: 'Huile', exact: true })).toBeVisible()
  await categories(page).getByRole('link', { name: 'Prestations', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Massage', exact: true })).toBeVisible()
})

test('erreur cadeau récupérable et état vide par catégorie', async ({ page }) => {
  const settings = { failGifts: true, empty: true }
  await catalog(page, settings)
  await page.goto('/centre-e2e/shop')
  await expect(page.getByText('Aucune prestation disponible')).toBeVisible()
  await categories(page).getByRole('link', { name: 'Produits', exact: true }).click()
  await expect(page.getByText('Aucun produit disponible')).toBeVisible()
  await categories(page).getByRole('link', { name: 'Cartes cadeaux' }).click()
  await expect(page.getByRole('alert')).toContainText('Impossible de charger les cartes cadeaux')
  settings.failGifts = false
  await page.getByRole('button', { name: 'Réessayer' }).click()
  await expect(page.getByRole('link', { name: 'Choisir le montant' })).toBeVisible()
})

test('erreur du catalogue expliquée et nouvelle tentative', async ({ page }) => {
  const settings = { failServices: true }
  await catalog(page, settings)
  await page.goto('/centre-e2e/shop')
  await expect(page.getByRole('alert')).toContainText('Catalogue temporairement indisponible')
  settings.failServices = false
  await page.getByRole('button', { name: 'Réessayer' }).click()
  await expect(page.getByRole('heading', { name: 'Massage', exact: true })).toBeVisible()
})
