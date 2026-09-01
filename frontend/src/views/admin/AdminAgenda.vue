<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '@/api'
import { useAdminStore } from '@/stores/admin'
import Spinner from '@/components/ui/Spinner.vue'

const admin = useAdminStore()
const bookings = ref([])
const staff = ref([])
const services = ref([])
const timeOffs = ref([])
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const viewMode = ref('week')
const anchor = ref(startOfWeek(new Date()))
const staffFilter = ref('')
const modal = ref(null)

const bookingForm = ref(emptyBooking())
const timeOffForm = ref(emptyTimeOff())

function startOfWeek(value) {
  const date = new Date(value)
  const offset = (date.getDay() + 6) % 7
  date.setDate(date.getDate() - offset)
  date.setHours(0, 0, 0, 0)
  return date
}

function addDays(value, amount) {
  const date = new Date(value)
  date.setDate(date.getDate() + amount)
  return date
}

function dateKey(value) {
  const date = new Date(value)
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function emptyBooking() {
  return {
    firstName: '', lastName: '', email: '', phone: '', notes: '',
    serviceCode: '', staffMemberId: '', date: dateKey(new Date()), time: '09:00',
  }
}

function emptyTimeOff() {
  return {
    staffMemberId: '', date: dateKey(new Date()), start: '12:00', end: '13:00', reason: 'Pause',
  }
}

const visibleDays = computed(() => {
  const count = viewMode.value === 'week' ? 7 : 1
  return Array.from({ length: count }, (_, index) => addDays(anchor.value, index))
})

const periodLabel = computed(() => {
  const first = visibleDays.value[0]
  const last = visibleDays.value.at(-1)
  if (viewMode.value === 'day') {
    return first.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
  }
  const left = first.toLocaleDateString('fr-FR', { day: 'numeric', month: first.getMonth() === last.getMonth() ? undefined : 'short' })
  const right = last.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' })
  return `${left} – ${right}`
})

const activeStaff = computed(() => staff.value.filter((member) => member.active !== false))
const eligibleStaff = computed(() => activeStaff.value.filter((member) =>
  !bookingForm.value.serviceCode || (member.serviceCodes || []).includes(bookingForm.value.serviceCode),
))

function memberById(id) {
  return staff.value.find((member) => Number(member.id) === Number(id))
}

function eventColor(event) {
  return memberById(event.staffMemberId)?.color || '#1f5c57'
}

function matchesStaff(event) {
  return !staffFilter.value || Number(event.staffMemberId) === Number(staffFilter.value)
}

function bookingsFor(day) {
  const key = dateKey(day)
  return bookings.value
    .filter((booking) => dateKey(booking.slotStart) === key && matchesStaff(booking))
    .sort((a, b) => new Date(a.slotStart) - new Date(b.slotStart))
}

function timeOffsFor(day) {
  const key = dateKey(day)
  return timeOffs.value
    .filter((timeOff) => dateKey(timeOff.start) === key && matchesStaff(timeOff))
    .sort((a, b) => new Date(a.start) - new Date(b.start))
}

function time(value) {
  return new Date(value).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
}

function dayTitle(day) {
  return day.toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric', month: 'short' })
}

function dayIsToday(day) {
  return dateKey(day) === dateKey(new Date())
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const from = visibleDays.value[0].toISOString()
    const to = addDays(visibleDays.value.at(-1), 1).toISOString()
    ;[bookings.value, staff.value, services.value, timeOffs.value] = await Promise.all([
      api.getTenantBookings(admin.tenantId),
      api.getStaffMembers(admin.tenantId),
      api.getJumpTypes(admin.tenantId),
      api.getStaffTimeOffs(admin.tenantId, { from, to }),
    ])
  } catch (exception) {
    error.value = exception.message || 'L’agenda n’a pas pu être chargé.'
  } finally {
    loading.value = false
  }
}

async function move(direction) {
  anchor.value = addDays(anchor.value, direction * (viewMode.value === 'week' ? 7 : 1))
  await load()
}

async function today() {
  anchor.value = viewMode.value === 'week' ? startOfWeek(new Date()) : new Date(new Date().setHours(0, 0, 0, 0))
  await load()
}

