import { test, expect } from '@playwright/test'

test('menus mobiles : sous-menu, clavier, brouillons indépendants et saisie conservée après erreur', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await page.addInitScript(() => localStorage.setItem('todatempo.admin.centre-e2e', JSON.stringify({ admin: { name: 'Équipe', permissions: ['settings'] }, tenant: { id: 'centre-e2e', name: 'Institut', currency: 'EUR' } })))
  const menus = { main: { items: [], primaryLink: null }, footer: { items: [], primaryLink: null } }
  let fail = true
  await page.route('**/api/v2/**', async route => {
    const path = new URL(route.request().url()).pathname
    if (path.endsWith('/site/pages')) return route.fulfill({ json: { member: [{ id: 'a'.repeat(32), draft: { title: 'Contact' }, published: null, archived: false }] } })
    const location = path.split('/').at(-1)
    if (path.includes('/site/menus/')) {
      if (route.request().method() === 'PUT') {
        if (fail) return route.fulfill({ status: 422, json: { error: 'Lien invalide.' } })
        menus[location] = route.request().postDataJSON()
      }
      return route.fulfill({ json: menus[location] })
    }
    return route.fulfill({ json: { member: [] } })
  })
  await page.goto('/centre-e2e/admin/site/menus')
  await page.getByRole('button', { name: 'Ajouter un lien', exact: true }).click()
  await page.getByLabel('Libellé', { exact: true }).fill('Prestations')
  await page.getByRole('button', { name: 'Ajouter un lien enfant' }).click()
  await page.getByLabel('Libellé', { exact: true }).nth(1).fill('Contact')
  await page.getByLabel('Destination', { exact: true }).nth(1).selectOption('page')
  await page.getByLabel('Page', { exact: true }).selectOption('a'.repeat(32))
  await expect(page.getByText('« Contact » devra être publiée', { exact: false })).toBeVisible()
  await page.getByRole('button', { name: 'Ajouter un lien', exact: true }).click()
  await page.getByLabel('Libellé', { exact: true }).nth(2).fill('Boutique')
  const move = page.getByRole('button', { name: 'Monter Boutique', exact: true })
  await move.focus(); await page.keyboard.press('Enter')
  await expect(page.getByLabel('Libellé', { exact: true }).first()).toHaveValue('Boutique')
  await page.getByRole('button', { name: 'Enregistrer le brouillon' }).click()
  await expect(page.getByRole('alert')).toContainText('Lien invalide')
  await expect(page.getByLabel('Libellé', { exact: true }).last()).toHaveValue('Contact')
  fail = false
  await page.getByRole('button', { name: 'Enregistrer le brouillon' }).click()
  await expect(page.getByText('Brouillon enregistré.', { exact: false })).toBeVisible()
  expect(menus.main.items[1].children[0].link.target).toBe('a'.repeat(32))
  expect(menus.main.items[1].children[0]).not.toHaveProperty('children')
  await page.getByLabel('Menu à modifier').selectOption('footer')
  await expect(page.getByText('Ce menu est vide.', { exact: false })).toBeVisible()
  expect(menus.footer.items).toEqual([])
  await page.getByLabel('Menu à modifier').selectOption('main')
  await expect(page.getByLabel('Libellé', { exact: true }).first()).toHaveValue('Boutique')
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy()
})
