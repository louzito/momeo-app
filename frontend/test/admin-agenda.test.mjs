import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import vm from 'node:vm'

const source = await readFile(new URL('../src/views/admin/AdminAgenda.vue', import.meta.url), 'utf8')
const script = source.split('<script setup>')[1].split('</script>')[0].replace(/^import .*$/gm, '')

function setup(day = 10) {
  class FixedDate extends Date {
    constructor(...args) {
      super(...(args.length ? args : [2026, 9, day, 15]))
    }
  }
  const context = vm.createContext({
    Date: FixedDate,
    ref: value => ({ value }),
    computed: getter => ({ get value() { return getter() } }),
    onMounted: () => {},
    nextTick: async () => {},
    useAdminStore: () => ({ tenantId: 'salon' }),
    api: Object.fromEntries(['getTenantBookings', 'getStaffMembers', 'getJumpTypes', 'getStaffTimeOffs'].map(name => [name, async () => []])),
  })
  vm.runInContext(`${script}\nglobalThis.view = { anchor, viewMode, bookingForm, timeOffForm, agendaScroll, openBooking, openTimeOff, changeMode, move, today, load, dateKey };`, context)
  return context.view
}

for (const day of [10, 11]) {
  test(`les boutons du haut et la vue Jour utilisent aujourd’hui le ${day} octobre`, async () => {
    const view = setup(day)
    view.openBooking()
    view.openTimeOff()
    assert.equal(view.bookingForm.value.date, `2026-10-${day}`)
    assert.equal(view.timeOffForm.value.date, `2026-10-${day}`)
    await view.changeMode('day')
    assert.equal(view.dateKey(view.anchor.value), `2026-10-${day}`)
    await view.changeMode('week')
    assert.equal(view.dateKey(view.anchor.value), '2026-10-05')
  })
}

test('le bouton de colonne garde sa date explicite', () => {
  const view = setup()
  view.openBooking(new Date(2026, 9, 7))
  assert.equal(view.bookingForm.value.date, '2026-10-07')
})

test('une autre semaine utilise son premier jour, et Aujourd’hui revient à la date courante', async () => {
  const view = setup()
  await view.move(1)
  view.openBooking()
  view.openTimeOff()
  assert.equal(view.bookingForm.value.date, '2026-10-12')
  assert.equal(view.timeOffForm.value.date, '2026-10-12')
  await view.changeMode('day')
  assert.equal(view.dateKey(view.anchor.value), '2026-10-12')
  view.openBooking()
  assert.equal(view.bookingForm.value.date, '2026-10-12')
  await view.today()
  assert.equal(view.dateKey(view.anchor.value), '2026-10-10')
})

test('le chargement rend la colonne courante visible sans déplacement vertical', async () => {
  const view = setup()
  let bounds = { left: 900, right: 1050 }
  const container = {
    scrollLeft: 0,
    scrollTop: 42,
    getBoundingClientRect: () => ({ left: 0, right: 700 }),
    querySelector: selector => {
      assert.equal(selector, '[data-today="true"]')
      return { getBoundingClientRect: () => bounds }
    },
  }
  view.agendaScroll.value = container
  await view.load()
  assert.equal(container.scrollLeft, 350)
  assert.equal(container.scrollTop, 42)
  bounds = { left: 400, right: 550 }
  await view.load()
  assert.equal(container.scrollLeft, 350)
  bounds = { left: -50, right: 100 }
  await view.load()
  assert.equal(container.scrollLeft, 300)
  await view.changeMode('day')
  assert.equal(container.scrollLeft, 300)
  container.querySelector = () => null
  await view.changeMode('week')
  assert.equal(container.scrollLeft, 300)
})
