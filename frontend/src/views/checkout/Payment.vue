<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useCheckoutGuard } from '@/composables/useCheckoutGuard'
import { useTenantContext } from '@/composables/useTenantContext'
import { useSessionStore } from '@/stores/session'
import api from '@/api'
import GiftCardPayment from '@/components/GiftCardPayment.vue'
import CheckoutLayout from '@/components/CheckoutLayout.vue'
import { formatMoney } from '@/utils/format'

const props = defineProps({ embedded: Boolean, beforePay: { type: Function, default: null } })
const emit = defineEmits(['processing'])
const router = useRouter()
const session = useSessionStore()
const { cart } = useCheckoutGuard()
const { tenant } = useTenantContext()

const bankMethod = ref(null)
const stripeMethod = ref(null)
const methodsLoaded = ref(false)
const canBankTransfer = computed(() => !!bankMethod.value)
const canStripe = computed(() => !!stripeMethod.value)
const noOnlinePayment = computed(() => !cart.isGift && cart.dueNowCents === 0)
const method = ref('bank_transfer')

const giftPayment = ref(null)
const giftFull = computed(() => !!giftPayment.value?.code && giftPayment.value.remaining === 0)
const dueNow = computed(() => giftPayment.value?.code ? giftPayment.value.remaining / 100 : cart.dueNow)
function useGift(value) {
  giftPayment.value = value; cart.giftCardCode = value.code
  selectMethod(value.code ? (value.remaining === 0 ? 'gift_card' : 'stripe_web_elements') : (canStripe.value ? 'stripe_web_elements' : 'bank_transfer'))
}
const methodsError = ref('')

async function loadMethods() {
  methodsLoaded.value = false
  methodsError.value = ''
  try {
    const list = (await api.getCheckoutPaymentMethods?.()) || []
    bankMethod.value = list.find((m) => m.code === 'bank_transfer') || null
    stripeMethod.value = list.find((m) => m.code === 'stripe_web_elements') || null
  } catch {
    bankMethod.value = null
    stripeMethod.value = null
    methodsError.value = 'Impossible de charger les moyens de paiement.'
  }
  methodsLoaded.value = true
  if (cart.lastResult) selectMethod(cart.lastResult.order.paymentMethod)
  else if (noOnlinePayment.value) selectMethod('none')
  else if (canStripe.value) selectMethod('stripe_web_elements')
  else if (canBankTransfer.value) selectMethod('bank_transfer')
}
onMounted(loadMethods)

const processing = ref(false)
const error = ref('')

function selectMethod(m) {
  method.value = m
  cart.setPaymentMethod(m)
}

// Sans moyen actif chez le centre : aucun moyen de creer une
// vraie commande -> on bloque avant l'appel API plutot que de laisser passer
// silencieusement un cheque cadeau mock.
const blocked = computed(() => methodsLoaded.value && !noOnlinePayment.value && !giftFull.value && (giftPayment.value?.code ? !canStripe.value : (!canBankTransfer.value && !canStripe.value)))

async function pay() {
  if (processing.value || !methodsLoaded.value) return
  if (blocked.value) {
    error.value = "Aucun moyen de paiement n’est disponible. Contactez l’établissement."
    return
  }
  processing.value = true
  emit('processing', true)
  error.value = ''
  try {
    if (!cart.lastResult && props.beforePay && !(await props.beforePay())) return
    cart.setPaymentMethod(method.value)
    const result = await cart.checkout(session.customer?.id || null)
    if (!cart.isGift && cart.giftCardCode && !result.order.giftSettled) {
      const gift = await api.settleGiftCardPayment(result.order.orderToken)
      Object.assign(result.order, { paymentId: gift.paymentId, paymentMethod: gift.paymentMethod, paymentBreakdown: gift, status: gift.status, giftSettled: true })
    }
    if (result.order.paymentMethod === 'stripe_web_elements') {
      const destination = cart.isGift
        ? { name: 'checkout-shop-confirmation', params: { orderToken: result.order.orderToken } }
        : { name: 'checkout-confirmation', params: { bookingId: result.booking.id } }
      const confirmation = new URL(router.resolve(destination).href, window.location.origin)
      const stripe = await api.createStripeCheckoutSession({
        orderToken: result.order.orderToken,
        paymentId: result.order.paymentId,
        bookingToken: result.booking?.id,
        successUrl: `${confirmation.toString()}?payment=success`,
        cancelUrl: `${confirmation.toString()}?payment=cancelled`,
      })
      window.location.assign(stripe.url)
      return
    }
    await router.push(cart.isGift
      ? { name: 'checkout-shop-confirmation', params: { orderToken: result.order.orderToken } }
      : { name: 'checkout-confirmation', params: { bookingId: result.booking.id } })
  } catch (e) {
    error.value = e?.message || 'La commande a échoué. Réessayez.'
  } finally {
    processing.value = false
    emit('processing', false)
  }
}
</script>

