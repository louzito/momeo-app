import { test, expect } from '@playwright/test'

const json = (route, body, status = 200) => route.fulfill({ status, contentType: 'application/ld+json', body: JSON.stringify(body) })

async function catalog(page, legacy = false, attributes = true) {
  const code = legacy ? 'jump_test' : 'service_test'
  await page.route('**/api/v2/**', (route) => {
    const path = new URL(route.request().url()).pathname
    if (path.endsWith('/shop/channels')) return json(route, { member: [{ code: 'WEB', name: 'Institut test', baseCurrency: { code: 'EUR' } }] })
    if (path.includes('/shop/taxons/')) return json(route, {}, 404)
    if (path.endsWith('/shop/products')) return json(route, { member: [{ code, name: 'Soin détente', shortDescription: 'Pour les adultes', description: 'Massage du visage.\nServiette fournie.', defaultVariantData: { price: 6500 } }] })
    if (path.endsWith('/attributes')) return json(route, { member: attributes ? [
      { code: legacy ? 'jump_duration' : 'todatempo_duration', value: 90 },
      { code: 'jump_height_min', value: 150 },
      { code: 'jump_age_min', value: 18 },
      { code: 'jump_medical_cert', value: true },
      { code: 'todatempo_requirements', value: JSON.stringify([{ key: 'arrival', label: 'Venir sans maquillage.' }]) },
    ] : [] })
    return json(route, { member: [] })
  })
  await page.goto(`/centre-e2e/services/${code}`)
}

for (const width of [360, 1280]) {
  test(`fiche soin lisible à ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 844 })
    await catalog(page)
    await expect(page.getByRole('heading', { name: 'Soin détente' })).toBeVisible()
    await expect(page.getByText('1 h 30 min', { exact: true })).toBeVisible()
    await expect(page.getByText('Pour les adultes', { exact: true })).toBeVisible()
    await expect(page.getByText('Venir sans maquillage.', { exact: true })).toBeVisible()
    await expect(page.getByText(/65,00/)).toBeVisible()
    await expect(page.getByText(/Taille minimum|Capacité|Certificat médical|Sylius|migration/)).toHaveCount(0)
    await expect(page.getByRole('button', { name: 'Réserver cette prestation' })).toBeVisible()
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
  })
}

test('fiche historique conserve ses contraintes renseignées', async ({ page }) => {
  await catalog(page, true)
  await expect(page.getByText('150 cm', { exact: true })).toBeVisible()
  await expect(page.getByText('18 ans', { exact: true })).toBeVisible()
  await expect(page.getByText('Certificat médical', { exact: true })).toBeVisible()
  await expect(page.getByText('Poids maximum', { exact: true })).toHaveCount(0)
})

test('fiche sans attributs : pas de caractéristiques fabriquées', async ({ page }) => {
  await catalog(page, true, false)
  await expect(page.getByRole('heading', { name: 'Soin détente' })).toBeVisible()
  await expect(page.locator('dl')).toHaveCount(0)
})
