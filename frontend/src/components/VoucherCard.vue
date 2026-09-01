<script setup>
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import StatusBadge from './ui/StatusBadge.vue'
import { formatMoney, formatDate, daysBetween } from '@/utils/format'

const props = defineProps({
  voucher: { type: Object, required: true },
})

const expiresInDays = computed(() => daysBetween(new Date(), props.voucher.expiresAt))
const isExpired = computed(() => props.voucher.status === 'expired')
const isReservable = computed(() => props.voucher.status === 'active')
const isUsedOrReserved = computed(() =>
  ['used', 'reserved', 'awaiting_payment'].includes(props.voucher.status),
)
</script>

<template>
  <div class="card flex flex-col overflow-hidden">
    <div class="flex items-start justify-between gap-3 border-b border-dashed border-slate-200 bg-gradient-to-br from-brand-50 to-white p-5">
      <div>
        <p class="text-xs uppercase tracking-wide text-slate-400">Cheque cadeau</p>
        <p class="font-mono text-lg font-bold tracking-wider text-slate-900">{{ voucher.code }}</p>
        <p class="mt-1 text-sm text-slate-500">{{ voucher.tenantName }}</p>
      </div>
      <StatusBadge :status="voucher.status" />
    </div>

    <div class="flex-1 space-y-3 p-5">
      <div class="flex items-center justify-between">
        <span class="font-semibold text-slate-800">{{ voucher.jumpTypeName }}</span>
        <span class="font-bold text-brand-700">{{ formatMoney(voucher.amount, voucher.currency) }}</span>
      </div>

      <p v-if="voucher.personalMessage" class="rounded-lg bg-slate-50 p-3 text-sm italic text-slate-600">
        « {{ voucher.personalMessage }} »
        <span class="mt-1 block text-xs not-italic text-slate-400">— {{ voucher.purchaserName }}</span>
      </p>

      <div class="flex items-center gap-2 text-sm" :class="isExpired ? 'text-rose-600' : 'text-slate-500'">
        <span>🗓️</span>
        <span v-if="isExpired">Expire le {{ formatDate(voucher.expiresAt, { short: true }) }}</span>
        <span v-else>
          Valable jusqu'au {{ formatDate(voucher.expiresAt, { short: true }) }}
          <span v-if="expiresInDays <= 45" class="font-medium text-amber-600">(J-{{ expiresInDays }})</span>
        </span>
      </div>
    </div>

    <div class="border-t border-slate-100 p-4">
      <RouterLink
        v-if="isReservable"
        :to="{ name: 'beneficiary-schedule', params: { code: voucher.code } }"
        class="btn-primary w-full"
      >
        Choisir un creneau
      </RouterLink>
      <RouterLink
        v-else-if="isExpired"
        :to="{ name: 'beneficiary-expired', params: { code: voucher.code } }"
        class="btn-outline w-full border-rose-200 text-rose-600 hover:bg-rose-50"
      >
        Voir / prolonger
      </RouterLink>
      <RouterLink
        v-else-if="voucher.bookingId"
        :to="{ name: 'boarding-pass', params: { bookingId: voucher.bookingId } }"
        class="btn-outline w-full"
      >
        Voir la reservation
      </RouterLink>
      <button v-else class="btn-ghost w-full" disabled>
        {{ voucher.status === 'awaiting_payment' ? 'Paiement en attente' : isUsedOrReserved ? 'Deja utilise' : 'Indisponible' }}
      </button>
    </div>
  </div>
</template>
