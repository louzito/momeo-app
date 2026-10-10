import { test, expect } from '@playwright/test'

for (const [section, label] of [['terms', 'Texte des conditions générales'], ['mentions', 'Texte des mentions légales']]) {
  test(`${section} : mise en forme enregistrée et rechargée`, async ({ page }) => {
    await page.addInitScript(() => localStorage.setItem('todatempo.admin.centre-e2e', JSON.stringify({ admin: { name: 'Équipe', permissions: ['settings'] }, tenant: { id: 'centre-e2e', name: 'Institut', currency: 'EUR' } })))
    let config = { name: 'Institut', legal: { [section]: { enabled: true, content: 'Texte existant\nDeuxième paragraphe' } } }
    await page.route('**/api/v2/**', async route => {
      const path = new URL(route.request().url()).pathname
      let body = { member: [] }
      if (path.endsWith('/admin/taxons/todatempo_config')) {
        if (route.request().method() === 'PUT') config = JSON.parse(route.request().postDataJSON().translations.en_US.description)
        body = { code: 'todatempo_config', translations: { en_US: { description: JSON.stringify(config) } }, images: [] }
      }
      if (path.includes('/admin/channels/')) body = { name: 'Institut' }
      await route.fulfill({ json: body })
    })
    await page.goto(`/centre-e2e/admin/settings?section=${section}`)
    const editor = page.getByRole('textbox', { name: label })
    await expect(editor).toContainText('Texte existant')
    expect(await editor.evaluate(element => element.getBoundingClientRect().height)).toBeGreaterThanOrEqual(448)
    await editor.click()
    await page.keyboard.press('Control+Home')
    await page.getByRole('button', { name: 'Titre 2', exact: true }).click()
    await expect(editor.locator('h2')).toHaveText('Texte existant')
    await page.getByRole('button', { name: 'Enregistrer le brouillon', exact: true }).click()
    await expect(page.getByRole('status')).toContainText('Brouillon enregistré')
    expect(config.draft.legal[section].content).toBe('Texte existant\nDeuxième paragraphe')
    expect(config.draft.legal[section].contentRich.content[0].type).toBe('heading')
    await page.reload()
    await expect(editor.locator('h2')).toHaveText('Texte existant')
  })
}
