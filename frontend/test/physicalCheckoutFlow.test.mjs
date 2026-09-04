import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'

const api = await readFile(new URL('../src/api/httpApi.js', import.meta.url), 'utf8')
const view = await readFile(new URL('../src/views/PhysicalProducts.vue', import.meta.url), 'utf8')

test('le checkout physique utilise un panier et ne crée aucun rendez-vous', () => {
  const start = api.indexOf('async createPhysicalOrder(payload)')
  const end = api.indexOf('\n  },', start)
  const checkout = api.slice(start, end)
  assert.match(checkout, /payload\.items/)
  assert.match(checkout, /physical-fulfillment/)
  assert.doesNotMatch(checkout, /createPersistentBooking|\/shop\/bookings/)
})

test('le checkout physique collecte remise, adresse et affiche les frais', () => {
  assert.match(view, /pickup/)
  assert.match(view, /delivery/)
  assert.match(view, /customer\.street/)
  assert.match(view, /deliveryFee/)
  assert.match(view, /product\.stock/)
})
