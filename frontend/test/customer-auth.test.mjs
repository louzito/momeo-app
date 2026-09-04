import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const auth = readFileSync(new URL('../src/api/customerAuth.js', import.meta.url), 'utf8')
const session = readFileSync(new URL('../src/stores/session.js', import.meta.url), 'utf8')
const api = readFileSync(new URL('../src/api/httpApi.js', import.meta.url), 'utf8')
const login = readFileSync(new URL('../src/views/account/AccountLogin.vue', import.meta.url), 'utf8')
const router = readFileSync(new URL('../src/router/index.js', import.meta.url), 'utf8')

test('le JWT client est isolé par tenant et ne persiste pas dans localStorage', () => {
  assert.match(auth, /sessionStorage\.setItem\(TOKEN_KEY, token\)/)
  assert.match(auth, /todatempo\.customer\.jwt\.\$\{TENANT_SLUG\}/)
  assert.doesNotMatch(session, /localStorage|fixtures|skybook\.session/)
})

test('les appels privés transmettent le JWT et purgent une session refusée', () => {
  assert.match(auth, /headers\.Authorization = `Bearer \$\{token\}`/)
  assert.match(auth, /response\.status === 401/)
  assert.match(api, /\/shop\/account\/bookings/)
  assert.doesNotMatch(api.slice(api.indexOf('async getCustomerBookings'), api.indexOf('async getPaymentMethods')), /mockApi\.getCustomer/)
})

test('aucune identité de démonstration et le mot de passe oublié est disponible', () => {
  assert.doesNotMatch(login, /client@todatempo\.test|fillDemo|Compte de demonstration/)
  assert.match(login, /requestPasswordReset/)
})

test('les écrans exposant une réservation nécessitent une session client', () => {
  assert.match(router, /name: 'booking-detail',[\s\S]*?requiresCustomer: true/)
  assert.match(router, /name: 'boarding-pass',[\s\S]*?requiresCustomer: true/)
  assert.match(router, /name: 'checkout-confirmation',[\s\S]*?requiresCustomer: true/)
})
