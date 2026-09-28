import { test, expect } from '@playwright/test'

test('images mobiles : import, réutilisation clavier, alternative et suppression protégée', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await page.addInitScript(() => localStorage.setItem('todatempo.admin.centre-e2e', JSON.stringify({ admin: { name: 'Équipe', permissions: ['settings'] }, tenant: { id: 'centre-e2e', name: 'Institut', currency: 'EUR' } })))
  const id = 'a'.repeat(32)
  const photo = { id, path: '/media/image/photo.webp', thumbnail: '/media/image/thumb.webp', width: 800, height: 400, alt: 'Notre jardin', used: false }
  let images = [], imports = 0, reject = false
  const sitePage = { id: 'b'.repeat(32), archived: false, published: null, draft: { title: 'Contact', slug: 'contact', document: { schemaVersion: 1, blocks: [] }, seo: { title: '', description: '' } } }
  await page.route('**/api/v2/**', async route => {
    expect(route.request().headers()['x-skybook-tenant']).toBe('centre-e2e')
    const path = new URL(route.request().url()).pathname, method = route.request().method()
    if (path.endsWith('/site/media') && method === 'POST') {
      imports++; images = [photo]; return route.fulfill({ status: 201, json: photo })
    }
    if (path.endsWith(`/site/media/${id}`) && method === 'DELETE') return route.fulfill({ status: 409, json: { error: 'Cette image est utilisée dans une version publiée.' } })
    if (path.endsWith('/site/media')) return route.fulfill({ json: { member: images } })
    if (path.endsWith('/site/pages')) return route.fulfill({ json: { member: [sitePage] } })
    if (path.endsWith(`/site/pages/${sitePage.id}`) && method === 'PUT') {
      if (reject) return route.fulfill({ status: 422, json: { error: 'Impossible d’enregistrer pour le moment.' } })
      sitePage.draft = route.request().postDataJSON(); return route.fulfill({ json: sitePage })
    }
    return route.fulfill({ json: { member: [] } })
  })
  await page.goto('/centre-e2e/admin/site/images')
  await expect(page.getByText('Aucune image pour le moment.', { exact: false })).toBeVisible()
  await page.getByLabel('Importer une image').setInputFiles({ name: 'jardin.png', mimeType: 'image/png', buffer: Buffer.from('image fixture') })
  await page.getByLabel('Texte alternatif', { exact: true }).fill('Notre jardin')
  await page.getByRole('button', { name: 'Importer', exact: true }).click()
  await expect(page.getByAltText('Notre jardin')).toBeVisible()
  page.once('dialog', dialog => dialog.accept())
  await page.getByRole('button', { name: 'Supprimer l’image 1' }).click()
  await expect(page.getByRole('alert')).toContainText('version publiée')
  await page.goto('/centre-e2e/admin/site/pages')
  await page.getByRole('button', { name: 'Images', exact: true }).click()
  const picker = page.getByRole('button', { name: 'Choisir une image', exact: true })
  await picker.focus(); await page.keyboard.press('Enter')
  await expect(page.getByRole('dialog')).toBeVisible()
  await page.keyboard.press('Escape'); await expect(picker).toBeFocused()
  await picker.click()
  await page.getByRole('button', { name: 'Choisir l’image 1' }).click()
  await expect(page.getByRole('dialog')).not.toBeVisible()
  await page.getByLabel('Texte alternatif dans cette page').fill('Le jardin au printemps')
  await expect(page.getByAltText('Le jardin au printemps')).toHaveAttribute('width', '800')
  reject = true
  await page.getByRole('button', { name: 'Enregistrer', exact: true }).click()
  await expect(page.getByRole('alert')).toContainText('Impossible d’enregistrer')
  await expect(page.getByLabel('Texte alternatif dans cette page')).toHaveValue('Le jardin au printemps')
  reject = false
  await page.getByRole('button', { name: 'Enregistrer', exact: true }).click()
  await expect(page.getByText('Page enregistrée.', { exact: true })).toBeVisible()
  expect(imports).toBe(1)
  expect(sitePage.draft.document.blocks[0].props).toEqual({ mediaId: id, alt: 'Le jardin au printemps' })
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy()
})
