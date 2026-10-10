import test from 'node:test'
import assert from 'node:assert/strict'
import vm from 'node:vm'
import { readFile } from 'node:fs/promises'

const source = await readFile(new URL('../src/api/adminApi.js', import.meta.url), 'utf8')
const section = source.slice(source.indexOf('export async function getTaxConfiguration()'), source.indexOf('export async function getProductTaxCategory(')).replaceAll('export ', '')
function setup(request) {
  const context = { request, crypto: { randomUUID: () => '00000000-0000-0000-0000-000000000001' } }
  vm.createContext(context)
  vm.runInContext(section, context)
  return context
}
const form = { name: 'TVA standard', percentage: 20, zone: '/api/v2/admin/zones/FR', includedInPrice: true }

test('creating a rate creates its category and converts percent to a fraction', async () => {
  const calls = []
  const api = setup(async (...args) => { calls.push(args); return { '@id': '/api/v2/admin/tax-categories/new' } })
  await api.saveTaxRate(form)
  assert.equal(calls[0][1], '/admin/tax-categories')
  assert.equal(calls[1][1], '/admin/tax-rates')
  assert.equal(calls[1][2].amount, 0.2)
  assert.equal(calls[1][2].category, '/api/v2/admin/tax-categories/new')
  assert.equal(calls[1][2].includedInPrice, true)
  assert.equal(calls[1][2].calculator, 'default')
})

test('editing retains the existing category and does not create another one', async () => {
  const calls = []
  await setup(async (...args) => { calls.push(args) }).saveTaxRate({ ...form, code: 'reduced', category: '/api/v2/admin/tax-categories/reduced', percentage: 5.5 })
  assert.equal(calls.length, 1)
  assert.equal(calls[0][0], 'PUT')
  assert.equal(calls[0][2].amount, 0.055)
})

test('a failed creation cleans up its new category', async () => {
  const calls = []
  const api = setup(async (...args) => {
    calls.push(args)
    if (args[1] === '/admin/tax-rates') throw new Error('Zone invalide')
    return { '@id': '/api/v2/admin/tax-categories/new' }
  })
  await assert.rejects(api.saveTaxRate(form), /Zone invalide/)
  assert.equal(calls[2][0], 'DELETE')
})

test('invalid percentages and date ranges do not write anything', async () => {
  const api = setup(async () => { assert.fail('Unexpected write') })
  for (const percentage of [-1, 101, 'invalid']) await assert.rejects(api.saveTaxRate({ ...form, percentage }))
  await assert.rejects(api.saveTaxRate({ ...form, startDate: '2026-10-20', endDate: '2026-10-10' }))
})
