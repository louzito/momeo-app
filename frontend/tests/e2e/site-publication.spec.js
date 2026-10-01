import { test, expect } from '@playwright/test'

const id = 'a'.repeat(32)
const draft = { title: 'Notre institut', slug: 'bienvenue', seo: { title: '', description: '' }, document: { schemaVersion: 1, blocks: [
  { id: 'banner', type: 'banner', props: { mediaId: null, alt: '', title: 'Bienvenue chez nous', text: 'Un accueil personnalisé', button: null } },
  { id: 'catalog', type: 'catalog', props: { title: 'Nos soins', mode: 'category', category: 'prestations', codes: [], limit: 9, buttonLabel: 'Toutes les prestations' } },
  { id: 'gift', type: 'giftCard', props: { title: 'Faites plaisir', text: 'Une carte cadeau', image: null, buttonLabel: 'Offrir maintenant' } },
] } }
async function setup(page, admin = false) {
  if (admin) await page.addInitScript(() => {
    localStorage.setItem('todatempo.admin.centre-e2e', JSON.stringify({ admin: { name: 'Équipe', permissions: ['settings'] }, tenant: { id: 'centre-e2e', name: 'Institut', currency: 'EUR' } }))
    localStorage.setItem('todatempo.sylius.jwt.centre-e2e', 'test-token')
  })
  const value = { id, role: 'home', revision: 1, archived: false, draft: structuredClone(draft), published: null }
  let fail = true
  await page.route('**/api/v2/**', async route => {
    expect(route.request().headers()['x-skybook-tenant']).toBe('centre-e2e')
    const path = new URL(route.request().url()).pathname
    let body = { member: [] }
    if (path.endsWith('/admin/site/pages')) body = { member: [value] }
    else if (path.endsWith('/preview')) {
      expect(route.request().headers().authorization).toBe('Bearer test-token')
      body = { ...value.draft, media: {}, links: {}, role: 'home', navigation: {} }
    } else if (path.includes('/admin/site/menus/')) body = { location: path.split('/').at(-1), revision: 1, items: [], published: null }
    else if (path.endsWith('/admin/site/publish')) {
      if (fail) return route.fulfill({ status: 409, json: { error: 'Cette page a changé. Rechargez la liste.' } })
      expect(route.request().postDataJSON()).toEqual({ pages: [{ id, revision: 1 }], menus: [{ id: 'main', revision: 1 }] })
      value.published = structuredClone(value.draft); value.revision++; body = { ok: true }
    } else if (path.includes('/shop/site/roles/')) {
      if (!value.published && admin) return route.fulfill({ status: 404, json: {} })
      body = { ...(value.published || value.draft), media: {}, links: {}, role: 'home' }
    } else if (path.includes('/shop/site/pages/')) return route.fulfill({ status: 404, json: {} })
    else if (path.endsWith('/shop/site/navigation')) body = { main: null, footer: null, primary: null }
    else if (path.endsWith('/shop/channels')) body = { member: [{ code: 'WEB', name: 'Institut', baseCurrency: { code: 'EUR' } }] }
    else if (path.includes('/shop/taxons/')) return route.fulfill({ status: 404, json: {} })
    else if (path.endsWith('/shop/products')) body = { member: [{ code: 'service_test', name: 'Soin détente', description: 'Un soin', defaultVariantData: { price: 6500 } }] }
    else if (path.endsWith('/shop/gift-cards/offer')) body = { enabled: true, currency: 'EUR', minimum: 1000, maximum: 100000, presets: [5000], shopName: 'Institut', validityMonths: 12, paymentMethods: [{ code: 'bank_transfer', label: 'Virement' }] }
    await route.fulfill({ json: body })
  })
  return { value, allowPublish: () => { fail = false } }
}
for (const width of [390, 1280]) {
  test(`accueil publié → prestation → réservation et carte cadeau à ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 })
    await setup(page)
    await page.goto('/centre-e2e/')
    await expect(page.getByRole('heading', { name: 'Bienvenue chez nous' })).toBeVisible()
    await page.getByRole('link', { name: /Soin détente/ }).click()
    await expect(page).toHaveURL(/\/services\/service_test$/)
    await page.getByRole('button', { name: 'Réserver cette prestation' }).click()
    await expect(page).toHaveURL(/\/checkout\/schedule$/)
    await page.goto('/centre-e2e/')
    await page.getByRole('link', { name: 'Offrir maintenant' }).click()
    await expect(page).toHaveURL(/\/gift-card$/)
    await page.getByLabel('Nom du destinataire').fill('Marie')
    await page.getByRole('button', { name: 'Ajouter au panier' }).click()
    await expect(page).toHaveURL(/\/cart$/)
    await expect(page.getByText('Marie', { exact: false }).first()).toBeVisible()
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
  })
}
test('aperçu authentifié, conflit conservé puis publication des pages et menus', async ({ page }) => {
  const state = await setup(page, true)
  await page.goto('/centre-e2e/admin/site/pages')
  await page.getByLabel('Inclure dans la publication').check()
  await page.getByLabel('Menu principal', { exact: true }).check()
  await page.getByRole('link', { name: 'Aperçu privé' }).click()
  await expect(page.getByRole('heading', { name: 'Bienvenue chez nous' })).toBeVisible()
  await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/)
  await page.getByRole('link', { name: 'Retour aux pages' }).click()
  await page.getByLabel('Inclure dans la publication').check()
  await page.getByLabel('Menu principal', { exact: true }).check()
  await page.getByRole('button', { name: 'Publier la sélection' }).click()
  await expect(page.getByRole('alert')).toContainText('a changé')
  await expect(page.getByLabel('Inclure dans la publication')).toBeChecked()
  state.allowPublish()
  await page.getByRole('button', { name: 'Publier la sélection' }).click()
  await expect(page.getByRole('status')).toContainText('maintenant publiée')
  await page.goto('/centre-e2e/')
  await expect(page.getByRole('heading', { name: 'Bienvenue chez nous' })).toBeVisible()
})

for (const width of [390, 1280]) {
  test(`menus publiés avec accueil historique et sous-menus à ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 })
    await setup(page)
    await page.route('**/shop/site/roles/*', route => route.fulfill({ status: 404, json: {} }))
    await page.route('**/shop/site/navigation', route => route.fulfill({ json: {
      main: [{ label: 'Nos prestations', url: 'shop?categorie=prestations', external: false, children: [
        { label: 'Notre boutique', url: 'shop?categorie=produits', external: false, children: [] },
        { label: 'Offrir une carte', url: 'gift-card', external: false, children: [] },
      ] }],
      footer: [{ label: 'Lien du pied de page', url: 'shop', external: false, children: [] }],
      primary: { url: 'shop?categorie=prestations', external: false },
    } }))
    await page.goto('/centre-e2e/shop')
    if (width < 768) await page.getByRole('button', { name: 'Menu', exact: true }).click()
    const nav = page.getByRole('navigation', { name: width < 768 ? 'Menu principal mobile' : 'Menu principal', exact: true })
    await expect(nav.getByRole('link', { name: 'Nos prestations', exact: true })).toHaveAttribute('href', '/centre-e2e/shop?categorie=prestations')
    await expect(nav.getByRole('link', { name: 'Notre boutique', exact: true })).toHaveAttribute('href', '/centre-e2e/shop?categorie=produits')
    await expect(nav.getByRole('link', { name: 'Offrir une carte', exact: true })).toHaveAttribute('href', '/centre-e2e/gift-card')
    await expect(page.getByRole('navigation', { name: 'Pied de page', exact: true }).getByRole('link', { name: 'Lien du pied de page' })).toHaveAttribute('href', '/centre-e2e/shop')
    await nav.getByRole('link', { name: 'Notre boutique', exact: true }).click()
    await expect(page).toHaveURL(/\/centre-e2e\/shop\?categorie=produits$/)
    if (width < 768) {
      await expect(nav).toBeHidden()
      await page.getByRole('button', { name: 'Menu', exact: true }).click()
    }
    await nav.getByRole('link', { name: 'Offrir une carte', exact: true }).click()
    await expect(page).toHaveURL(/\/centre-e2e\/gift-card$/)
    if (width < 768) await expect(nav).toBeHidden()
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
  })
}
