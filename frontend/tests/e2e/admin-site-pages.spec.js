import { test, expect } from '@playwright/test'

test('pages : création, erreur conservée, renommage, duplication et archivage sur mobile', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await page.addInitScript(() => localStorage.setItem('todatempo.admin.centre-e2e', JSON.stringify({ admin: { name: 'Équipe', permissions: ['settings'] }, tenant: { id: 'centre-e2e', name: 'Institut', currency: 'EUR' } })))
  let pages = []
  let fail = false
  await page.route('**/api/v2/**', async route => {
    const path = new URL(route.request().url()).pathname
    const method = route.request().method()
    let body = { member: [] }
    if (path.includes('/admin/site/pages')) {
      if (fail && method === 'POST') return route.fulfill({ status: 422, json: { error: 'Cette adresse est déjà utilisée.' } })
      if (method === 'POST') {
        const data = route.request().postDataJSON()
        const source = path.endsWith('/duplicate') ? pages[0].draft : { document: { schemaVersion: 1, blocks: [] }, seo: { title: '', description: '' } }
        body = { id: String(pages.length + 1), role: null, archived: false, published: null, draft: { ...source, title: data.title, slug: data.slug } }
        pages.push(body)
      } else if (method === 'PUT') {
        pages[0].draft = route.request().postDataJSON(); body = pages[0]
      } else if (method === 'DELETE') {
        pages[0].archived = true; body = { ok: true }
      } else body = { member: pages }
    }
    await route.fulfill({ json: body })
  })
  await page.goto('/centre-e2e/admin/site/pages')
  await expect(page.getByText('Aucune page pour le moment.', { exact: false })).toBeVisible()
  await page.getByRole('button', { name: 'Nouvelle page' }).click()
  await expect(page.getByLabel('Titre', { exact: true })).toBeFocused()
  await page.getByLabel('Titre', { exact: true }).fill('Contact')
  await page.getByLabel('Adresse de la page').fill('contact')
  fail = true
  await page.getByRole('button', { name: 'Enregistrer', exact: true }).click()
  await expect(page.getByRole('alert')).toContainText('déjà utilisée')
  await expect(page.getByLabel('Titre', { exact: true })).toHaveValue('Contact')
  fail = false
  await page.getByRole('button', { name: 'Enregistrer', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Contact', exact: true })).toBeVisible()
  await page.getByRole('button', { name: 'Renommer Contact', exact: true }).click()
  await page.getByLabel('Titre', { exact: true }).fill('Nous contacter')
  await page.getByRole('button', { name: 'Enregistrer', exact: true }).click()
  await page.getByRole('button', { name: 'Dupliquer Nous contacter', exact: true }).click()
  await page.getByRole('button', { name: 'Enregistrer', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Nous contacter (copie)', exact: true })).toBeVisible()
  page.once('dialog', dialog => dialog.accept())
  await page.getByRole('button', { name: 'Archiver Nous contacter', exact: true }).click()
  await expect(page.getByRole('button', { name: 'Renommer Nous contacter', exact: true })).toHaveCount(0)
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy()
})
