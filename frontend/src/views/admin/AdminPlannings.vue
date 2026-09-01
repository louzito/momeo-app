<script setup>
// Plannings — edites sur un CALENDRIER ANNUEL : les 12 mois de l'annee sont
// visibles (navigation par annee), on coche les jours un a un (avec les
// creneaux courants) ou on assigne EN MASSE (plage de dates + jours de semaine
// + creneaux). Modele v2 : jours dates explicites
//   days = { "YYYY-MM-DD": ["09:00", "11:30"], ... }
// (l'ancien format hebdo openDays/times reste lu pour les plannings existants).
// Persistes en taxons Sylius (voir adminApi). Capacite max par creneau,
// 1 saut = 1 place. Rattachement a des sauts precis (aucun = tous).
import { ref, onMounted, computed, watch } from 'vue'
import { useAdminStore } from '@/stores/admin'
import api from '@/api'
import Spinner from '@/components/ui/Spinner.vue'

const admin = useAdminStore()
const plannings = ref([])
const jumpTypes = ref([])
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const notice = ref('')
const deleteArm = ref(null)
const editing = ref(null)

// --- Palette de creneaux appliquee (clic jour + assignation en masse) --------
const timesPalette = ref(['09:00', '11:30', '14:00', '16:30'])
const newTime = ref('')

// --- Calendrier : vue Annee (compacte + tooltip) ou vue Mois (creneaux
// visibles dans les cases) -----------------------------------------------------
const viewMode = ref('year') // 'year' | 'month'
const year = ref(new Date().getFullYear())
const month = ref(new Date().getMonth()) // 0-11, pour la vue Mois

function prevMonth() {
  if (month.value === 0) { month.value = 11; year.value-- } else month.value--
}
function nextMonth() {
  if (month.value === 11) { month.value = 0; year.value++ } else month.value++
}
// Depuis la vue annee : clic sur le nom d'un mois -> zoom sur ce mois.
function openMonth(m) {
  month.value = m
  viewMode.value = 'month'
}
const MONTHS = ['Janvier', 'Fevrier', 'Mars', 'Avril', 'Mai', 'Juin',
  'Juillet', 'Aout', 'Septembre', 'Octobre', 'Novembre', 'Decembre']
const WEEKDAYS_SHORT = ['L', 'M', 'M', 'J', 'V', 'S', 'D']
const DAYS = [
  { d: 1, l: 'Lun' }, { d: 2, l: 'Mar' }, { d: 3, l: 'Mer' }, { d: 4, l: 'Jeu' },
  { d: 5, l: 'Ven' }, { d: 6, l: 'Sam' }, { d: 0, l: 'Dim' },
]

const pad = (n) => String(n).padStart(2, '0')
const dateKey = (y, m, d) => `${y}-${pad(m + 1)}-${pad(d)}`
const todayKey = (() => { const t = new Date(); return dateKey(t.getFullYear(), t.getMonth(), t.getDate()) })()

// Grille d'un mois : alignee lundi en premier (cellules vides en tete).
function monthCells(y, m) {
  const first = new Date(y, m, 1)
  const daysInMonth = new Date(y, m + 1, 0).getDate()
  const lead = (first.getDay() + 6) % 7 // 0 = lundi
  const cells = Array.from({ length: lead }, () => null)
  for (let d = 1; d <= daysInMonth; d++) cells.push({ d, key: dateKey(y, m, d) })
  return cells
}

// Vue Annee : les 12 mois.
const calendar = computed(() => MONTHS.map((label, m) => ({ label, m, cells: monthCells(year.value, m) })))
// Vue Mois : le mois courant seul.
const monthGrid = computed(() => monthCells(year.value, month.value))

// --- Assignation en masse ----------------------------------------------------
const bulk = ref({ start: '', end: '', weekdays: [1, 3] }) // defaut : lundi + mercredi

function eachBulkDate(cb) {
  if (!bulk.value.start || !bulk.value.end) return 0
  const start = new Date(`${bulk.value.start}T00:00:00`)
  const end = new Date(`${bulk.value.end}T00:00:00`)
  if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime()) || start > end) return -1
  let n = 0
  for (let d = new Date(start); d <= end; d.setDate(d.getDate() + 1)) {
    if (!bulk.value.weekdays.includes(d.getDay())) continue
    cb(dateKey(d.getFullYear(), d.getMonth(), d.getDate()))
    n++
  }
  return n
}

