import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import vm from 'node:vm'
import { computed } from 'vue'

const source = await readFile(new URL('../src/components/BookingPaymentStatus.vue', import.meta.url), 'utf8')
const script = source.match(/<script setup>([\s\S]*?)<\/script>/)[1].replace(/^import .*$/gm, '')
function status(booking) {
  const context = { computed, defineProps: () => ({ booking }), formatMoney: (amount) => `${amount.toFixed(2)} €` }
  vm.createContext(context)
  vm.runInContext(`${script}\nglobalThis.result = payment.value`, context)
  return context.result
}

test('an unpaid deposit is marked pending with the later balance kept separate', () => {
  const value = status({ paymentState: 'awaiting_payment', amount: 1680, balanceDue: 3920, paymentBreakdown: { paidAmount: 0, dueNow: 1680, dueLater: 3920 } })
  assert.equal(value.label, 'Paiement en attente')
  assert.equal(value.message, '16.80 € à recevoir · Solde au rendez-vous : 39.20 €')
})

test('the received transfer overrides the stale booking payment state', () => {
  const value = status({ paymentState: 'awaiting_payment', paymentBreakdown: { paidAmount: 1680, dueNow: 1680, dueLater: 3920 } })
  assert.equal(value.label, 'Acompte reçu')
  assert.equal(value.message, '16.80 € réglés · Solde au rendez-vous : 39.20 €')
})

test('full and partial payments have distinct labels', () => {
  const booking = { paymentState: 'awaiting_payment', paymentBreakdown: { paidAmount: 5600, dueNow: 5600, dueLater: 0 } }
  assert.equal(status(booking).label, 'Paiement reçu')
  booking.paymentBreakdown.paidAmount = 1680
  assert.equal(status(booking).label, 'Paiement incomplet')
  assert.equal(status(booking).message, '39.20 € à recevoir')
})

test('manual bookings without payment information are not reported as paid', () => {
  assert.equal(status({ source: 'manual', paymentState: null }).label, 'Paiement non renseigné')
})
