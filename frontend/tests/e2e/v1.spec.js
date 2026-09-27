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
  await expect(page).toHaveURL(/\/centre-e2e\/shop$/)
  await expect(page.getByRole('heading', { name: 'Prestations', exact: true })).toBeVisible()
  await page.goto('/centre-e2e/status/slot-unavailable')
  await expect(page.getByText(/indisponible/i).first()).toBeVisible()
  await page.goto('/centre-e2e/status/eligibility-blocked')
  await expect(page.getByText(/éligibilité|conditions/i).first()).toBeVisible()
})

for (const width of [1280, 390]) {
  test(`navigation boutique sans calendrier global à ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 844 })
    const requests = await isolatedApi(page)
    await page.goto('/centre-e2e/')
    await expect(page.getByRole('link', { name: 'Découvrir nos prestations' })).toBeVisible()
    const header = page.locator('header')
    if (width < 768) await header.getByRole('button', { name: 'Menu' }).click()
    await expect(header.getByRole('link', { name: 'Prestations', exact: true }).filter({ visible: true })).toBeVisible()
    await expect(header.getByRole('link', { name: /Calendrier|professionnel|cadeau/i })).toHaveCount(0)
    await expect(page.locator('a[href$="/calendar"]')).toHaveCount(0)
    await expect(page.locator('footer').getByRole('link', { name: 'Espace professionnel' })).toBeVisible()
    await expect(page.locator('footer').getByRole('link', { name: 'Utiliser un chèque cadeau' })).toHaveAttribute('href', '/centre-e2e/beneficiary/login')
    await header.getByRole('link', { name: 'Boutique', exact: true }).filter({ visible: true }).click()
    await expect(page).toHaveURL(/\/centre-e2e\/products$/)
    await expect(page.getByText('Aucun produit disponible')).toBeVisible()
    expect(requests.some((r) => r.path.endsWith('/shop/availability'))).toBe(false)
    await page.reload()
    await expect(page.getByText('Aucun produit disponible')).toBeVisible()
  })
}

test('une prestation conserve ses disponibilités et son choix de créneau', async ({ page }) => {
  await isolatedApi(page)
  await page.route('**/api/v2/shop/products?*', (route) => json(route, {
    member: [{ code: 'service_test', name: 'Massage détente', defaultVariantData: { price: 5000 } }],
  }))
  const availabilityRequests = []
  await page.route('**/api/v2/shop/availability?*', (route) => {
    availabilityRequests.push(new URL(route.request().url()).searchParams.get('serviceCode'))
    return json(route, { member: [{
      id: 'slot-test', start: '2026-10-15T10:00:00Z', end: '2026-10-15T11:00:00Z',
      remaining: 2, compatibleJumpTypeIds: ['service_test'],
    }] })
  })
  await page.goto('/centre-e2e/services/service_test')
  await expect(page.getByRole('heading', { name: 'Massage détente' })).toBeVisible()
  expect(availabilityRequests).toHaveLength(0)
  await page.getByRole('button', { name: 'Réserver cette prestation' }).click()
  await expect(page.getByText('Disponibilités pour « Massage détente ».')).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Vos options' })).toHaveCount(0)
  await page.getByRole('button', { name: /10:00.*2 places/ }).click()
  await page.getByRole('button', { name: 'Continuer', exact: true }).click()
  await expect(page).toHaveURL(/\/checkout\/details$/)
  expect(availabilityRequests).toEqual(['service_test'])
})

test('l’agenda professionnel reste accessible avec les droits agenda', async ({ page }) => {
  await isolatedApi(page)
  await page.addInitScript(() => localStorage.setItem('todatempo.admin.centre-e2e', JSON.stringify({
    admin: { permissions: ['agenda'] }, tenant: { id: 'centre-e2e', name: 'Test' },
  })))
  await page.goto('/centre-e2e/admin/agenda')
  await expect(page.getByRole('heading', { name: 'Agenda', exact: true })).toBeVisible()
  await expect(page).toHaveURL(/\/admin\/agenda$/)
})

for (const path of ['options', 'mode', 'summary', 'payment', 'gift', 'details']) {
  test(`ancienne URL ${path} sans panier : retour au catalogue`, async ({ page }) => {
    await isolatedApi(page)
    await page.goto(`/centre-e2e/checkout/${path}`)
    await expect(page).toHaveURL(/\/shop$/)
  })
}

for (const mode of ['none', 'percentage', 'fixed', 'full']) {
  test(`réservation mobile ${mode} : options, retour et double clic`, async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 })
    await page.addInitScript(() => sessionStorage.setItem('todatempo.customer.jwt.centre-e2e', 'customer-test'))
    await isolatedApi(page)
    await page.route('**/api/v2/shop/products?*', (route) => json(route, { member: [
      { code: 'service_test', name: 'Massage détente', defaultVariantData: { price: 5000 } },
      { code: 'opt_pj_test', name: 'Huile parfumée', defaultVariantData: { price: 1000 } },
    ] }))
    await page.route('**/shop/products/service_test/attributes*', (route) => json(route, { member: [
      { code: 'todatempo_payment_mode', value: mode },
      { code: 'todatempo_payment_value', value: mode === 'fixed' ? 2000 : 30 },
    ] }))
    await page.route('**/shop/availability?*', (route) => json(route, { member: [{
      id: 'slot-test', start: '2026-10-15T10:00:00Z', end: '2026-10-15T11:00:00Z',
      remaining: 2, compatibleJumpTypeIds: ['service_test'],
    }] }))
    await page.route('**/shop/payment-methods*', (route) => json(route, { member: [{ code: 'bank_transfer', name: 'Virement bancaire' }] }))
    let orders = 0
    let bookings = 0
    let savedBooking
    await page.route('**/shop/orders**', async (route) => {
      const req = route.request()
      const path = new URL(req.url()).pathname
      if (path.endsWith('/orders') && req.method() === 'POST') orders++
      if (path.endsWith('/payment-terms')) return json(route, { dueNow: mode === 'none' ? 0 : mode === 'fixed' ? 2000 : mode === 'full' ? 6000 : 1800 })
      return json(route, { tokenValue: 'order-test', number: 'ORDER-1', total: 6000, currencyCode: 'EUR', payments: [{ id: 1 }] })
    })
    await page.route('**/shop/bookings', async (route) => {
      bookings++
      savedBooking = route.request().postDataJSON()
      await new Promise((resolve) => setTimeout(resolve, 150))
      return json(route, { id: 'booking-test' })
    })
    await page.route('**/shop/account/bookings/booking-test', (route) => json(route, {
      id: 'booking-test', orderNumber: 'ORDER-1', amount: 6000, currencyCode: 'EUR',
      status: 'confirmed', paymentState: mode === 'none' ? 'paid' : 'awaiting_payment',
      slotStart: '2026-10-15T10:00:00Z', slotEnd: '2026-10-15T11:00:00Z', options: [],
    }))
    await page.goto('/centre-e2e/services/service_test')
    await page.getByRole('button', { name: 'Réserver cette prestation' }).click()
    await expect(page).toHaveURL(/\/checkout\/schedule$/)
    await expect(page.getByText(/Pour moi|Offrir cette prestation/)).toHaveCount(0)
    await page.getByRole('checkbox', { name: /Huile parfumée/ }).check()
    await page.getByRole('button', { name: /10:00.*2 places/ }).click()
    await page.getByRole('button', { name: 'Continuer', exact: true }).click()
    await page.getByPlaceholder('Prenom', { exact: true }).fill('Ada')
    await page.getByPlaceholder('Nom', { exact: true }).fill('Test')
    await page.getByPlaceholder('vous@example.com').fill('ada@example.com')
    await page.getByRole('checkbox', { name: /conditions de réservation/ }).check()
    await page.getByRole('checkbox', { name: /mes donnees/ }).check()
    await page.getByRole('link', { name: /Modifier la date/ }).click()
    await expect(page.getByRole('checkbox', { name: /Huile parfumée/ })).toBeChecked()
    await expect(page.getByRole('button', { name: 'Continuer', exact: true })).toBeEnabled()
    await page.getByRole('button', { name: 'Continuer', exact: true }).click()
    await expect(page.getByPlaceholder('Prenom', { exact: true })).toHaveValue('Ada')
    const due = mode === 'none' ? '0,00' : mode === 'fixed' ? '20,00' : mode === 'full' ? '60,00' : '18,00'
    await expect(page.getByText('Montant dû maintenant').locator('..')).toContainText(due)
    await page.getByRole('button', { name: /Confirmer la réservation sans paiement|Commander/ }).evaluate((button) => { button.click(); button.click() })
    await expect(page).toHaveURL(/\/checkout\/confirmation\/booking-test$/)
    expect(orders).toBe(1)
    expect(bookings).toBe(1)
    expect(savedBooking.orderToken).toBe('order-test')
    expect(savedBooking.start).toBe('2026-10-15T10:00:00Z')
    expect(savedBooking.options).toEqual([{ name: 'Huile parfumée', price: 10 }])
    await page.reload()
    await expect(page.getByText(/Commande enregistrée !|Réservation confirmée !/)).toBeVisible()
  })
}