function bulkApply() {
  error.value = ''
  if (!timesPalette.value.length) { error.value = 'Ajoutez au moins un creneau a appliquer.'; return }
  if (!bulk.value.weekdays.length) { error.value = 'Choisissez au moins un jour de semaine.'; return }
  const n = eachBulkDate((key) => { editing.value.days[key] = [...timesPalette.value] })
  if (n === -1) { error.value = 'Plage de dates invalide (debut > fin ?).'; return }
  if (n === 0) { error.value = 'Renseignez les dates de debut et de fin.'; return }
  notice.value = `${n} jour(s) renseigne(s) avec ${timesPalette.value.length} creneau(x) chacun.`
  setTimeout(() => (notice.value = ''), 4000)
}

function bulkRemove() {
  error.value = ''
  const n = eachBulkDate((key) => { delete editing.value.days[key] })
  if (n === -1) { error.value = 'Plage de dates invalide (debut > fin ?).'; return }
  if (n === 0) { error.value = 'Renseignez les dates de debut et de fin.'; return }
  notice.value = `${n} jour(s) retire(s) du planning.`
  setTimeout(() => (notice.value = ''), 4000)
}

function toggleBulkWeekday(d) {
  const i = bulk.value.weekdays.indexOf(d)
  if (i >= 0) bulk.value.weekdays.splice(i, 1)
  else bulk.value.weekdays.push(d)
}

// --- Tooltip au survol d'un jour planifie ------------------------------------
// Une seule tooltip flottante (position: fixed) pilotee par mouseenter/leave :
// pas de clipping par les cartes de mois, lisible meme en bord d'ecran.
const hoverTip = ref(null) // { dateLabel, times, x, y }

function showTip(cell, ev) {
  const times = editing.value?.days?.[cell.key]
  if (!times?.length) { hoverTip.value = null; return }
  const rect = ev.currentTarget.getBoundingClientRect()
  const d = new Date(`${cell.key}T00:00:00`)
  hoverTip.value = {
    dateLabel: d.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }),
    times,
    x: Math.min(Math.max(rect.left + rect.width / 2, 90), window.innerWidth - 90),
    y: rect.top,
  }
}
function hideTip() {
  hoverTip.value = null
}
// La tooltip ne doit pas survivre a un changement de vue / d'annee / de mois
// (le mouseleave ne se declenche pas si la grille disparait sous la souris).
watch([viewMode, year, month], hideTip)

// --- Clic sur un jour du calendrier ------------------------------------------
function toggleDay(cell) {
  if (!cell) return
  error.value = ''
  if (editing.value.days[cell.key]) {
    delete editing.value.days[cell.key]
  } else {
    if (!timesPalette.value.length) { error.value = 'Ajoutez au moins un creneau a appliquer.'; return }
    editing.value.days[cell.key] = [...timesPalette.value]
  }
}

// --- Palette creneaux ---------------------------------------------------------
function addTime() {
  const t = newTime.value.trim()
  if (!/^([01]?\d|2[0-3]):[0-5]\d$/.test(t)) { error.value = 'Heure invalide (format HH:MM).'; return }
  error.value = ''
  const norm = t.padStart(5, '0')
  if (!timesPalette.value.includes(norm)) { timesPalette.value.push(norm); timesPalette.value.sort() }
  newTime.value = ''
}
function removeTime(t) {
  timesPalette.value = timesPalette.value.filter((x) => x !== t)
}

// --- Stats / affichage --------------------------------------------------------
const dayCount = computed(() => Object.keys(editing.value?.days || {}).length)
const slotCount = computed(() => Object.values(editing.value?.days || {}).reduce((s, t) => s + t.length, 0))

function planningSummary(p) {
  const keys = Object.keys(p.days || {}).sort()
  if (keys.length) {
    const nb = Object.values(p.days).reduce((s, t) => s + t.length, 0)
    return `${keys.length} jour(s) planifie(s) · ${nb} creneau(x) · du ${keys[0]} au ${keys[keys.length - 1]}`
  }
  if (p.openDays?.length) {
    return `Ancien format hebdo : ${p.openDays.map((d) => DAYS.find((x) => x.d === d)?.l).join(' ')} · ${(p.times || []).join(' ')}`
  }
  return 'Aucun jour planifie'
}

