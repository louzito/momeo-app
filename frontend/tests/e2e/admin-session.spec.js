import { test, expect } from '@playwright/test'

test('une session BO expirée redirige vers le website et efface la session', async ({ page }) => {
  await page.route('**/api/v2/shop/**', (route) => route.fulfill({ contentType: 'application/json', body: '{"member":[]}' }))
  await page.goto('/centre-e2e/admin/login')
  await page.evaluate(() => {
    localStorage.setItem('todatempo.admin.centre-e2e', JSON.stringify({ admin: { permissions: ['settings'] }, tenant: { id: 'centre-e2e' } }))
    localStorage.setItem('todatempo.sylius.jwt.centre-e2e', 'expired-token')
  })
  await page.route('**/api/v2/**', (route) => route.fulfill({ status: 401, contentType: 'application/json', body: JSON.stringify({ message: 'Expired JWT Token' }) }))
  await page.route('**/todatempo/fr/connexion', (route) => route.fulfill({ contentType: 'text/html', body: '<h1>Connexion website</h1>' }))
  await page.goto('/centre-e2e/admin/staff')
  await expect(page).toHaveURL(/\/todatempo\/fr\/connexion$/)
  expect(await page.evaluate(() => localStorage.getItem('todatempo.admin.centre-e2e'))).toBeNull()
  expect(await page.evaluate(() => localStorage.getItem('todatempo.sylius.jwt.centre-e2e'))).toBeNull()
})

test('un mauvais mot de passe reste sur le formulaire de connexion', async ({ page }) => {
  await page.route('**/api/v2/**', (route) => route.fulfill({ status: route.request().url().includes('/administrators/token') ? 401 : 200, contentType: 'application/json', body: JSON.stringify({ message: 'Identifiants incorrects', member: [] }) }))
  await page.goto('/centre-e2e/admin/login')
  await page.getByLabel('Email professionnel').fill('test@example.test')
  await page.getByLabel('Mot de passe', { exact: true }).fill('wrong-password')
  await page.getByRole('button', { name: 'Accéder à mon espace' }).click()
  await expect(page.getByText('Identifiants incorrects')).toBeVisible()
  await expect(page).toHaveURL(/\/centre-e2e\/admin\/login$/)
})
