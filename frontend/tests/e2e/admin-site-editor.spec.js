import { test, expect } from '@playwright/test'
for (const width of [390, 1280]) {
  test(`éditeur : brouillon, sections, texte et conflit à ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 })
    await page.addInitScript(() => localStorage.setItem('todatempo.admin.centre-e2e', JSON.stringify({ admin: { name: 'Équipe', permissions: ['settings'] }, tenant: { id: 'centre-e2e', name: 'Institut', currency: 'EUR' } })))
    let value = { id: 'a'.repeat(32), revision: 1, archived: false, draft: { title: 'Présentation', slug: 'presentation', document: { schemaVersion: 1, blocks: [] }, seo: { title: '', description: '' } }, published: null }
    let conflict = false
    await page.route('**/api/v2/**', async route => {
      const path = new URL(route.request().url()).pathname
      let body = { member: [] }
      if (path.includes('/admin/site/pages/')) {
        if (route.request().method() === 'PUT') {
          if (conflict) return route.fulfill({ status: 409, json: { error: 'Conflit' } })
          const { revision, ...draft } = route.request().postDataJSON()
          expect(revision).toBe(value.revision)
          value = { ...value, revision: revision + 1, draft }
        }
        body = value
      } else if (path.endsWith('/admin/site/pages')) body = { member: [value] }
      await route.fulfill({ json: body })
    })
    await page.goto(`/centre-e2e/admin/site/pages/${value.id}/edit`)
    await page.getByRole('button', { name: 'Ajouter une section', exact: true }).click()
    await page.getByLabel('Titre', { exact: true }).fill('Notre institut')
    const preview = page.getByRole('region', { name: 'Aperçu de la page' })
    await expect(preview.getByRole('heading', { name: 'Notre institut' })).toBeVisible()
    await page.getByRole('button', { name: 'Masquer', exact: true }).click()
    await expect(preview.getByRole('heading', { name: 'Notre institut' })).toHaveCount(0)
    await page.getByRole('button', { name: 'Afficher', exact: true }).click()
    await page.getByLabel('Nouvelle section').selectOption('text')
    await page.getByRole('button', { name: 'Ajouter une section', exact: true }).click()
    await page.getByRole('textbox', { name: 'Texte de la section' }).evaluate(element => {
      element.focus()
      const data = new DataTransfer()
      data.setData('text/html', '<p style="color:red" onclick="alert(1)"><strong>Texte collé</strong><img src=x onerror="alert(1)"><a href="javascript:alert(1)">Lien</a></p>')
      element.dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true }))
    })
    await expect(preview).toContainText('Texte collé')
    await expect(preview.locator('[onclick], [onerror], a[href^="javascript:"]')).toHaveCount(0)
    await page.getByRole('textbox', { name: 'Texte de la section' }).fill('Bienvenue dans notre institut')
    await expect(preview).toContainText('Bienvenue dans notre institut')
    await page.getByRole('button', { name: 'Monter la section 2' }).focus()
    await page.keyboard.press('Enter')
    await page.getByRole('button', { name: 'Enregistrer le brouillon' }).click()
    await expect(page.getByRole('status')).toContainText('Brouillon enregistré')
    expect(value.draft.document.blocks[0].type).toBe('text')
    expect(value.published).toBeNull()
    await page.reload()
    await expect(preview).toContainText('Bienvenue dans notre institut')
    await page.getByRole('button', { name: 'Mobile', exact: true }).click()
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy()
    await page.getByRole('textbox', { name: 'Texte de la section' }).fill('Modification à conserver')
    conflict = true
    await page.getByRole('button', { name: 'Enregistrer le brouillon' }).click()
    await expect(page.getByRole('alert')).toContainText('autre fenêtre')
    await expect(preview).toContainText('Modification à conserver')
    page.once('dialog', dialog => dialog.dismiss())
    await page.getByRole('link', { name: 'Retour aux pages' }).click()
    await expect(page.getByRole('heading', { name: 'Composer la page' })).toBeVisible()
  })
}
