<script setup>
import { ref, onMounted, computed } from 'vue'
import { useAdminStore } from '@/stores/admin'
import api from '@/api'
import Spinner from '@/components/ui/Spinner.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import SlotCalendar from '@/components/SlotCalendar.vue'
import { formatDate, formatTime } from '@/utils/format'

const admin = useAdminStore()
const bookings = ref([])
const loading = ref(true)
const filter = ref('upcoming')
const busyId = ref(null)
const error = ref('')

// Modal d'action (reprogrammer / reporter)
const modal = ref(null) // { mode, booking }
const modalSlots = ref([])
const modalLoading = ref(false)
const postponeReason = ref('')

const FILTERS = [
  { key: 'upcoming', label: 'À venir' },
  { key: 'postponed', label: 'Reportés' },
  { key: 'completed', label: 'Effectués' },
  { key: 'cancelled', label: 'Annulés' },
  { key: 'all', label: 'Tous' },
]

const filtered = computed(() => {
  const now = Date.now()
  if (filter.value === 'all') return bookings.value
  if (filter.value === 'upcoming')
    return bookings.value.filter((b) => b.status === 'confirmed' && new Date(b.slotStart) >= now - 864e5)
  return bookings.value.filter((b) => b.status === filter.value)
})

async function load() {
  loading.value = true
  error.value = ''
  try {
    bookings.value = await api.getTenantBookings(admin.tenantId)
  } catch (e) {
    error.value = e.message || 'Impossible de charger les réservations.'
  } finally {
    loading.value = false
  }
}
onMounted(load)

async function act(fn, id) {
  busyId.value = id
  error.value = ''
  try {
    await fn()
    await load()
  } catch (e) {
    error.value = e.message || 'Cette action n’a pas pu être effectuée.'
  } finally {
    busyId.value = null
  }
}

const complete = (b) => act(() => api.completeBooking(b.id), b.id)
const cancel = (b) => act(() => api.cancelBooking(b.id), b.id)

async function openReschedule(b) {
  modal.value = { mode: 'reschedule', booking: b }
  modalLoading.value = true
  modalSlots.value = await api.getSlots(b.tenantId, { jumpTypeId: b.jumpTypeId })
  modalLoading.value = false
}
function openPostpone(b) {
  modal.value = { mode: 'postpone', booking: b }
  postponeReason.value = 'Indisponibilité exceptionnelle. Une nouvelle date reste à choisir.'
}
function closeModal() {
  modal.value = null
  modalSlots.value = []
}

async function chooseSlot(slot) {
  const b = modal.value.booking
  closeModal()
  await act(() => api.rescheduleBooking(b.id, slot), b.id)
}
async function confirmPostpone() {
  const b = modal.value.booking
  const reason = postponeReason.value
  closeModal()
  await act(() => api.postponeBooking(b.id, reason), b.id)
}

const isFuture = (b) => new Date(b.slotStart) >= Date.now()
</script>

