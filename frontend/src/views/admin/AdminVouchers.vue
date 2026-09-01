<script setup>
// Chèques cadeaux — liste REELLE des chèques vendus par le centre (Phase 3).
// Isolation par tenant : automatique côté backend (BDD du centre courant),
// aucun filtre à faire ici — le centre ne voit structurellement que les
// siens (voir App\Controller\AdminGiftVoucherApiController).
import { ref, computed, onMounted } from 'vue'
import { useAdminStore } from '@/stores/admin'
import api from '@/api'
import { formatMoney, formatDate } from '@/utils/format'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import Spinner from '@/components/ui/Spinner.vue'

const admin = useAdminStore()
const vouchers = ref([])
const loading = ref(true)
const error = ref('')
const filter = ref('all') // all | awaiting_payment | active | used | expired
const expandedCode = ref(null)

const FILTERS = [
  { key: 'all', label: 'Tous' },
  { key: 'awaiting_payment', label: '🏦 En attente de paiement' },
  { key: 'active', label: '✓ Actifs' },
  { key: 'used', label: 'Utilisés' },
  { key: 'expired', label: 'Périmés' },
]

const counts = computed(() => ({
  all: vouchers.value.length,
  awaiting_payment: vouchers.value.filter((v) => v.status === 'awaiting_payment').length,
  active: vouchers.value.filter((v) => v.status === 'active').length,
  used: vouchers.value.filter((v) => v.status === 'used').length,
  expired: vouchers.value.filter((v) => v.status === 'expired').length,
}))

const visible = computed(() => {
  if (filter.value === 'all') return vouchers.value
  return vouchers.value.filter((v) => v.status === filter.value)
})

function toggleDetail(v) {
  expandedCode.value = expandedCode.value === v.code ? null : v.code
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const res = await api.getAdminVouchers(admin.tenantId)
    vouchers.value = res.vouchers || []
  } catch (e) {
    error.value = e?.message || 'Impossible de charger les chèques cadeaux.'
  } finally {
    loading.value = false
  }
}
onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-4xl">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="font-display text-2xl font-bold text-slate-900">Chèques cadeaux</h1>
        <p class="mt-1 text-slate-500">
          Tous les chèques cadeaux vendus sur votre boutique. Un chèque passe automatiquement en
          « Actif » dès l'encaissement du virement (onglet Commandes), puis en « Utilisé » lorsque
          le bénéficiaire réserve sa prestation.
        </p>
      </div>
      <button class="btn-ghost px-3 py-1 text-xs" :disabled="loading" @click="load">↻ Actualiser</button>
    </div>

    <!-- Filtres -->
    <div class="mt-5 flex flex-wrap gap-2">
      <button
        v-for="f in FILTERS"
        :key="f.key"
        type="button"
        class="rounded-full px-4 py-1.5 text-sm font-medium transition"
        :class="filter === f.key ? 'bg-brand-600 text-white shadow-sm' : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50'"
        @click="filter = f.key"
      >
        {{ f.label }}
        <span class="ml-1 opacity-70">({{ counts[f.key] }})</span>
      </button>
    </div>

    <div v-if="error" class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
      ⚠️ {{ error }}
    </div>

    <Spinner v-if="loading" />

    <div v-else-if="!visible.length" class="card mt-4 p-6 text-sm text-slate-400">
      Aucun chèque cadeau pour ce filtre.
    </div>

    <div v-else class="mt-4 space-y-3">
      <div v-for="v in visible" :key="v.code" class="card p-4">
        <!-- Ligne cliquable : ouvre / ferme le detail -->
        <div class="flex cursor-pointer flex-wrap items-center justify-between gap-3" @click="toggleDetail(v)">
          <div class="min-w-0 flex-1">
            <p class="flex flex-wrap items-center gap-2">
              <span class="text-xs text-slate-400">{{ expandedCode === v.code ? '▾' : '▸' }}</span>
              <span class="font-mono font-semibold text-slate-800">{{ v.code }}</span>
              <StatusBadge :status="v.status" />
              <span class="text-xs capitalize text-slate-400">{{ v.createdAt ? formatDate(v.createdAt, { short: true }) : '' }}</span>
            </p>
            <p class="mt-1 max-w-2xl truncate text-xs text-slate-500">
              {{ v.jumpTypeName }} · Offert par {{ v.purchaserName }} à {{ v.beneficiaryName || v.beneficiaryEmail }}
            </p>
          </div>
          <span class="font-display text-lg font-bold text-slate-800">{{ formatMoney(v.amount, v.currency) }}</span>
        </div>

        <!-- Detail -->
        <div v-if="expandedCode === v.code" class="mt-4 grid gap-5 border-t border-slate-100 pt-4 lg:grid-cols-2">
          <div class="space-y-3 text-sm">
            <div>
              <p class="text-xs font-semibold uppercase text-slate-400">Acheteur</p>
              <p class="mt-1 font-medium text-slate-800">{{ v.purchaserName }}</p>
              <p class="text-slate-500">{{ v.purchaserEmail }}</p>
            </div>
            <div>
              <p class="text-xs font-semibold uppercase text-slate-400">Bénéficiaire</p>
              <p class="mt-1 font-medium text-slate-800">{{ v.beneficiaryName || '—' }}</p>
              <p class="text-slate-500">{{ v.beneficiaryEmail }}</p>
            </div>
            <div v-if="v.personalMessage">
              <p class="text-xs font-semibold uppercase text-slate-400">Message</p>
              <p class="mt-1 rounded-lg bg-slate-50 p-2 text-slate-600">💬 {{ v.personalMessage }}</p>
            </div>
          </div>
          <div class="space-y-3 text-sm">
            <div>
              <p class="text-xs font-semibold uppercase text-slate-400">Prestation offerte</p>
              <p class="mt-1 font-medium text-slate-800">{{ v.jumpTypeName }}</p>
            </div>
            <div>
              <p class="text-xs font-semibold uppercase text-slate-400">Commande d'achat</p>
              <p class="mt-1 font-mono text-slate-700">{{ v.purchaseOrderNumber }}</p>
            </div>
            <div v-if="v.usageOrderNumber">
              <p class="text-xs font-semibold uppercase text-slate-400">Réservation (utilisation)</p>
              <p class="mt-1 font-mono text-slate-700">{{ v.usageOrderNumber }}</p>
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <p class="text-xs font-semibold uppercase text-slate-400">Expire le</p>
                <p class="mt-1 text-slate-700">{{ v.expiresAt ? formatDate(v.expiresAt, { short: true }) : '—' }}</p>
              </div>
              <div v-if="v.usedAt">
                <p class="text-xs font-semibold uppercase text-slate-400">Utilisé le</p>
                <p class="mt-1 text-slate-700">{{ formatDate(v.usedAt, { short: true }) }}</p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
