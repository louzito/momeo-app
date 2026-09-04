import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'

const api = await readFile(new URL('../src/api/httpApi.js', import.meta.url), 'utf8')
const payment = await readFile(new URL('../src/views/checkout/Payment.vue', import.meta.url), 'utf8')
const confirmation = await readFile(new URL('../src/views/checkout/OrderConfirmation.vue', import.meta.url), 'utf8')

test('Stripe Checkout utilise une vraie commande et une URL serveur', () => {
  assert.match(api, /stripe_web_elements/)
  assert.match(api, /createStripeCheckoutSession/)
  assert.match(payment, /window\.location\.assign\(stripe\.url\)/)
  assert.doesNotMatch(`${api}\n${payment}`, /paid_demo|card_demo/)
})

test('le retour abandon annule avant affichage et masque la confirmation', () => {
  assert.match(confirmation, /route\.query\.payment === 'cancelled'/)
  assert.match(confirmation, /cancelStripePayment/)
  assert.match(confirmation, /booking\.status === 'confirmed'/)
})
