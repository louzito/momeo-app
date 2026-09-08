import { expect, test } from '@playwright/test'

const json = (route, body, status = 200) => route.fulfill({ status, contentType: 'application/ld+json', body: JSON.stringify(body) })

// Le contrat d'inscription est celui de Sylius 2.2 : POST /shop/customers avec
// `password` (pas `plainPassword`), sans le telephone — que le front pose
// ensuite sur la fiche client, une fois la session ouverte.
async function registrationApi(page) {
  const calls = []
  await page.route('**/api/v2/**', async (route) => {
    const request = route.request()
    const url = new URL(request.url())
    const call = { path: url.pathname, method: request.method(), body: request.postDataJSON?.() ?? null }
    calls.push(call)
    if (url.pathname.endsWith('/shop/customers') && request.method() === 'POST') {
      if (call.body?.password === undefined) {
        return json(route, { detail: 'Request does not have the following required fields specified: password.' }, 422)
      }
      return route.fulfill({ status: 204, body: '' })
    }
    if (url.pathname.endsWith('/shop/customers/token')) return json(route, { token: 'jwt-e2e' })
    if (url.pathname.endsWith('/shop/account/profile')) return json(route, { id: 42, email: 'ada@example.test', firstName: 'Ada', lastName: 'Lovelace', phone: '' })
    if (url.pathname.includes('/shop/customers/')) return json(route, { id: 42 })
    if (url.pathname.endsWith('/shop/channels')) return json(route, { member: [{ code: 'WEB', name: 'Cabinet E2E', baseCurrency: { code: 'EUR' } }] })
    if (url.pathname.includes('/shop/taxons/')) return json(route, {}, 404)
    return json(route, { member: [] })
  })
  return calls
}

test("l'inscription client envoie le mot de passe attendu par Sylius puis enregistre le telephone", async ({ page }) => {
  const calls = await registrationApi(page)
  await page.goto('/centre-e2e/account/login')
  await page.getByRole('button', { name: 'Inscription' }).click()
  await page.getByLabel('Prenom').fill('Ada')
  await page.getByLabel('Nom', { exact: true }).fill('Lovelace')
  await page.getByLabel('Email').fill('ada@example.test')
  await page.getByLabel('Mot de passe').fill('Motdepasse1!')
  await page.getByLabel('Telephone (optionnel)').fill('0600000000')
  await page.getByRole('button', { name: 'Creer mon compte' }).click()

  await expect(page).toHaveURL(/\/centre-e2e\/account$/)

  const registration = calls.find((call) => call.method === 'POST' && call.path.endsWith('/shop/customers'))
  expect(registration).toBeTruthy()
  expect(registration.body).toMatchObject({
    email: 'ada@example.test',
    password: 'Motdepasse1!',
    firstName: 'Ada',
    lastName: 'Lovelace',
  })
  expect(registration.body.plainPassword).toBeUndefined()
  expect(registration.body.phoneNumber).toBeUndefined()

  const phoneUpdate = calls.find((call) => call.method === 'PUT' && call.path.endsWith('/shop/customers/42'))
  expect(phoneUpdate?.body).toEqual({ phoneNumber: '0600000000' })
})
