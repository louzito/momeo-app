<script setup>
import { onMounted, ref } from 'vue'
import api from '@/api'
import Spinner from '@/components/ui/Spinner.vue'

const weekdays = [
  ['monday', 'Lundi'], ['tuesday', 'Mardi'], ['wednesday', 'Mercredi'],
  ['thursday', 'Jeudi'], ['friday', 'Vendredi'], ['saturday', 'Samedi'], ['sunday', 'Dimanche'],
]
const types = { cabin: 'Cabine', room: 'Salle', machine: 'Machine', chair: 'Fauteuil' }
const resources = ref([])
const editing = ref(null)
const loading = ref(true)
const saving = ref(false)
const error = ref('')

const blank = () => ({ code: '', name: '', type: 'room', capacity: 1, active: true, calendar: weekdays.reduce((v, [key]) => ({ ...v, [key]: [] }), {}) })

async function load() {
  loading.value = true
  try { resources.value = await api.getBookableResources() }
  catch (e) { error.value = e?.message || 'Impossible de charger les ressources.' }
  finally { loading.value = false }
}
onMounted(load)

function edit(resource) {
  editing.value = JSON.parse(JSON.stringify(resource || blank()))
  weekdays.forEach(([key]) => { editing.value.calendar[key] ||= [] })
}
function toggleDay(key, enabled) {
  editing.value.calendar[key] = enabled ? (editing.value.calendar[key].length ? editing.value.calendar[key] : [{ start: '09:00', end: '18:00' }]) : []
}
function addRange(key) { editing.value.calendar[key].push({ start: '09:00', end: '18:00' }) }
function removeRange(key, index) { editing.value.calendar[key].splice(index, 1) }

async function save() {
  saving.value = true; error.value = ''
  try {
    if (editing.value.code) await api.updateBookableResource(editing.value.code, editing.value)
    else await api.createBookableResource(editing.value)
    editing.value = null
    await load()
  } catch (e) { error.value = e?.message || 'Échec de l’enregistrement.' }
  finally { saving.value = false }
}

async function remove(resource) {
  if (!window.confirm(`Supprimer « ${resource.name} » ? Une ressource déjà utilisée sera seulement désactivée.`)) return
  try { await api.deleteBookableResource(resource.code); await load() }
  catch (e) { error.value = e?.message || 'Échec de la suppression.' }
}
</script>

<template>
  <div class="mx-auto max-w-5xl">
    <div class="flex items-center justify-between gap-4">
      <div><h1 class="font-display text-2xl font-bold text-slate-900">Ressources réservables</h1><p class="mt-1 text-slate-500">Cabines, salles, machines et fauteuils disponibles à la réservation.</p></div>
      <button v-if="!editing" class="btn-primary" @click="edit(null)">+ Nouvelle ressource</button>
    </div>
    <div v-if="error" class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">⚠️ {{ error }}</div>
    <Spinner v-if="loading" />

    <form v-else-if="editing" class="card mt-6 space-y-5 p-6" @submit.prevent="save">
      <div class="grid gap-4 sm:grid-cols-3">
        <div><label class="label">Nom</label><input v-model="editing.name" class="input" required placeholder="Cabine 1" /></div>
        <div><label class="label">Type</label><select v-model="editing.type" class="input"><option v-for="(label, value) in types" :key="value" :value="value">{{ label }}</option></select></div>
        <div><label class="label">Capacité simultanée</label><input v-model.number="editing.capacity" type="number" min="1" class="input" required /></div>
      </div>
      <label class="flex items-center gap-2"><input v-model="editing.active" type="checkbox" class="h-4 w-4 rounded" /><span class="text-sm text-slate-700">Ressource active</span></label>
      <section>
        <h2 class="font-semibold text-slate-800">Calendrier hebdomadaire</h2>
        <div class="mt-3 divide-y divide-slate-100 rounded-xl border border-slate-200">
          <div v-for="([key, label]) in weekdays" :key="key" class="grid gap-3 p-3 sm:grid-cols-[8rem_1fr]">
            <label class="flex items-center gap-2 text-sm font-medium"><input type="checkbox" :checked="editing.calendar[key].length > 0" @change="toggleDay(key, $event.target.checked)" />{{ label }}</label>
            <div v-if="editing.calendar[key].length" class="space-y-2">
              <div v-for="(range, index) in editing.calendar[key]" :key="index" class="flex items-center gap-2">
                <input v-model="range.start" type="time" class="input w-32" required /><span class="text-slate-400">à</span><input v-model="range.end" type="time" class="input w-32" required />
                <button type="button" class="text-rose-500" @click="removeRange(key, index)">Supprimer</button>
              </div>
              <button type="button" class="text-sm text-brand-600" @click="addRange(key)">+ Ajouter une plage</button>
            </div>
          </div>
        </div>
      </section>
      <div class="flex justify-end gap-3"><button type="button" class="btn-ghost" @click="editing = null">Annuler</button><button class="btn-primary" :disabled="saving">{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</button></div>
    </form>

    <div v-else class="mt-6 grid gap-4 sm:grid-cols-2">
      <article v-for="resource in resources" :key="resource.code" class="card p-5">
        <div class="flex justify-between gap-3"><div><p class="text-xs font-semibold uppercase text-brand-600">{{ types[resource.type] }}</p><h2 class="font-semibold text-slate-900">{{ resource.name }}</h2></div><span class="chip" :class="resource.active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'">{{ resource.active ? 'Active' : 'Inactive' }}</span></div>
        <p class="mt-3 text-sm text-slate-500">Capacité : {{ resource.capacity }} · {{ Object.values(resource.calendar).filter(r => r.length).length }} jour(s) ouverts</p>
        <div class="mt-4 flex gap-2"><button class="btn-outline text-sm" @click="edit(resource)">Modifier</button><button class="btn-ghost text-sm text-rose-600" @click="remove(resource)">Supprimer</button></div>
      </article>
      <p v-if="!resources.length" class="text-slate-500">Aucune ressource n’a encore été créée.</p>
    </div>
  </div>
</template>
