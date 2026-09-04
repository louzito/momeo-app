import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'

const orders = await readFile(new URL('../src/views/admin/AdminOrders.vue', import.meta.url), 'utf8')
const api = await readFile(new URL('../src/api/adminApi.js', import.meta.url), 'utf8')

test('admin orders offers bounded full or partial refunds', () => {
  assert.match(orders, /maximum = Math\.max/)
  assert.match(orders, /api\.refundPayment/)
  assert.match(orders, /partially_refunded/)
  assert.match(orders, /creditNoteNumber/)
})

test('refund request carries an idempotency key', () => {
  assert.match(api, /idempotencyKey/)
  assert.match(api, /\/refunds/)
})
