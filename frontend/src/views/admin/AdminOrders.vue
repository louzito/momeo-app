<script setup>
// Commandes — liste REELLE des commandes Sylius du centre, avec filtres.
// C'est ici que le centre encaisse les virements : filtre « Virement en
// attente » puis bouton « Virement reçu » (PATCH /admin/payments/{id}/complete).
import { ref, computed, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import api from '@/api'
import { formatMoney, formatDate } from '@/utils/format'
import Spinner from '@/components/ui/Spinner.vue'

const orders = ref([])
const loading = ref(true)
const error = ref('')
const payingId = ref(null)
const filter = ref('all') // all | awaiting | paid

// --- Detail d'une commande (depliable) ---------------------------------------
const expandedId = ref(null)
const orderDetails = ref({}) // token -> detail complet (items, client, adresse...)
const loadingDetail = ref(null)

const detailsOf = (o) => orderDetails.value[o.id] || null

async function toggleDetail(o) {
  if (expandedId.value === o.id) { expandedId.value = null; return }
  expandedId.value = o.id
  if (!orderDetails.value[o.id]) {
    loadingDetail.value = o.id
    try {
      orderDetails.value[o.id] = await api.getSyliusOrder(o.id)
    } catch (e) {
      error.value = e?.message || 'Impossible de charger le detail.'
      expandedId.value = null
    } finally {
      loadingDetail.value = null
    }
  }
}

const FILTERS = [
  { key: 'all', label: 'Toutes' },
  { key: 'awaiting', label: '🏦 Virement en attente' },
  { key: 'paid', label: '✓ Payées' },
]

const counts = computed(() => ({
  all: orders.value.length,
  awaiting: orders.value.filter((o) => o.paymentState === 'awaiting_payment').length,
  paid: orders.value.filter((o) => o.paymentState === 'paid').length,
}))

const visible = computed(() => {
  if (filter.value === 'awaiting') return orders.value.filter((o) => o.paymentState === 'awaiting_payment')
  if (filter.value === 'paid') return orders.value.filter((o) => o.paymentState === 'paid')
  return orders.value
})

// Etat de paiement -> badge lisible
function payBadge(o) {
  switch (o.paymentState) {
    case 'awaiting_payment': return { label: 'En attente de virement', cls: 'bg-amber-100 text-amber-700' }
    case 'paid': return { label: 'Payée', cls: 'bg-emerald-100 text-emerald-700' }
    case 'refunded': return { label: 'Remboursée', cls: 'bg-slate-200 text-slate-600' }
    case 'cancelled': return { label: 'Annulée', cls: 'bg-rose-100 text-rose-700' }
    default: return { label: o.paymentState, cls: 'bg-slate-100 text-slate-500' }
  }
}

// Notes historiques ou actuelles -> détails lisibles sans préfixe de marque.
function details(o) {
  return (o.notes || '').replace(/^(?:\x53kyBook|TodaTempo)\s*—\s*/, '') || '—'
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    orders.value = (await api.getSyliusOrders?.({})) || []
  } catch (e) {
    error.value = e?.message || 'Impossible de charger les commandes.'
  } finally {
    loading.value = false
  }
}
onMounted(load)

async function markPaid(order) {
  payingId.value = order.id
  error.value = ''
  try {
    await api.markOrderPaid(order.paymentId)
    order.paymentState = 'paid'
    order.state = 'fulfilled'
  } catch (e) {
    error.value = e?.message || 'Echec de l\'encaissement.'
  } finally {
    payingId.value = null
  }
}
</script>

