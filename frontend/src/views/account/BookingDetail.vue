<script setup>
import { computed, ref, onMounted } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import { useTenantContext } from '@/composables/useTenantContext'
import api from '@/api'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import Spinner from '@/components/ui/Spinner.vue'
import SlotCalendar from '@/components/SlotCalendar.vue'
import { formatDate, formatTime, formatMoney } from '@/utils/format'

const route = useRoute()
const { tenant } = useTenantContext()
const booking = ref(null)
const loading = ref(true)
const error = ref('')
const actionError = ref('')
const acting = ref(false)
const rescheduling = ref(false)
const slots = ref([])
const selectedSlot = ref(null)

const cancelDeadline = computed(() => booking.value ? new Date(new Date(booking.value.slotStart).getTime() - (booking.value.changePolicy?.cancelHours ?? 24) * 3600000) : null)
const rescheduleDeadline = computed(() => booking.value ? new Date(new Date(booking.value.slotStart).getTime() - (booking.value.changePolicy?.rescheduleHours ?? 24) * 3600000) : null)
const canCancel = computed(() => booking.value?.status === 'confirmed' && Date.now() < cancelDeadline.value?.getTime())
const canReschedule = computed(() => booking.value?.status === 'confirmed' && Date.now() < rescheduleDeadline.value?.getTime())

onMounted(async () => {
  try {
    booking.value = await api.getBooking(route.params.bookingId)
  } catch (e) {
    error.value = e?.message || 'La réservation est introuvable.'
  } finally {
    loading.value = false
  }
})

async function cancelBooking() {
  if (!window.confirm('Confirmer l’annulation de cette réservation ?')) return
  acting.value = true; actionError.value = ''
  try { booking.value = await api.cancelCustomerBooking(booking.value.id) }
  catch (e) { actionError.value = e?.message || 'L’annulation a échoué.' }
  finally { acting.value = false }
}

async function openReschedule() {
  acting.value = true; actionError.value = ''
  try {
    slots.value = await api.getSlots(tenant.value?.id, { jumpTypeId: booking.value.jumpTypeId })
    rescheduling.value = true
  } catch (e) { actionError.value = e?.message || 'Les créneaux sont indisponibles.' }
  finally { acting.value = false }
}

async function confirmReschedule() {
  if (!selectedSlot.value) return
  acting.value = true; actionError.value = ''
  try {
    booking.value = await api.rescheduleCustomerBooking(booking.value.id, selectedSlot.value)
    rescheduling.value = false; selectedSlot.value = null
  } catch (e) { actionError.value = e?.message || 'Le déplacement a échoué.' }
  finally { acting.value = false }
}
</script>

