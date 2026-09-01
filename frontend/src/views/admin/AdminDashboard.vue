<script setup>
import { ref, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { useAdminStore } from '@/stores/admin'
import api from '@/api'
import Spinner from '@/components/ui/Spinner.vue'
import { formatMoney } from '@/utils/format'

const admin = useAdminStore()
const stats = ref(null)
const loading = ref(true)

onMounted(async () => {
  stats.value = await api.getAdminOverview(admin.tenantId)
  loading.value = false
})
</script>

<template>
  <div>
    <h1 class="font-display text-2xl font-bold text-slate-900">Tableau de bord</h1>
    <p class="mt-1 text-slate-500">Vue d'ensemble de {{ admin.tenant?.name }}.</p>

    <Spinner v-if="loading" />

    <template v-else-if="stats">
      <!-- KPIs -->
      <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="card p-5">
          <p class="text-sm text-slate-400">Chiffre d'affaires</p>
          <p class="mt-1 font-display text-2xl font-bold text-slate-900">{{ formatMoney(stats.revenue, stats.currency) }}</p>
          <p class="mt-1 text-xs text-slate-400">{{ stats.ordersCount }} commandes</p>
        </div>
        <div class="card p-5">
          <p class="text-sm text-slate-400">Rendez-vous à venir</p>
          <p class="mt-1 font-display text-2xl font-bold text-brand-700">{{ stats.upcomingCount }}</p>
          <RouterLink :to="{ name: 'admin-bookings' }" class="mt-1 inline-block text-xs text-brand-600 hover:underline">Voir le planning →</RouterLink>
        </div>
        <div class="card p-5">
          <p class="text-sm text-slate-400">À reprogrammer</p>
          <p class="mt-1 font-display text-2xl font-bold text-amber-600">{{ stats.postponedCount }}</p>
          <p class="mt-1 text-xs text-slate-400">à reprogrammer</p>
        </div>
        <div class="card p-5">
          <p class="text-sm text-slate-400">Rendez-vous terminés</p>
          <p class="mt-1 font-display text-2xl font-bold text-emerald-600">{{ stats.completedCount }}</p>
          <p class="mt-1 text-xs text-slate-400">historique</p>
        </div>
      </div>

      <!-- Chèques cadeaux + catalogue -->
      <div class="mt-6 grid gap-4 lg:grid-cols-2">
        <div class="card p-6">
          <div class="mb-4 flex items-center justify-between">
            <h2 class="font-semibold text-slate-800">Chèques cadeaux</h2>
            <RouterLink :to="{ name: 'admin-vouchers' }" class="text-xs text-brand-600 hover:underline">Voir tout →</RouterLink>
          </div>
          <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-xl bg-amber-50 p-3 text-center">
              <p class="text-xl font-bold text-amber-700">{{ stats.vouchers.awaitingPayment }}</p>
              <p class="text-xs text-amber-600">En attente</p>
            </div>
            <div class="rounded-xl bg-emerald-50 p-3 text-center">
              <p class="text-xl font-bold text-emerald-700">{{ stats.vouchers.active }}</p>
              <p class="text-xs text-emerald-600">Actifs</p>
            </div>
            <div class="rounded-xl bg-slate-100 p-3 text-center">
              <p class="text-xl font-bold text-slate-700">{{ stats.vouchers.used }}</p>
              <p class="text-xs text-slate-500">Utilisés</p>
            </div>
            <div class="rounded-xl bg-rose-50 p-3 text-center">
              <p class="text-xl font-bold text-rose-700">{{ stats.vouchers.expired }}</p>
              <p class="text-xs text-rose-600">Périmés</p>
            </div>
          </div>
          <p class="mt-3 text-xs text-slate-400">Les chèques périmés (breakage) restent tracés pour la comptabilité.</p>
        </div>

        <div class="card p-6">
          <h2 class="mb-4 font-semibold text-slate-800">Catalogue</h2>
          <div class="space-y-3">
            <RouterLink :to="{ name: 'admin-products' }" class="flex items-center justify-between rounded-xl border border-slate-200 p-4 transition hover:border-brand-300">
              <span class="flex items-center gap-3"><span class="text-xl">✦</span> Prestations</span>
              <span class="font-bold text-slate-800">{{ stats.jumpTypesCount }}</span>
            </RouterLink>
            <RouterLink :to="{ name: 'admin-options' }" class="flex items-center justify-between rounded-xl border border-slate-200 p-4 transition hover:border-brand-300">
              <span class="flex items-center gap-3"><span class="text-xl">＋</span> Options & suppléments</span>
              <span class="font-bold text-slate-800">{{ stats.optionsCount }}</span>
            </RouterLink>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
