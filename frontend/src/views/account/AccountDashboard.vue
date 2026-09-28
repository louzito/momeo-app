<script setup>
import PaymentBreakdown from '@/components/PaymentBreakdown.vue'
import { ref, onMounted, onUnmounted, computed } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { useSessionStore } from '@/stores/session'
import api from '@/api'
import BookingCard from '@/components/BookingCard.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Spinner from '@/components/ui/Spinner.vue'
import { formatDate, formatMoney } from '@/utils/format'
import { splitCustomerBookings } from '@/utils/customerBookings'

const session = useSessionStore()
const router = useRouter()
const orders = ref([])
const bookings = ref([])
const gifts = ref({ received: [], purchased: [] })
const cardCode = ref('')
const claiming = ref(false)
const claimError = ref('')
const claimSuccess = ref('')
const movementLabels = { issue: 'Émission', reserve: 'Montant réservé', debit: 'Paiement', release: 'Réservation libérée', refund: 'Remboursement' }
const cardStatus = { active: 'Active', expired: 'Expirée', inactive: 'Inactive' }

async function claimCard() {
  claiming.value = true
  claimError.value = ''
  claimSuccess.value = ''
  try {
    await api.claimCustomerGiftCard(cardCode.value.trim())
    cardCode.value = ''
    gifts.value = await api.getCustomerGiftCards()
    claimSuccess.value = 'Votre carte cadeau est rattachée à votre compte.'
  } catch (e) {
    claimError.value = e?.message || 'Impossible de rattacher cette carte.'
  } finally {
    claiming.value = false
  }
}

// Relire les soldes au retour du paiement, y compris depuis un autre onglet.
function refreshOnReturn() {
  if (document.visibilityState === 'visible' && !loading.value && !claiming.value) loadAccount()
}
const loading = ref(true)
const error = ref('')

function logout() {
  session.logout()
  router.replace({ name: 'account-login' })
}

const splitBookings = computed(() => splitCustomerBookings(bookings.value))
const upcoming = computed(() => splitBookings.value.upcoming)
const past = computed(() => splitBookings.value.past)

async function loadAccount() {
  loading.value = true
  error.value = ''
  try {
    ;[orders.value, bookings.value, gifts.value] = await Promise.all([
      api.getCustomerOrders(),
      api.getCustomerBookings(),
      api.getCustomerGiftCards(),
    ])
  } catch (e) {
    error.value = e?.message || 'Impossible de charger votre espace client.'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  loadAccount()
  window.addEventListener('focus', refreshOnReturn)
  document.addEventListener('visibilitychange', refreshOnReturn)
})
onUnmounted(() => {
  window.removeEventListener('focus', refreshOnReturn)
  document.removeEventListener('visibilitychange', refreshOnReturn)
})
</script>

