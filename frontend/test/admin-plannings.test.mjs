import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import vm from 'node:vm'

const source = await readFile(new URL('../src/views/admin/AdminPlannings.vue', import.meta.url), 'utf8')
const script = source.split('<script setup>')[1].split('</script>')[0].replace(/^import .*$/gm, '')
const context = vm.createContext({
  ref: value => ({ value }),
  computed: getter => ({ get value() { return getter() } }),
  onMounted: () => {},
  watch: () => {},
  useAdminStore: () => ({}),
})
vm.runInContext(`${script}\nglobalThis.summary = planningSummary`, context)

test('le résumé affiche les dates françaises et les accents sans modifier le planning', () => {
  const planning = { days: { '2026-10-30': ['14:00'], '2026-10-01': ['09:00', '11:30'] } }
  const original = JSON.stringify(planning)
  assert.equal(context.summary(planning), '2 jour(s) planifié(s) · 3 créneau(x) · du 01/10/2026 au 30/10/2026')
  assert.equal(JSON.stringify(planning), original)
  assert.equal(context.summary({ days: [] }), 'Aucun jour planifié')
  assert.equal(context.summary({ openDays: [1, 3], times: ['09:00'] }), 'Ancien format hebdo : Lun Mer · 09:00')
})
