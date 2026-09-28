import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
const source = (await readFile(new URL('../src/api/httpApi.js', import.meta.url), 'utf8'))
  .replace(/^import .*$/gm, '')
const stubs = `const API_BASE='/api/v2', TENANT_SLUG='demo', tenantHeaders=x=>x, displayImageUrl=x=>x, isServiceProductCode=()=>true, sylius={}, customerRequest=()=>{}, readSiteConfigDocument=()=>{};`
const { httpApi: api } = await import(`data:text/javascript;base64,${Buffer.from(stubs + source).toString('base64')}`)

function transport(failAt = '') {
  const calls = []
  globalThis.fetch = async (url, options = {}) => {
    const path = new URL(url, 'http://localhost').pathname.replace('/api/v2', '')
    calls.push({ path, method: options.method || 'GET', body: options.body && JSON.parse(options.body) })
    const fail = path === failAt
    const data = fail ? { error: 'Indisponible' } : path === '/shop/orders' ? { tokenValue: 'token' }
      : path === '/shop/orders/token' ? { payments: [{ id: 1 }], shipments: [] }
      : path.endsWith('/payment-terms') ? { dueNow: 3000 }
      : path.endsWith('/complete') ? { tokenValue: 'token', number: 'ORDER', total: 3000, currencyCode: 'EUR' }
      : path === '/shop/bookings' ? { id: 'booking' } : {}
    return { ok: !fail, status: fail ? 409 : 200, text: async () => JSON.stringify(data) }
  }
  return calls
}
const booking = { kind: 'direct', jumpTypeId: 'service_test', paymentMethod: 'gift_card', giftCardCode: 'ABC',
  jumper: { firstName: 'Client', lastName: 'Test', email: 'client@example.test' },
  slot: { start: '2026-12-10T10:00:00Z', end: '2026-12-10T11:00:00Z' } }

test('le crédit est préparé après l’acompte et réglé après la réservation persistée', async () => {
  const calls = transport()
  const result = await api.createOrder(booking)
  assert.equal(result.booking.id, 'booking')
  const paths = calls.map(c => c.path)
  assert.ok(paths.indexOf('/shop/orders/token/payment-terms') < paths.indexOf('/shop/gift-cards/prepare'))
  assert.ok(paths.indexOf('/shop/gift-cards/prepare') < paths.indexOf('/shop/orders/token/complete'))
  assert.equal(paths.includes('/shop/gift-cards/settle'), false)
  await api.settleGiftCardPayment(result.order.orderToken)
  assert.deepEqual(calls.at(-1).body, { orderToken: 'token' })
  assert.equal(calls.at(-1).path, '/shop/gift-cards/settle')
})

test('un créneau refusé ne provoque aucun débit ni réservation de crédit', async () => {
  const calls = transport('/shop/bookings')
  await assert.rejects(api.createOrder(booking), /Indisponible/)
  assert.equal(calls.some(c => c.path.endsWith('/settle')), false)
})

test('un stock refusé arrête le parcours avant la préparation du cadeau', async () => {
  const calls = transport('/shop/orders/token/physical-fulfillment')
  await assert.rejects(api.createPhysicalOrder({ items: [{ id: 'product', quantity: 1 }], mode: 'pickup', address: {}, giftCardCode: 'ABC' }), /Indisponible/)
  assert.equal(calls.some(c => c.path.startsWith('/shop/gift-cards/')), false)
})

test('le checkout produit conserve le jeton pour reprendre le règlement sans recréer la commande', async () => {
  const calls = transport()
  const result = await api.createPhysicalOrder({ items: [{ id: 'product', quantity: 1 }], mode: 'pickup', address: {}, giftCardCode: 'ABC', paymentMethod: 'gift_card' })
  assert.equal(result.orderToken, 'token')
  await api.settleGiftCardPayment(result.orderToken)
  await api.settleGiftCardPayment(result.orderToken)
  assert.equal(calls.filter(c => c.path === '/shop/orders' && c.method === 'POST').length, 1)
  assert.equal(calls.filter(c => c.path === '/shop/gift-cards/settle').length, 2)
})
