<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import api from '@/api'
import { useAdminStore } from '@/stores/admin'
import Spinner from '@/components/ui/Spinner.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'

const admin = useAdminStore()
const clients = ref([])
const stats = ref({ total: 0, newThisMonth: 0, withUpcoming: 0, recurring: 0 })
const loading = ref(true)
const error = ref('')
const search = ref('')
const filter = ref('all')
const selectedClient = ref(null)
const draft = ref(null)
const saving = ref(false)
const saveError = ref('')
let searchTimer

const filters = [
  { key: 'all', label: 'Tous' },
  { key: 'upcoming', label: 'Rendez-vous à venir' },
  { key: 'recurring', label: 'Clients fidèles' },
]

const filteredClients = computed(() => {
  const needle = search.value.trim().toLocaleLowerCase('fr-FR')
  return clients.value.filter((client) => {
    if (filter.value === 'upcoming' && !client.nextBookingAt) return false
    if (filter.value === 'recurring' && client.bookingCount < 2) return false
    if (!needle) return true
    return [client.displayName, client.email, client.phone, client.lastServiceName, client.nextServiceName]
      .filter(Boolean)
      .some((value) => String(value).toLocaleLowerCase('fr-FR').includes(needle))
  })
})

async function load() {
  loading.value = true
  error.value = ''
  try {
    const result = await api.getClients(admin.tenantId, search.value)
    clients.value = result.clients
    stats.value = { ...stats.value, ...result.stats }
  } catch (exception) {
    error.value = exception.message || 'La liste des clients n’a pas pu être chargée.'
  } finally {
    loading.value = false
  }
}

function initials(client) {
  return `${client.firstName?.[0] || ''}${client.lastName?.[0] || ''}`.toUpperCase() || 'CL'
}

function formatDate(value, withTime = false) {
  if (!value) return '—'
  return new Date(value).toLocaleDateString('fr-FR', withTime
    ? { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }
    : { day: 'numeric', month: 'short', year: 'numeric' })
}

function formatMoney(cents, currency = 'EUR') {
  return new Intl.NumberFormat('fr-FR', { style: 'currency', currency }).format((cents || 0) / 100)
}

function sourceLabel(source) {
  if (source === 'voucher') return 'Chèque cadeau'
  if (source === 'manual') return 'Ajout manuel'
  return 'Réservation en ligne'
}

function openClient(client) {
  selectedClient.value = client
  draft.value = JSON.parse(JSON.stringify(client))
  saveError.value = ''
}

function addTag(event) {
  const value = event.target.value.trim()
  if (value && !draft.value.tags.includes(value)) draft.value.tags.push(value)
  event.target.value = ''
}

async function saveClient() {
  saving.value = true
  saveError.value = ''
  try {
    const updated = await api.updateClient(admin.tenantId, selectedClient.value.id, draft.value)
    Object.assign(selectedClient.value, updated, { displayName: `${updated.firstName} ${updated.lastName}`.trim() })
    draft.value = JSON.parse(JSON.stringify(selectedClient.value))
  } catch (exception) {
    saveError.value = exception.message || 'La fiche n’a pas pu être enregistrée.'
  } finally {
    saving.value = false
  }
}

watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(load, 300)
})

