import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'

const orders = await readFile(new URL('../src/views/admin/AdminOrders.vue', import.meta.url), 'utf8')
const api = await readFile(new URL('../src/api/adminApi.js', import.meta.url), 'utf8')

test('admin orders offers bounded full or partial refunds', () => {
  assert.doesNotMatch(orders, /window\.prompt/)
  assert.match(orders, /requestedAmount\.value <= selectedPayment\.value\.refundableAmount/)
  assert.match(orders, /api\.refundPayment/)
  assert.match(orders, /partially_refunded/)
  assert.match(orders, /creditNoteNumber/)
})

test('refund request carries an idempotency key', () => {
  assert.match(api, /idempotencyKey/)
  assert.match(api, /\/refunds/)
})

const { refundAmount } = await import('../src/utils/refunds.js')
test('refund amounts accept French decimals and reject rounding, exponents and invalid values', () => {
  for (const [value, expected] of [['25,50', 2550], ['0.01', 1], [' 100 ', 10000], ['25.5', 2550]]) {
    assert.equal(refundAmount(value), expected)
  }
  for (const value of ['', '0', '-1', '1.001', '1e2', 'NaN', 'Infinity', '1,2,3', '9007199254740991']) {
    assert.equal(refundAmount(value), null)
  }
})
