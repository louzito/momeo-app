<script setup>
import { ref, onMounted, computed } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { useSessionStore } from '@/stores/session'
import api from '@/api'
import BookingCard from '@/components/BookingCard.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Spinner from '@/components/ui/Spinner.vue'
import { formatDate, formatMoney } from '@/utils/format'

const session = useSessionStore()
const router = useRouter()
const orders = ref([])
const bookings = ref([])
const loading = ref(true)

function logout() {
  session.logout()
  router.replace({ name: 'account-login' })
}

const upcoming = computed(() =>
  bookings.value
    .filter((b) => ['confirmed', 'postponed'].includes(b.status) && new Date(b.slotStart) >= new Date(Date.now() - 864e5))
    .sort((a, b) => new Date(a.slotStart) - new Date(b.slotStart)),
)
const past = computed(() =>
  bookings.value
    .filter((b) => b.status === 'completed' || new Date(b.slotStart) < new Date(Date.now() - 864e5))
    .sort((a, b) => new Date(b.slotStart) - new Date(a.slotStart)),
)

onMounted(async () => {
  ;[orders.value, bookings.value] = await Promise.all([
    api.getCustomerOrders(),
    api.getCustomerBookings(),
  ])
  loading.value = false
})
</script>

<template>
  <div class="section py-10">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="font-display text-3xl font-bold text-slate-900">Bonjour {{ session.customer.firstName }} 👋</h1>
        <p class="mt-1 text-slate-500">{{ session.customer.email }}</p>
      </div>
      <button class="btn-ghost" @click="logout">Se deconnecter</button>
    </div>

    <Spinner v-if="loading" />

    <template v-else>
      <!-- Reservations a venir -->
      <section class="mt-8">
        <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-400">Reservations a venir</h2>
        <div v-if="upcoming.length" class="grid gap-3">
          <BookingCard v-for="b in upcoming" :key="b.id" :booking="b" />
        </div>
        <EmptyState v-else icon="🗓️" title="Aucune reservation a venir" message="Choisissez votre prochaine prestation des maintenant.">
          <RouterLink :to="{ name: 'tenant-home' }" class="btn-primary">Voir les prestations</RouterLink>
        </EmptyState>
      </section>

      <!-- Historique des reservations -->
      <section v-if="past.length" class="mt-10">
        <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-400">Rendez-vous passes</h2>
        <div class="grid gap-3">
          <BookingCard v-for="b in past" :key="b.id" :booking="b" />
        </div>
      </section>

      <!-- Commandes -->
      <section class="mt-10">
        <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-400">Historique des commandes</h2>
        <div class="card divide-y divide-slate-100">
          <div v-for="o in orders" :key="o.id" class="flex flex-wrap items-center justify-between gap-3 p-4">
            <div>
              <p class="font-mono font-semibold text-slate-800">{{ o.number }}</p>
              <p class="text-sm text-slate-400">
                {{ formatDate(o.createdAt, { short: true }) }} ·
                {{ o.kind === 'gift' ? '🎁 Cheque cadeau' : 'Achat direct' }}
              </p>
            </div>
            <div class="flex items-center gap-3">
              <StatusBadge :status="o.status" />
              <span class="font-semibold text-slate-800">{{ formatMoney(o.total, o.currency) }}</span>
            </div>
          </div>
        </div>
      </section>
    </template>
  </div>
</template>
