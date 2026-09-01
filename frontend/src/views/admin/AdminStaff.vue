<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '@/api'
import { useAdminStore } from '@/stores/admin'
import Spinner from '@/components/ui/Spinner.vue'
import EmptyState from '@/components/ui/EmptyState.vue'

const admin = useAdminStore()
const members = ref([])
const services = ref([])
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const editingId = ref(null)
const editorOpen = ref(false)
const archiveId = ref(null)

const days = [
  ['monday', 'Lundi'],
  ['tuesday', 'Mardi'],
  ['wednesday', 'Mercredi'],
  ['thursday', 'Jeudi'],
  ['friday', 'Vendredi'],
  ['saturday', 'Samedi'],
  ['sunday', 'Dimanche'],
]

function defaultHours() {
  return Object.fromEntries(days.map(([key], index) => [key, {
    enabled: index < 5,
    start: '09:00',
    end: '18:00',
  }]))
}

function emptyForm() {
  return {
    firstName: '',
    lastName: '',
    email: '',
    phone: '',
    jobTitle: '',
    bio: '',
    color: '#1f5c57',
    active: true,
    bookable: true,
    serviceCodes: [],
    workingHours: defaultHours(),
    position: members.value.length,
  }
}

const form = ref(emptyForm())
const activeCount = computed(() => members.value.filter((member) => member.active).length)
const bookableCount = computed(() => members.value.filter((member) => member.active && member.bookable).length)

function hydrateHours(value = {}) {
  const defaults = defaultHours()
  return Object.fromEntries(days.map(([key]) => [key, { ...defaults[key], ...(value[key] || {}) }]))
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    ;[members.value, services.value] = await Promise.all([
      api.getStaffMembers(admin.tenantId),
      api.getJumpTypes(admin.tenantId),
    ])
  } catch (e) {
    error.value = e.message || 'Impossible de charger l’équipe.'
  } finally {
    loading.value = false
  }
}

function createMember() {
  editingId.value = null
  form.value = emptyForm()
  error.value = ''
  editorOpen.value = true
}

