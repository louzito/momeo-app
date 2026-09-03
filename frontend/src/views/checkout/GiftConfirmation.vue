<script setup>
import { computed, onMounted } from 'vue'
import { useRouter, RouterLink } from 'vue-router'
import { useCartStore } from '@/stores/cart'
import { useTenantContext } from '@/composables/useTenantContext'
import QrCode from '@/components/ui/QrCode.vue'
import { formatDate, formatMoney } from '@/utils/format'

const router = useRouter()
const cart = useCartStore()
const { tenant, slug } = useTenantContext()

const voucher = computed(() => cart.lastResult?.voucher)
const order = computed(() => cart.lastResult?.order)
// Cheque cadeau reel : le GiftVoucher est cree en `awaiting_payment` des la
// commande enregistree, puis passe `active` (email + QR envoyes) seulement
// quand le centre encaisse le virement (voir ActivateGiftVoucherOnPaymentCompletedListener).
const awaitingPayment = computed(() => voucher.value?.awaitingPayment)

onMounted(() => {
  if (!cart.lastResult?.voucher) {
    router.replace({ name: 'tenant-home', params: { slug: slug.value } })
  }
})

function downloadPdf() {
  // PDF factice : on utilise l'impression du navigateur sur la maquette.
  window.print()
}
</script>

<template>
  <div v-if="voucher && awaitingPayment" class="section py-12">
    <div class="mx-auto max-w-2xl text-center">
      <div class="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-full bg-amber-100 text-3xl">🏦</div>
      <h1 class="font-display text-3xl font-extrabold text-slate-900">Commande enregistree !</h1>
      <p class="mt-2 text-slate-500">
        Il ne reste qu'a effectuer le virement — le cheque cadeau (code + QR) sera envoye par email a
        <strong>{{ voucher.beneficiaryEmail }}</strong> des sa reception.
      </p>
    </div>

    <div class="mx-auto mt-8 max-w-2xl space-y-4">
      <div class="card border-amber-200 bg-amber-50/60 p-6">
        <p class="font-semibold text-amber-900">💸 Reglez par virement pour activer le cheque cadeau</p>
        <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
          <div>
            <dt class="text-amber-700/70">Montant</dt>
            <dd class="font-semibold text-amber-900">{{ formatMoney(order?.total, order?.currency) }}</dd>
          </div>
          <div>
            <dt class="text-amber-700/70">Reference a indiquer</dt>
            <dd class="font-mono font-semibold text-amber-900">{{ order?.number }}</dd>
          </div>
        </dl>
        <div
          v-if="order?.paymentInstructions"
          class="mt-3 whitespace-pre-line rounded-xl bg-white/70 p-4 font-mono text-sm text-amber-900"
        >{{ order.paymentInstructions }}</div>
        <p v-else class="mt-3 text-sm text-amber-800">Le centre vous communiquera ses coordonnees bancaires par email.</p>
      </div>

      <div class="card p-6">
        <p class="text-sm font-semibold text-slate-700">Cheque cadeau</p>
        <dl class="mt-3 grid grid-cols-2 gap-4 text-sm">
          <div>
            <dt class="text-slate-400">Prestation offerte</dt>
            <dd class="font-semibold text-slate-800">{{ voucher.jumpTypeName }}</dd>
          </div>
          <div>
            <dt class="text-slate-400">Code (provisoire)</dt>
            <dd class="font-mono font-semibold text-slate-800">{{ voucher.code }}</dd>
          </div>
          <div>
            <dt class="text-slate-400">Beneficiaire</dt>
            <dd class="font-semibold text-slate-800">{{ voucher.beneficiaryName || voucher.beneficiaryEmail }}</dd>
          </div>
          <div>
            <dt class="text-slate-400">Valable jusqu'au</dt>
            <dd class="font-semibold text-slate-800">{{ formatDate(voucher.expiresAt) }}</dd>
          </div>
        </dl>
      </div>

      <p class="text-center text-sm text-slate-400">
        <RouterLink :to="{ name: 'tenant-home', params: { slug } }" class="underline">Retour a {{ tenant?.name }}</RouterLink>
      </p>
    </div>
  </div>

  <div v-else-if="voucher" class="section py-12">
    <div class="mx-auto max-w-2xl text-center">
      <div class="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-full bg-accent-500/15 text-3xl">🎁</div>
      <h1 class="font-display text-3xl font-extrabold text-slate-900">Cheque cadeau genere !</h1>
      <p class="mt-2 text-slate-500">
        Il a ete envoye a <strong>{{ voucher.beneficiaryEmail }}</strong>. Voici son apercu :
      </p>
    </div>

    <!-- Apercu du cheque cadeau (PDF factice) -->
    <div class="mx-auto mt-8 max-w-2xl overflow-hidden rounded-3xl shadow-soft ring-1 ring-slate-200 print:shadow-none">
      <div class="bg-gradient-to-br from-brand-700 via-brand-600 to-accent-500 p-8 text-white">
        <div class="flex items-start justify-between">
          <div>
            <p class="text-sm uppercase tracking-widest text-white/70">{{ tenant?.name }}</p>
            <p class="mt-1 font-display text-3xl font-extrabold">Cheque cadeau</p>
          </div>
          <span class="text-4xl">🪂</span>
        </div>
        <p class="mt-8 text-lg">{{ voucher.jumpTypeName }}</p>
        <p class="font-display text-4xl font-bold">{{ formatMoney(voucher.amount, voucher.currency) }}</p>
      </div>

      <div class="grid gap-6 bg-white p-8 sm:grid-cols-[1fr_auto]">
        <div class="space-y-3">
          <div>
            <p class="text-xs uppercase text-slate-400">Code du cheque</p>
            <p class="font-mono text-2xl font-bold tracking-wider text-slate-900">{{ voucher.code }}</p>
          </div>
          <div>
            <p class="text-xs uppercase text-slate-400">Pour</p>
            <p class="font-semibold text-slate-800">{{ voucher.beneficiaryName || voucher.beneficiaryEmail }}</p>
          </div>
          <div v-if="voucher.personalMessage">
            <p class="text-xs uppercase text-slate-400">Message</p>
            <p class="italic text-slate-600">« {{ voucher.personalMessage }} »</p>
          </div>
          <div>
            <p class="text-xs uppercase text-slate-400">Valable jusqu'au</p>
            <p class="font-semibold text-slate-800">{{ formatDate(voucher.expiresAt) }}</p>
          </div>
        </div>
        <div class="flex flex-col items-center justify-center border-slate-200 sm:border-l sm:pl-6">
          <QrCode :value="voucher.code" :size="140" />
          <p class="mt-2 text-center text-[11px] text-slate-400">A activer sur l'espace beneficiaire</p>
        </div>
      </div>
    </div>

    <div class="mx-auto mt-8 flex max-w-2xl flex-col gap-3 sm:flex-row">
      <button class="btn-primary flex-1" @click="downloadPdf">⬇️ Telecharger le PDF (imprimer)</button>
      <RouterLink :to="{ name: 'beneficiary-login' }" class="btn-outline flex-1">Espace beneficiaire</RouterLink>
    </div>
    <p class="mx-auto mt-4 max-w-2xl text-center text-sm text-slate-400">
      Le beneficiaire pourra se connecter avec ce code et son email pour choisir sa date.
      <RouterLink :to="{ name: 'tenant-home', params: { slug } }" class="underline">Retour au centre</RouterLink>
    </p>
  </div>
</template>
