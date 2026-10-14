<script setup>
// Commandes — liste REELLE des commandes Sylius du centre, avec filtres.
// C'est ici que le centre encaisse les virements : filtre « Virement en
// attente » puis bouton « Virement reçu » (PATCH /admin/payments/{id}/complete).
import { ref, computed, onMounted, nextTick } from 'vue'
import { RouterLink } from 'vue-router'
import api from '@/api'
import { formatMoney, formatDate } from '@/utils/format'
import { refundAmount } from '@/utils/refunds'
import Spinner from '@/components/ui/Spinner.vue'

const orders = ref([])
const loading = ref(true)
const error = ref('')
const payingId = ref(null)
const refundingId = ref(null)
const refunds = ref({})
const refundOrder = ref(null)
const refundContext = ref(null)
const refundLoading = ref(false)
const refundError = ref('')
const refundPaymentId = ref(null)
const refundMode = ref('full')
const refundValue = ref('')
const refundReason = ref('')
const refundConfirmed = ref(false)
const refundReview = ref(false)
const refundDialog = ref(null)
const refundKey = ref(null)
const refundTrigger = ref(null)
const success = ref('')
const selectedPayment = computed(() => refundContext.value?.payments.find(p => p.id === refundPaymentId.value))
const refundablePayments = computed(() => refundContext.value?.payments.filter(p => p.refundableAmount > 0) || [])
const requestedAmount = computed(() => refundMode.value === 'full' ? selectedPayment.value?.refundableAmount : refundAmount(refundValue.value))
const validRefund = computed(() => selectedPayment.value && selectedPayment.value.mode !== 'unsupported' && requestedAmount.value > 0 && requestedAmount.value <= selectedPayment.value.refundableAmount)
function resetReview() { refundReview.value = false; refundConfirmed.value = false; refundKey.value = null }
function closeRefund() {
  if (refundingId.value) return
  refundDialog.value?.close()
  refundOrder.value = null
  refundTrigger.value?.focus()
}
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
      if (o.paymentId && ['paid', 'partially_paid', 'partially_refunded', 'refunded'].includes(o.paymentState)) {
        const history = await api.getPaymentRefunds(o.paymentId)
        refunds.value[o.id] = history.member || []
        o.refundedAmount = history.context.refundedAmount / 100
        refunds.value[o.id] = history.context.payments.flatMap(p => p.operations)
      }
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
    case 'partially_paid': return { label: 'Partiellement payée', cls: 'bg-amber-100 text-amber-700' }
    case 'paid': return { label: 'Payée', cls: 'bg-emerald-100 text-emerald-700' }
    case 'refunded': return { label: 'Remboursée', cls: 'bg-slate-200 text-slate-600' }
    case 'partially_refunded': return { label: 'Partiellement remboursée', cls: 'bg-sky-100 text-sky-700' }
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

async function refund(order) {
  refundTrigger.value = document.activeElement
  refundOrder.value = order
  refundContext.value = null
  refundLoading.value = true
  refundError.value = ''
  refundReason.value = ''
  refundValue.value = ''
  refundMode.value = 'full'
  resetReview()
  await nextTick()
  refundDialog.value.showModal()
  try {
    const history = await api.getPaymentRefunds(order.paymentId)
    refundContext.value = history.context
    refundPaymentId.value = refundablePayments.value.find(p => p.mode !== 'unsupported')?.id ?? refundablePayments.value[0]?.id
  } catch (e) { refundError.value = e?.message || 'Impossible de charger les paiements.' }
  finally { refundLoading.value = false }
}