<template>
  <div class="section py-10">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="font-display text-3xl font-bold text-slate-900">Mon compte</h1>
        <p class="mt-1 text-slate-500">{{ session.customer.firstName }} · {{ session.customer.email }}</p>
      </div>
      <button class="btn-outline" :disabled="loading || claiming" @click="loadAccount">Actualiser</button>
      <button class="btn-ghost" @click="logout">Se déconnecter</button>
    </div>

    <Spinner v-if="loading" />

    <div v-else-if="error" class="mt-8 rounded-2xl border border-rose-200 bg-rose-50 p-6 text-center text-rose-700">
      <p>{{ error }}</p>
      <button class="btn-outline mt-4" @click="loadAccount">Réessayer</button>
    </div>

    <template v-else>
      <section id="cadeaux" class="mt-8 space-y-4" aria-labelledby="gifts-title">
        <h2 id="gifts-title" class="text-xl font-semibold">Mes cartes cadeaux</h2>
        <p class="text-slate-600">Ajoutez la carte que vous avez reçue avec son code. Vous la retrouverez ensuite ici à chaque connexion.</p>
        <form class="card space-y-3 p-4" @submit.prevent="claimCard">
          <label for="gift-card-code" class="label">Code secret de la carte cadeau</label>
          <input id="gift-card-code" v-model="cardCode" class="input font-mono" required maxlength="32" autocomplete="off" spellcheck="false" />
          <button class="btn-primary" :disabled="claiming">{{ claiming ? 'Ajout en cours…' : 'Ajouter à mon compte' }}</button>
          <p v-if="claimError" role="alert" class="text-rose-700">{{ claimError }}</p>
          <p v-if="claimSuccess" role="status" class="text-emerald-700">{{ claimSuccess }}</p>
        </form>
        <div v-if="gifts.received.length" class="grid gap-4 md:grid-cols-2">
          <article v-for="card in gifts.received" :key="card.id" class="card space-y-3 p-5">
            <h3 class="font-semibold">Carte de {{ formatMoney(card.initialAmount / 100, card.currency) }}</h3>
            <p class="text-2xl font-bold">{{ formatMoney(card.available / 100, card.currency) }} <span class="text-sm font-normal">disponibles</span></p>
            <p v-if="card.reserved">{{ formatMoney(card.reserved / 100, card.currency) }} réservés pour un paiement en cours</p>
            <p>{{ cardStatus[card.status] || card.status }} · Valable jusqu’au {{ formatDate(card.expiresAt, { short: true }) }}</p>
            <p class="text-sm">Code à saisir au paiement : <strong class="block break-all font-mono">{{ card.code }}</strong></p>
            <RouterLink v-if="card.status === 'active' && card.available > 0" :to="{ name: 'shop' }" class="btn-primary">Utiliser dans la boutique</RouterLink>
            <details>
              <summary class="cursor-pointer font-semibold">Historique de la carte</summary>
              <ul v-if="card.history.length" class="mt-3 space-y-3 text-sm">
                <li v-for="(movement, index) in card.history" :key="index">
                  {{ formatDate(movement.createdAt, { short: true }) }} · {{ movementLabels[movement.kind] || movement.kind }} : {{ formatMoney(movement.amount / 100, card.currency) }}
                  <span class="block text-slate-500">Solde disponible après opération : {{ formatMoney(movement.availableAfter / 100, card.currency) }}</span>
                </li>
              </ul>
              <p v-else class="mt-2 text-slate-500">Aucune opération pour le moment.</p>
              <p v-if="card.hasMoreHistory" class="mt-2 text-slate-500">Les 50 dernières opérations sont affichées.</p>
            </details>
          </article>
        </div>
        <EmptyState v-else icon="🎁" title="Aucune carte ajoutée" message="Vous avez reçu une carte cadeau ? Ajoutez son code ci-dessus pour consulter son solde." />
        <h3 class="pt-4 text-lg font-semibold">Cartes cadeaux achetées</h3>
        <ul v-if="gifts.purchased.length" class="card divide-y divide-slate-100">
          <li v-for="card in gifts.purchased" :key="card.id" class="p-4">
            <p class="font-semibold">Carte de {{ formatMoney(card.amount / 100, card.currency) }}</p>
            <p class="text-sm text-slate-500">{{ card.purchaseOrderNumber }} · {{ formatDate(card.createdAt, { short: true }) }}</p>
            <p class="text-sm">Retrouvez la carte dans l’e-mail envoyé lors de l’achat. Son utilisation reste privée pour son bénéficiaire.</p>
          </li>
        </ul>
        <EmptyState v-else icon="🎁" title="Aucune carte achetée" message="Les cartes émises après paiement apparaîtront ici." />
        <div class="rounded-xl bg-slate-50 p-4">
          <h3 class="font-semibold">Vous avez un ancien bon pour une prestation ?</h3>
          <p class="mt-1 text-sm text-slate-600">Votre bon reste valable selon ses conditions. Retrouvez-le avec son code et l’adresse e-mail de son bénéficiaire.</p>
          <RouterLink :to="{ name: 'beneficiary-login', query: { legacy: '1' } }" class="mt-2 inline-block text-brand-700 underline">Accéder à mes anciens bons prestation</RouterLink>
        </div>
      </section>

      <!-- Rendez-vous à venir -->
      <section class="mt-8">
        <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-400">Rendez-vous à venir</h2>
        <div v-if="upcoming.length" class="grid gap-3">
          <BookingCard v-for="b in upcoming" :key="b.id" :booking="b" />
        </div>
        <EmptyState v-else icon="🗓️" title="Aucun rendez-vous à venir" message="Choisissez votre prochaine prestation dès maintenant.">
          <RouterLink :to="{ name: 'shop' }" class="btn-primary">Voir les prestations</RouterLink>
        </EmptyState>
      </section>

      <!-- Historique des reservations -->
      <section class="mt-10">
        <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-400">Rendez-vous passés</h2>
        <div v-if="past.length" class="grid gap-3">
          <BookingCard v-for="b in past" :key="b.id" :booking="b" />
        </div>
        <EmptyState v-else icon="📋" title="Aucun rendez-vous passé" message="Votre historique apparaîtra ici." />
      </section>

      <!-- Commandes -->
      <section class="mt-10">
        <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-400">Historique des commandes</h2>
        <div v-if="orders.length" class="card divide-y divide-slate-100">
          <div v-for="o in orders" :key="o.id" class="flex flex-wrap items-center justify-between gap-3 p-4">
            <div>
              <p class="font-mono font-semibold text-slate-800">{{ o.number }}</p>
              <p class="text-sm text-slate-400">
                {{ formatDate(o.createdAt, { short: true }) }} ·
                {{ o.kind === 'gift_card' ? 'Carte cadeau' : o.kind === 'gift' ? 'Bon prestation' : 'Achat boutique' }}
              </p>
            </div>
            <div class="flex items-center gap-3">
              <StatusBadge :status="o.status" />
              <span class="font-semibold text-slate-800">{{ formatMoney(o.total, o.currency) }}</span>
              <PaymentBreakdown :value="o.paymentBreakdown" :currency="o.currency" />
            </div>
          </div>
        </div>
        <EmptyState v-else icon="🧾" title="Aucune commande" message="Vos commandes apparaîtront ici." />
      </section>
    </template>
  </div>
</template>
