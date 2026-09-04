import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { splitCustomerBookings } from '../src/utils/customerBookings.js'

test('les rendez-vous sont répartis autour de maintenant et triés sans marge artificielle', () => {
  const now = new Date('2026-09-04T12:00:00Z')
  const bookings = [
    { id: 'future-2', slotStart: '2026-09-05T12:00:00Z' },
    { id: 'past-2', slotStart: '2026-09-03T12:00:00Z' },
    { id: 'now', slotStart: '2026-09-04T12:00:00Z' },
    { id: 'past-1', slotStart: '2026-09-04T11:59:59Z' },
    { id: 'future-1', slotStart: '2026-09-04T12:00:01Z' },
  ]

  const result = splitCustomerBookings(bookings, now)

  assert.deepEqual(result.upcoming.map(({ id }) => id), ['now', 'future-1', 'future-2'])
  assert.deepEqual(result.past.map(({ id }) => id), ['past-1', 'past-2'])
})

test('les lectures de l’espace client utilisent uniquement les endpoints authentifiés', async () => {
  const api = await readFile(new URL('../src/api/httpApi.js', import.meta.url), 'utf8')
  const accountApi = api.slice(api.indexOf('async getCustomerOrders'), api.indexOf('// --- CATALOGUE'))
  const bookingApi = api.slice(api.indexOf('async getBooking(bookingId)'), api.indexOf('async adminLogin('))

  assert.match(accountApi, /customerRequest\('GET', '\/shop\/account\/orders'\)/)
  assert.match(accountApi, /customerRequest\('GET', '\/shop\/account\/bookings'\)/)
  assert.match(bookingApi, /\/shop\/account\/bookings\/\$\{encodeURIComponent\(bookingId\)\}/)
  assert.doesNotMatch(accountApi + bookingApi, /mockApi/)
})
