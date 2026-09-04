<script setup>
import { computed } from 'vue'
import { formatMoney, formatDate, formatTime } from '@/utils/format'

// Recapitulatif de prix reutilisable (panier, paiement, confirmation).
const props = defineProps({
  jumpType: { type: Object, default: null },
  options: { type: Array, default: () => [] },
  slot: { type: Object, default: null },
  currency: { type: String, default: 'USD' },
  kind: { type: String, default: 'direct' },
  gift: { type: Object, default: null },
})

const total = computed(
  () => (props.jumpType?.basePrice || 0) + props.options.reduce((s, o) => s + o.price, 0),
)
const dueNow = computed(() => {
  const cents = Math.round(total.value * 100)
  const mode = props.jumpType?.paymentMode || 'full'
  const value = Number(props.jumpType?.paymentValue) || 0
  if (mode === 'none') return 0
  if (mode === 'fixed') return Math.min(cents, Math.round(value * 100)) / 100
  if (mode === 'percentage') return Math.min(cents, Math.floor((cents * Math.round(value) + 50) / 100)) / 100
  return cents / 100
})
const balanceDue = computed(() => (Math.round(total.value * 100) - Math.round(dueNow.value * 100)) / 100)
</script>

<template>
  <div class="card p-6">
    <h3 class="mb-4 font-display text-lg font-bold text-slate-900">Recapitulatif</h3>

    <div v-if="jumpType" class="flex items-center justify-between border-b border-slate-100 pb-3">
      <div>
        <p class="font-semibold text-slate-800">{{ jumpType.name }}</p>
        <p v-if="kind === 'gift'" class="text-xs text-accent-600">🎁 Cheque cadeau</p>
      </div>
      <span class="font-semibold text-slate-800">{{ formatMoney(jumpType.basePrice, currency) }}</span>
    </div>

    <div v-if="slot" class="flex items-center gap-2 border-b border-slate-100 py-3 text-sm text-slate-600">
      <span>🗓️</span>
      <span class="capitalize">{{ formatDate(slot.start, { weekday: true, short: true }) }} · {{ formatTime(slot.start) }}</span>
    </div>

    <div v-if="kind === 'gift' && gift?.email" class="flex items-center gap-2 border-b border-slate-100 py-3 text-sm text-slate-600">
      <span>👤</span>
      <span>Pour {{ gift.name || gift.email }}</span>
    </div>

    <ul v-if="options.length" class="divide-y divide-slate-100 py-1">
      <li v-for="opt in options" :key="opt.id" class="flex items-center justify-between py-2 text-sm">
        <span class="text-slate-600">{{ opt.name }}</span>
        <span class="text-slate-700">+{{ formatMoney(opt.price, currency) }}</span>
      </li>
    </ul>

    <div class="mt-4 flex items-center justify-between border-t border-slate-200 pt-4">
      <span class="font-semibold text-slate-900">Total</span>
      <span class="font-display text-2xl font-bold text-brand-700">{{ formatMoney(total, currency) }}</span>
    </div>
    <div v-if="kind === 'direct'" class="mt-3 space-y-2 rounded-xl bg-brand-50 p-3 text-sm">
      <div class="flex justify-between font-semibold text-brand-800">
        <span>Montant dû maintenant</span><span>{{ formatMoney(dueNow, currency) }}</span>
      </div>
      <div class="flex justify-between text-slate-600">
        <span>Solde à régler sur place</span><span>{{ formatMoney(balanceDue, currency) }}</span>
      </div>
    </div>

    <slot />
  </div>
</template>
