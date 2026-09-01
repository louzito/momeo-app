<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import api from '@/api'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import Spinner from '@/components/ui/Spinner.vue'
import { formatDate, formatTime, formatMoney } from '@/utils/format'

const route = useRoute()
const booking = ref(null)
const loading = ref(true)

onMounted(async () => {
  try {
    booking.value = await api.getBooking(route.params.bookingId)
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <Spinner v-if="loading" />

  <div v-else-if="booking" class="section py-10">
    <RouterLink :to="{ name: 'account-dashboard' }" class="text-sm text-slate-400 hover:text-brand-600">← Mon compte</RouterLink>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="font-display text-3xl font-bold text-slate-900">{{ booking.jumpTypeName }}</h1>
        <p class="mt-1 text-slate-500">{{ booking.tenantName }} · Ref. {{ booking.reference }}</p>
      </div>
      <StatusBadge :status="booking.status" />
    </div>

    <!-- Bandeau de reprogrammation -->
    <div v-if="booking.status === 'postponed'" class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-5">
      <p class="font-semibold text-amber-800">Rendez-vous a reprogrammer</p>
      <p class="mt-1 text-sm text-amber-700">{{ booking.postponedReason }}</p>
      <p class="mt-2 text-sm text-amber-700">Votre reservation reste valable : choisissez une nouvelle date sans frais.</p>
      <RouterLink
        :to="{ name: 'calendar', params: { slug: booking.tenantSlug } }"
        class="btn-accent mt-4"
      >Choisir une nouvelle date</RouterLink>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
      <div class="card p-6 lg:col-span-2">
        <h2 class="mb-4 font-semibold text-slate-800">Details de la reservation</h2>
        <dl class="grid grid-cols-2 gap-4 text-sm">
          <div>
            <dt class="text-slate-400">Client</dt>
            <dd class="font-semibold text-slate-800">{{ booking.jumperName }}</dd>
          </div>
          <div v-if="booking.weightDeclaredKg">
            <dt class="text-slate-400">Poids declare</dt>
            <dd class="font-semibold text-slate-800">{{ booking.weightDeclaredKg }} kg</dd>
          </div>
          <div>
            <dt class="text-slate-400">Date</dt>
            <dd class="font-semibold capitalize text-slate-800">{{ formatDate(booking.slotStart, { weekday: true, short: true }) }}</dd>
          </div>
          <div>
            <dt class="text-slate-400">Heure</dt>
            <dd class="font-semibold text-slate-800">{{ formatTime(booking.slotStart) }}</dd>
          </div>
          <div>
            <dt class="text-slate-400">Source</dt>
            <dd class="font-semibold text-slate-800">{{ booking.source === 'voucher' ? 'Cheque cadeau' : 'Achat direct' }}</dd>
          </div>
        </dl>

        <div v-if="booking.options?.length" class="mt-5 border-t border-slate-100 pt-4">
          <p class="mb-2 text-sm font-semibold text-slate-700">Options</p>
          <ul class="space-y-1 text-sm">
            <li v-for="o in booking.options" :key="o.name" class="flex justify-between">
              <span class="text-slate-600">{{ o.name }}</span>
              <span class="text-slate-700">{{ formatMoney(o.price, 'USD') }}</span>
            </li>
          </ul>
        </div>
      </div>

      <div class="card flex flex-col p-6">
        <h2 class="mb-3 font-semibold text-slate-800">Confirmation de rendez-vous</h2>
        <p class="flex-1 text-sm text-slate-500">
          Retrouvez ici les informations utiles et le QR code a presenter a votre arrivee.
        </p>
        <RouterLink
          v-if="booking.boardingPassId && booking.status !== 'postponed'"
          :to="{ name: 'boarding-pass', params: { bookingId: booking.id } }"
          class="btn-primary mt-4"
        >Ouvrir la confirmation</RouterLink>
        <p v-else class="mt-4 rounded-lg bg-slate-50 p-3 text-sm text-slate-400">
          Disponible une fois une nouvelle date confirmee.
        </p>
      </div>
    </div>
  </div>
</template>