<template>
  <div>
    <h1 class="font-display text-2xl font-bold text-slate-900">Réservations</h1>
    <p class="mt-1 text-slate-500">Suivez les rendez-vous de l’établissement, reprogrammez-les ou mettez à jour leur statut.</p>

    <p v-if="error" class="mt-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</p>

    <!-- Filtres -->
    <div class="mt-5 flex flex-wrap gap-2">
      <button
        v-for="f in FILTERS"
        :key="f.key"
        class="rounded-xl px-4 py-2 text-sm font-medium transition"
        :class="filter === f.key ? 'bg-slate-900 text-white' : 'bg-white text-slate-600 hover:bg-slate-100'"
        @click="filter = f.key"
      >{{ f.label }}</button>
    </div>

    <Spinner v-if="loading" />

    <div v-else-if="!filtered.length" class="mt-6 rounded-2xl border border-dashed border-slate-300 p-10 text-center text-slate-500">
      Aucune réservation dans cette catégorie.
    </div>

    <!-- Liste -->
    <div v-else class="mt-6 space-y-3">
      <div v-for="b in filtered" :key="b.id" class="card p-4">
        <div class="flex flex-wrap items-center gap-4">
          <div class="flex h-12 w-12 shrink-0 flex-col items-center justify-center rounded-xl bg-brand-50 text-brand-700">
            <span class="text-sm font-bold leading-none">{{ new Date(b.slotStart).getDate() }}</span>
            <span class="text-[9px] uppercase">{{ new Date(b.slotStart).toLocaleDateString('fr-FR', { month: 'short' }) }}</span>
          </div>
          <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
              <p class="font-semibold text-slate-800">{{ b.jumperName }}</p>
              <StatusBadge :status="b.status" />
              <span class="chip bg-slate-100 text-slate-500">{{ b.source === 'voucher' ? '🎁 Chèque' : b.source === 'manual' ? 'Ajout manuel' : 'Direct' }}</span>
            </div>
            <p class="text-sm text-slate-500">
              {{ b.jumpTypeName }} · {{ formatDate(b.slotStart, { short: true }) }} à {{ formatTime(b.slotStart) }} · Réf. {{ b.reference }}
            </p>
            <p class="mt-1 text-xs text-slate-400">
              <span v-if="b.staffName">Avec {{ b.staffName }}</span>
              <span v-if="b.staffName && (b.customerEmail || b.customerPhone)"> · </span>
              <span v-if="b.customerEmail">{{ b.customerEmail }}</span>
              <span v-if="b.customerEmail && b.customerPhone"> · </span>
              <span v-if="b.customerPhone">{{ b.customerPhone }}</span>
            </p>
          </div>

          <!-- Actions -->
          <div class="flex flex-wrap items-center gap-1.5">
            <template v-if="busyId === b.id">
              <span class="px-3 text-sm text-slate-400">…</span>
            </template>
            <template v-else>
              <button v-if="['confirmed','postponed'].includes(b.status)" class="btn-outline px-3 py-1.5 text-xs" @click="openReschedule(b)">Reprogrammer</button>
              <button v-if="b.status === 'confirmed' && isFuture(b)" class="btn px-3 py-1.5 text-xs bg-amber-100 text-amber-700 hover:bg-amber-200" @click="openPostpone(b)">Reporter</button>
              <button v-if="b.status === 'confirmed'" class="btn px-3 py-1.5 text-xs bg-emerald-100 text-emerald-700 hover:bg-emerald-200" @click="complete(b)">Effectué</button>
              <button v-if="['confirmed','postponed'].includes(b.status)" class="btn px-3 py-1.5 text-xs bg-slate-100 text-slate-600 hover:bg-slate-200" @click="cancel(b)">Annuler</button>
            </template>
          </div>
        </div>
        <p v-if="b.status === 'postponed' && b.postponedReason" class="mt-2 rounded-lg bg-amber-50 px-3 py-1.5 text-xs text-amber-700">{{ b.postponedReason }}</p>
      </div>
    </div>

    <!-- Modal -->
    <div v-if="modal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="closeModal">
      <div class="max-h-[85vh] w-full max-w-4xl overflow-y-auto rounded-3xl bg-white p-6 shadow-soft">
        <div class="mb-4 flex items-center justify-between">
          <h2 class="font-display text-lg font-bold text-slate-900">
            {{ modal.mode === 'reschedule' ? 'Reprogrammer' : 'Reporter' }} — {{ modal.booking.jumperName }}
          </h2>
          <button class="btn-ghost" @click="closeModal">×</button>
        </div>

        <!-- Reprogrammer : calendrier -->
        <template v-if="modal.mode === 'reschedule'">
          <p class="mb-4 text-sm text-slate-500">Choisissez un nouveau créneau pour « {{ modal.booking.jumpTypeName }} ».</p>
          <Spinner v-if="modalLoading" />
          <SlotCalendar v-else :slots="modalSlots" :jump-type-id="modal.booking.jumpTypeId" @select="chooseSlot" />
        </template>

        <!-- Reporter : raison -->
        <template v-else>
          <p class="mb-3 text-sm text-slate-500">
            Le rendez-vous passe en statut « Reporté » et libère immédiatement le créneau du collaborateur.
            Vous pourrez ensuite choisir une nouvelle date avec la cliente.
          </p>
          <label class="label">Motif du report</label>
          <textarea v-model="postponeReason" rows="3" class="input" />
          <div class="mt-4 flex justify-end gap-3">
            <button class="btn-ghost" @click="closeModal">Annuler</button>
            <button class="btn px-6 bg-amber-500 text-white hover:bg-amber-600" @click="confirmPostpone">Confirmer le report</button>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>