<template>
  <Spinner v-if="loading" />

  <div v-else-if="error" class="section py-10">
    <div class="mx-auto max-w-md rounded-2xl border border-rose-200 bg-rose-50 p-6 text-center text-rose-700">
      <p>{{ error }}</p>
      <RouterLink :to="{ name: 'account-dashboard' }" class="btn-outline mt-4">Retour à mon compte</RouterLink>
    </div>
  </div>

  <div v-else-if="booking" class="section py-10">
    <RouterLink :to="{ name: 'account-dashboard' }" class="text-sm text-slate-400 hover:text-brand-600">← Mon compte</RouterLink>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="font-display text-3xl font-bold text-slate-900">{{ booking.jumpTypeName }}</h1>
        <p class="mt-1 text-slate-500">{{ tenant?.name }} · Réf. {{ booking.reference }}</p>
      </div>
      <StatusBadge :status="booking.status" />
    </div>

    <div v-if="booking.status === 'confirmed'" class="mt-6 rounded-2xl border border-slate-200 bg-white p-5">
      <h2 class="font-semibold text-slate-800">Gérer mon rendez-vous</h2>
      <p class="mt-1 text-sm text-slate-500">Déplacement possible jusqu’au {{ formatDate(rescheduleDeadline, { short: true }) }} à {{ formatTime(rescheduleDeadline) }} ; annulation jusqu’au {{ formatDate(cancelDeadline, { short: true }) }} à {{ formatTime(cancelDeadline) }}.</p>
      <p v-if="actionError" class="mt-3 rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{{ actionError }}</p>
      <div class="mt-4 flex flex-wrap gap-3">
        <button class="btn-primary" :disabled="acting || !canReschedule" @click="openReschedule">Déplacer</button>
        <button class="btn-outline text-rose-600" :disabled="acting || !canCancel" @click="cancelBooking">Annuler</button>
      </div>
      <p v-if="!canCancel && !canReschedule" class="mt-3 text-sm text-amber-700">Les délais de modification sont dépassés. Contactez le centre.</p>
    </div>

    <div v-if="rescheduling" class="mt-6 card p-6">
      <h2 class="mb-4 font-semibold text-slate-800">Choisir un nouveau créneau</h2>
      <SlotCalendar :slots="slots" :selected-slot-id="selectedSlot?.id" :jump-type-id="booking.jumpTypeId" @select="selectedSlot = $event" />
      <div class="mt-5 flex justify-end gap-3">
        <button class="btn-ghost" :disabled="acting" @click="rescheduling = false">Fermer</button>
        <button class="btn-primary" :disabled="acting || !selectedSlot" @click="confirmReschedule">Confirmer le déplacement</button>
      </div>
    </div>

    <!-- Bandeau de reprogrammation -->
    <div v-if="booking.status === 'postponed'" class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-5">
      <p class="font-semibold text-amber-800">Rendez-vous a reprogrammer</p>
      <p class="mt-1 text-sm text-amber-700">{{ booking.postponedReason }}</p>
      <p class="mt-2 text-sm text-amber-700">Votre reservation reste valable : choisissez une nouvelle date sans frais.</p>
      <RouterLink
        :to="{ name: 'calendar', params: { slug: booking.tenantSlug } }"
        class="btn-accent mt-4"
      >Choisir une nouvelle date</RouterLink>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
      <div class="card p-6 lg:col-span-2">
        <h2 class="mb-4 font-semibold text-slate-800">Details de la reservation</h2>
        <dl class="grid grid-cols-2 gap-4 text-sm">
          <div>
            <dt class="text-slate-400">Client</dt>
            <dd class="font-semibold text-slate-800">{{ booking.jumperName }}</dd>
          </div>
          <div>
            <dt class="text-slate-400">Date</dt>
            <dd class="font-semibold capitalize text-slate-800">{{ formatDate(booking.slotStart, { weekday: true, short: true }) }}</dd>
          </div>
          <div>
            <dt class="text-slate-400">Heure</dt>
            <dd class="font-semibold text-slate-800">{{ formatTime(booking.slotStart) }}</dd>
          </div>
          <div>
            <dt class="text-slate-400">Source</dt>
            <dd class="font-semibold text-slate-800">{{ booking.source === 'voucher' ? 'Cheque cadeau' : 'Achat direct' }}</dd>
          </div>
        </dl>

        <div v-if="booking.options?.length" class="mt-5 border-t border-slate-100 pt-4">
          <p class="mb-2 text-sm font-semibold text-slate-700">Options</p>
          <ul class="space-y-1 text-sm">
            <li v-for="o in booking.options" :key="o.name" class="flex justify-between">
              <span class="text-slate-600">{{ o.name }}</span>
              <span class="text-slate-700">{{ formatMoney(o.price, booking.currencyCode) }}</span>
            </li>
          </ul>
        </div>
      </div>

      <div class="card flex flex-col p-6">
        <h2 class="mb-3 font-semibold text-slate-800">Confirmation de rendez-vous</h2>
        <p class="flex-1 text-sm text-slate-500">
          Retrouvez ici les informations utiles et le QR code a presenter a votre arrivee.
        </p>
        <RouterLink
          v-if="booking.boardingPassId && booking.status !== 'postponed'"
          :to="{ name: 'boarding-pass', params: { bookingId: booking.id } }"
          class="btn-primary mt-4"
        >Ouvrir la confirmation</RouterLink>
        <p v-else class="mt-4 rounded-lg bg-slate-50 p-3 text-sm text-slate-400">
          Disponible une fois une nouvelle date confirmee.
        </p>
      </div>
    </div>
  </div>
</template>
