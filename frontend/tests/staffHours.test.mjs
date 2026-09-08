import { test } from 'node:test'
import assert from 'node:assert/strict'
import { slotsFromHours, hoursFromSlots, validateSlots } from '../src/utils/staffHours.js'

test('préserve les anciennes plages et les jours indisponibles', () => {
  const slots = slotsFromHours({ monday: { enabled: true, start: '09:00', end: '12:00' }, tuesday: { enabled: false, start: '09:00', end: '18:00' } })
  assert.deepEqual(slots, [{ start: '09:00', end: '12:00', days: ['monday'] }])
  assert.deepEqual(hoursFromSlots(slots).tuesday, [])
  assert.deepEqual(slotsFromHours({}), [])
})
test('création, relecture, modification et suppression de plages avec pause', () => {
  const slots = [{ start: '09:00', end: '12:00', days: ['monday', 'tuesday'] }, { start: '13:00', end: '19:00', days: ['monday', 'tuesday'] }]
  assert.deepEqual(validateSlots(slots), ['', ''])
  assert.deepEqual(slotsFromHours(hoursFromSlots(slots)), slots)
  slots[1].days = ['tuesday']
  assert.equal(hoursFromSlots(slots).monday.length, 1)
  slots.splice(0, 1)
  assert.deepEqual(hoursFromSlots(slots).monday, [])
})
test('refuse les heures invalides, aucun jour et les chevauchements uniquement sur un même jour', () => {
  assert.match(validateSlots([{ start: '12:00', end: '12:00', days: ['monday'] }])[0], /postérieure/)
  assert.match(validateSlots([{ start: '25:00', end: '26:00', days: ['monday'] }])[0], /valides/)
  assert.match(validateSlots([{ start: '09:00', end: '12:00', days: [] }])[0], /au moins un jour/)
  const slots = [{ start: '09:00', end: '12:00', days: ['monday'] }, { start: '11:00', end: '14:00', days: ['monday'] }]
  assert.ok(validateSlots(slots).every((error) => error.includes('Chevauchement')))
  slots[1].days = ['tuesday']
  assert.deepEqual(validateSlots(slots), ['', ''])
  slots[1] = { start: '12:00', end: '14:00', days: ['monday'] }
  assert.deepEqual(validateSlots(slots), ['', ''])
})
