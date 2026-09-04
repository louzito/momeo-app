<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useCheckoutGuard } from '@/composables/useCheckoutGuard'
import { useTenantContext } from '@/composables/useTenantContext'
import { useSessionStore } from '@/stores/session'
import api from '@/api'
import CheckoutLayout from '@/components/CheckoutLayout.vue'
import { formatMoney } from '@/utils/format'

const router = useRouter()
const session = useSessionStore()
const { cart } = useCheckoutGuard()
const { tenant, slug } = useTenantContext()

// Le virement et Stripe créent une vraie commande Sylius. Aucun moyen de
// paiement simulé n'est proposé : toute réservation publique est persistée.
// Cheques cadeaux REELS (chantier 2026-08) : un cheque doit correspondre a une
// vraie commande Sylius (le backend cree le GiftVoucher a partir de la
// commande) -> le virement est donc le SEUL moyen de paiement propose pour un
// cadeau.
// Le virement n'est propose QUE si le centre l'a active dans son espace admin
// (payment-method Sylius `bank_transfer` enabled) — verifie sur le shop API.
const bankMethod = ref(null)
const stripeMethod = ref(null)
const methodsLoaded = ref(false)
const canBankTransfer = computed(() => !!bankMethod.value)
const canStripe = computed(() => !!stripeMethod.value && !cart.isGift)
const method = ref('bank_transfer')

onMounted(async () => {
  try {
    const list = (await api.getCheckoutPaymentMethods?.()) || []
    bankMethod.value = list.find((m) => m.code === 'bank_transfer') || null
    stripeMethod.value = list.find((m) => m.code === 'stripe_web_elements') || null
  } catch {
    bankMethod.value = null
  }
  methodsLoaded.value = true
  if (canStripe.value) selectMethod('stripe_web_elements')
  else if (canBankTransfer.value) selectMethod('bank_transfer')
})

const processing = ref(false)
const error = ref('')

function selectMethod(m) {
  method.value = m
  cart.setPaymentMethod(m)
}

// Sans moyen actif chez le centre : aucun moyen de creer une
// vraie commande -> on bloque avant l'appel API plutot que de laisser passer
// silencieusement un cheque cadeau mock.
const blocked = computed(() => methodsLoaded.value && !canBankTransfer.value && !canStripe.value)

async function pay() {
  if (blocked.value) {
    error.value = "Le virement n'est pas active par ce centre : impossible d'enregistrer la commande pour le moment. Contactez le centre."
    return
  }
  processing.value = true
  error.value = ''
  try {
    cart.setPaymentMethod(method.value)
    const result = await cart.checkout(session.customer?.id || null)
    if (method.value === 'stripe_web_elements') {
      const confirmation = new URL(`${slug.value}/checkout/confirmation/${result.booking.id}`, `${window.location.origin}/`)
      const stripe = await api.createStripeCheckoutSession({
        orderToken: result.order.orderToken,
        paymentId: result.order.paymentId,
        bookingToken: result.booking.id,
        successUrl: `${confirmation.toString()}?payment=success`,
        cancelUrl: `${confirmation.toString()}?payment=cancelled`,
      })
      window.location.assign(stripe.url)
      return
    }
    const next = cart.isGift ? 'checkout-gift-confirmation' : 'checkout-confirmation'
    const params = { slug: slug.value }
    if (!cart.isGift) params.bookingId = result.booking.id
    router.push({ name: next, params })
  } catch (e) {
    error.value = e?.message || 'La commande a echoue. Reessayez.'
  } finally {
    processing.value = false
  }
}
</script>

<template>
  <CheckoutLayout
    v-if="cart.jumpType"
    step="payment"
    title="Paiement"
    subtitle="Choisissez votre moyen de paiement."
  >
    <div class="max-w-lg">
      <!-- Choix du moyen de paiement -->
      <div class="grid gap-3 sm:grid-cols-2">
        <button
          v-if="canStripe"
          type="button"
          class="card p-4 text-left transition hover:border-brand-400"
          :class="method === 'stripe_web_elements' ? 'border-brand-500 ring-2 ring-brand-500/20' : ''"
          @click="selectMethod('stripe_web_elements')"
        >
          <div class="text-2xl">💳</div>
          <p class="mt-2 font-semibold text-slate-900">{{ stripeMethod?.name || 'Carte bancaire' }}</p>
          <p class="mt-1 text-xs text-slate-500">Paiement sécurisé par Stripe.</p>
        </button>

        <button
          v-if="canBankTransfer"
          type="button"
          class="card p-4 text-left transition hover:border-brand-400"
          :class="method === 'bank_transfer' ? 'border-brand-500 ring-2 ring-brand-500/20' : ''"
          @click="selectMethod('bank_transfer')"
        >
          <div class="text-2xl">🏦</div>
          <p class="mt-2 font-semibold text-slate-900">{{ bankMethod?.name || 'Virement bancaire' }}</p>
          <p class="mt-1 text-xs text-slate-500">
            Commande enregistree immediatement, confirmee a reception du virement.
          </p>
        </button>

      </div>

      <!-- Virement non actif : blocage explicite, aucune confirmation simulée. -->
      <div v-if="blocked" class="mt-6 rounded-2xl border border-rose-200 bg-rose-50 p-5 text-sm text-rose-700">
        Ce centre n'a pas encore active le virement bancaire : il est impossible d'enregistrer une commande reelle pour le moment. Contactez le centre directement.
      </div>

      <!-- Virement : comment ca marche -->
      <div v-if="method === 'bank_transfer' && !blocked" class="mt-6 rounded-2xl border border-brand-200 bg-brand-50/50 p-5">
        <p class="font-semibold text-slate-800">Comment ca marche</p>
        <ol v-if="cart.isGift" class="mt-2 list-decimal space-y-1 pl-5 text-sm text-slate-600">
          <li>Votre commande est enregistree tout de suite.</li>
          <li>Vous recevez les coordonnees bancaires et la reference a indiquer.</li>
          <li>Des reception du virement, le cheque cadeau (code + QR) est envoye par email au beneficiaire.</li>
        </ol>
        <ol v-else class="mt-2 list-decimal space-y-1 pl-5 text-sm text-slate-600">
          <li>Votre commande est enregistree tout de suite (creneau garde).</li>
          <li>Vous recevez les coordonnees bancaires et la reference a indiquer.</li>
          <li>L'etablissement confirme votre rendez-vous a reception du virement.</li>
        </ol>
      </div>

      <div v-if="error" class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
        ⚠️ {{ error }}
      </div>

      <button class="btn-primary mt-6 w-full py-3 text-base" :disabled="processing || blocked" @click="pay">
        <template v-if="processing">Enregistrement…</template>
        <template v-else>
          {{ method === 'stripe_web_elements'
            ? `Payer ${formatMoney(cart.total, tenant?.currency)} par carte`
            : `Commander ${formatMoney(cart.total, tenant?.currency)} (payer par virement)` }}
        </template>
      </button>
      <p class="mt-3 flex items-center justify-center gap-1 text-xs text-slate-400">
        🔒 Commande réelle (Sylius) · paiement sécurisé
      </p>
    </div>
  </CheckoutLayout>
</template>
