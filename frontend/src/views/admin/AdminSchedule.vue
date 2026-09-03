<script setup>
import { ref, onMounted } from 'vue'
import { useAdminStore } from '@/stores/admin'
import api from '@/api'
import Spinner from '@/components/ui/Spinner.vue'

const admin = useAdminStore()
const loading = ref(true)
const saving = ref(false)
const saved = ref(false)
const newTime = ref('')

const WEEKDAYS = [
  { v: 1, label: 'Lun' },
  { v: 2, label: 'Mar' },
  { v: 3, label: 'Mer' },
  { v: 4, label: 'Jeu' },
  { v: 5, label: 'Ven' },
  { v: 6, label: 'Sam' },
  { v: 0, label: 'Dim' },
]

const schedule = ref({ openDays: [], times: [], capacity: 8 })

onMounted(async () => {
  schedule.value = await api.getSchedule(admin.tenantId)
  loading.value = false
})

function toggleDay(v) {
  const i = schedule.value.openDays.indexOf(v)
  if (i >= 0) schedule.value.openDays.splice(i, 1)
  else schedule.value.openDays.push(v)
}
function addTime() {
  const t = newTime.value.trim()
  if (/^\d{2}:\d{2}$/.test(t) && !schedule.value.times.includes(t)) {
    schedule.value.times.push(t)
    schedule.value.times.sort()
    newTime.value = ''
  }
}
function removeTime(t) {
  schedule.value.times = schedule.value.times.filter((x) => x !== t)
}

async function save() {
  saving.value = true
  saved.value = false
  try {
    await api.updateSchedule(admin.tenantId, {
      openDays: schedule.value.openDays,
      times: schedule.value.times,
      capacity: Number(schedule.value.capacity) || 1,
    })
    saved.value = true
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-2xl">
    <h1 class="font-display text-2xl font-bold text-slate-900">Horaires des rendez-vous</h1>
    <p class="mt-1 text-slate-500">Définissez les jours d'ouverture, les plages horaires et la capacité par créneau. Le calendrier client se régénère automatiquement.</p>

    <Spinner v-if="loading" />

    <div v-else class="mt-6 space-y-6">
      <!-- Jours -->
      <section class="card p-6">
        <h2 class="mb-3 font-semibold text-slate-800">Jours d'ouverture</h2>
        <div class="flex flex-wrap gap-2">
          <button
            v-for="d in WEEKDAYS"
            :key="d.v"
            type="button"
            class="rounded-xl px-4 py-2 text-sm font-semibold transition"
            :class="schedule.openDays.includes(d.v) ? 'bg-brand-600 text-white shadow-soft' : 'bg-slate-100 text-slate-500 hover:bg-slate-200'"
            @click="toggleDay(d.v)"
          >{{ d.label }}</button>
        </div>
      </section>

      <!-- Plages horaires -->
      <section class="card p-6">
        <h2 class="mb-3 font-semibold text-slate-800">Plages horaires de départ</h2>
        <div class="flex flex-wrap gap-2">
          <span v-for="t in schedule.times" :key="t" class="chip bg-brand-50 text-brand-700">
            {{ t }}
            <button type="button" class="ml-1 text-brand-400 hover:text-rose-500" @click="removeTime(t)">×</button>
          </span>
          <span v-if="!schedule.times.length" class="text-sm text-slate-400">Aucune plage définie.</span>
        </div>
        <div class="mt-4 flex gap-2">
          <input v-model="newTime" type="time" class="input max-w-[10rem]" />
          <button type="button" class="btn-outline" @click="addTime">Ajouter</button>
        </div>
      </section>

      <!-- Capacité -->
      <section class="card p-6">
        <h2 class="mb-3 font-semibold text-slate-800">Capacité par créneau</h2>
        <input v-model.number="schedule.capacity" type="number" min="1" class="input max-w-[8rem]" />
        <p class="mt-2 text-sm text-slate-400">Nombre de clients maximum par créneau.</p>
      </section>

      <div class="flex items-center justify-end gap-3">
        <span v-if="saved" class="text-sm text-emerald-600">✓ Horaires enregistrés</span>
        <button class="btn-primary px-8" :disabled="saving" @click="save">{{ saving ? 'Enregistrement…' : 'Enregistrer les horaires' }}</button>
      </div>
    </div>
  </div>
</template>
