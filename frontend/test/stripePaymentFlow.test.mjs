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

test('le retour abandon consulte le serveur et la confirmation dépend de la réservation', () => {
  assert.match(confirmation, /route\.query\.payment === 'cancelled'/)
  assert.match(confirmation, /cancelStripePayment/)
  assert.match(confirmation, /booking\.status === 'confirmed'/)
})


const shop = await readFile(new URL('../src/views/checkout/ShopOrderConfirmation.vue', import.meta.url), 'utf8')
const products = await readFile(new URL('../src/views/PhysicalProducts.vue', import.meta.url), 'utf8')

test('cadeaux et produits utilisent Stripe sans réservation fictive', () => {
  assert.doesNotMatch(payment, /canStripe = computed\([^\n]*!cart.isGift/)
  assert.match(payment, /bookingToken: result.booking\?\.id/)
  assert.match(payment, /getCheckoutPaymentMethods/)
  assert.match(payment, /createStripeCheckoutSession/)
  assert.doesNotMatch(products, /paymentMethod: 'bank_transfer'/)
})

test('le suivi relit le serveur et affiche les instructions de virement', () => {
  assert.match(shop, /getShopOrderPayment\(route.params.orderToken\)/)
  assert.match(shop, /status === 'paid'/)
  assert.doesNotMatch(shop, /payment === 'success'/)
  assert.match(shop, /order.paymentInstructions/)
  assert.match(shop, /order.number/)
})
