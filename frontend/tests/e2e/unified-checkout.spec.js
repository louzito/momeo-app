import { test, expect } from '@playwright/test'
const customer = { firstName: 'Alice', lastName: 'Test', email: 'alice@example.test', street: '1 rue des Tests', postcode: '75001', city: 'Paris', countryCode: 'FR' }
for (const width of [360, 1280]) {
  test(`panier mixte, réponse perdue et reprise à ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 850 })
    await page.addInitScript(({ customer }) => {
      if (!sessionStorage.getItem('todatempo.cart.centre-e2e')) sessionStorage.setItem('todatempo.cart.centre-e2e', JSON.stringify({ version: 1, state: {
        tenantId: 'workspace_centre-e2e', jumpType: { id: 'service_test', name: 'Soin', basePrice: 100, paymentMode: 'percentage', paymentValue: 30 },
        slot: { start: '2026-12-10T10:00:00Z', end: '2026-12-10T11:00:00Z', planningCode: 'planning_test' }, jumper: customer, eligibilityChecked: true,
      } }))
    }, { customer })
    let request = null, attempts = 0
    await page.route('**/api/v2/**', async route => {
      const path = new URL(route.request().url()).pathname
      let body = { member: [] }
      if (path.endsWith('/shop/channels')) body = { member: [{ code: 'WEB', name: 'Institut', baseCurrency: { code: 'EUR' } }] }
      else if (path.includes('/shop/taxons/')) return route.fulfill({ status: 404, json: {} })
      else if (path.endsWith('/shop/payment-methods')) body = { member: [{ code: 'bank_transfer', name: 'Virement bancaire' }] }
      else if (path.endsWith('/shop/physical-products')) body = { member: [{ code: 'physical_test', name: 'Crème', pickupEnabled: true, deliveryEnabled: true, deliveryFee: 500, defaultVariantData: { price: 2000, onHand: 5, onHold: 0 } }] }
      else if (path.endsWith('/shop/gift-cards/offer')) body = { enabled: true, currency: 'EUR', minimum: 1000, maximum: 100000, presets: [5000], shopName: 'Institut', validityMonths: 12, paymentMethods: [{ code: 'bank_transfer', label: 'Virement' }] }
      else if (path.endsWith('/shop/checkout')) {
        attempts++
        const sent = route.request().postDataJSON()
        if (!request) { request = sent; return route.abort('failed') }
        expect(sent).toEqual(request)
        expect(sent.items).toEqual([{ code: 'service_test', quantity: 1 }, { code: 'physical_test', quantity: 2 }])
        expect(sent.gifts).toHaveLength(1)
        expect(sent.customer.email).toBe('alice@example.test')
        body = { order: { orderToken: 'order-secret', number: 'CMD-1', paymentMethod: 'bank_transfer', total: 125, giftSettled: true }, booking: { id: 'booking-secret' } }
      } else if (path.endsWith('/shop/payments/stripe/orders/order-secret')) body = { number: 'CMD-1', total: 125, currency: 'EUR', status: 'new', kind: 'mixed', fulfillmentMode: 'delivery', paymentMethod: 'bank_transfer', paymentInstructions: 'Utilisez CMD-1.', booking: { serviceName: 'Soin', slotStart: '2026-12-10T10:00:00Z', status: 'confirmed' }, items: [{ name: 'Crème', quantity: 2, total: 4000 }, { name: 'Carte cadeau pour Marie', quantity: 1, total: 5000 }], paymentBreakdown: { dueNow: 12500, dueLater: 7000 } }
      await route.fulfill({ json: body })
    })
    await page.goto('/centre-e2e/products')
    await page.getByRole('button', { name: 'Ajouter au panier' }).click()
    await page.getByRole('button', { name: 'Ajouter au panier' }).click()
    await page.goto('/centre-e2e/gift-card')
    await page.getByLabel('Nom du destinataire').fill('Marie')
    await page.getByRole('button', { name: 'Ajouter au panier' }).click()
    await expect(page).toHaveURL(/\/cart$/)
    await expect(page.getByRole('region', { name: 'Articles du panier' })).toContainText('Soin')
    await page.getByLabel('Mode de remise').selectOption('delivery')
    await expect(page.getByText('À régler maintenant :', { exact: false })).toContainText('125,00')
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy()
    await page.screenshot({ path: `/tmp/todatempo-cart-${width}.png`, fullPage: true })
    await page.getByRole('button', { name: /Commander.*virement/ }).click()
    await expect(page.getByText('Votre commande a été envoyée.', { exact: false })).toBeVisible()
    await page.reload()
    await page.getByRole('button', { name: /Commander.*virement/ }).click()
    await expect(page).toHaveURL(/shop-confirmation\/order-secret$/)
    await expect(page.getByText('Rendez-vous :', { exact: false })).toContainText('Soin')
    await expect(page.getByText('Carte cadeau pour Marie', { exact: false })).toBeVisible()
    expect(attempts).toBe(2)
  })
}

test('le crédit exclut le montant des cartes offertes dans le récapitulatif', async ({ page }) => {
  await page.addInitScript(({ customer }) => sessionStorage.setItem('todatempo.cart.centre-e2e', JSON.stringify({ version: 1, state: {
    tenantId: 'workspace_centre-e2e', products: [{ id: 'physical_test', name: 'Crème', price: 20, quantity: 2, stock: 5, pickupEnabled: true }],
    gifts: [{ amount: 5000, recipientName: 'Marie', delivery: 'buyer', recipientEmail: '', message: '' }], jumper: customer,
  } })), { customer })
  await page.route('**/api/v2/**', async route => {
    const path = new URL(route.request().url()).pathname
    const body = path.endsWith('/shop/channels') ? { member: [{ code: 'WEB', name: 'Institut', baseCurrency: { code: 'EUR' } }] }
      : path.endsWith('/shop/payment-methods') ? { member: [{ code: 'stripe_web_elements', name: 'Carte bancaire' }] }
        : path.endsWith('/shop/gift-cards/balance') ? { available: 10000, currency: 'EUR' } : { member: [] }
    await route.fulfill({ json: body })
  })
  await page.goto('/centre-e2e/cart')
  await page.getByLabel('Code de la carte').fill('ABC')
  await page.getByRole('button', { name: 'Appliquer', exact: true }).click()
  await expect(page.getByText('Crédit cadeau :', { exact: false })).toContainText('40,00')
  await expect(page.getByText('Reste à payer maintenant :', { exact: false })).toContainText('50,00')
  await expect(page.getByRole('button', { name: /Payer 50,00.*par carte/ })).toBeEnabled()
})
