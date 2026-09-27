import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { createPinia, setActivePinia } from 'pinia'

const source = (await readFile(new URL('../src/stores/cart.js', import.meta.url), 'utf8'))
  .replace("from 'pinia'", `from '${import.meta.resolve('pinia')}'`)
  .replace("import api from '@/api'", 'export const api = {}')
const { useCartStore, api } = await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`)
const makeCart = () => {
  setActivePinia(createPinia())
  const cart = useCartStore()
  cart.startPurchase('tenant-a', { id: 'service', basePrice: 100, paymentMode: 'percentage', paymentValue: 30 })
  cart.setSlot({ id: 'slot', start: '2026-10-15T10:00:00Z' })
  return cart
}

test('options obligatoires, retour au créneau et acompte conservent leurs montants', () => {
  const cart = makeCart()
  const mandatory = { id: 'required', scope: 'PER_ORDER', price: 10, mandatory: true }
  const optional = { id: 'optional', scope: 'PER_JUMP', price: 20 }
  cart.ensureMandatoryOptions([mandatory])
  cart.toggleOption(optional)
  cart.ensureMandatoryOptions([mandatory])
  cart.toggleOption(mandatory)
  cart.setSlot({ id: 'other-slot' })
  assert.equal(cart.total, 130)
  assert.equal(cart.dueNow, 39)
  assert.equal(cart.balanceDue, 91)
  cart.toggleOption(optional)
  assert.equal(cart.total, 110)
  cart.jumpType.paymentMode = 'none'
  assert.equal(cart.dueNow, 0)
  assert.equal(cart.balanceDue, 110)
  cart.jumpType.paymentMode = 'fixed'
  cart.jumpType.paymentValue = 25
  assert.equal(cart.dueNow, 25)
})

test('double clic et nouvelle tentative Stripe réutilisent la même commande', async () => {
  const cart = makeCart()
  let calls = 0
  let resolve
  api.createOrder = (payload) => {
    calls++
    assert.equal(payload.kind, 'direct')
    assert.equal(payload.slotId, 'slot')
    return new Promise((done) => { resolve = done })
  }
  const first = cart.checkout()
  const second = cart.checkout()
  assert.equal(calls, 1)
  const result = { booking: { id: 'persisted' }, order: { id: 'order' } }
  resolve(result)
  assert.deepEqual(await first, result)
  assert.deepEqual(await second, result)
  assert.deepEqual(await cart.checkout(), result)
  assert.equal(calls, 1)
})

test('une erreur libère le verrou et un nouvel achat efface la commande précédente', async () => {
  const cart = makeCart()
  api.createOrder = async () => { throw new Error('Indisponible') }
  await assert.rejects(cart.checkout(), /Indisponible/)
  api.createOrder = async () => ({ booking: { id: 'ok' } })
  assert.equal((await cart.checkout()).booking.id, 'ok')
  cart.startPurchase('tenant-b', { id: 'another', basePrice: 50 })
  assert.equal(cart.lastResult, null)
  assert.equal(cart.slot, null)
  assert.equal(cart.kind, 'direct')
  assert.equal(cart.selectedOptions.length, 0)
})
