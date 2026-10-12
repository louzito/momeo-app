import { test, expect } from '@playwright/test'

test('établissement accessible et enregistrable malgré une 404 sur les fuseaux', async ({ page }) => {
  await page.addInitScript(() => localStorage.setItem('todatempo.admin.centre-e2e', JSON.stringify({
    admin: { permissions: ['settings'] }, tenant: { id: 'centre-e2e', currency: 'EUR' },
  })))
  let unavailable = true
  let savedConfig
  const config = { name: 'Institut', timezone: 'Europe/Paris' }
  await page.route('**/api/v2/**', async route => {
    const path = new URL(route.request().url()).pathname
    if (path.endsWith('/admin/timezones')) {
      return unavailable
        ? route.fulfill({ status: 404, json: { message: 'Not Found' } })
        : route.fulfill({ json: ['Europe/Paris', 'UTC'] })
    }
    if (path.endsWith('/admin/taxons/todatempo_config')) {
      if (route.request().method() === 'PUT') {
        savedConfig = JSON.parse(route.request().postDataJSON().translations.en_US.description)
      }
      return route.fulfill({ json: { code: 'todatempo_config', translations: { en_US: { description: JSON.stringify(config) } }, images: [] } })
    }
    if (path.endsWith('/admin/channels/WEB')) return route.fulfill({ json: { code: 'WEB', name: 'Institut' } })
    if (path.endsWith('/admin/channels')) return route.fulfill({ json: { member: [{ code: 'WEB' }] } })
    return route.fulfill({ json: { member: [] } })
  })
  await page.goto('/centre-e2e/admin/settings')
  await expect(page.getByRole('heading', { name: 'Établissement', exact: true })).toBeVisible()
  await expect(page.getByRole('status')).toContainText('Le fuseau actuel est conservé')
  await expect(page.getByRole('alert')).toHaveCount(0)
  const timezone = page.locator('#center-timezone')
  await expect(timezone).toHaveValue('Europe/Paris')
  await expect(timezone).toBeDisabled()
  await page.getByPlaceholder('Institut TodaTempo').fill('Nouveau nom')
  await page.getByRole('button', { name: 'Enregistrer', exact: true }).click()
  await expect.poll(() => savedConfig?.published?.name).toBe('Nouveau nom')
  expect(savedConfig.published.timezone).toBe('Europe/Paris')

  unavailable = false
  await page.getByRole('button', { name: 'Réessayer de charger les fuseaux horaires' }).click()
  await expect(timezone).toBeEnabled()
  await timezone.selectOption('UTC')
  await page.getByRole('button', { name: 'Enregistrer', exact: true }).click()
  await expect.poll(() => savedConfig?.published?.timezone).toBe('UTC')
})
