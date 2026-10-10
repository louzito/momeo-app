import { test, expect } from '@playwright/test'

async function setup(page, { empty = false, reject = false } = {}) {
  await page.addInitScript(() => {
    localStorage.setItem('todatempo.admin.centre-e2e', JSON.stringify({ admin: { permissions: ['agenda', 'clients', 'catalog'] }, tenant: { id: 'centre-e2e', currency: 'EUR' } }))
    localStorage.setItem('todatempo.sylius.jwt.centre-e2e', 'test-token')
  })
  const bookings = []
  await page.route('**/api/v2/**', async route => {
    const request = route.request()
    const path = new URL(request.url()).pathname
    let body = { member: [] }
    let status = 200
    if (path.endsWith('/admin/team/session')) body = { permissions: ['agenda', 'clients', 'catalog'] }
    else if (path.endsWith('/shop/channels')) body = { member: [{ code: 'INSTITUT_WEB', baseCurrency: { code: 'EUR' }, defaultLocale: { code: 'fr_FR' } }] }
    else if (path.includes('/shop/taxons/')) body = { description: '{}' }
    else if (path.endsWith('/shop/products')) body = { member: empty ? [] : [{ code: 'service_soin', name: 'Soin visage', defaultVariantData: { price: 6500 } }] }
    else if (path.endsWith('/admin/staff-members')) body = { member: [{ id: 1, displayName: 'Alice Martin', active: true, serviceCodes: ['service_soin'] }] }
    else if (path.endsWith('/admin/bookings')) {
      if (request.method() === 'POST') {
        if (reject) return route.fulfill({ status: 409, json: { error: 'Ce créneau est déjà occupé.' } })
        const data = request.postDataJSON()
        expect(data.serviceCode).toBe('service_soin')
        expect(Number(data.staffMemberId)).toBe(1)
        const booking = { id: 1, ...data, slotStart: data.start, slotEnd: new Date(new Date(data.start).getTime() + 3600000).toISOString(), customerName: `${data.customer.firstName} ${data.customer.lastName}`, jumperName: `${data.customer.firstName} ${data.customer.lastName}`, serviceName: 'Soin visage', staffName: 'Alice Martin', status: 'confirmed', source: 'manual', paymentState: 'pay_on_site' }
        bookings.push(booking)
        status = 201; body = booking
      } else body = { member: bookings }
    } else if (path.endsWith('/admin/clients')) body = { member: bookings.length ? [{ id: 'client', firstName: 'Jeanne', lastName: 'Dupont', displayName: 'Jeanne Dupont', email: 'jeanne@example.test', tags: [], consents: {}, consentHistory: [], bookingCount: 1, nextBookingAt: bookings[0].slotStart, bookings, purchases: [] }] : [], stats: { total: bookings.length } }
    else if (path.endsWith('/admin/dashboard/overview')) body = { appointments: bookings.length, paidRevenue: 0, newClients: bookings.length, occupancyRate: 0, giftCards: {} }
    await route.fulfill({ status, json: body })
  })
  await page.goto('/centre-e2e/admin/agenda')
  await page.getByRole('button', { name: '+ Nouveau rendez-vous' }).click()
  return bookings
}

async function fillCustomer(page) {
  const dialog = page.getByRole('dialog')
  await dialog.getByLabel('Prénom *').fill('Jeanne')
  await dialog.getByLabel('Nom *', { exact: true }).fill('Dupont')
  await dialog.getByLabel('Email *').fill('jeanne@example.test')
  await dialog.getByLabel('Heure *').fill('23:00')
}

for (const width of [1280, 390]) {
  test(`sans prestation : explication, lien et footer visible (${width} × 670)`, async ({ page }) => {
    await page.setViewportSize({ width, height: 670 })
    await setup(page, { empty: true })
    const dialog = page.getByRole('dialog')
    await expect(dialog.getByRole('status')).toContainText('Aucune prestation')
    await expect(dialog.getByLabel('Prestation *')).toBeDisabled()
    const submit = dialog.getByRole('button', { name: 'Ajouter le rendez-vous' })
    await expect(submit).toBeDisabled()
    for (const button of [submit, dialog.getByRole('button', { name: 'Annuler' })]) {
      const bounds = await button.boundingBox()
      expect(bounds.y).toBeGreaterThanOrEqual(0)
      expect(bounds.y + bounds.height).toBeLessThanOrEqual(670)
    }
    const body = dialog.locator('.overflow-y-auto')
    expect(await body.evaluate(el => el.scrollHeight > el.clientHeight)).toBeTruthy()
    await body.evaluate(el => { el.scrollTop = el.scrollHeight })
    await expect(dialog.getByLabel('Note interne')).toBeInViewport()
    await dialog.getByRole('link', { name: 'Catalogue > Prestations' }).click()
    await expect(page).toHaveURL(/\/admin\/products$/)
  })
}

test('un rendez-vous manuel apparaît dans les écrans admin (API simulée)', async ({ page }) => {
  await setup(page)
  await fillCustomer(page)
  await page.getByRole('button', { name: 'Ajouter le rendez-vous' }).click()
  await expect(page.getByRole('dialog')).toHaveCount(0)
  await expect(page.locator('article').filter({ hasText: 'Jeanne Dupont' })).toContainText('Soin visage')
  await page.goto('/centre-e2e/admin/bookings')
  await expect(page.getByRole('button', { name: /À venir/ })).toBeVisible()
  await expect(page.locator('main')).toContainText('Jeanne Dupont')
  await page.goto('/centre-e2e/admin/clients')
  await page.getByRole('button', { name: 'Ouvrir', exact: true }).click()
  await expect(page.locator('main')).toContainText('Ajout manuel')
  await expect(page.locator('main')).toContainText('Soin visage')
  await page.goto('/centre-e2e/admin')
  await expect(page.locator('.card').filter({ has: page.getByText('Rendez-vous', { exact: true }) }).locator('.text-2xl')).toHaveText('1')
})

test('une erreur de création conserve le formulaire et les boutons accessibles', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 670 })
  await setup(page, { reject: true })
  await fillCustomer(page)
  await page.getByRole('button', { name: 'Ajouter le rendez-vous' }).click()
  const dialog = page.getByRole('dialog')
  await expect(dialog).toContainText('Ce créneau est déjà occupé.')
  await expect(dialog.getByLabel('Prénom *')).toHaveValue('Jeanne')
  await expect(dialog.getByRole('button', { name: 'Ajouter le rendez-vous' })).toBeEnabled()
  const bounds = await dialog.getByRole('button', { name: 'Annuler' }).boundingBox()
  expect(bounds.y + bounds.height).toBeLessThanOrEqual(670)
})
