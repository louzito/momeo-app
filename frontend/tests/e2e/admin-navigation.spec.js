import { test, expect } from '@playwright/test'

const allPermissions = ['agenda', 'clients', 'finances', 'catalog', 'settings']

async function session(page, permissions = allPermissions) {
  await page.addInitScript((permissions) => {
    localStorage.setItem('todatempo.admin.centre-e2e', JSON.stringify({
      admin: { name: 'Équipe test', permissions },
      tenant: { id: 'centre-e2e', name: 'Institut test', currency: 'EUR' },
    }))
  }, permissions)
  let document = { name: 'Institut test' }
  let failConfig = false
  await page.route('**/api/v2/**', async (route) => {
    const path = new URL(route.request().url()).pathname
    let body = { member: [] }
    if (path.endsWith('/admin/taxons/todatempo_config')) {
      if (failConfig) return route.fulfill({ status: 500, contentType: 'application/json', body: '{"message":"Configuration indisponible"}' })
      if (route.request().method() === 'PUT') {
        document = JSON.parse(route.request().postDataJSON().translations.en_US.description)
      }
      body = { code: 'todatempo_config', translations: { en_US: { description: JSON.stringify(document) } }, images: [] }
    }
    if (path.includes('/admin/channels/')) body = { name: 'Institut test' }
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify(body) })
  })
  return { document: () => document, failConfig: (value) => { failConfig = value } }
}

const navigation = (page) => page.getByRole('navigation', { name: 'Navigation professionnelle' })

async function expand(page, label) {
  const button = navigation(page).getByRole('button', { name: label, exact: true })
  if (await button.getAttribute('aria-expanded') !== 'true') await button.click()
}

test('les groupes donnent accès aux écrans existants et sélectionnent les détails', async ({ page }) => {
  await session(page)
  await page.goto('/centre-e2e/admin/products/new')
  const nav = navigation(page)
  await expect(nav.getByRole('button')).toHaveCount(7)
  await expect(nav.getByRole('button', { name: 'Catalogue', exact: true })).toHaveAttribute('aria-expanded', 'true')
  await expect(nav.locator('[aria-current="page"]')).toHaveText('Prestations')
  await expect(nav.getByRole('link', { name: 'Produits physiques' })).toHaveAttribute('href', '/centre-e2e/admin/physical-products')
  await expand(page, 'Réglages')
  await expect(nav.getByRole('link', { name: 'Plannings' })).toHaveAttribute('href', '/centre-e2e/admin/plannings')
  await expand(page, 'Ventes')
  await expect(nav.getByRole('link', { name: 'Commandes et factures' })).toHaveAttribute('href', '/centre-e2e/admin/orders')
  await expect(page.getByRole('link', { name: 'Voir mon site' })).toHaveAttribute('href', '/centre-e2e/')
  await expand(page, 'Mon site internet')
  await expect(nav.getByRole('link', { name: 'Menus', exact: true })).toHaveCount(0)
  await page.goto('/centre-e2e/admin/options/example')
  await expect(navigation(page).locator('[aria-current="page"]')).toHaveText('Options et suppléments')
  await page.goto('/centre-e2e/admin/products/example')
  await expect(navigation(page).locator('[aria-current="page"]')).toHaveText('Prestations')
})

for (const [permission, groups, destination, forbidden] of [
  ['agenda', ['Tableau de bord', 'Rendez-vous', 'Réglages'], 'plannings', 'settings?section=appearance'],
  ['catalog', ['Tableau de bord', 'Catalogue', 'Réglages'], 'resources', 'payments'],
  ['finances', ['Tableau de bord', 'Ventes', 'Réglages'], 'payments', 'physical-products'],
  ['clients', ['Tableau de bord', 'Clients'], 'clients', 'settings?section=home'],
  ['settings', ['Tableau de bord', 'Mon site internet', 'Réglages'], 'settings', 'plannings'],
]) {
  test(`permission ${permission} : groupes vides masqués et accès direct protégé`, async ({ page }) => {
    await session(page, [permission])
    await page.goto(`/centre-e2e/admin/${destination}`)
    await expect(navigation(page).getByRole('button')).toHaveText(groups.map((label) => new RegExp(label)))
    await page.goto(`/centre-e2e/admin/${forbidden}`)
    await expect(page).not.toHaveURL(new RegExp(`/admin/${forbidden.replace('?', '\\?')}$`))
    await expect(navigation(page).getByRole('button')).toHaveCount(groups.length)
  })
}

