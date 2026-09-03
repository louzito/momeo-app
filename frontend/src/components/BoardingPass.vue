<script setup>
import QrCode from './ui/QrCode.vue'
import { formatDate, formatTime } from '@/utils/format'

const props = defineProps({
  pass: { type: Object, required: true },
  tenantName: { type: String, default: '' },
})
</script>

<template>
  <div class="mx-auto max-w-2xl overflow-hidden rounded-3xl bg-white shadow-soft ring-1 ring-slate-200 print:shadow-none">
    <!-- Bandeau -->
    <div class="flex items-center justify-between bg-gradient-to-r from-brand-700 to-brand-500 px-6 py-4 text-white">
      <div>
        <p class="text-xs uppercase tracking-widest text-white/70">Confirmation de rendez-vous</p>
        <p class="font-display text-lg font-bold">{{ tenantName || 'TodaTempo' }}</p>
      </div>
      <span class="text-3xl">✓</span>
    </div>

    <div class="grid gap-6 p-6 sm:grid-cols-[1fr_auto]">
      <div class="space-y-4">
        <div>
          <p class="text-xs uppercase tracking-wide text-slate-400">Client</p>
          <p class="text-lg font-bold text-slate-900">{{ pass.jumperName }}</p>
        </div>
        <div class="grid grid-cols-2 gap-4">
          <div>
            <p class="text-xs uppercase tracking-wide text-slate-400">Prestation</p>
            <p class="font-semibold text-slate-800">{{ pass.jumpTypeName }}</p>
          </div>
          <div>
            <p class="text-xs uppercase tracking-wide text-slate-400">Reference</p>
            <p class="font-mono font-semibold text-slate-800">{{ pass.reference }}</p>
          </div>
          <div>
            <p class="text-xs uppercase tracking-wide text-slate-400">Date</p>
            <p class="font-semibold capitalize text-slate-800">{{ formatDate(pass.slotStart, { short: true }) }}</p>
          </div>
          <div>
            <p class="text-xs uppercase tracking-wide text-slate-400">Heure</p>
            <p class="font-semibold text-slate-800">{{ formatTime(pass.slotStart) }}</p>
          </div>
        </div>
        <div v-if="pass.options?.length">
          <p class="text-xs uppercase tracking-wide text-slate-400">Options</p>
          <p class="text-sm text-slate-700">{{ pass.options.join(', ') }}</p>
        </div>
        <div v-if="pass.checkedInAt" class="inline-flex items-center gap-2 rounded-lg bg-emerald-50 px-3 py-1.5 text-sm font-medium text-emerald-700">
          ✓ Accueil effectué
        </div>
      </div>

      <!-- Souche QR -->
      <div class="flex flex-col items-center justify-center border-slate-200 sm:border-l sm:pl-6">
        <QrCode :value="`${pass.reference}|${pass.jumperName}`" :size="150" />
        <p class="mt-2 text-center text-[11px] text-slate-400">Presentez ce code a l'accueil<br />lors de votre rendez-vous</p>
      </div>
    </div>

    <div class="border-t border-dashed border-slate-200 bg-slate-50 px-6 py-3 text-center text-xs text-slate-400">
      Confirmation generee automatiquement · QR code factice (maquette)
    </div>
  </div>
</template>