async function submitRefund() {
  if (!validRefund.value || !refundConfirmed.value || refundingId.value) return
  const order = refundOrder.value
  refundingId.value = order.id
  refundError.value = ''
  // Preserve the same key on retry after an ambiguous network error.
  refundKey.value ||= `admin-${refundPaymentId.value}-${crypto.randomUUID()}`
  try {
    await api.refundPayment(refundPaymentId.value, requestedAmount.value, refundReason.value.trim(), refundKey.value)
    const history = await api.getPaymentRefunds(order.paymentId)
    order.refundedAmount = history.context.refundedAmount / 100
    order.paymentState = history.context.paymentState
    refunds.value[order.id] = history.context.payments.flatMap(p => p.operations)
    success.value = `Remboursement enregistré pour la commande ${order.number}.`
    refundingId.value = null
    closeRefund()
  } catch (e) { refundError.value = e?.message || 'Échec du remboursement. Réessayez sans modifier la demande pour conserver sa référence.' }
  finally { refundingId.value = null }
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

    <p v-if="success" role="status" class="mt-4 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800">{{ success }}</p>
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
            <button
              v-if="['paid','partially_paid','partially_refunded','refunded'].includes(o.paymentState) && o.paymentId"
              class="btn-outline px-4 py-1.5 text-sm text-rose-700"
              :disabled="refundingId === o.id"
              @click.stop="refund(o)"
            >
              {{ refundingId === o.id ? 'Remboursement…' : o.paymentState === 'refunded' ? 'Remboursements' : 'Rembourser' }}
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
              <p v-if="o.refundedAmount" class="text-xs text-slate-500">
                Remboursé : {{ formatMoney(o.refundedAmount, o.currency) }}
                <template v-if="refunds[o.id]?.[0]?.creditNoteNumber"> · Avoir {{ refunds[o.id][0].creditNoteNumber }}</template>
              </p>
              <ul v-if="refunds[o.id]?.length" class="space-y-1 border-t border-slate-100 pt-2 text-xs text-slate-500">
                <li v-for="operation in refunds[o.id]" :key="operation.idempotencyKey">
                  {{ formatDate(operation.createdAt, { short: true }) }} · {{ formatMoney(operation.amount / 100, operation.currency) }}
                  · {{ operation.provider }} · {{ operation.creditNoteNumber }}
                  <span v-if="operation.reason"> · {{ operation.reason }}</span>
                </li>
              </ul>
            </div>
          </div>
        </div>
      </div>
    </div>
    <dialog ref="refundDialog" class="w-[calc(100%-2rem)] max-w-2xl rounded-2xl p-0 shadow-xl backdrop:bg-slate-900/50" aria-labelledby="refund-title" @cancel.prevent="closeRefund">
      <div class="p-6" v-if="refundOrder">
        <div class="flex items-start justify-between gap-4">
          <div><h2 id="refund-title" class="text-xl font-bold text-slate-900">Rembourser la commande {{ refundOrder.number }}</h2>
            <p class="mt-1 text-sm text-slate-500">Choisissez le règlement et vérifiez le montant avant de confirmer.</p></div>
          <button type="button" aria-label="Fermer" :disabled="!!refundingId" @click="closeRefund" class="btn-ghost">✕</button>
        </div>
        <p v-if="refundError" role="alert" class="mt-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{{ refundError }}</p>
        <Spinner v-if="refundLoading" />
        <template v-else-if="refundContext">
          <dl class="my-5 grid grid-cols-2 gap-3 rounded-xl bg-slate-50 p-4 text-sm">
            <div><dt class="text-slate-500">Valeur de la commande</dt><dd class="font-semibold">{{ formatMoney(refundContext.orderTotal / 100, refundOrder.currency) }}</dd></div>
            <div><dt class="text-slate-500">Montant encaissé</dt><dd class="font-semibold">{{ formatMoney(refundContext.collectedAmount / 100, refundOrder.currency) }}</dd></div>
            <div><dt class="text-slate-500">Déjà remboursé</dt><dd class="font-semibold">{{ formatMoney(refundContext.refundedAmount / 100, refundOrder.currency) }}</dd></div>
            <div><dt class="text-slate-500">Solde prévu sur place</dt><dd class="font-semibold">{{ formatMoney(refundContext.balanceDue / 100, refundOrder.currency) }}</dd></div>
          </dl>
          <p v-if="refundContext.balanceDue" class="mb-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-800">Cette commande comporte un acompte. Seules les sommes encaissées sont remboursables. Le solde prévu sur place n’est pas inclus.</p>
          <p v-if="!refundablePayments.length" class="text-sm text-slate-600">Aucun paiement encaissé ne présente de solde remboursable.</p>
          <form v-else @submit.prevent="refundReview ? submitRefund() : (refundReview = true)">
            <fieldset :disabled="refundReview || !!refundingId" class="space-y-4">
              <label class="block text-sm font-medium">Paiement à rembourser
                <select v-model="refundPaymentId" @change="resetReview" class="mt-1 w-full rounded-lg border border-slate-300 p-2">
                  <option v-for="payment in refundablePayments" :key="payment.id" :value="payment.id">{{ payment.methodName || payment.method }} · #{{ payment.id }} · disponible : {{ formatMoney(payment.refundableAmount / 100, refundOrder.currency) }}</option>
                </select>
              </label>
              <p v-if="selectedPayment?.mode === 'automatic'" class="text-sm text-slate-600">Le remboursement sera demandé à Stripe sur le paiement d’origine.</p>
              <p v-else-if="selectedPayment?.mode === 'gift_card'" class="text-sm text-slate-600">Le montant sera recrédité sur la carte cadeau utilisée.</p>
              <p v-else-if="selectedPayment?.mode === 'manual'" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800">Effectuez d’abord le remboursement au client par virement ou en espèces. Cette action enregistre le remboursement ; elle ne transfère pas d’argent.</p>
              <p v-else class="text-sm text-rose-700">Ce moyen de paiement ne dispose pas d’un remboursement pris en charge.</p>
              <div class="flex flex-wrap gap-4 text-sm">
                <label><input v-model="refundMode" type="radio" value="full" @change="resetReview"> Tout le solde de ce paiement</label>
                <label><input v-model="refundMode" type="radio" value="partial" @change="resetReview"> Montant partiel</label>
              </div>
              <label v-if="refundMode === 'partial'" class="block text-sm font-medium">Montant ({{ refundOrder.currency }})
                <input v-model="refundValue" inputmode="decimal" required placeholder="Ex. 25,50" @input="resetReview" class="mt-1 w-full rounded-lg border border-slate-300 p-2">
                <span class="mt-1 block text-xs text-slate-500">Maximum : {{ formatMoney(selectedPayment?.refundableAmount / 100, refundOrder.currency) }}. Deux décimales maximum.</span>
              </label>
              <label class="block text-sm font-medium">Motif du remboursement (facultatif)
                <textarea v-model="refundReason" rows="2" maxlength="1000" @input="resetReview" class="mt-1 w-full rounded-lg border border-slate-300 p-2" placeholder="Annulation, geste commercial…"></textarea>
              </label>
            </fieldset>
            <div v-if="refundReview" class="mt-4 space-y-3 rounded-xl border border-rose-200 p-4 text-sm">
              <p class="font-semibold">Montant à rembourser : {{ formatMoney(requestedAmount / 100, refundOrder.currency) }}</p>
              <p>Solde remboursable de ce paiement après l’opération : {{ formatMoney((selectedPayment.refundableAmount - requestedAmount) / 100, refundOrder.currency) }}.</p>
              <p v-if="refundContext.payments.every(p => p.refundableAmount === 0 || (p.id === refundPaymentId && p.refundableAmount === requestedAmount))">Tous les montants encaissés seront remboursés. La réservation associée sera annulée, y compris pour un acompte.</p>
              <label class="flex items-start gap-2"><input v-model="refundConfirmed" type="checkbox" :disabled="!!refundingId" class="mt-1">
                <span>{{ selectedPayment.mode === 'manual' ? 'Je confirme avoir déjà remboursé ce montant au client.' : 'Je confirme le paiement et le montant à rembourser.' }}</span>
              </label>
            </div>
            <div class="mt-5 flex flex-wrap justify-end gap-3">
              <button type="button" class="btn-ghost" :disabled="!!refundingId" @click="closeRefund">Annuler</button>
              <button v-if="refundReview" type="button" class="btn-outline" :disabled="!!refundingId" @click="resetReview">Modifier</button>
              <button type="submit" class="btn-primary" :disabled="!validRefund || !!refundingId || (refundReview && !refundConfirmed)">{{ refundingId ? 'Remboursement en cours…' : refundReview ? 'Confirmer le remboursement' : 'Vérifier le remboursement' }}</button>
            </div>
          </form>
          <div v-if="refundContext.payments.some(p => p.operations.length)" class="mt-5 border-t pt-4 text-sm">
            <h3 class="font-semibold">Historique des remboursements</h3>
            <ul class="mt-2 space-y-2"><li v-for="operation in refundContext.payments.flatMap(p => p.operations)" :key="operation.idempotencyKey">
              {{ formatDate(operation.createdAt, { short: true }) }} · {{ formatMoney(operation.amount / 100, operation.currency) }} · {{ operation.creditNoteNumber }}
              <p class="text-xs text-slate-500">{{ operation.reason || 'Sans motif' }} · {{ operation.actor }}</p>
            </li></ul>
          </div>
        </template>
      </div>
    </dialog>
  </div>
</template>
