<script setup>
import { ref, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { useAdminStore } from '@/stores/admin'
import api from '@/api'
import Spinner from '@/components/ui/Spinner.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { formatMoney } from '@/utils/format'

const admin = useAdminStore()
const jumps = ref([])
const loading = ref(true)
const confirmId = ref(null)

async function load() {
  loading.value = true
  jumps.value = await api.getJumpTypes(admin.tenantId)
  loading.value = false
}
onMounted(load)

async function remove(id) {
  await api.deleteJumpType(admin.tenantId, id)
  confirmId.value = null
  await load()
}
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="font-display text-2xl font-bold text-slate-900">Prestations</h1>
        <p class="mt-1 text-slate-500">Créez les services que vos clients peuvent découvrir et réserver en ligne.</p>
      </div>
      <RouterLink :to="{ name: 'admin-product-new' }" class="btn-primary">+ Nouvelle prestation</RouterLink>
      <RouterLink :to="{ name: 'admin-physical-products' }" class="btn-outline">Gérer les produits physiques</RouterLink>
    </div>

    <Spinner v-if="loading" />

    <EmptyState v-else-if="!jumps.length" icon="✦" title="Aucune prestation" message="Créez votre première prestation pour ouvrir les réservations.">
      <RouterLink :to="{ name: 'admin-product-new' }" class="btn-primary">Créer une prestation</RouterLink>
    </EmptyState>

    <div v-else class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
      <div v-for="jt in jumps" :key="jt.id" class="card overflow-hidden">
        <div class="relative h-32">
          <img :src="jt.image" :alt="jt.name" class="h-full w-full object-cover" />
          <span v-if="jt.popular" class="absolute left-2 top-2 chip bg-accent-500 text-white">★ Populaire</span>
        </div>
        <div class="p-4">
          <div class="flex items-start justify-between gap-2">
            <h3 class="font-semibold text-slate-900">{{ jt.name }}</h3>
            <span class="font-bold text-brand-700">{{ formatMoney(jt.basePrice, admin.currency) }}</span>
          </div>
          <p class="mt-1 line-clamp-2 text-sm text-slate-500">{{ jt.summary }}</p>
          <div class="mt-3 flex flex-wrap gap-1.5 text-xs">
            <span class="chip bg-slate-100 text-slate-600">👥 {{ jt.capacityPerSlot }}/créneau</span>
            <span class="chip bg-slate-100 text-slate-600">⏱️ {{ jt.durationMin }} min</span>
          </div>

          <div class="mt-4 flex items-center gap-2">
            <RouterLink :to="{ name: 'admin-product-edit', params: { id: jt.id } }" class="btn-outline flex-1 py-2">Modifier</RouterLink>
            <template v-if="confirmId === jt.id">
              <button class="btn bg-rose-600 text-white hover:bg-rose-700" @click="remove(jt.id)">Confirmer</button>
              <button class="btn-ghost" @click="confirmId = null">×</button>
            </template>
            <button v-else class="btn-ghost text-rose-500 hover:bg-rose-50" @click="confirmId = jt.id">Supprimer</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
