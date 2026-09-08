import { test } from 'node:test'
import assert from 'node:assert/strict'
import { hydrateHours, validateHours } from '../src/utils/workingHours.js'
const morning = { start: '09:00', end: '12:00', days: ['monday', 'tuesday'] }
const afternoon = { start: '13:00', end: '19:00', days: ['monday', 'tuesday'] }
test('legacy hours are grouped without enabling unavailable days', () => {
  assert.deepEqual(hydrateHours({ monday: { enabled: true, start: '09:00', end: '12:00' }, tuesday: { enabled: true, start: '09:00', end: '12:00' }, sunday: { enabled: false } }), [morning])
  assert.deepEqual(hydrateHours({}), [])
})
test('editing and deleting ranges preserves independent selections', () => {
  const original = [morning, afternoon]
  const edited = hydrateHours(original)
  edited[0].days.pop()
  edited[0].end = '11:00'
  edited.splice(1, 1)
  assert.equal(validateHours(edited), '')
  assert.equal(original[0].days.length, 2)
  assert.equal(original.length, 2)
  assert.equal(validateHours([]), '')
})
test('valid pauses, adjacent ranges and different days are accepted', () => {
  assert.equal(validateHours([morning, afternoon]), '')
  assert.equal(validateHours([morning, { ...afternoon, start: '12:00' }]), '')
  assert.equal(validateHours([morning, { ...morning, days: ['sunday'] }]), '')
})
test('invalid times, empty days and shared-day overlaps are rejected', () => {
  assert.match(validateHours([{ ...morning, end: '09:00' }]), /postérieure/)
  assert.match(validateHours([{ ...morning, start: '24:00' }]), /valides/)
  assert.match(validateHours([{ ...morning, days: [] }]), /au moins un jour/)
  assert.match(validateHours([morning, { ...afternoon, start: '11:00' }]), /chevauchement/)
})
