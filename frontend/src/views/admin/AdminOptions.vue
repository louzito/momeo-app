<script setup>
import { ref, onMounted, computed } from 'vue'
import { RouterLink } from 'vue-router'
import { useAdminStore } from '@/stores/admin'
import api from '@/api'
import Spinner from '@/components/ui/Spinner.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { formatMoney } from '@/utils/format'

const admin = useAdminStore()
const options = ref([])
const jumps = ref([])
const loading = ref(true)
const confirmId = ref(null)

const upsells = computed(() => options.value.filter((o) => o.scope === 'PER_JUMP'))
const orderOptions = computed(() => options.value.filter((o) => o.scope === 'PER_ORDER'))

function linkLabel(o) {
  if (!o.linkedJumpTypeIds?.length) return 'Toutes les prestations'
  const names = o.linkedJumpTypeIds.map((id) => jumps.value.find((j) => j.id === id)?.name).filter(Boolean)
  return names.join(', ') || 'Toutes les prestations'
}

async function load() {
  loading.value = true
  ;[options.value, jumps.value] = await Promise.all([
    api.getOptions(admin.tenantId),
    api.getJumpTypes(admin.tenantId),
  ])
  loading.value = false
}
onMounted(load)

async function remove(id) {
  await api.deleteOption(admin.tenantId, id)
  confirmId.value = null
  await load()
}
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="font-display text-2xl font-bold text-slate-900">Options & suppléments</h1>
        <p class="mt-1 text-slate-500">Ajoutez des compléments à une prestation ou à l’ensemble de la réservation.</p>
      </div>
      <RouterLink :to="{ name: 'admin-option-new' }" class="btn-primary">+ Nouvelle option</RouterLink>
    </div>

    <Spinner v-if="loading" />

    <template v-else>
      <!-- Options liees a une prestation (stockage legacy PER_JUMP) -->
      <section class="mt-6">
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-400">Compléments de prestation</h2>
        <EmptyState v-if="!upsells.length" icon="＋" title="Aucun complément" message="Ajoutez par exemple un soin ciblé ou une finition premium.">
          <RouterLink :to="{ name: 'admin-option-new' }" class="btn-primary">Créer</RouterLink>
        </EmptyState>
        <div v-else class="grid gap-3 sm:grid-cols-2">
          <div v-for="o in upsells" :key="o.id" class="card flex items-start justify-between gap-3 p-4">
            <div class="min-w-0">
              <div class="flex items-center gap-2">
                <p class="font-semibold text-slate-800">{{ o.name }}</p>
                <span class="font-bold text-brand-700">+{{ formatMoney(o.price, admin.currency) }}</span>
                <span v-if="o.mandatory" class="chip bg-slate-100 text-slate-500">Obligatoire</span>
              </div>
              <p class="mt-0.5 text-sm text-slate-500">{{ o.description }}</p>
              <p class="mt-1 text-xs text-slate-400">🔗 Lié à : {{ linkLabel(o) }}</p>
            </div>
            <div class="flex shrink-0 flex-col gap-1">
              <RouterLink :to="{ name: 'admin-option-edit', params: { id: o.id } }" class="btn-ghost px-2 py-1 text-xs">Modifier</RouterLink>
              <template v-if="confirmId === o.id">
                <button class="btn bg-rose-600 px-2 py-1 text-xs text-white" @click="remove(o.id)">OK</button>
                <button class="btn-ghost px-2 py-1 text-xs" @click="confirmId = null">×</button>
              </template>
              <button v-else class="btn-ghost px-2 py-1 text-xs text-rose-500" @click="confirmId = o.id">Suppr.</button>
            </div>
          </div>
        </div>
      </section>

      <!-- Options de commande (PER_ORDER) -->
      <section class="mt-8">
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-400">Options de réservation</h2>
        <div v-if="orderOptions.length" class="grid gap-3 sm:grid-cols-2">
          <div v-for="o in orderOptions" :key="o.id" class="card flex items-start justify-between gap-3 p-4">
            <div class="min-w-0">
              <div class="flex items-center gap-2">
                <p class="font-semibold text-slate-800">{{ o.name }}</p>
                <span class="font-bold text-brand-700">+{{ formatMoney(o.price, admin.currency) }}</span>
                <span v-if="o.mandatory" class="chip bg-amber-100 text-amber-700">Obligatoire</span>
              </div>
              <p class="mt-0.5 text-sm text-slate-500">{{ o.description }}</p>
            </div>
            <div class="flex shrink-0 flex-col gap-1">
              <RouterLink :to="{ name: 'admin-option-edit', params: { id: o.id } }" class="btn-ghost px-2 py-1 text-xs">Modifier</RouterLink>
              <template v-if="confirmId === o.id">
                <button class="btn bg-rose-600 px-2 py-1 text-xs text-white" @click="remove(o.id)">OK</button>
                <button class="btn-ghost px-2 py-1 text-xs" @click="confirmId = null">×</button>
              </template>
              <button v-else class="btn-ghost px-2 py-1 text-xs text-rose-500" @click="confirmId = o.id">Suppr.</button>
            </div>
          </div>
        </div>
        <p v-else class="text-sm text-slate-400">Aucune option générale pour le moment.</p>
      </section>
    </template>
  </div>
</template>