const isLegacy = computed(() => editing.value && !Object.keys(editing.value.days).length && editing.value.openDays?.length)

const jumpName = (code) => jumpTypes.value.find((j) => j.id === code)?.name || code
const isNew = computed(() => editing.value && !editing.value.code)

function blankPlanning() {
  return { code: null, name: '', capacity: 8, jumpCodes: [], active: true, days: {}, openDays: [], times: [] }
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const [p, j] = await Promise.all([api.getPlannings?.() || [], api.getJumpTypes(admin.tenantId)])
    plannings.value = p
    jumpTypes.value = j
  } catch (e) {
    error.value = e?.message || 'Impossible de charger les plannings.'
  } finally {
    loading.value = false
  }
}
onMounted(load)

function startCreate() {
  editing.value = blankPlanning()
  year.value = new Date().getFullYear()
}
function startEdit(p) {
  editing.value = JSON.parse(JSON.stringify(p))
  // Ancien format hebdo -> pre-remplit la palette et l'assignation en masse
  // pour migrer facilement vers des jours dates.
  if (!Object.keys(editing.value.days).length && editing.value.times?.length) {
    timesPalette.value = [...editing.value.times]
    bulk.value.weekdays = [...(editing.value.openDays || [])]
  }
  const keys = Object.keys(editing.value.days).sort()
  // Positionne le calendrier sur le premier jour planifie a venir (ou aujourd'hui).
  const anchor = keys.find((k) => k >= todayKey) || keys[0]
  if (anchor) {
    year.value = Number(anchor.slice(0, 4))
    month.value = Number(anchor.slice(5, 7)) - 1
  } else {
    year.value = new Date().getFullYear()
    month.value = new Date().getMonth()
  }
}
function cancelEdit() {
  editing.value = null
}

function toggleJump(code) {
  const i = editing.value.jumpCodes.indexOf(code)
  if (i >= 0) editing.value.jumpCodes.splice(i, 1)
  else editing.value.jumpCodes.push(code)
}

async function save() {
  if (!editing.value.name.trim()) { error.value = 'Donnez un nom au planning.'; return }
  if (!Object.keys(editing.value.days).length && !editing.value.openDays?.length) {
    error.value = 'Renseignez au moins un jour (clic sur le calendrier ou assignation en masse).'
    return
  }
  saving.value = true
  error.value = ''
  try {
    if (editing.value.code) await api.updatePlanning(admin.tenantId, editing.value.code, editing.value)
    else await api.createPlanning(admin.tenantId, editing.value)
    editing.value = null
    await load()
  } catch (e) {
    error.value = e?.message || 'Echec de l\'enregistrement.'
  } finally {
    saving.value = false
  }
}

async function toggleActive(p) {
  try {
    await api.updatePlanning(admin.tenantId, p.code, { ...p, active: !p.active })
    p.active = !p.active
  } catch (e) {
    error.value = e?.message || 'Echec de la mise a jour.'
  }
}

async function remove(p) {
  if (deleteArm.value !== p.code) {
    deleteArm.value = p.code
    setTimeout(() => { if (deleteArm.value === p.code) deleteArm.value = null }, 4000)
    return
  }
  deleteArm.value = null
  try {
    await api.deletePlanning(admin.tenantId, p.code)
    plannings.value = plannings.value.filter((x) => x.code !== p.code)
  } catch (e) {
    error.value = e?.message || 'Echec de la suppression.'
  }
}
</script>