async function changeMode(mode) {
  viewMode.value = mode
  anchor.value = mode === 'week' ? startOfWeek(anchor.value) : new Date(anchor.value)
  await load()
}

function openBooking(day = visibleDays.value[0]) {
  const form = emptyBooking()
  form.date = dateKey(day)
  form.serviceCode = services.value[0]?.id || ''
  form.staffMemberId = activeStaff.value.find((member) => (member.serviceCodes || []).includes(form.serviceCode))?.id || ''
  bookingForm.value = form
  error.value = ''
  modal.value = 'booking'
}

function serviceChanged() {
  if (!eligibleStaff.value.some((member) => Number(member.id) === Number(bookingForm.value.staffMemberId))) {
    bookingForm.value.staffMemberId = eligibleStaff.value[0]?.id || ''
  }
}

function openTimeOff(day = visibleDays.value[0]) {
  const form = emptyTimeOff()
  form.date = dateKey(day)
  form.staffMemberId = staffFilter.value || activeStaff.value[0]?.id || ''
  timeOffForm.value = form
  error.value = ''
  modal.value = 'timeOff'
}

function closeModal() {
  modal.value = null
}

async function saveBooking() {
  saving.value = true
  error.value = ''
  try {
    await api.createManualBooking(admin.tenantId, {
      serviceCode: bookingForm.value.serviceCode,
      staffMemberId: bookingForm.value.staffMemberId,
      start: new Date(`${bookingForm.value.date}T${bookingForm.value.time}:00`).toISOString(),
      customer: {
        firstName: bookingForm.value.firstName,
        lastName: bookingForm.value.lastName,
        email: bookingForm.value.email,
        phone: bookingForm.value.phone,
        notes: bookingForm.value.notes,
      },
    })
    closeModal()
    await load()
  } catch (exception) {
    error.value = exception.message || 'Le rendez-vous n’a pas pu être ajouté.'
  } finally {
    saving.value = false
  }
}

async function saveTimeOff() {
  saving.value = true
  error.value = ''
  try {
    await api.createStaffTimeOff(admin.tenantId, {
      staffMemberId: timeOffForm.value.staffMemberId,
      start: new Date(`${timeOffForm.value.date}T${timeOffForm.value.start}:00`).toISOString(),
      end: new Date(`${timeOffForm.value.date}T${timeOffForm.value.end}:00`).toISOString(),
      reason: timeOffForm.value.reason,
    })
    closeModal()
    await load()
  } catch (exception) {
    error.value = exception.message || 'L’indisponibilité n’a pas pu être ajoutée.'
  } finally {
    saving.value = false
  }
}