<template>
  <div class="mx-auto max-w-4xl">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="font-display text-2xl font-bold text-slate-900">Commandes</h1>
        <p class="mt-1 text-slate-500">
          Toutes les commandes passées sur votre boutique. Encaissez les virements dès réception
          (référence indiquée par le client = numéro de commande).
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
      {{ filter === 'awaiting' ? 'Aucun virement en attente. 🎉' : 'Aucune commande pour ce filtre.' }}
    </div>

    <div v-else class="mt-4 space-y-3">
      <div
        v-for="o in visible"
        :key="o.id"
        class="card p-4"
        :class="o.paymentState === 'awaiting_payment' ? 'border-amber-200' : ''"
      >
        <!-- Ligne cliquable : ouvre / ferme le detail -->
        <div class="flex cursor-pointer flex-wrap items-center justify-between gap-3" @click="toggleDetail(o)">
          <div class="min-w-0 flex-1">
            <p class="flex flex-wrap items-center gap-2">
              <span class="text-xs text-slate-400">{{ expandedId === o.id ? '▾' : '▸' }}</span>
              <span class="font-mono font-semibold text-slate-800">{{ o.number }}</span>
              <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="payBadge(o).cls">
                {{ payBadge(o).label }}
              </span>
              <span class="text-xs capitalize text-slate-400">{{ o.createdAt ? formatDate(o.createdAt, { short: true }) : '' }}</span>
            </p>
            <p class="mt-1 max-w-2xl truncate text-xs text-slate-500" :title="details(o)">{{ details(o) }}</p>
          </div>
          <div class="flex items-center gap-3">
            <span class="font-display text-lg font-bold text-slate-800">{{ formatMoney(o.total, o.currency) }}</span>
            <button
              v-if="o.paymentState === 'awaiting_payment'"
              class="btn-primary px-4 py-1.5 text-sm"
              :disabled="payingId === o.id"
              @click.stop="markPaid(o)"
            >
              {{ payingId === o.id ? 'Encaissement…' : '✓ Virement reçu' }}
            </button>
          </div>
        </div>

        <!-- Detail de la commande -->
        <div v-if="expandedId === o.id" class="mt-4 border-t border-slate-100 pt-4">
          <Spinner v-if="loadingDetail === o.id" />
          <div v-else-if="detailsOf(o)" class="grid gap-5 lg:grid-cols-3">
            <!-- Articles -->
            <div class="lg:col-span-2">
              <p class="mb-2 text-xs font-semibold uppercase text-slate-400">Articles</p>
              <table class="w-full text-sm">
                <tbody class="divide-y divide-slate-100">
                  <tr v-for="(it, i) in detailsOf(o).items" :key="i">
                    <td class="py-2 text-slate-700">{{ it.name }}</td>
                    <td class="py-2 text-right text-slate-400">× {{ it.quantity }}</td>
                    <td class="py-2 text-right font-medium text-slate-700">{{ formatMoney(it.total, o.currency) }}</td>
                  </tr>
                  <tr>
                    <td colspan="2" class="py-2 font-semibold text-slate-800">Total</td>
                    <td class="py-2 text-right font-display font-bold text-slate-900">{{ formatMoney(detailsOf(o).total, o.currency) }}</td>
                  </tr>
                </tbody>
              </table>
              <p v-if="detailsOf(o).notes" class="mt-2 rounded-lg bg-slate-50 p-2 text-xs text-slate-500">
                📝 {{ detailsOf(o).notes }}
              </p>
            </div>
            <!-- Client + paiement -->
            <div class="space-y-3 text-sm">
              <div>
                <p class="text-xs font-semibold uppercase text-slate-400">Client</p>
                <p class="mt-1 font-medium text-slate-800">
                  {{ detailsOf(o).billing ? `${detailsOf(o).billing.firstName} ${detailsOf(o).billing.lastName}` : '—' }}
                </p>
                <p class="text-slate-500">{{ detailsOf(o).customerEmail || '—' }}</p>
              </div>
              <div>
                <p class="text-xs font-semibold uppercase text-slate-400">Paiement</p>
                <p class="mt-1 text-slate-700">
                  {{ detailsOf(o).paymentMethodName || '—' }}
                  <span class="ml-1 rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="payBadge(o).cls">{{ payBadge(o).label }}</span>
                </p>
              </div>
              <RouterLink
                :to="{ name: 'admin-invoice', params: { token: o.id } }"
                class="btn-outline inline-block px-4 py-1.5 text-sm"
              >
                🧾 Voir la facture
              </RouterLink>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