test('navigation clavier sur mobile, fermeture et retour du focus', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await session(page)
  await page.goto('/centre-e2e/admin/settings')
  const open = page.getByRole('button', { name: 'Ouvrir le menu' })
  await expect(navigation(page)).toHaveCount(0) // Le menu fermé est inerte.
  await open.focus()
  await page.keyboard.press('Enter')
  const close = page.getByRole('button', { name: 'Fermer le menu' })
  await expect(close).toBeFocused()
  await page.keyboard.press('Shift+Tab')
  await expect(page.getByRole('button', { name: 'Se déconnecter' })).toBeFocused()
  await page.keyboard.press('Tab')
  await expect(close).toBeFocused()
  await page.keyboard.press('Escape')
  await expect(open).toBeFocused()
  await open.press('Enter')
  const site = navigation(page).getByRole('button', { name: 'Mon site internet', exact: true })
  await site.focus()
  await page.keyboard.press('Space')
  await expect(site).toHaveAttribute('aria-expanded', 'true')
  await page.keyboard.press('Tab')
  await expect(navigation(page).getByRole('link', { name: 'Page d’accueil', exact: true })).toBeFocused()
  await page.keyboard.press('Enter')
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Page d’accueil')
  await expect(page.locator('main')).toBeFocused()
  await expect(open).toHaveAttribute('aria-expanded', 'false')
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy()
})

test('les rubriques conservent le brouillon commun, les anciennes URLs et la publication', async ({ page }) => {
  const api = await session(page, ['settings'])
  await page.goto('/centre-e2e/admin/settings')
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Établissement')
  await page.getByPlaceholder('Institut TodaTempo').fill('Nouveau nom')
  await expand(page, 'Mon site internet')
  await navigation(page).getByRole('link', { name: 'Apparence', exact: true }).click()
  await expect(navigation(page).locator('[aria-current="page"]')).toHaveText('Apparence')
  await expect(page.getByRole('heading', { name: 'Couleurs', exact: true })).toBeVisible()
  await page.goBack()
  await expect(page.getByPlaceholder('Institut TodaTempo')).toHaveValue('Nouveau nom')
  await page.goForward()
  await navigation(page).getByRole('link', { name: 'Page d’accueil', exact: true }).click()
  await page.getByPlaceholder('Prenez soin de vous, simplement').fill('Bienvenue chez nous')
  await page.getByRole('button', { name: 'Enregistrer le brouillon', exact: true }).click()
  await expect(page.getByRole('status')).toHaveText('✓ Brouillon enregistré')
  expect(api.document().draft.name).toBe('Nouveau nom')
  expect(api.document().draft.home.title).toBe('Bienvenue chez nous')
  expect(api.document().published.name).toBe('Institut test')
  await page.getByRole('button', { name: 'Publier', exact: true }).click()
  await expect(page.getByText('Version publiée : 1')).toBeVisible()
  expect(api.document().published.home.title).toBe('Bienvenue chez nous')
  await page.reload()
  await expect(page.getByPlaceholder('Prenez soin de vous, simplement')).toHaveValue('Bienvenue chez nous')
  await page.goto('/centre-e2e/admin/settings?section=inconnue')
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Établissement')
})

test('une erreur de chargement des réglages permet de réessayer', async ({ page }) => {
  const api = await session(page, ['settings'])
  api.failConfig(true)
  await page.goto('/centre-e2e/admin/settings?section=appearance')
  await expect(page.getByRole('alert')).toBeVisible()
  api.failConfig(false)
  await page.getByRole('button', { name: 'Réessayer' }).click()
  await expect(page.getByRole('heading', { name: 'Couleurs', exact: true })).toBeVisible()
  await expect(page.getByRole('alert')).toHaveCount(0)
})