function closeClient() {
  selectedClient.value = null
}

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-7xl">
    <div>
      <p class="text-xs font-semibold uppercase tracking-[0.2em] text-brand-600">Relation client</p>
      <h1 class="mt-1 font-display text-2xl font-bold text-slate-900">Clients</h1>
      <p class="mt-1 max-w-2xl text-slate-500">Retrouvez les coordonnées et l’historique des personnes ayant réservé dans votre établissement.</p>
    </div>

    <div class="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
      <div class="card p-4"><p class="text-sm text-slate-500">Clients enregistrés</p><p class="mt-1 font-display text-2xl font-bold text-slate-900">{{ stats.total }}</p></div>
      <div class="card p-4"><p class="text-sm text-slate-500">Nouveaux ce mois-ci</p><p class="mt-1 font-display text-2xl font-bold text-slate-900">{{ stats.newThisMonth }}</p></div>
      <div class="card p-4"><p class="text-sm text-slate-500">Avec un rendez-vous à venir</p><p class="mt-1 font-display text-2xl font-bold text-slate-900">{{ stats.withUpcoming }}</p></div>
      <div class="card p-4"><p class="text-sm text-slate-500">Clients revenus</p><p class="mt-1 font-display text-2xl font-bold text-slate-900">{{ stats.recurring }}</p></div>
    </div>

    <p v-if="error" class="mt-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</p>

    <div class="card mt-6 p-4">
      <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <label class="relative block w-full max-w-xl">
          <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-slate-400">⌕</span>
          <input v-model="search" type="search" class="input w-full pl-9" placeholder="Rechercher un nom, un email, un téléphone ou une prestation…" />
        </label>
        <div class="flex flex-wrap gap-2">
          <button v-for="item in filters" :key="item.key" type="button" class="rounded-xl px-3 py-2 text-sm font-medium transition" :class="filter === item.key ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'" @click="filter = item.key">{{ item.label }}</button>
        </div>
      </div>
      <p v-if="!loading" class="mt-3 text-xs text-slate-400">{{ filteredClients.length }} client{{ filteredClients.length > 1 ? 's' : '' }} affiché{{ filteredClients.length > 1 ? 's' : '' }}</p>
    </div>

    <Spinner v-if="loading" />

    <EmptyState v-else-if="!clients.length" class="mt-6" icon="♡" title="Aucun client pour le moment" message="Les clients apparaîtront automatiquement dès leur première réservation." />

    <div v-else-if="!filteredClients.length" class="mt-6 rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center text-slate-500">
      Aucun client ne correspond à cette recherche.
    </div>

    <div v-else class="mt-4">
      <div class="hidden overflow-hidden rounded-2xl border border-slate-200 bg-white lg:block">
        <table class="w-full text-left">
          <thead class="border-b border-slate-200 bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
            <tr><th class="px-5 py-3">Client</th><th class="px-4 py-3">Contact</th><th class="px-4 py-3">Rendez-vous</th><th class="px-4 py-3">Dernière visite</th><th class="px-4 py-3">Prochain rendez-vous</th><th class="px-5 py-3 text-right">Détail</th></tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="client in filteredClients" :key="client.id" class="cursor-pointer transition hover:bg-brand-50/40" tabindex="0" @click="openClient(client)" @keydown.enter="openClient(client)">
              <td class="px-5 py-4">
                <div class="flex items-center gap-3"><span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-100 text-xs font-bold text-brand-700">{{ initials(client) }}</span><div><p class="font-semibold text-slate-900">{{ client.displayName }}</p><p class="text-xs text-slate-400">Client depuis {{ formatDate(client.firstBookingAt) }}</p></div></div>
              </td>
              <td class="px-4 py-4"><p class="text-sm text-slate-700">{{ client.email }}</p><p class="mt-0.5 text-xs text-slate-400">{{ client.phone || 'Téléphone non renseigné' }}</p></td>
              <td class="px-4 py-4"><p class="font-semibold text-slate-800">{{ client.bookingCount }}</p><p class="text-xs text-slate-400">{{ client.bookingCount > 1 ? 'Client fidèle' : 'Première réservation' }}</p></td>
              <td class="px-4 py-4"><p class="text-sm text-slate-700">{{ formatDate(client.lastBookingAt) }}</p><p class="max-w-40 truncate text-xs text-slate-400">{{ client.lastServiceName || 'Aucune visite passée' }}</p></td>
              <td class="px-4 py-4"><template v-if="client.nextBookingAt"><p class="text-sm font-medium text-brand-700">{{ formatDate(client.nextBookingAt, true) }}</p><p class="max-w-40 truncate text-xs text-slate-400">{{ client.nextServiceName }}</p></template><span v-else class="text-sm text-slate-400">Aucun</span></td>
              <td class="px-5 py-4 text-right"><button type="button" class="btn-outline px-3 py-1.5 text-xs" @click.stop="openClient(client)">Ouvrir</button></td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="grid gap-3 lg:hidden">
        <button v-for="client in filteredClients" :key="client.id" type="button" class="card p-4 text-left" @click="openClient(client)">
          <div class="flex items-start gap-3"><span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand-100 text-xs font-bold text-brand-700">{{ initials(client) }}</span><div class="min-w-0 flex-1"><div class="flex items-start justify-between gap-2"><div><p class="font-semibold text-slate-900">{{ client.displayName }}</p><p class="truncate text-sm text-slate-500">{{ client.email }}</p></div><span class="chip bg-slate-100 text-slate-600">{{ client.bookingCount }} RDV</span></div><p v-if="client.nextBookingAt" class="mt-3 text-xs font-medium text-brand-700">Prochain : {{ formatDate(client.nextBookingAt, true) }}</p><p v-else class="mt-3 text-xs text-slate-400">Aucun rendez-vous à venir</p></div></div>
        </button>
      </div>
    </div>

    <div v-if="selectedClient" class="fixed inset-0 z-50 flex justify-end bg-black/45" @click.self="closeClient">
      <aside class="h-full w-full max-w-xl overflow-y-auto bg-white shadow-2xl">
        <div class="sticky top-0 z-10 flex items-start justify-between border-b border-slate-200 bg-white px-5 py-4 sm:px-6">
          <div class="flex min-w-0 items-center gap-3"><span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-brand-100 text-sm font-bold text-brand-700">{{ initials(selectedClient) }}</span><div class="min-w-0"><h2 class="truncate font-display text-xl font-bold text-slate-900">{{ selectedClient.displayName }}</h2><p class="text-sm text-slate-500">Client depuis {{ formatDate(selectedClient.firstBookingAt) }}</p></div></div>
          <button type="button" class="btn-ghost" aria-label="Fermer" @click="closeClient">×</button>
        </div>

        <div class="space-y-6 p-5 sm:p-6">
          <form v-if="draft" class="space-y-5" @submit.prevent="saveClient">
            <section class="rounded-2xl border border-sky-200 bg-sky-50/50 p-4">
              <h3 class="font-display font-bold text-slate-900">Données visibles du client</h3>
              <p class="mt-1 text-xs text-slate-500">Coordonnées et informations partagées avec le client.</p>
              <div class="mt-4 grid gap-3 sm:grid-cols-2">
                <label class="text-xs font-semibold text-slate-600">Prénom<input v-model="draft.firstName" required maxlength="100" class="input mt-1 w-full" /></label>
                <label class="text-xs font-semibold text-slate-600">Nom<input v-model="draft.lastName" required maxlength="100" class="input mt-1 w-full" /></label>
                <label class="text-xs font-semibold text-slate-600">Email<input v-model="draft.email" required type="email" maxlength="180" class="input mt-1 w-full" /></label>
                <label class="text-xs font-semibold text-slate-600">Téléphone<input v-model="draft.phone" maxlength="40" class="input mt-1 w-full" /></label>
              </div>
              <label class="mt-3 block text-xs font-semibold text-slate-600">Note visible<textarea v-model="draft.visibleNotes" rows="3" class="input mt-1 w-full" placeholder="Information communiquée au client…"></textarea></label>
            </section>

            <section class="rounded-2xl border-2 border-amber-200 bg-amber-50 p-4">
              <div class="flex items-center justify-between gap-3"><h3 class="font-display font-bold text-amber-950">Usage interne uniquement</h3><span class="rounded-full bg-amber-200 px-2 py-1 text-[10px] font-bold uppercase text-amber-900">Non visible par le client</span></div>
              <label class="mt-4 block text-xs font-semibold text-amber-900">Notes internes<textarea v-model="draft.internalNotes" rows="4" class="input mt-1 w-full" placeholder="Suivi interne de l’équipe…"></textarea></label>
              <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <label class="text-xs font-semibold text-amber-900">Allergies<textarea v-model="draft.allergies" rows="2" class="input mt-1 w-full"></textarea></label>
                <label class="text-xs font-semibold text-amber-900">Contre-indications<textarea v-model="draft.contraindications" rows="2" class="input mt-1 w-full"></textarea></label>
              </div>
              <div class="mt-3"><p class="text-xs font-semibold text-amber-900">Tags</p><div class="mt-2 flex flex-wrap gap-2"><button v-for="(tag, index) in draft.tags" :key="tag" type="button" class="chip bg-amber-200 text-amber-950" @click="draft.tags.splice(index, 1)">{{ tag }} ×</button></div><input class="input mt-2 w-full" maxlength="50" placeholder="Ajouter un tag puis Entrée" @keydown.enter.prevent="addTag" /></div>
            </section>

            <section class="rounded-2xl border border-slate-200 p-4">
              <h3 class="font-display font-bold text-slate-900">Consentements</h3>
              <div class="mt-3 space-y-2"><label class="flex gap-2 text-sm"><input v-model="draft.consents.dataProcessing" type="checkbox" /> Traitement des données</label><label class="flex gap-2 text-sm"><input v-model="draft.consents.medicalData" type="checkbox" /> Données de santé</label><label class="flex gap-2 text-sm"><input v-model="draft.consents.marketing" type="checkbox" /> Communications marketing</label></div>
              <details v-if="draft.consentHistory.length" class="mt-4 text-xs text-slate-500"><summary class="cursor-pointer font-semibold">Journal ({{ draft.consentHistory.length }})</summary><ul class="mt-2 space-y-1"><li v-for="(entry, index) in [...draft.consentHistory].reverse()" :key="index">{{ formatDate(entry.recordedAt, true) }} · {{ entry.type }} · {{ entry.granted ? 'accordé' : 'retiré' }} · {{ entry.recordedBy }}</li></ul></details>
            </section>

            <p v-if="saveError" class="rounded-xl bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ saveError }}</p>
            <button type="submit" class="btn-primary w-full" :disabled="saving">{{ saving ? 'Enregistrement…' : 'Enregistrer la fiche' }}</button>
          </form>

          <section class="grid grid-cols-3 gap-3">
            <div class="rounded-2xl bg-slate-50 p-3 text-center"><p class="font-display text-xl font-bold text-slate-900">{{ selectedClient.bookingCount }}</p><p class="text-[11px] text-slate-500">Rendez-vous</p></div>
            <div class="rounded-2xl bg-slate-50 p-3 text-center"><p class="font-display text-xl font-bold text-slate-900">{{ selectedClient.completedCount }}</p><p class="text-[11px] text-slate-500">Effectués</p></div>
            <div class="rounded-2xl bg-slate-50 p-3 text-center"><p class="font-display text-lg font-bold text-slate-900">{{ formatMoney(selectedClient.totalAmount, selectedClient.currencyCode) }}</p><p class="text-[11px] text-slate-500">Montant réservé</p></div>
          </section>

          <section>
            <div class="flex items-center justify-between"><h3 class="font-display text-lg font-bold text-slate-900">Historique</h3><span class="text-xs text-slate-400">{{ selectedClient.bookings.length }} rendez-vous</span></div>
            <div class="mt-3 space-y-3">
              <article v-for="booking in selectedClient.bookings" :key="booking.id" class="rounded-2xl border border-slate-200 p-4">
                <div class="flex items-start justify-between gap-3"><div><p class="font-semibold text-slate-900">{{ booking.serviceName }}</p><p class="mt-1 text-sm text-slate-500">{{ formatDate(booking.slotStart, true) }}<span v-if="booking.staffName"> · {{ booking.staffName }}</span></p></div><StatusBadge :status="booking.status" /></div>
                <div class="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-3 text-xs text-slate-400"><span>{{ sourceLabel(booking.source) }} · Réf. {{ booking.reference }}</span><span v-if="booking.amount != null" class="font-medium text-slate-600">{{ formatMoney(booking.amount, booking.currencyCode) }}</span></div>
              </article>
            </div>
          </section>

          <section>
            <div class="flex items-center justify-between"><h3 class="font-display text-lg font-bold text-slate-900">Achats</h3><span class="text-xs text-slate-400">{{ selectedClient.purchases.length }} commande(s)</span></div>
            <div v-if="selectedClient.purchases.length" class="mt-3 space-y-2"><article v-for="purchase in selectedClient.purchases" :key="purchase.orderNumber" class="rounded-2xl border border-slate-200 p-4"><div class="flex justify-between gap-3"><div><p class="font-semibold text-slate-900">{{ purchase.label }}</p><p class="text-xs text-slate-400">Commande {{ purchase.orderNumber }} · {{ formatDate(purchase.purchasedAt, true) }}</p></div><p class="font-medium text-slate-700">{{ formatMoney(purchase.amount, purchase.currencyCode) }}</p></div></article></div>
            <p v-else class="mt-2 text-sm text-slate-400">Aucun achat lié.</p>
          </section>
        </div>
      </aside>
    </div>
  </div>
</template>
