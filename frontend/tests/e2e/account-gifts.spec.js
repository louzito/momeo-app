import { expect, test } from '@playwright/test'

const code = 'A'.repeat(32)
const json = (route, data, status = 200) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(data) })

async function accountApi(page) {
  const state = { claimed: false, available: 10000, fail: false, calls: [] }
  await page.addInitScript(() => sessionStorage.setItem('todatempo.customer.jwt.centre-e2e', 'account-test-token'))
  await page.route('**/api/v2/**', route => {
    const request = route.request()
    const path = new URL(request.url()).pathname
    state.calls.push({ path, method: request.method(), body: request.postDataJSON(), headers: request.headers() })
    if (path.endsWith('/shop/account/profile')) return json(route, { id: 1, firstName: 'Ada', email: 'ada@example.test' })
    if (path.endsWith('/shop/account/gift-cards/claim')) {
      if (request.postDataJSON().code !== code) return json(route, { error: 'Cette carte ne peut pas être rattachée à ce compte.' }, 422)
      state.claimed = true
      return json(route, { status: 'attached' })
    }
    if (path.endsWith('/shop/account/gift-cards')) {
      if (state.fail) return json(route, { error: 'Réessayez dans un instant.' }, 503)
      return json(route, {
        received: state.claimed ? [{ id: 1, code, currency: 'EUR', initialAmount: 10000, available: state.available, reserved: 0, status: 'active', expiresAt: '2027-09-28', history: state.available === 3000 ? [{ kind: 'debit', amount: 7000, availableAfter: 3000, createdAt: '2026-09-28' }] : [] }] : [],
        purchased: [],
      })
    }
    if (path.endsWith('/shop/channels')) return json(route, { member: [{ code: 'WEB', name: 'Institut', baseCurrency: { code: 'EUR' } }] })
    if (path.endsWith('/shop/gift-cards/offer')) return json(route, { enabled: false })
    return json(route, { member: [] })
  })
  return state
}

for (const width of [360, 1280]) {
  test(`compte unique, rattachement et solde actualisé à ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 844 })
    const state = await accountApi(page)
    await page.goto('/centre-e2e/account')
    await expect(page.getByRole('heading', { name: 'Mon compte', exact: true })).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Aucune carte ajoutée' })).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Aucun rendez-vous à venir' })).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Aucune commande', exact: true })).toBeVisible()
    await page.getByLabel('Code secret de la carte cadeau').fill(code)
    await page.getByRole('button', { name: 'Ajouter à mon compte' }).click()
    await expect(page.getByText('Votre carte cadeau est rattachée à votre compte.')).toBeVisible()
    await expect(page.locator('#cadeaux article')).toContainText('100,00')
    const claim = state.calls.find(c => c.path.endsWith('/claim'))
    expect(claim.body).toEqual({ code })
    expect(claim.headers.authorization).toBe('Bearer account-test-token')
    expect(state.calls.filter(c => c.path.includes('/beneficiary'))).toHaveLength(0)
    state.available = 3000
    await page.evaluate(() => window.dispatchEvent(new Event('focus')))
    await expect(page.locator('#cadeaux article .text-2xl')).toContainText('30,00')
    await page.getByText('Historique de la carte', { exact: true }).click()
    await expect(page.locator('#cadeaux article')).toContainText('Paiement : 70,00')
    await expect(page.getByRole('link', { name: 'Utiliser dans la boutique' })).toHaveAttribute('href', '/centre-e2e/shop')
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
    await page.getByRole('link', { name: 'Accéder à mes anciens bons prestation' }).click()
    await expect(page.getByRole('heading', { name: 'Anciens bons prestation' })).toBeVisible()
    await expect(page.getByRole('button', { name: 'Accéder à mes bons' })).toBeVisible()
    await page.getByRole('link', { name: 'Mes cartes cadeaux dans Mon compte' }).click()
    await expect(page.locator('#cadeaux article .text-2xl')).toContainText('30,00')
  })
}

test('erreurs de rattachement et de chargement récupérables', async ({ page }) => {
  const state = await accountApi(page)
  state.fail = true
  await page.goto('/centre-e2e/account')
  await expect(page.getByText('Réessayez dans un instant.')).toBeVisible()
  state.fail = false
  await page.getByRole('button', { name: 'Réessayer' }).click()
  await page.getByLabel('Code secret de la carte cadeau').fill('invalide')
  await page.getByRole('button', { name: 'Ajouter à mon compte' }).click()
  await expect(page.getByRole('alert')).toContainText('Cette carte ne peut pas être rattachée')
  await expect(page.getByRole('heading', { name: 'Aucune carte ajoutée' })).toBeVisible()
})