function editMember(member) {
  editingId.value = member.id
  form.value = {
    firstName: member.firstName || '',
    lastName: member.lastName || '',
    email: member.email || '',
    phone: member.phone || '',
    jobTitle: member.jobTitle || '',
    bio: member.bio || '',
    color: member.color || '#1f5c57',
    active: member.active !== false,
    bookable: member.bookable !== false,
    serviceCodes: [...(member.serviceCodes || [])],
    workingHours: hydrateHours(member.workingHours),
    position: member.position || 0,
  }
  error.value = ''
  editorOpen.value = true
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

function closeEditor() {
  editorOpen.value = false
  editingId.value = null
  error.value = ''
}

async function save() {
  error.value = ''
  saving.value = true
  try {
    if (editingId.value) {
      await api.updateStaffMember(admin.tenantId, editingId.value, form.value)
    } else {
      await api.createStaffMember(admin.tenantId, form.value)
    }
    closeEditor()
    await load()
  } catch (e) {
    error.value = e.message || 'La fiche n’a pas pu être enregistrée.'
  } finally {
    saving.value = false
  }
}

async function archive(member) {
  error.value = ''
  try {
    await api.archiveStaffMember(admin.tenantId, member.id)
    archiveId.value = null
    await load()
  } catch (e) {
    error.value = e.message || 'Le collaborateur n’a pas pu être archivé.'
  }
}

function serviceName(code) {
  return services.value.find((service) => service.id === code)?.name || code
}

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-7xl">
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-brand-600">Organisation</p>
        <h1 class="mt-1 font-display text-2xl font-bold text-slate-900">Équipe</h1>
        <p class="mt-1 max-w-2xl text-slate-500">Associez vos collaborateurs aux prestations et définissez leurs horaires habituels.</p>
      </div>
      <button type="button" class="btn-primary" @click="createMember">+ Ajouter un collaborateur</button>
    </div>

    <div class="mt-6 grid gap-3 sm:grid-cols-3">
      <div class="card p-4">
        <p class="text-sm text-slate-500">Collaborateurs actifs</p>
        <p class="mt-1 font-display text-2xl font-bold text-slate-900">{{ activeCount }}</p>
      </div>
      <div class="card p-4">
        <p class="text-sm text-slate-500">Disponibles à la réservation</p>
        <p class="mt-1 font-display text-2xl font-bold text-slate-900">{{ bookableCount }}</p>
      </div>
      <div class="card p-4">
        <p class="text-sm text-slate-500">Prestations configurées</p>
        <p class="mt-1 font-display text-2xl font-bold text-slate-900">{{ services.length }}</p>
      </div>
    </div>

    <p v-if="error" class="mt-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</p>

    <form v-if="editorOpen" class="card mt-6 overflow-hidden" @submit.prevent="save">
      <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-5 py-4">
        <div>
          <h2 class="font-display text-lg font-bold text-slate-900">{{ editingId ? 'Modifier le collaborateur' : 'Nouveau collaborateur' }}</h2>
          <p class="text-sm text-slate-500">Les informations de contact restent visibles uniquement dans votre espace.</p>
        </div>
        <button type="button" class="btn-ghost" aria-label="Fermer" @click="closeEditor">✕</button>
      </div>

      <div class="grid gap-8 p-5 xl:grid-cols-[minmax(0,1fr)_minmax(380px,0.9fr)]">
        <div class="space-y-6">
          <section>
            <h3 class="text-sm font-bold text-slate-800">Identité et rôle</h3>
            <div class="mt-3 grid gap-4 sm:grid-cols-2">
              <label class="block text-sm font-medium text-slate-700">Prénom *
                <input v-model.trim="form.firstName" class="input mt-1 w-full" required maxlength="100" autocomplete="given-name" />
              </label>
              <label class="block text-sm font-medium text-slate-700">Nom *
                <input v-model.trim="form.lastName" class="input mt-1 w-full" required maxlength="100" autocomplete="family-name" />
              </label>
              <label class="block text-sm font-medium text-slate-700">Métier ou spécialité
                <input v-model.trim="form.jobTitle" class="input mt-1 w-full" maxlength="120" placeholder="Ex. Esthéticienne" />
              </label>
              <label class="block text-sm font-medium text-slate-700">Couleur dans l’agenda
                <span class="mt-1 flex items-center gap-3 rounded-xl border border-slate-200 bg-white px-3 py-2">
                  <input v-model="form.color" type="color" class="h-8 w-10 cursor-pointer rounded border-0 bg-transparent p-0" />
                  <span class="font-mono text-xs text-slate-500">{{ form.color }}</span>
                </span>
              </label>
              <label class="block text-sm font-medium text-slate-700">Email
                <input v-model.trim="form.email" class="input mt-1 w-full" type="email" maxlength="180" autocomplete="email" />
              </label>
              <label class="block text-sm font-medium text-slate-700">Téléphone
                <input v-model.trim="form.phone" class="input mt-1 w-full" type="tel" maxlength="40" autocomplete="tel" />
              </label>
            </div>
            <label class="mt-4 block text-sm font-medium text-slate-700">Présentation interne
              <textarea v-model.trim="form.bio" class="input mt-1 min-h-24 w-full resize-y" placeholder="Compétences, informations utiles…" />
            </label>
          </section>

          <section>
            <div class="flex items-center justify-between gap-3">
              <h3 class="text-sm font-bold text-slate-800">Prestations réalisées</h3>
              <span class="text-xs text-slate-400">{{ form.serviceCodes.length }} sélectionnée(s)</span>
            </div>
            <div v-if="services.length" class="mt-3 grid gap-2 sm:grid-cols-2">
              <label v-for="service in services" :key="service.id" class="flex cursor-pointer items-center gap-3 rounded-xl border border-slate-200 bg-white px-3 py-3 transition hover:border-brand-300">
                <input v-model="form.serviceCodes" type="checkbox" :value="service.id" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500" />
                <span class="min-w-0 text-sm font-medium text-slate-700">{{ service.name }}</span>
              </label>
            </div>
            <p v-else class="mt-3 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800">Créez d’abord une prestation pour pouvoir l’affecter à l’équipe.</p>
          </section>

          <section class="grid gap-3 sm:grid-cols-2">
            <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 p-4">
              <input v-model="form.active" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500" />
              <span><strong class="block text-sm text-slate-800">Profil actif</strong><span class="text-xs text-slate-500">Le collaborateur fait partie de l’équipe actuelle.</span></span>
            </label>
            <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 p-4">
              <input v-model="form.bookable" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500" />
              <span><strong class="block text-sm text-slate-800">Réservable en ligne</strong><span class="text-xs text-slate-500">Ses disponibilités pourront générer des créneaux.</span></span>
            </label>
          </section>
        </div>

        <section>
          <h3 class="text-sm font-bold text-slate-800">Horaires habituels</h3>
          <p class="mt-1 text-xs text-slate-500">Cette base servira à construire les disponibilités individuelles.</p>
          <div class="mt-3 divide-y divide-slate-100 rounded-2xl border border-slate-200 bg-white">
            <div v-for="([key, label]) in days" :key="key" class="grid grid-cols-[105px_1fr] items-center gap-3 px-3 py-3 sm:grid-cols-[120px_1fr]">
              <label class="flex cursor-pointer items-center gap-2 text-sm font-medium text-slate-700">
                <input v-model="form.workingHours[key].enabled" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500" />
                {{ label }}
              </label>
              <div v-if="form.workingHours[key].enabled" class="flex items-center gap-2">
                <input v-model="form.workingHours[key].start" type="time" class="input min-w-0 flex-1 px-2 py-1.5 text-sm" />
                <span class="text-xs text-slate-400">à</span>
                <input v-model="form.workingHours[key].end" type="time" class="input min-w-0 flex-1 px-2 py-1.5 text-sm" />
              </div>
              <span v-else class="text-sm text-slate-400">Indisponible</span>
            </div>
          </div>
        </section>
      </div>

      <div class="flex justify-end gap-3 border-t border-slate-200 bg-slate-50 px-5 py-4">
        <button type="button" class="btn-outline" @click="closeEditor">Annuler</button>
        <button type="submit" class="btn-primary" :disabled="saving">
          {{ saving ? 'Enregistrement…' : editingId ? 'Enregistrer les modifications' : 'Ajouter à l’équipe' }}
        </button>
      </div>
    </form>

    <Spinner v-if="loading" />

    <EmptyState v-else-if="!members.length && !editorOpen" class="mt-6" icon="👥" title="Votre équipe est vide" message="Ajoutez votre premier collaborateur et associez-lui ses prestations.">
      <button type="button" class="btn-primary" @click="createMember">Ajouter un collaborateur</button>
    </EmptyState>

    <div v-else-if="members.length" class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
      <article v-for="member in members" :key="member.id" class="card p-5" :class="{ 'opacity-60': !member.active }">
        <div class="flex items-start gap-3">
          <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl text-sm font-bold text-white" :style="{ backgroundColor: member.color }">
            {{ member.firstName?.[0] }}{{ member.lastName?.[0] }}
          </div>
          <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
              <h2 class="truncate font-display font-bold text-slate-900">{{ member.displayName }}</h2>
              <span v-if="!member.active" class="chip bg-slate-100 text-slate-500">Archivé</span>
              <span v-else-if="member.bookable" class="chip bg-emerald-50 text-emerald-700">Réservable</span>
            </div>
            <p class="mt-0.5 text-sm text-slate-500">{{ member.jobTitle || 'Collaborateur' }}</p>
          </div>
        </div>

        <div class="mt-4 space-y-1 text-sm text-slate-500">
          <p v-if="member.email" class="truncate">{{ member.email }}</p>
          <p v-if="member.phone">{{ member.phone }}</p>
        </div>

        <div class="mt-4 flex min-h-7 flex-wrap gap-1.5">
          <span v-for="code in member.serviceCodes" :key="code" class="chip bg-brand-50 text-brand-700">{{ serviceName(code) }}</span>
          <span v-if="!member.serviceCodes?.length" class="text-xs text-slate-400">Aucune prestation affectée</span>
        </div>

        <div class="mt-5 flex items-center gap-2 border-t border-slate-100 pt-4">
          <button type="button" class="btn-outline flex-1 py-2" @click="editMember(member)">Modifier</button>
          <template v-if="member.active && archiveId === member.id">
            <button type="button" class="btn bg-rose-600 py-2 text-white hover:bg-rose-700" @click="archive(member)">Confirmer</button>
            <button type="button" class="btn-ghost" @click="archiveId = null">✕</button>
          </template>
          <button v-else-if="member.active" type="button" class="btn-ghost py-2 text-rose-600 hover:bg-rose-50" @click="archiveId = member.id">Archiver</button>
        </div>
      </article>
    </div>
  </div>
</template>