<template>
  <div class="mx-auto max-w-5xl">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="font-display text-2xl font-bold text-slate-900">Agenda</h1>
        <p class="mt-1 text-slate-500">
          Cochez vos jours d'ouverture sur le calendrier annuel, ou assignez vos creneaux en masse.
          Définissez les jours, horaires et capacités proposés à la réservation.
        </p>
      </div>
      <button v-if="!editing" class="btn-primary" @click="startCreate">+ Nouveau planning</button>
    </div>

    <div v-if="error" class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">⚠️ {{ error }}</div>
    <div v-if="notice" class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">✓ {{ notice }}</div>

    <!-- ======================= EDITEUR ======================= -->
    <div v-if="editing" class="card mt-5 space-y-6 p-6">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="font-semibold text-slate-800">{{ isNew ? 'Nouveau planning' : `Modifier « ${editing.name} »` }}</h2>
        <p class="text-sm text-slate-500">{{ dayCount }} jour(s) · {{ slotCount }} creneau(x)</p>
      </div>

      <div v-if="isLegacy" class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
        Ce planning utilise l'ancien format hebdomadaire. Ses jours/heures ont ete recopies dans
        l'assignation en masse ci-dessous : choisissez une plage de dates puis « Appliquer » pour le
        convertir en jours dates. Tant qu'aucun jour n'est coche, l'ancien pattern reste utilise.
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label class="label">Nom du planning</label>
          <input v-model="editing.name" class="input" placeholder="Horaires habituels, saison d’été…" />
        </div>
        <div>
          <label class="label">Capacite max / creneau (places)</label>
          <input v-model.number="editing.capacity" type="number" min="1" class="input" />
        </div>
      </div>

      <!-- Creneaux a appliquer -->
      <div class="rounded-2xl bg-slate-50 p-4">
        <label class="label">Creneaux a appliquer (au clic sur un jour et en masse)</label>
        <div class="mt-1 flex flex-wrap items-center gap-2">
          <span v-for="t in timesPalette" :key="t" class="chip bg-white font-mono text-slate-700 ring-1 ring-slate-200">
            {{ t }}
            <button type="button" class="ml-1 text-slate-400 hover:text-rose-500" @click="removeTime(t)">✕</button>
          </span>
          <input v-model="newTime" class="input w-24 py-1 text-sm" placeholder="HH:MM" @keyup.enter="addTime" />
          <button type="button" class="btn-ghost px-3 py-1 text-sm" @click="addTime">+ Ajouter</button>
        </div>
      </div>

      <!-- Assignation en masse -->
      <div class="rounded-2xl border border-brand-100 bg-brand-50/40 p-4">
        <p class="font-semibold text-slate-800">⚡ Assignation en masse</p>
        <p class="mt-0.5 text-xs text-slate-500">
          Renseigne les creneaux ci-dessus sur tous les jours de semaine choisis, entre deux dates.
        </p>
        <div class="mt-3 flex flex-wrap items-end gap-4">
          <div>
            <label class="label">Du</label>
            <input v-model="bulk.start" type="date" class="input" />
          </div>
          <div>
            <label class="label">Au</label>
            <input v-model="bulk.end" type="date" class="input" />
          </div>
          <div>
            <label class="label">Jours de semaine</label>
            <div class="flex flex-wrap gap-1.5">
              <button
                v-for="d in DAYS"
                :key="d.d"
                type="button"
                class="rounded-full px-3 py-1.5 text-sm font-medium transition"
                :class="bulk.weekdays.includes(d.d) ? 'bg-brand-600 text-white' : 'bg-white text-slate-500 ring-1 ring-slate-200 hover:bg-slate-50'"
                @click="toggleBulkWeekday(d.d)"
              >
                {{ d.l }}
              </button>
            </div>
          </div>
          <div class="flex gap-2">
            <button type="button" class="btn-primary px-5" @click="bulkApply">Appliquer</button>
            <button type="button" class="btn-ghost px-4 text-rose-500 hover:bg-rose-50" @click="bulkRemove">Retirer</button>
          </div>
        </div>
      </div>

      <!-- Calendrier -->
      <div>
        <!-- Navigation + switch de vue -->
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
          <div class="flex items-center gap-3">
            <template v-if="viewMode === 'year'">
              <button type="button" class="btn-ghost px-3 py-1" @click="year--">←</button>
              <span class="font-display text-xl font-bold text-slate-900">{{ year }}</span>
              <button type="button" class="btn-ghost px-3 py-1" @click="year++">→</button>
            </template>
            <template v-else>
              <button type="button" class="btn-ghost px-3 py-1" @click="prevMonth">←</button>
              <span class="font-display text-xl font-bold text-slate-900">{{ MONTHS[month] }} {{ year }}</span>
              <button type="button" class="btn-ghost px-3 py-1" @click="nextMonth">→</button>
            </template>
          </div>
          <!-- Switch Annee / Mois -->
          <div class="flex rounded-full bg-slate-100 p-1 text-sm font-medium">
            <button
              type="button"
              class="rounded-full px-4 py-1 transition"
              :class="viewMode === 'year' ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-500'"
              @click="viewMode = 'year'"
            >
              Annee
            </button>
            <button
              type="button"
              class="rounded-full px-4 py-1 transition"
              :class="viewMode === 'month' ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-500'"
              @click="viewMode = 'month'"
            >
              Mois
            </button>
          </div>
        </div>
        <p class="mb-3 text-center text-xs text-slate-400">
          Clic sur un jour : ajoute les creneaux ci-dessus · re-clic : retire le jour.
          <span class="ml-2 inline-block h-3 w-3 rounded bg-brand-600 align-middle" /> jour planifie
          <template v-if="viewMode === 'year'"> · survol : detail des creneaux · clic sur un nom de mois : zoom</template>
        </p>

        <!-- ===== Vue ANNEE : 12 mois compacts + tooltip au survol ===== -->
        <div v-if="viewMode === 'year'" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
          <div v-for="mo in calendar" :key="mo.m" class="rounded-xl border border-slate-200 bg-white p-2.5">
            <button
              type="button"
              class="mb-1.5 w-full text-center text-sm font-semibold text-slate-700 hover:text-brand-600"
              title="Ouvrir ce mois"
              @click="openMonth(mo.m)"
            >
              {{ mo.label }}
            </button>
            <div class="grid grid-cols-7 gap-0.5 text-center">
              <span v-for="(w, i) in WEEKDAYS_SHORT" :key="'h' + i" class="text-[10px] font-semibold text-slate-400">{{ w }}</span>
              <template v-for="(cell, i) in mo.cells" :key="i">
                <span v-if="!cell" />
                <button
                  v-else
                  type="button"
                  class="h-6 w-6 rounded text-[11px] leading-6 transition"
                  :class="[
                    editing.days[cell.key]
                      ? 'bg-brand-600 font-semibold text-white hover:bg-brand-700'
                      : 'text-slate-600 hover:bg-brand-100',
                    cell.key < todayKey ? 'opacity-40' : '',
                    cell.key === todayKey ? 'ring-1 ring-accent-500' : '',
                  ]"
                  @click="toggleDay(cell)"
                  @mouseenter="showTip(cell, $event)"
                  @mouseleave="hideTip"
                >
                  {{ cell.d }}
                </button>
              </template>
            </div>
          </div>
        </div>

        <!-- ===== Vue MOIS : grandes cases avec creneaux visibles ===== -->
        <div v-else class="rounded-xl border border-slate-200 bg-white p-3">
          <div class="grid grid-cols-7 gap-1.5">
            <span v-for="d in DAYS" :key="'mh' + d.d" class="pb-1 text-center text-xs font-semibold text-slate-400">{{ d.l }}</span>
            <template v-for="(cell, i) in monthGrid" :key="i">
              <span v-if="!cell" />
              <button
                v-else
                type="button"
                class="flex min-h-[4.5rem] flex-col items-stretch rounded-lg border p-1.5 text-left transition"
                :class="[
                  editing.days[cell.key]
                    ? 'border-brand-300 bg-brand-50 hover:border-brand-400'
                    : 'border-slate-100 bg-white hover:border-brand-200 hover:bg-brand-50/40',
                  cell.key < todayKey ? 'opacity-40' : '',
                ]"
                @click="toggleDay(cell)"
              >
                <span
                  class="text-xs font-semibold"
                  :class="[
                    editing.days[cell.key] ? 'text-brand-700' : 'text-slate-500',
                    cell.key === todayKey ? 'inline-block w-fit rounded bg-accent-500 px-1 text-white' : '',
                  ]"
                >
                  {{ cell.d }}
                </span>
                <span v-if="editing.days[cell.key]" class="mt-1 flex flex-wrap gap-0.5">
                  <span
                    v-for="t in editing.days[cell.key]"
                    :key="t"
                    class="rounded bg-brand-600 px-1 py-0.5 font-mono text-[10px] leading-none text-white"
                  >
                    {{ t }}
                  </span>
                </span>
              </button>
            </template>
          </div>
        </div>
      </div>

      <!-- Tooltip flottante (vue Annee) -->
      <div
        v-if="hoverTip"
        class="pointer-events-none fixed z-50 -translate-x-1/2 -translate-y-full pb-2"
        :style="{ left: hoverTip.x + 'px', top: hoverTip.y + 'px' }"
      >
        <div class="rounded-xl bg-slate-900 px-3 py-2 text-white shadow-lg">
          <p class="text-xs font-semibold capitalize">{{ hoverTip.dateLabel }}</p>
          <div class="mt-1.5 flex flex-wrap gap-1">
            <span v-for="t in hoverTip.times" :key="t" class="rounded bg-white/15 px-1.5 py-0.5 font-mono text-[11px]">{{ t }}</span>
          </div>
        </div>
      </div>

      <!-- Prestations concernees -->
      <div>
        <label class="label">Prestations concernées</label>
        <p class="mb-2 text-xs text-slate-400">Sans sélection, cet agenda s’applique à toutes les prestations.</p>
        <div class="flex flex-wrap gap-2">
          <button
            v-for="j in jumpTypes"
            :key="j.id"
            type="button"
            class="rounded-full px-3.5 py-1.5 text-sm font-medium transition"
            :class="editing.jumpCodes.includes(j.id) ? 'bg-accent-500 text-white' : 'bg-white text-slate-500 ring-1 ring-slate-200 hover:bg-slate-50'"
            @click="toggleJump(j.id)"
          >
            {{ j.name }}
          </button>
        </div>
      </div>

      <label class="flex items-center gap-2">
        <input v-model="editing.active" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-brand-600" />
        <span class="text-sm text-slate-700">Planning actif (creneaux proposes aux clients)</span>
      </label>

      <div class="flex justify-end gap-3">
        <button class="btn-ghost" @click="cancelEdit">Annuler</button>
        <button class="btn-primary px-8" :disabled="saving" @click="save">
          {{ saving ? 'Enregistrement…' : 'Enregistrer' }}
        </button>
      </div>
    </div>

    <Spinner v-if="loading" />

    <div v-else-if="!plannings.length && !editing" class="card mt-6 p-8 text-center text-slate-400">
      <p class="text-3xl">🗓️</p>
      <p class="mt-2">Aucun planning pour l'instant.</p>
      <button class="btn-primary mt-4" @click="startCreate">Creer le premier planning</button>
    </div>

    <!-- ======================= LISTE ======================= -->
    <div v-else class="mt-6 space-y-4">
      <div v-for="p in plannings" :key="p.code" class="card p-5" :class="p.active ? '' : 'opacity-60'">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <p class="font-semibold text-slate-800">
              {{ p.name }}
              <span class="ml-2 rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="p.active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-500'">
                {{ p.active ? 'Actif' : 'Inactif' }}
              </span>
            </p>
            <p class="mt-1 text-sm text-slate-500">
              {{ planningSummary(p) }}
              <span class="mx-1 text-slate-300">|</span>
              {{ p.capacity }} places / creneau
            </p>
            <p class="mt-1 text-xs text-slate-400">
              <template v-if="p.jumpCodes.length">Prestations : {{ p.jumpCodes.map(jumpName).join(', ') }}</template>
              <template v-else>S’applique à toutes les prestations</template>
            </p>
          </div>
          <div class="flex items-center gap-2">
            <button class="btn-ghost px-3 py-1 text-sm" @click="startEdit(p)">Modifier</button>
            <button class="btn-ghost px-3 py-1 text-sm" @click="toggleActive(p)">{{ p.active ? 'Desactiver' : 'Activer' }}</button>
            <button
              class="btn-ghost px-3 py-1 text-sm"
              :class="deleteArm === p.code ? 'bg-rose-500 text-white hover:bg-rose-600' : 'text-rose-500 hover:bg-rose-50'"
              @click="remove(p)"
            >
              {{ deleteArm === p.code ? 'Confirmer ?' : 'Supprimer' }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
