<script setup>
import { computed, onMounted } from 'vue'
import { useRouter, RouterLink } from 'vue-router'
import { useCartStore } from '@/stores/cart'
import { useTenantContext } from '@/composables/useTenantContext'
import { formatDate, formatTime, formatMoney } from '@/utils/format'

const router = useRouter()
const cart = useCartStore()
const { tenant, slug } = useTenantContext()

const result = computed(() => cart.lastResult)
const booking = computed(() => result.value?.booking)
const order = computed(() => result.value?.order)
// Commande reelle Sylius payee par virement : en attente de reception.
const awaitingTransfer = computed(() => order.value?.status === 'awaiting_payment')

onMounted(() => {
  if (!cart.lastResult?.order) {
    router.replace({ name: 'tenant-home', params: { slug: slug.value } })
  }
})
</script>

<template>
  <div v-if="order" class="section py-12">
    <div class="mx-auto max-w-2xl text-center">
      <div
        class="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-full text-3xl"
        :class="awaitingTransfer ? 'bg-amber-100' : 'bg-emerald-100'"
      >
        {{ awaitingTransfer ? '🏦' : '✓' }}
      </div>
      <h1 class="font-display text-3xl font-extrabold text-slate-900">
        {{ awaitingTransfer ? 'Commande enregistree !' : 'Reservation confirmee !' }}
      </h1>
      <p class="mt-2 text-slate-500">
        <template v-if="awaitingTransfer">
          Merci {{ cart.jumper.firstName || '' }}. Votre creneau est garde — il ne reste qu'a effectuer le virement.
        </template>
        <template v-else>
          Merci {{ cart.jumper.firstName || '' }}. Un email de confirmation vient de vous etre envoye (simule).
        </template>
      </p>
    </div>

    <div class="mx-auto mt-8 max-w-2xl space-y-4">
      <!-- Instructions de virement (commande reelle Sylius, paymentState awaiting_payment) -->
      <div v-if="awaitingTransfer" class="card border-amber-200 bg-amber-50/60 p-6">
        <p class="font-semibold text-amber-900">💸 Reglez par virement pour confirmer votre rendez-vous</p>
        <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
          <div>
            <dt class="text-amber-700/70">Montant</dt>
            <dd class="font-semibold text-amber-900">{{ formatMoney(order.total, order.currency) }}</dd>
          </div>
          <div>
            <dt class="text-amber-700/70">Reference a indiquer</dt>
            <dd class="font-mono font-semibold text-amber-900">{{ order.number }}</dd>
          </div>
        </dl>
        <!-- Coordonnees de reglement saisies par le centre dans son espace admin
             (Moyens de paiement -> Instructions de reglement). -->
        <div
          v-if="order.paymentInstructions"
          class="mt-3 whitespace-pre-line rounded-xl bg-white/70 p-4 font-mono text-sm text-amber-900"
        >{{ order.paymentInstructions }}</div>
        <p v-else class="mt-3 text-sm text-amber-800">
          Le centre vous communiquera ses coordonnees bancaires par email.
        </p>
        <p class="mt-3 text-xs text-amber-700/80">
          Votre reservation sera confirmee par le centre a reception du virement (2-3 jours ouvres).
        </p>
      </div>

      <div class="card p-6">
        <div class="flex items-center justify-between border-b border-slate-100 pb-4">
          <div>
            <p class="text-xs uppercase text-slate-400">Commande</p>
            <p class="font-mono font-semibold text-slate-800">{{ order.number }}</p>
          </div>
          <span class="font-display text-2xl font-bold text-brand-700">{{ formatMoney(order.total, order.currency) }}</span>
        </div>

        <dl class="mt-4 grid grid-cols-2 gap-4 text-sm">
          <div>
            <dt class="text-slate-400">Prestation</dt>
            <dd class="font-semibold text-slate-800">{{ booking.jumpTypeName }}</dd>
          </div>
          <div>
            <dt class="text-slate-400">Reference</dt>
            <dd class="font-mono font-semibold text-slate-800">{{ booking.reference }}</dd>
          </div>
          <div>
            <dt class="text-slate-400">Date</dt>
            <dd class="font-semibold capitalize text-slate-800">{{ formatDate(booking.slotStart, { weekday: true, short: true }) }}</dd>
          </div>
          <div>
            <dt class="text-slate-400">Heure</dt>
            <dd class="font-semibold text-slate-800">{{ formatTime(booking.slotStart) }}</dd>
          </div>
        </dl>
      </div>

      <div class="flex flex-col gap-3 sm:flex-row">
        <RouterLink :to="{ name: 'boarding-pass', params: { bookingId: booking.id } }" class="btn-primary flex-1">
          Voir ma confirmation
        </RouterLink>
        <RouterLink :to="{ name: 'account-dashboard' }" class="btn-outline flex-1">Mon compte</RouterLink>
      </div>

      <p class="text-center text-sm text-slate-400">
        <RouterLink :to="{ name: 'tenant-home', params: { slug } }" class="underline">Retour a {{ tenant?.name }}</RouterLink>
      </p>
    </div>
  </div>
</template>
