import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'

const apiSource = await readFile(new URL('../src/api/httpApi.js', import.meta.url), 'utf8')
const paymentSource = await readFile(new URL('../src/views/checkout/Payment.vue', import.meta.url), 'utf8')
const confirmationSource = await readFile(new URL('../src/views/checkout/OrderConfirmation.vue', import.meta.url), 'utf8')
const routerSource = await readFile(new URL('../src/router/index.js', import.meta.url), 'utf8')

test('le checkout direct ne crée plus de commande ou paiement mock', () => {
  const createOrder = apiSource.slice(apiSource.indexOf('async createOrder(payload)'), apiSource.indexOf('async _createGiftOrder(payload)'))
  assert.doesNotMatch(createOrder, /mockApi\.createOrder|paid_demo/)
  assert.doesNotMatch(paymentSource, /processPayment|card_demo|4242/)
})

test('la confirmation est adressable par le jeton de réservation persisté', () => {
  assert.match(routerSource, /path: '\/checkout\/confirmation\/:bookingId'/)
  assert.match(paymentSource, /params\.bookingId = result\.booking\.id/)
  assert.match(confirmationSource, /api\.getBooking\(route\.params\.bookingId\)/)
})

test('une réservation introuvable ne retombe jamais sur les fixtures', () => {
  const getBooking = apiSource.slice(apiSource.indexOf('async getBooking(bookingId)'), apiSource.indexOf('async getBoardingPass(bookingId)'))
  const getBoardingPass = apiSource.slice(apiSource.indexOf('async getBoardingPass(bookingId)'), apiSource.indexOf('async adminLogin('))
  assert.doesNotMatch(getBooking, /mockApi/)
  assert.doesNotMatch(getBoardingPass, /mockApi/)
  assert.match(confirmationSource, /v-else-if="error"/)
})

test('la création persistante transmet le jeton de la commande réelle', () => {
  assert.match(apiSource, /orderToken: completed\.tokenValue/)
  assert.match(apiSource, /orderToken: commercial\.orderToken \|\| null/)
})
