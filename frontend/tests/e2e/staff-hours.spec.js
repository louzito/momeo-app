import { test, expect } from '@playwright/test'

test('disponibilités : création, erreurs, modification et suppression sur mobile', async ({ page }, testInfo) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await page.addInitScript(() => localStorage.setItem('todatempo.admin.centre-e2e', JSON.stringify({ admin: { permissions: ['settings'] }, tenant: { id: 'centre-e2e', name: 'Test' } })))
  let members = []
  await page.route('**/api/v2/**', async (route) => {
    const path = new URL(route.request().url()).pathname
    let body = { member: [] }
    if (path.includes('/admin/staff-members')) {
      if (['POST', 'PUT'].includes(route.request().method())) {
        const data = route.request().postDataJSON()
        members = [{ ...data, id: 1, displayName: 'Ada Test' }]
        body = members[0]
      } else body = { member: members }
    }
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify(body) })
  })
  await page.goto('/centre-e2e/admin/staff')
  await page.getByRole('button', { name: '+ Ajouter un collaborateur' }).click()
  await page.getByLabel('Prénom *', { exact: true }).fill('Ada')
  await page.getByLabel('Nom *', { exact: true }).fill('Test')
  await page.getByLabel('Fin', { exact: true }).fill('12:00')
  await page.getByRole('button', { name: '+ Ajouter un créneau', exact: true }).click()
  const rows = page.locator('fieldset')
  await rows.nth(1).getByLabel('Début', { exact: true }).fill('11:00')
  await rows.nth(1).getByLabel('Fin', { exact: true }).fill('19:00')
  await expect(rows.first().getByRole('alert')).toContainText('Chevauchement')
  await rows.nth(1).getByLabel('Début', { exact: true }).fill('13:00')
  await expect(page.locator('fieldset [role="alert"]')).toHaveCount(0)
  await expect(rows.nth(1).getByRole('button', { name: 'Lundi', exact: true })).toHaveAttribute('aria-pressed', 'true')
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy()
  await page.locator('[aria-labelledby="availability-title"]').screenshot({ path: testInfo.outputPath('disponibilites-mobile.png') })
  await page.getByRole('button', { name: 'Ajouter à l’équipe', exact: true }).click()
  await expect(page.locator('form')).toHaveCount(0)
  expect(members[0].workingHours.monday).toEqual([{ start: '09:00', end: '12:00' }, { start: '13:00', end: '19:00' }])
  await page.getByRole('button', { name: 'Modifier', exact: true }).click()
  await expect(rows).toHaveCount(2)
  await rows.nth(1).getByRole('button', { name: 'Lundi', exact: true }).click()
  await expect(rows.first().getByRole('button', { name: 'Lundi', exact: true })).toHaveAttribute('aria-pressed', 'true')
  await page.getByRole('button', { name: 'Supprimer le créneau 1', exact: true }).click()
  await page.getByRole('button', { name: 'Enregistrer les modifications' }).click()
  await expect(page.locator('form')).toHaveCount(0)
  expect(members[0].workingHours.monday).toEqual([])
  expect(members[0].workingHours.tuesday).toEqual([{ start: '13:00', end: '19:00' }])
})