<template>
  <component
    :is="embedded ? 'section' : CheckoutLayout"
    :class="embedded ? 'mt-8' : ''"
    v-if="cart.jumpType"
    step="payment"
    title="Paiement"
    subtitle="Choisissez votre moyen de paiement."
  >
    <div class="max-w-lg">
      <h2 v-if="embedded" class="mb-4 text-lg font-semibold">Paiement</h2>
      <p v-if="!methodsLoaded" role="status" class="mb-4 text-sm text-slate-500">Chargement des moyens de paiement…</p>
      <div v-if="methodsError" role="alert" class="mb-4 text-sm text-rose-700">
        <p>{{ methodsError }}</p>
        <button type="button" class="btn-outline mt-2" @click="loadMethods">Réessayer</button>
      </div>
      <GiftCardPayment v-if="!cart.isGift && cart.dueNowCents > 0" :due="cart.dueNowCents" :later="Math.round(cart.balanceDue * 100)" :disabled="processing || !!cart.lastResult" @change="useGift" />
      <p v-if="giftFull" class="mb-4 text-sm text-emerald-700">Le montant dû maintenant sera réglé intégralement avec votre carte cadeau.</p>
      <!-- Choix du moyen de paiement -->
      <div v-if="noOnlinePayment" class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 text-sm text-emerald-800">
        Aucun paiement n’est demandé maintenant. Votre réservation sera enregistrée et le solde de {{ formatMoney(cart.balanceDue, tenant?.currency) }} sera à régler sur place.
      </div>
      <div v-else-if="!giftFull" class="grid gap-3 sm:grid-cols-2">
        <button
          v-if="canStripe"
          type="button"
          class="card p-4 text-left transition hover:border-brand-400"
          :class="method === 'stripe_web_elements' ? 'border-brand-500 ring-2 ring-brand-500/20' : ''"
          :disabled="processing || !!cart.lastResult"
          @click="selectMethod('stripe_web_elements')"
        >
          <div class="text-2xl">💳</div>
          <p class="mt-2 font-semibold text-slate-900">{{ stripeMethod?.name || 'Carte bancaire' }}</p>
          <p class="mt-1 text-xs text-slate-500">Paiement sécurisé par Stripe.</p>
        </button>

        <button
          v-if="canBankTransfer && !giftPayment?.code"
          type="button"
          class="card p-4 text-left transition hover:border-brand-400"
          :class="method === 'bank_transfer' ? 'border-brand-500 ring-2 ring-brand-500/20' : ''"
          :disabled="processing || !!cart.lastResult"
          @click="selectMethod('bank_transfer')"
        >
          <div class="text-2xl">🏦</div>
          <p class="mt-2 font-semibold text-slate-900">{{ bankMethod?.name || 'Virement bancaire' }}</p>
          <p class="mt-1 text-xs text-slate-500">
            Commande enregistrée immédiatement, confirmée à réception du virement.
          </p>
        </button>

      </div>

      <!-- Virement non actif : blocage explicite, aucune confirmation simulée. -->
      <div v-if="blocked" class="mt-6 rounded-2xl border border-rose-200 bg-rose-50 p-5 text-sm text-rose-700">
        Aucun moyen de paiement n’est disponible pour le moment. Contactez l’établissement.
      </div>

      <!-- Virement : comment ca marche -->
      <div v-if="methodsLoaded && method === 'bank_transfer' && !blocked" class="mt-6 rounded-2xl border border-brand-200 bg-brand-50/50 p-5">
        <p class="font-semibold text-slate-800">Comment ça marche</p>
        <ol v-if="cart.isGift" class="mt-2 list-decimal space-y-1 pl-5 text-sm text-slate-600">
          <li>Votre commande est enregistrée tout de suite.</li>
          <li>Vous recevez les coordonnées bancaires et la référence à indiquer.</li>
          <li>Dès réception du virement, le cadeau est activé et envoyé par e-mail au bénéficiaire.</li>
        </ol>
        <ol v-else class="mt-2 list-decimal space-y-1 pl-5 text-sm text-slate-600">
          <li>Votre commande est enregistrée tout de suite (créneau conservé).</li>
          <li>Vous recevez les coordonnées bancaires et la référence à indiquer.</li>
          <li>L’établissement confirme votre rendez-vous à réception du virement.</li>
        </ol>
      </div>

      <div v-if="error" class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
        ⚠️ {{ error }}
      </div>

      <button class="btn-primary mt-6 w-full py-3 text-base" :disabled="processing || !methodsLoaded || blocked" @click="pay">
        <template v-if="processing">Enregistrement…</template>
        <template v-else>
          {{ giftFull ? 'Confirmer avec la carte cadeau' : noOnlinePayment ? 'Confirmer la réservation sans paiement'
            : method === 'stripe_web_elements'
              ? `Payer ${formatMoney(dueNow, tenant?.currency)} par carte`
              : `Commander ${formatMoney(dueNow, tenant?.currency)} (payer par virement)` }}
        </template>
      </button>
      <p class="mt-3 flex items-center justify-center gap-1 text-xs text-slate-400">
        🔒 Réservation sécurisée
      </p>
    </div>
  </component>
</template>
