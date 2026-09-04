<script setup>
import { ref, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { useAdminStore } from '@/stores/admin'
import api from '@/api'
import Spinner from '@/components/ui/Spinner.vue'
import { formatMoney, isoDay } from '@/utils/format'

const admin = useAdminStore()
const stats = ref(null)
const loading = ref(true)
const error = ref('')
const timezone = ref(Intl.DateTimeFormat().resolvedOptions().timeZone || 'Europe/Paris')
const from = ref(isoDay(new Date()))
const tomorrow = new Date()
tomorrow.setDate(tomorrow.getDate() + 1)
const to = ref(isoDay(tomorrow))

async function load() {
  loading.value = true
  error.value = ''
  try {
    stats.value = await api.getAdminOverview(admin.tenantId, { from: from.value, to: to.value, timezone: timezone.value })
  } catch (e) {
    stats.value = null
    error.value = e?.message || 'Impossible de charger les indicateurs.'
  } finally {
    loading.value = false
  }
}
onMounted(load)
</script>

<template>
  <div>
    <h1 class="font-display text-2xl font-bold text-slate-900">Tableau de bord</h1>
    <p class="mt-1 text-slate-500">Indicateurs réels de {{ admin.tenant?.name }}.</p>

    <form class="card mt-6 grid gap-4 p-4 sm:grid-cols-4" @submit.prevent="load">
      <label class="text-sm text-slate-600">Du (inclus)<input v-model="from" type="date" required class="input mt-1 w-full" /></label>
      <label class="text-sm text-slate-600">Au (exclu)<input v-model="to" type="date" required class="input mt-1 w-full" /></label>
      <label class="text-sm text-slate-600">Fuseau horaire<input v-model="timezone" type="text" required class="input mt-1 w-full" placeholder="Europe/Paris" /></label>
      <button class="btn-primary self-end" type="submit" :disabled="loading">Actualiser</button>
    </form>

    <p v-if="error" class="mt-4 rounded-xl bg-rose-50 p-4 text-sm text-rose-700">{{ error }}</p>
    <Spinner v-if="loading" />
    <template v-else-if="stats">
      <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div class="card p-5"><p class="text-sm text-slate-400">Rendez-vous</p><p class="mt-1 text-2xl font-bold text-brand-700">{{ stats.appointments }}</p><RouterLink :to="{ name: 'admin-bookings' }" class="text-xs text-brand-600 hover:underline">Voir le planning →</RouterLink></div>
        <div class="card p-5"><p class="text-sm text-slate-400">Chiffre d'affaires encaissé</p><p class="mt-1 text-2xl font-bold text-slate-900">{{ formatMoney(stats.paidRevenue / 100, stats.currency) }}</p><p class="text-xs text-slate-400">Paiements encaissés et cartes activées</p></div>
        <div class="card p-5"><p class="text-sm text-slate-400">Taux d'occupation</p><p class="mt-1 text-2xl font-bold text-emerald-600">{{ stats.occupancyRate }} %</p><p class="text-xs text-slate-400">{{ stats.occupiedMinutes }} / {{ stats.capacityMinutes }} minutes-capacité</p></div>
        <div class="card p-5"><p class="text-sm text-slate-400">Annulations / absences</p><p class="mt-1 text-2xl font-bold text-rose-600">{{ stats.cancelled }} / {{ stats.noShows }}</p><p class="text-xs text-slate-400">Selon la date du rendez-vous</p></div>
        <div class="card p-5"><p class="text-sm text-slate-400">Nouveaux clients</p><p class="mt-1 text-2xl font-bold text-slate-900">{{ stats.newClients }}</p><p class="text-xs text-slate-400">Première réservation créée sur la plage</p></div>
        <div class="card p-5"><p class="text-sm text-slate-400">Cartes cadeaux vendues</p><p class="mt-1 text-2xl font-bold text-violet-700">{{ stats.giftCards.sold }}</p><p class="text-xs text-slate-400">{{ formatMoney(stats.giftCards.paidAmount / 100, stats.currency) }} encaissés</p></div>
      </div>
      <div class="card mt-6 p-6">
        <div class="mb-4 flex items-center justify-between"><h2 class="font-semibold text-slate-800">État des cartes cadeaux</h2><RouterLink :to="{ name: 'admin-vouchers' }" class="text-xs text-brand-600 hover:underline">Voir tout →</RouterLink></div>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <div class="rounded-xl bg-amber-50 p-3 text-center"><p class="text-xl font-bold text-amber-700">{{ stats.giftCards.awaitingPayment }}</p><p class="text-xs text-amber-600">En attente</p></div>
          <div class="rounded-xl bg-emerald-50 p-3 text-center"><p class="text-xl font-bold text-emerald-700">{{ stats.giftCards.active }}</p><p class="text-xs text-emerald-600">Actives</p></div>
          <div class="rounded-xl bg-slate-100 p-3 text-center"><p class="text-xl font-bold text-slate-700">{{ stats.giftCards.used }}</p><p class="text-xs text-slate-500">Utilisées</p></div>
          <div class="rounded-xl bg-rose-50 p-3 text-center"><p class="text-xl font-bold text-rose-700">{{ stats.giftCards.expired }}</p><p class="text-xs text-rose-600">Périmées</p></div>
        </div>
      </div>
    </template>
  </div>
</template>
