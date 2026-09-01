<script setup>
import { RouterLink } from 'vue-router'
import StatusBadge from './ui/StatusBadge.vue'
import { formatDate, formatTime } from '@/utils/format'

defineProps({
  booking: { type: Object, required: true },
})
</script>

<template>
  <RouterLink
    :to="{ name: 'booking-detail', params: { bookingId: booking.id } }"
    class="card flex items-center gap-4 p-4 transition hover:border-brand-300 hover:shadow-sm"
  >
    <div class="flex h-14 w-14 shrink-0 flex-col items-center justify-center rounded-xl bg-brand-50 text-brand-700">
      <span class="text-lg font-bold leading-none">{{ new Date(booking.slotStart).getDate() }}</span>
      <span class="text-[10px] uppercase">{{ new Date(booking.slotStart).toLocaleDateString('fr-FR', { month: 'short' }) }}</span>
    </div>
    <div class="min-w-0 flex-1">
      <div class="flex items-center gap-2">
        <p class="truncate font-semibold text-slate-800">{{ booking.jumpTypeName }}</p>
        <StatusBadge :status="booking.status" />
      </div>
      <p class="truncate text-sm text-slate-500">
        {{ booking.tenantName }} · {{ formatDate(booking.slotStart, { short: true }) }} a {{ formatTime(booking.slotStart) }}
      </p>
      <p class="text-xs text-slate-400">Ref. {{ booking.reference }}</p>
    </div>
    <span class="text-slate-300">›</span>
  </RouterLink>
</template>
