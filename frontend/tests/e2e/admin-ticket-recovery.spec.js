import { test, expect } from '@playwright/test'

async function session(page) {
  await page.addInitScript(() => {
    localStorage.setItem('todatempo.admin.centre-e2e', JSON.stringify({
      admin: { permissions: ['agenda', 'catalog', 'settings'] },
      tenant: { id: 'centre-e2e', currency: 'EUR' },
    }))
  })
}

test('une photo en échec peut être renvoyée sans recréer la prestation', async ({ page }) => {
  await session(page)
  let creations = 0
  let uploads = 0
  await page.route('**/api/v2/**', async route => {
    const { pathname } = new URL(route.request().url())
    const method = route.request().method()
    let body = { member: [] }
    if (pathname.endsWith('/admin/products') && method === 'POST') {
      creations++
      body = { code: 'service_soin' }
    }
    if (pathname.endsWith('/images') && method === 'POST') {
      if (++uploads === 1) return route.abort('failed')
      body = { id: 1, path: 'photo.png' }
    }
    if (pathname.endsWith('/admin/products/service_soin')) body = { images: [] }
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify(body) })
  })
  await page.goto('/centre-e2e/admin/products/new')
  await page.getByPlaceholder('Soin visage éclat').fill('Soin')
  await page.locator('input[type=file]').setInputFiles({ name: 'photo.png', mimeType: 'image/png', buffer: Buffer.from('photo') })
  await page.getByRole('button', { name: 'Enregistrer', exact: true }).click()
  await expect(page.getByText('Connexion au serveur interrompue', { exact: false })).toBeVisible()
  await page.getByRole('button', { name: 'Enregistrer', exact: true }).click()
  await expect(page).toHaveURL(/\/admin\/products$/)
  expect(creations).toBe(1)
  expect(uploads).toBe(2)
})

test('les dates ajoutées à un planning renvoyé vide sont enregistrées', async ({ page }) => {
  await session(page)
  let saved
  await page.route('**/api/v2/**', async route => {
    const path = new URL(route.request().url()).pathname
    let body = { member: [] }
    if (path.endsWith('/admin/plannings')) body = { member: [{ code: 'planning_vide', name: 'Planning vide', capacity: 8, active: true, days: [], openDays: [], times: [], jumpCodes: [] }] }
    if (path.endsWith('/admin/plannings/planning_vide') && route.request().method() === 'PUT') {
      saved = route.request().postDataJSON()
      body = saved
    }
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify(body) })
  })
  await page.goto('/centre-e2e/admin/plannings')
  await page.getByRole('button', { name: 'Modifier', exact: true }).click()
  await page.locator('button').filter({ hasText: /^1$/ }).first().click()
  await page.getByRole('button', { name: 'Enregistrer', exact: true }).click()
  await expect.poll(() => saved).toBeTruthy()
  expect(Array.isArray(saved.days)).toBe(false)
  expect(Object.keys(saved.days)).toHaveLength(1)
  expect(Object.values(saved.days)[0].length).toBeGreaterThan(0)
})