async function deleteTimeOff(timeOff) {
  error.value = ''
  try {
    await api.deleteStaffTimeOff(admin.tenantId, timeOff.id)
    await load()
  } catch (exception) {
    error.value = exception.message || 'L’indisponibilité n’a pas pu être supprimée.'
  }
}

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-[1500px]">
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-brand-600">Organisation du salon</p>
        <h1 class="mt-1 font-display text-2xl font-bold text-slate-900">Agenda</h1>
        <p class="mt-1 text-slate-500">Rendez-vous, pauses et absences de toute l’équipe au même endroit.</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <button type="button" class="btn-outline" @click="openTimeOff()">Bloquer un créneau</button>
        <button type="button" class="btn-primary" @click="openBooking()">+ Nouveau rendez-vous</button>
      </div>
    </div>

    <p v-if="error" class="mt-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</p>

    <div class="card mt-6 p-3 sm:p-4">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
          <button type="button" class="btn-outline px-3" aria-label="Période précédente" @click="move(-1)">←</button>
          <button type="button" class="btn-outline" @click="today">Aujourd’hui</button>
          <button type="button" class="btn-outline px-3" aria-label="Période suivante" @click="move(1)">→</button>
        </div>
        <h2 class="min-w-56 text-center font-display text-lg font-bold capitalize text-slate-900">{{ periodLabel }}</h2>
        <div class="flex flex-wrap items-center gap-2">
          <select v-model="staffFilter" class="input py-2 text-sm">
            <option value="">Toute l’équipe</option>
            <option v-for="member in activeStaff" :key="member.id" :value="member.id">{{ member.displayName }}</option>
          </select>
          <div class="flex rounded-xl bg-slate-100 p-1">
            <button type="button" class="rounded-lg px-3 py-1.5 text-sm font-medium" :class="viewMode === 'day' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500'" @click="changeMode('day')">Jour</button>
            <button type="button" class="rounded-lg px-3 py-1.5 text-sm font-medium" :class="viewMode === 'week' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500'" @click="changeMode('week')">Semaine</button>
          </div>
        </div>
      </div>
    </div>

    <Spinner v-if="loading" />

    <div v-else class="mt-4 overflow-x-auto pb-2">
      <div class="grid gap-3" :class="viewMode === 'week' ? 'min-w-[1050px] grid-cols-7' : 'grid-cols-1'">
        <section v-for="day in visibleDays" :key="dateKey(day)" class="min-h-[520px] rounded-2xl border bg-white p-3" :class="dayIsToday(day) ? 'border-brand-300 ring-2 ring-brand-100' : 'border-slate-200'">
        <button type="button" class="flex w-full items-center justify-between border-b border-slate-100 pb-3 text-left" @click="openBooking(day)">
          <span class="font-display text-sm font-bold capitalize" :class="dayIsToday(day) ? 'text-brand-700' : 'text-slate-700'">{{ dayTitle(day) }}</span>
          <span class="text-lg text-slate-300">＋</span>
        </button>

        <div class="mt-3 space-y-2">
          <article v-for="item in timeOffsFor(day)" :key="`off-${item.id}`" class="rounded-xl border border-dashed border-amber-300 bg-amber-50 p-2.5">
            <div class="flex items-start justify-between gap-2">
              <div>
                <p class="text-xs font-bold text-amber-800">{{ time(item.start) }}–{{ time(item.end) }}</p>
                <p class="mt-0.5 text-sm font-semibold text-amber-950">{{ item.reason }}</p>
                <p class="mt-1 text-[11px] text-amber-700">{{ item.staffName }}</p>
              </div>
              <button type="button" class="text-sm text-amber-500 hover:text-rose-600" aria-label="Supprimer le blocage" @click="deleteTimeOff(item)">×</button>
            </div>
          </article>

          <article v-for="booking in bookingsFor(day)" :key="`booking-${booking.id}`" class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="h-1.5" :style="{ backgroundColor: eventColor(booking) }" />
            <div class="p-2.5">
              <div class="flex items-center justify-between gap-2">
                <p class="text-xs font-bold text-slate-800">{{ time(booking.slotStart) }}–{{ time(booking.slotEnd) }}</p>
                <span v-if="booking.status !== 'confirmed'" class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] text-slate-500">{{ booking.status }}</span>
              </div>
              <p class="mt-1 truncate text-sm font-semibold text-slate-900">{{ booking.customerName || booking.jumperName }}</p>
              <p class="truncate text-xs text-slate-500">{{ booking.serviceName || booking.jumpTypeName }}</p>
              <p class="mt-1 truncate text-[11px] text-slate-400">{{ booking.staffName || 'Non affecté' }}</p>
            </div>
          </article>

          <button v-if="!bookingsFor(day).length && !timeOffsFor(day).length" type="button" class="w-full rounded-xl border border-dashed border-slate-200 px-3 py-8 text-xs text-slate-400 hover:border-brand-300 hover:text-brand-600" @click="openBooking(day)">Aucun rendez-vous</button>
        </div>
        </section>
      </div>
    </div>

    <div v-if="modal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="closeModal">
      <form v-if="modal === 'booking'" class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-3xl bg-white shadow-soft" @submit.prevent="saveBooking">
        <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
          <div><h2 class="font-display text-xl font-bold text-slate-900">Nouveau rendez-vous</h2><p class="text-sm text-slate-500">Ajout manuel depuis le téléphone ou l’accueil.</p></div>
          <button type="button" class="btn-ghost" @click="closeModal">×</button>
        </div>
        <p v-if="error" class="mx-6 mt-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</p>
        <div class="grid gap-4 p-6 sm:grid-cols-2">
          <label class="block text-sm font-medium text-slate-700">Prénom *<input v-model.trim="bookingForm.firstName" required class="input mt-1 w-full" /></label>
          <label class="block text-sm font-medium text-slate-700">Nom *<input v-model.trim="bookingForm.lastName" required class="input mt-1 w-full" /></label>
          <label class="block text-sm font-medium text-slate-700">Email *<input v-model.trim="bookingForm.email" required type="email" class="input mt-1 w-full" /></label>
          <label class="block text-sm font-medium text-slate-700">Téléphone<input v-model.trim="bookingForm.phone" type="tel" class="input mt-1 w-full" /></label>
          <label class="block text-sm font-medium text-slate-700">Prestation *
            <select v-model="bookingForm.serviceCode" required class="input mt-1 w-full" @change="serviceChanged">
              <option disabled value="">Choisir</option><option v-for="service in services" :key="service.id" :value="service.id">{{ service.name }}</option>
            </select>
          </label>
          <label class="block text-sm font-medium text-slate-700">Collaborateur *
            <select v-model="bookingForm.staffMemberId" required class="input mt-1 w-full">
              <option disabled value="">Choisir</option><option v-for="member in eligibleStaff" :key="member.id" :value="member.id">{{ member.displayName }}</option>
            </select>
          </label>
          <label class="block text-sm font-medium text-slate-700">Date *<input v-model="bookingForm.date" required type="date" class="input mt-1 w-full" /></label>
          <label class="block text-sm font-medium text-slate-700">Heure *<input v-model="bookingForm.time" required type="time" step="900" class="input mt-1 w-full" /></label>
          <label class="block text-sm font-medium text-slate-700 sm:col-span-2">Note interne<textarea v-model.trim="bookingForm.notes" rows="3" class="input mt-1 w-full" /></label>
        </div>
        <div class="flex justify-end gap-3 border-t border-slate-200 bg-slate-50 px-6 py-4"><button type="button" class="btn-outline" @click="closeModal">Annuler</button><button type="submit" class="btn-primary" :disabled="saving || !eligibleStaff.length">{{ saving ? 'Ajout…' : 'Ajouter le rendez-vous' }}</button></div>
      </form>

      <form v-else class="w-full max-w-lg rounded-3xl bg-white shadow-soft" @submit.prevent="saveTimeOff">
        <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4"><div><h2 class="font-display text-xl font-bold text-slate-900">Bloquer un créneau</h2><p class="text-sm text-slate-500">Pause, congé ou indisponibilité.</p></div><button type="button" class="btn-ghost" @click="closeModal">×</button></div>
        <p v-if="error" class="mx-6 mt-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</p>
        <div class="grid gap-4 p-6 sm:grid-cols-2">
          <label class="block text-sm font-medium text-slate-700 sm:col-span-2">Collaborateur *<select v-model="timeOffForm.staffMemberId" required class="input mt-1 w-full"><option disabled value="">Choisir</option><option v-for="member in activeStaff" :key="member.id" :value="member.id">{{ member.displayName }}</option></select></label>
          <label class="block text-sm font-medium text-slate-700 sm:col-span-2">Date *<input v-model="timeOffForm.date" required type="date" class="input mt-1 w-full" /></label>
          <label class="block text-sm font-medium text-slate-700">Début *<input v-model="timeOffForm.start" required type="time" class="input mt-1 w-full" /></label>
          <label class="block text-sm font-medium text-slate-700">Fin *<input v-model="timeOffForm.end" required type="time" class="input mt-1 w-full" /></label>
          <label class="block text-sm font-medium text-slate-700 sm:col-span-2">Motif<input v-model.trim="timeOffForm.reason" maxlength="255" class="input mt-1 w-full" placeholder="Pause déjeuner, congé…" /></label>
        </div>
        <div class="flex justify-end gap-3 border-t border-slate-200 bg-slate-50 px-6 py-4"><button type="button" class="btn-outline" @click="closeModal">Annuler</button><button type="submit" class="btn-primary" :disabled="saving">{{ saving ? 'Enregistrement…' : 'Bloquer le créneau' }}</button></div>
      </form>
    </div>
  </div>
</template>
