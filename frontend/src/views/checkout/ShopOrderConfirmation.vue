<script setup>
import PaymentBreakdown from '@/components/PaymentBreakdown.vue'
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import api from '@/api'
import { formatMoney } from '@/utils/format'

const route = useRoute()
const router = useRouter()
const processing = ref(false)
const order = ref(null)
const loading = ref(true)
const error = ref('')
let timer
let stopped = false
let attempts = 0
const paid = computed(() => order.value?.status === 'paid')
const transfer = computed(() => order.value?.paymentMethod === 'bank_transfer')
const failed = computed(() => ['failed', 'cancelled'].includes(order.value?.status))
const preparation = computed(() => ({ pending: 'En attente de préparation', preparing: 'En préparation', ready: 'Prête', handed_over: 'Remise effectuée' })[order.value?.preparationState] || 'En attente de préparation')

async function refresh() {
  clearTimeout(timer)
  error.value = ''
  try {
    order.value = await api.getShopOrderPayment(route.params.orderToken)
    if (!stopped && !paid.value && !failed.value && !transfer.value && attempts++ < 10) timer = setTimeout(refresh, 2000)
  } catch (e) {
    error.value = e?.message || 'Impossible de consulter la commande.'
  } finally { loading.value = false }
}
async function resumePayment() {
  if (processing.value) return
  processing.value = true
  error.value = ''
  try {
    const confirmation = new URL(router.resolve({ name: 'checkout-shop-confirmation', params: { orderToken: route.params.orderToken } }).href, window.location.origin)
    const stripe = await api.createStripeCheckoutSession({
      orderToken: route.params.orderToken, paymentId: order.value.paymentId, bookingToken: order.value.booking?.id,
      successUrl: `${confirmation}?payment=success`, cancelUrl: `${confirmation}?payment=cancelled`,
    })
    window.location.assign(stripe.url)
  } catch (e) { error.value = e?.message || 'Impossible de reprendre le paiement.' }
  finally { processing.value = false }
}
onMounted(refresh)
onUnmounted(() => { stopped = true; clearTimeout(timer) })
</script>

<template>
  <main class="section py-12">
    <div class="mx-auto max-w-2xl space-y-5">
      <h1 class="font-display text-3xl font-bold">{{ paid ? 'Paiement confirmé' : 'Suivi de votre commande' }}</h1>
      <p v-if="loading" role="status">Chargement de la commande…</p>
      <div v-if="error" role="alert" class="rounded-xl bg-rose-50 p-4 text-rose-700">{{ error }}</div>
      <section v-if="order" class="card space-y-4 p-6" aria-live="polite">
        <p>Référence : <strong class="break-all">{{ order.number }}</strong></p>
        <p>Montant : <strong>{{ formatMoney(order.total, order.currency) }}</strong></p>
        <ul v-if="order.items?.length" class="space-y-2"><li v-for="(item, index) in order.items" :key="index">{{ item.name }} × {{ item.quantity }} — {{ formatMoney(item.total / 100, order.currency) }}</li></ul>
        <p v-if="order.fulfillmentMode">Remise des produits : {{ order.fulfillmentMode === 'delivery' ? 'livraison' : 'retrait au centre' }}.</p>
        <div v-if="order.booking" class="rounded bg-brand-50 p-4"><p>Rendez-vous : {{ order.booking.serviceName }} — {{ new Date(order.booking.slotStart).toLocaleString('fr-FR') }}</p><p>{{ order.booking.status === 'cancelled' ? 'Réservation annulée' : order.booking.status === 'awaiting_payment' ? 'Créneau réservé en attente du paiement' : 'Réservation enregistrée' }}</p></div>
        <PaymentBreakdown :value="order.paymentBreakdown" :currency="order.currency" />
        <div v-if="order.giftCardTerms" class="space-y-2">
          <p>Crédit offert : <strong>{{ formatMoney(order.total, order.currency) }}</strong>, utilisable dans la boutique de {{ order.giftCardTerms.shopName }}.</p>
          <p>Validité : {{ order.giftCardTerms.validityMonths }} mois à compter de la confirmation du paiement.</p>
          <p>Envoi {{ order.giftCardTerms.delivery === 'recipient' ? 'directement au destinataire' : 'à l’acheteur pour offrir lui-même' }}.</p>
        </div>
        <template v-if="paid">
          <p v-if="order.kind === 'gift'">Le paiement de votre cadeau a été encaissé. Le code et le document imprimable sont envoyés à l’adresse choisie lors de l’achat.</p>
          <p v-else-if="order.preparationState">Votre commande peut être préparée. État : {{ preparation }}.</p><p v-else>Votre commande est confirmée.</p>
        </template>
        <p v-else-if="failed">Le paiement a échoué ou a été annulé. Contactez l’établissement avec votre référence pour organiser un nouveau règlement.</p>
        <template v-else-if="transfer">
          <h2 class="font-semibold">En attente de votre virement</h2>
          <p>Indiquez la référence <strong>{{ order.number }}</strong> dans le libellé du virement.</p>
          <p v-if="order.paymentInstructions" class="whitespace-pre-line rounded-xl bg-slate-50 p-4">{{ order.paymentInstructions }}</p>
          <p v-else>Contactez l’établissement pour obtenir ses coordonnées bancaires, en indiquant votre référence de commande.</p>
          <p>{{ order.kind === 'gift' ? 'Le cadeau sera activé après encaissement du montant complet.' : 'La préparation commencera après réception du paiement.' }}</p>
        </template>
        <template v-else>
          <p v-if="route.query.payment === 'cancelled'">Vous avez quitté le paiement. Aucun encaissement n’est confirmé à ce stade.</p>
          <p v-else>La confirmation du paiement est en attente. Vous pouvez actualiser cette page dans quelques instants.</p>
          <p>{{ order.kind === 'gift' ? 'Le cadeau reste inutilisable tant que le paiement n’est pas confirmé.' : 'La préparation commencera après confirmation du paiement.' }}</p>
        </template>
      </section>
      <button v-if="order?.canPay && !paid" class="btn-primary" :disabled="processing" @click="resumePayment">{{ processing ? 'Redirection…' : 'Payer par carte bancaire' }}</button>
      <button v-if="!loading" class="btn-outline" @click="refresh">Actualiser le statut</button>
      <RouterLink :to="{ name: 'tenant-home' }" class="btn-outline ml-3">Retour à la boutique</RouterLink>
    </div>
  </main>
</template>
