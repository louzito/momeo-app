import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

test('public waitlist requires explicit consent and explains no automatic booking', () => {
  const source = readFileSync(new URL('../src/views/checkout/ScheduleStep.vue', import.meta.url), 'utf8')
  assert.match(source, /v-model="waitlist\.consent" required type="checkbox"/)
  assert.match(source, /sans la réserver automatiquement/)
  assert.match(source, /api\.joinWaitlist/)
})

test('waitlist is exposed in tenant admin with an unsubscribe action', () => {
  const view = readFileSync(new URL('../src/views/admin/AdminWaitlist.vue', import.meta.url), 'utf8')
  const router = readFileSync(new URL('../src/router/index.js', import.meta.url), 'utf8')
  assert.match(view, /unsubscribeWaitlistEntry/)
  assert.match(router, /admin-waitlist/)
  assert.match(router, /waitlist-unsubscribe/)
})
