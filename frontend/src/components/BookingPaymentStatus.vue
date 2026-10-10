<script setup>
import { computed } from 'vue'
import { formatMoney } from '@/utils/format'

const props = defineProps({ booking: { type: Object, required: true } })
const payment = computed(() => {
  const b = props.booking
  const details = b.paymentBreakdown
  if (b.paymentState === 'refunded') return { label: 'Paiement remboursé', tone: 'neutral' }
  if (!details && !b.paymentState) return { label: 'Paiement non renseigné', tone: 'neutral' }
  const paid = details?.paidAmount ?? (b.paymentState === 'paid' ? (b.amount ?? 0) : 0)
  const dueNow = Math.max(0, (details?.dueNow ?? b.amount ?? 0) - paid)
  const dueLater = Math.max(0, details?.dueLater ?? b.balanceDue ?? 0)
  const money = (amount) => formatMoney(amount / 100, b.currencyCode || 'EUR')
  if (dueNow > 0) return {
    label: paid > 0 ? 'Paiement incomplet' : 'Paiement en attente', tone: 'warning',
    message: `${money(dueNow)} à recevoir${dueLater > 0 ? ` · Solde au rendez-vous : ${money(dueLater)}` : ''}`,
  }
  if (dueLater > 0) return {
    label: 'Acompte reçu', tone: 'warning',
    message: `${money(paid)} réglés · Solde au rendez-vous : ${money(dueLater)}`,
  }
  if (details || b.paymentState === 'paid' || b.source === 'voucher') return {
    label: 'Paiement reçu', tone: 'success', message: paid > 0 ? `${money(paid)} réglés` : undefined,
  }
  return { label: 'Paiement en attente', tone: 'warning' }
})
</script>

<template>
  <div class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
    <span class="chip" :class="payment.tone === 'warning' ? 'bg-amber-100 text-amber-800' : payment.tone === 'success' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'">{{ payment.label }}</span>
    <span v-if="payment.message" class="text-slate-600">{{ payment.message }}</span>
  </div>
</template>
