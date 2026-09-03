<script setup>
// Facture d'une commande — page HORS layout admin (pas de sidebar).
//
// PRIORITE A LA VRAIE FACTURE SYLIUS (plugin sylius/invoicing-plugin) :
// si une facture officielle existe pour la commande (numerotation legale,
// PDF genere par Gotenberg et archive dans back/private/invoices/), on
// affiche CE PDF directement dans la page (iframe) + bouton Telecharger.
// Les endpoints /api/v2/admin/invoices[?orderNumber=] et
// /api/v2/admin/invoices/{id}/download sont fournis par notre controleur
// back AdminInvoiceApiController (JWT admin).
//
// FALLBACK : commandes passees AVANT l'installation du plugin (<= 000000026)
// -> pas de facture Sylius. On garde alors l'ancien rendu imprimable cote
// front, avec un bandeau expliquant que ce n'est pas la facture officielle
// (rattrapage possible : bin/console sylius-invoicing:generate-invoices).
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAdminStore } from '@/stores/admin'
import api from '@/api'
import { formatMoney, formatDate } from '@/utils/format'
import Spinner from '@/components/ui/Spinner.vue'

const route = useRoute()
const router = useRouter()
const admin = useAdminStore()

const order = ref(null)
const loading = ref(true)
const error = ref('')

// Facture officielle Sylius + son PDF charge en blob (affichage iframe).
const syliusInvoice = ref(null)
const pdfUrl = ref('')
const pdfLoading = ref(false)
const downloading = ref(false)

async function tryLoadSyliusInvoice(number) {
  try {
    const list = await api.getSyliusInvoices?.(number)
    if (Array.isArray(list) && list.length) syliusInvoice.value = list[0]
  } catch { /* plugin absent ou indisponible -> fallback rendu front */ }
}

// Charge le PDF officiel et l'affiche dans la page.
async function loadPdfPreview() {
  if (!syliusInvoice.value) return
  pdfLoading.value = true
  try {
    const blob = await api.downloadSyliusInvoice(syliusInvoice.value.id)
    pdfUrl.value = URL.createObjectURL(blob)
  } catch (e) {
    // le PDF ne charge pas -> on retombe sur le rendu front
    error.value = e?.message || 'Impossible de charger le PDF de la facture.'
    syliusInvoice.value = null
  } finally {
    pdfLoading.value = false
  }
}

function downloadSyliusPdf() {
  if (!pdfUrl.value) return
  downloading.value = true
  try {
    const a = document.createElement('a')
    a.href = pdfUrl.value
    a.download = `facture-${syliusInvoice.value?.number?.replaceAll('/', '-') || order.value?.number || 'sylius'}.pdf`
    a.click()
  } finally {
    downloading.value = false
  }
}

const PAY_LABELS = {
  awaiting_payment: 'En attente de paiement',
  paid: 'Payée',
  refunded: 'Remboursée',
  cancelled: 'Annulée',
}

onMounted(async () => {
  try {
    order.value = await api.getSyliusOrder(route.params.token)
    if (order.value?.number) {
      await tryLoadSyliusInvoice(order.value.number)
      if (syliusInvoice.value) await loadPdfPreview()
    }
  } catch (e) {
    error.value = e?.message || 'Commande introuvable.'
  } finally {
    loading.value = false
  }
})

onBeforeUnmount(() => {
  if (pdfUrl.value) URL.revokeObjectURL(pdfUrl.value)
})

function printInvoice() {
  window.print()
}
</script>

