<script setup>
import { onMounted, ref } from 'vue'
import { getGiftCards, getGiftCardMovements } from '@/api/adminApi'
import { formatMoney, formatDate } from '@/utils/format'
import Spinner from '@/components/ui/Spinner.vue'

const cards = ref([])
const page = ref(1)
const more = ref(false)
const loading = ref(false)
const error = ref('')
const selected = ref(null)
const movements = ref([])
const movementPage = ref(1)
const movementMore = ref(false)
const movementLoading = ref(false)
const movementError = ref('')
const statuses = { active: 'Active', inactive: 'Inactive', expired: 'Expirée' }
const kinds = { issue: 'Émission', reserve: 'Réservation', debit: 'Débit', release: 'Libération', refund: 'Restitution' }
const money = (cents, currency) => formatMoney(cents / 100, currency)

async function load(target = 1) {
  loading.value = true
  error.value = ''
  try {
    const data = await getGiftCards(target)
    cards.value = data.member || []
    more.value = data.hasMore
    page.value = target
    selected.value = null
  } catch {
    error.value = 'Impossible de charger les cartes cadeaux. Veuillez réessayer.'
  } finally {
    loading.value = false
  }
}
async function history(card, target = 1) {
  if (movementLoading.value) return
  selected.value = card.id
  movements.value = []
  movementLoading.value = true
  movementError.value = ''
  try {
    const data = await getGiftCardMovements(card.id, target)
    movements.value = data.member || []
    movementPage.value = target
    movementMore.value = data.hasMore
  } catch {
    movementError.value = 'Impossible de charger l’historique. Veuillez réessayer.'
  } finally {
    movementLoading.value = false
  }
}
onMounted(() => load())
</script>

<template>
  <section aria-labelledby="gift-cards-title">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h2 id="gift-cards-title" class="font-display text-2xl font-bold text-slate-900">Cartes cadeaux à solde</h2>
      <button class="btn-ghost" :disabled="loading || movementLoading" @click="load(page)">Actualiser</button>
    </div>
    <p class="mt-2 text-sm text-slate-500">Crédit utilisable uniquement dans votre boutique. Les montants réservés sont en attente de validation. Les restitutions ne prolongent pas la validité de la carte.</p>
    <p v-if="error" role="alert" class="mt-3 text-rose-700">{{ error }}</p>
    <Spinner v-if="loading" />
    <template v-else-if="!error">
      <p v-if="!cards.length" class="card mt-4 p-4 text-slate-500">Aucune carte cadeau à solde vendue pour le moment.</p>
      <article v-for="card in cards" :key="card.id" class="card mt-4 p-4">
        <div class="flex flex-wrap justify-between gap-2">
          <span class="break-all font-mono text-sm">{{ card.code }}</span>
          <span>{{ statuses[card.status] || card.status }}</span>
        </div>
        <dl class="mt-3 grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
          <div><dt>Montant initial</dt><dd class="font-semibold">{{ money(card.initialAmount, card.currency) }}</dd></div>
          <div><dt>Disponible</dt><dd class="font-semibold">{{ money(card.available, card.currency) }}</dd></div>
          <div><dt>Réservé</dt><dd class="font-semibold">{{ money(card.reserved, card.currency) }}</dd></div>
        </dl>
        <p class="mt-3 break-words text-sm text-slate-500">Commande {{ card.purchaseOrderNumber }} · Expire le {{ formatDate(card.expiresAt) }}</p>
        <button class="btn-ghost mt-3" :disabled="movementLoading" :aria-expanded="selected === card.id" @click="selected === card.id ? selected = null : history(card)">Historique des mouvements</button>
        <div v-if="selected === card.id" class="mt-3 border-t pt-3">
          <Spinner v-if="movementLoading" />
          <div v-else-if="movementError" role="alert">
            <p class="text-rose-700">{{ movementError }}</p>
            <button class="btn-ghost" @click="history(card)">Réessayer</button>
          </div>
          <template v-else>
            <p v-if="!movements.length">Aucun mouvement.</p>
            <ul class="space-y-3">
              <li v-for="movement in movements" :key="movement.id" class="text-sm">
                <p class="font-semibold">{{ kinds[movement.kind] }} · {{ money(movement.amount, card.currency) }}</p>
                <p class="break-words text-slate-500">{{ formatDate(movement.createdAt) }} · Commande {{ movement.orderNumber }}</p>
                <p v-if="movement.reference" class="break-words">Référence : {{ movement.reference }}</p>
                <p>Après opération : {{ money(movement.availableAfter, card.currency) }} disponibles, {{ money(movement.reservedAfter, card.currency) }} réservés.</p>
              </li>
            </ul>
            <div class="mt-3 flex gap-3">
              <button v-if="movementPage > 1" class="btn-ghost" @click="history(card, movementPage - 1)">Mouvements précédents</button>
              <button v-if="movementMore" class="btn-ghost" @click="history(card, movementPage + 1)">Mouvements suivants</button>
            </div>
          </template>
        </div>
      </article>
      <div class="mt-3 flex gap-3">
        <button v-if="page > 1" class="btn-ghost" :disabled="movementLoading" @click="load(page - 1)">Page précédente</button>
        <button v-if="more" class="btn-ghost" :disabled="movementLoading" @click="load(page + 1)">Page suivante</button>
      </div>
    </template>
  </section>
</template>
