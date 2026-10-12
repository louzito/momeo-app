import { test, expect } from '@playwright/test'

for (const width of [390, 1280]) {
  test(`apparence : ancien thème, aperçu isolé, brouillon et erreur à ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 })
    await page.addInitScript(() => localStorage.setItem('todatempo.admin.centre-e2e', JSON.stringify({ admin: { name: 'Équipe', permissions: ['settings'] }, tenant: { id: 'centre-e2e', name: 'Institut', currency: 'EUR' } })))
    const original = { name: 'Institut réel', colors: { header: '#123456', textHeader: '#ffffff', footer: '#111111', textFooter: '#ffffff' }, assets: { logo: 'tenant/logo.png' } }
    const document = { schemaVersion: 1, revision: 2, published: structuredClone(original), draft: structuredClone(original) }
    let state = { revision: 'before', primaryLink: null }, fail = true
    let uploads = 0
    await page.route('**/api/v2/**', async route => {
      const path = new URL(route.request().url()).pathname
      let body = { member: [] }
      if (path.endsWith('/site/appearance/logo')) {
        uploads++
        expect(route.request().method()).toBe('POST')
        expect(route.request().headers()['content-type']).toContain('multipart/form-data')
        return route.fulfill({ status: 201, json: { path: 'tenant/new-logo.png' } })
      }
      if (path.includes('/admin/taxons/')) body = { translations: { en_US: { description: JSON.stringify(document) } }, images: [{ type: 'logo', path: 'tenant/logo.png' }] }
      if (path.includes('/admin/channels/')) body = { name: 'Institut réel' }
      if (path.endsWith('/site/appearance')) {
        if (route.request().method() === 'PUT') {
          if (fail) return route.fulfill({ status: 422, json: { error: 'Le texte doit être suffisamment contrasté.' } })
          const data = route.request().postDataJSON()
          Object.assign(document.draft, { colors: data.colors, typography: data.typography, branding: data.branding })
          document.draft.assets = { logo: data.logo }
          state = { revision: 'after', primaryLink: data.primaryLink }
        }
        body = state
      }
      await route.fulfill({ json: body })
    })
    await page.route('https://api.fontsource.org/**', route => route.fulfill({ json: [{ family: 'Lora', type: 'google' }, { family: 'Inter', type: 'google' }] }))
    await page.goto('/centre-e2e/admin/settings?section=appearance')
    await expect(page).toHaveURL(/site\/appearance$/)
    const preview = page.getByRole('region', { name: 'Aperçu du brouillon' })
    await expect(page.getByLabel('Fond du haut de page')).toHaveValue('#123456')
    await expect(page.getByAltText('Logo actuel')).toHaveAttribute('src', /tenant\/logo.png/)
    const rootBefore = await page.evaluate(() => document.documentElement.style.cssText)
    await page.getByLabel('Police des titres').selectOption('Lora')
    await page.getByLabel('Couleur principale').fill('#123456')
    await page.getByLabel('Afficher le bouton').check()
    await page.getByLabel('Choisir un logo').setInputFiles({ name: 'logo.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=', 'base64') })
    await page.getByRole('button', { name: 'Mobile', exact: true }).click()
    await expect(preview.locator('h2.font-display').first()).toHaveCSS('font-family', /Lora/)
    expect(await page.evaluate(() => document.documentElement.style.cssText)).toBe(rootBefore)
    const save = page.getByRole('button', { name: 'Enregistrer', exact: true })
    await save.focus(); await page.keyboard.press('Enter')
    await expect(page.getByRole('alert')).toContainText('contrasté')
    await expect(page.getByLabel('Police des titres')).toHaveValue('Lora')
    fail = false; await save.click()
    await expect(page.getByRole('status')).toContainText('site publié reste inchangé')
    expect(document.published).toEqual(original)
    expect(document.draft.assets.logo).toBe('tenant/new-logo.png')
    expect(uploads).toBe(1)
    expect(state.primaryLink).toEqual({ type: 'route', target: 'booking' })
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy()
  })
}