<template>
  <div class="min-h-screen bg-slate-100 py-8 print:bg-white print:py-0">
    <!-- Barre d'actions (masquee a l'impression) -->
    <div class="mx-auto mb-6 flex max-w-3xl items-center justify-between gap-3 px-4 print:hidden">
      <button class="btn-ghost" @click="router.back()">← Retour aux commandes</button>
      <div class="flex items-center gap-2">
        <button
          v-if="syliusInvoice && pdfUrl"
          class="btn-primary px-6"
          :disabled="downloading"
          @click="downloadSyliusPdf"
        >
          {{ downloading ? 'Telechargement…' : '⬇️ Telecharger le PDF' }}
        </button>
        <button v-if="!syliusInvoice" class="btn-primary px-6" :disabled="!order" @click="printInvoice">
          🖨️ Imprimer / PDF
        </button>
      </div>
    </div>

    <Spinner v-if="loading" />
    <div v-else-if="error && !order" class="mx-auto max-w-3xl px-4">
      <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">⚠️ {{ error }}</div>
    </div>

    <!-- ===================== FACTURE OFFICIELLE SYLIUS (PDF) ===================== -->
    <div v-else-if="syliusInvoice" class="mx-auto max-w-3xl px-4 print:max-w-none print:p-0">
      <div class="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 print:hidden">
        <p>
          ✅ Facture officielle <span class="font-mono font-semibold">{{ syliusInvoice.number }}</span>
          — commande <span class="font-mono">{{ order?.number }}</span>
          <template v-if="syliusInvoice.issuedAt"> · émise le {{ formatDate(syliusInvoice.issuedAt, { short: true }) }}</template>
        </p>
        <!-- total facture Sylius en CENTIMES -> /100 pour formatMoney -->
        <p class="font-semibold">{{ formatMoney(syliusInvoice.total / 100, syliusInvoice.currencyCode || order?.currency) }}</p>
      </div>

      <Spinner v-if="pdfLoading" />
      <iframe
        v-else-if="pdfUrl"
        :src="pdfUrl"
        title="Facture PDF"
        class="h-[75vh] w-full rounded-xl border border-slate-200 bg-white shadow-sm"
      />
    </div>

    <!-- ===================== FALLBACK : rendu front (pas de facture Sylius) ===================== -->
    <div v-else-if="order" class="mx-auto max-w-3xl px-4 print:max-w-none print:p-0">
      <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 print:hidden">
        ⚠️ Pas de facture officielle Sylius pour cette commande (passée avant l'activation de la
        facturation). Rendu indicatif ci-dessous — pour générer les factures manquantes :
        <span class="font-mono text-xs">bin/console sylius-invoicing:generate-invoices</span>
      </div>

      <div class="bg-white p-10 shadow-sm print:p-0 print:shadow-none">
        <!-- En-tete -->
        <div class="flex items-start justify-between border-b border-slate-200 pb-6">
          <div>
            <p class="font-display text-2xl font-extrabold text-slate-900">{{ admin.tenant?.name || 'TodaTempo' }}</p>
            <p class="mt-1 text-sm text-slate-500">
              {{ admin.tenant?.city || '' }}<template v-if="admin.tenant?.email"> · {{ admin.tenant.email }}</template>
            </p>
          </div>
          <div class="text-right">
            <p class="text-xs uppercase tracking-wide text-slate-400">Facture</p>
            <p class="font-mono text-lg font-bold text-slate-900">{{ order.number }}</p>
            <p class="mt-1 text-sm capitalize text-slate-500">{{ order.createdAt ? formatDate(order.createdAt, { short: true }) : '' }}</p>
          </div>
        </div>

        <!-- Client -->
        <div class="mt-6 grid gap-6 sm:grid-cols-2">
          <div>
            <p class="text-xs font-semibold uppercase text-slate-400">Facturé à</p>
            <p class="mt-1 font-semibold text-slate-800">
              {{ order.billing ? `${order.billing.firstName} ${order.billing.lastName}` : '—' }}
            </p>
            <p class="text-sm text-slate-500">{{ order.customerEmail }}</p>
            <p v-if="order.billing?.street && order.billing.street !== 'Adresse non collectee'" class="text-sm text-slate-500">
              {{ order.billing.street }}<br />{{ order.billing.postcode }} {{ order.billing.city }}
            </p>
          </div>
          <div class="sm:text-right">
            <p class="text-xs font-semibold uppercase text-slate-400">Règlement</p>
            <p class="mt-1 text-sm text-slate-700">{{ order.paymentMethodName || '—' }}</p>
            <p class="text-sm font-medium" :class="order.paymentState === 'paid' ? 'text-emerald-600' : 'text-amber-600'">
              {{ PAY_LABELS[order.paymentState] || order.paymentState }}
            </p>
          </div>
        </div>

        <!-- Lignes -->
        <table class="mt-8 w-full text-sm">
          <thead>
            <tr class="border-b-2 border-slate-200 text-left text-xs uppercase tracking-wide text-slate-400">
              <th class="pb-2">Prestation</th>
              <th class="pb-2 text-right">Qté</th>
              <th class="pb-2 text-right">PU</th>
              <th class="pb-2 text-right">Total</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="(it, i) in order.items" :key="i">
              <td class="py-3 text-slate-700">{{ it.name }}</td>
              <td class="py-3 text-right text-slate-500">{{ it.quantity }}</td>
              <td class="py-3 text-right text-slate-500">{{ formatMoney(it.unitPrice, order.currency) }}</td>
              <td class="py-3 text-right font-medium text-slate-800">{{ formatMoney(it.total, order.currency) }}</td>
            </tr>
          </tbody>
          <tfoot>
            <tr v-if="order.taxTotal" class="text-slate-500">
              <td colspan="3" class="pt-3 text-right">dont taxes</td>
              <td class="pt-3 text-right">{{ formatMoney(order.taxTotal, order.currency) }}</td>
            </tr>
            <tr>
              <td colspan="3" class="pt-3 text-right font-semibold text-slate-800">Total</td>
              <td class="pt-3 text-right font-display text-xl font-bold text-slate-900">{{ formatMoney(order.total, order.currency) }}</td>
            </tr>
          </tfoot>
        </table>

        <!-- Contexte métier (prestation / créneau / client) -->
        <p v-if="order.notes" class="mt-6 rounded-lg bg-slate-50 p-3 text-xs text-slate-500 print:border print:border-slate-200">
          {{ order.notes }}
        </p>

        <p class="mt-8 border-t border-slate-100 pt-4 text-center text-xs text-slate-400">
          Merci pour votre confiance — a tres vite dans les airs ! 🪂
          <template v-if="order.paymentState === 'awaiting_payment'">
            <br />Reference a rappeler pour le virement : <span class="font-mono">{{ order.number }}</span>
          </template>
        </p>
      </div>
    </div>
  </div>
</template>
