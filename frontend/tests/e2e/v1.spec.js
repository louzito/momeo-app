import { expect, test } from '@playwright/test'

const json = (route, body, status = 200) => route.fulfill({ status, contentType: 'application/ld+json', body: JSON.stringify(body) })

async function isolatedApi(page, { failCatalog = false } = {}) {
  const requests = []
  await page.route('**/api/v2/**', async (route) => {
    const request = route.request()
    const url = new URL(request.url())
    requests.push({ path: url.pathname, headers: request.headers(), method: request.method() })
    if (failCatalog && url.pathname.endsWith('/shop/products')) return json(route, { detail: 'Catalogue indisponible' }, 503)
    if (url.pathname.endsWith('/shop/channels')) return json(route, { member: [{ code: 'WEB', name: 'Cabinet E2E', baseCurrency: { code: 'EUR' } }] })
    if (url.pathname.includes('/shop/taxons/')) return json(route, {}, 404)
    if (url.pathname.endsWith('/shop/products')) return json(route, { member: [] })
    if (url.pathname.endsWith('/shop/account/profile')) return json(route, { firstName: 'Ada', lastName: 'Test' })
    if (url.pathname.endsWith('/shop/account/orders') || url.pathname.endsWith('/shop/account/bookings')) return json(route, { member: [] })
    return json(route, { member: [] })
  })
  return requests
}

test('résout le tenant et affiche un catalogue vide issu de son API', async ({ page }) => {
  const requests = await isolatedApi(page)
  await page.goto('/centre-e2e/')
  await expect(page.getByRole('heading', { name: 'Cabinet E2E' })).toBeVisible()
  await expect(page.getByText('Aucune prestation disponible')).toBeVisible()
  expect(requests.length).toBeGreaterThan(0)
  expect(requests.every((request) => request.headers['x-skybook-tenant'] === 'centre-e2e')).toBeTruthy()
})

test('un tenant absent est refusé sans aucun appel API', async ({ page }) => {
  const requests = await isolatedApi(page)
  await page.goto('/')
  await expect(page.getByText(/Centre absent ou invalide/)).toBeVisible()
  expect(requests).toHaveLength(0)
})

test('une erreur catalogue reste visible et ne déclenche aucun fallback', async ({ page }) => {
  const requests = await isolatedApi(page, { failCatalog: true })
  await page.goto('/centre-e2e/')
  await expect(page.getByText('Catalogue indisponible')).toBeVisible()
  await expect(page.getByText('Aucune prestation disponible')).toHaveCount(0)
  expect(requests.filter((request) => request.path.endsWith('/shop/products')).length).toBeGreaterThan(0)
})

test('les gardes protègent tunnel client et administration', async ({ page }) => {
  await isolatedApi(page)
  await page.goto('/centre-e2e/checkout/confirmation/reservation-e2e')
  await expect(page).toHaveURL(/\/centre-e2e\/account\/login\?redirect=/)
  await expect(page.getByRole('heading', { name: 'Votre compte TodaTempo' })).toBeVisible()
  await page.goto('/centre-e2e/admin')
  await expect(page).toHaveURL(/\/centre-e2e\/admin\/login\?redirect=/)
  await expect(page.getByRole('heading', { name: 'Connexion à TodaTempo' })).toBeVisible()
})

test('les pages de disponibilité et les états erreur sont adressables', async ({ page }) => {
  await isolatedApi(page)
  await page.goto('/centre-e2e/calendar')
  await expect(page.getByRole('heading', { name: /calendrier/i })).toBeVisible()
  await page.goto('/centre-e2e/status/slot-unavailable')
  await expect(page.getByText(/indisponible/i).first()).toBeVisible()
  await page.goto('/centre-e2e/status/eligibility-blocked')
  await expect(page.getByText(/éligibilité|conditions/i).first()).toBeVisible()
})
