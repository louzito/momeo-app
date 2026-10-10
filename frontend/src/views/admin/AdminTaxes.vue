<script setup>
import { computed, ref, onMounted } from 'vue'
import api from '@/api'
import Spinner from '@/components/ui/Spinner.vue'

const rates = ref([])
const categories = ref([])
const zones = ref([])
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const success = ref('')
const form = ref(null)
const relationCode = (value) => value?.code || String(value?.['@id'] || value || '').split('/').pop()
const unusedCategories = computed(() => categories.value.filter((category) => !rates.value.some((rate) => relationCode(rate.category) === category.code)))
const percent = (amount) => new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 4 }).format(Number(amount) * 100)
const zoneName = (rate) => zones.value.find((zone) => zone.code === relationCode(rate.zone))?.name || relationCode(rate.zone)
const categoryName = (rate) => categories.value.find((category) => category.code === relationCode(rate.category))?.name || relationCode(rate.category)

async function load() {
  loading.value = true
  error.value = ''
  try {
    const data = await api.getTaxConfiguration()
    rates.value = data.rates
    categories.value = data.categories
    zones.value = data.zones
  } catch (e) { error.value = e.message || 'Impossible de charger les taux de TVA.' }
  finally { loading.value = false }
}
onMounted(load)

function open(rate = null) {
  error.value = ''
  success.value = ''
  form.value = rate ? {
    code: rate.code, name: rate.name, percentage: Number((Number(rate.amount) * 100).toFixed(4)),
    category: rate.category?.['@id'] || rate.category,
    zone: rate.zone?.['@id'] || rate.zone,
    includedInPrice: rate.includedInPrice,
    startDate: rate.startDate?.slice(0, 10) || '', endDate: rate.endDate?.slice(0, 10) || '',
  } : {
    code: null, name: '', percentage: 20, category: '',
    zone: (zones.value.find((zone) => zone.code === 'FR') || zones.value[0])?.['@id'] || '',
    includedInPrice: true, startDate: '', endDate: '',
  }
}

async function save() {
  saving.value = true
  error.value = ''
  success.value = ''
  try {
    await api.saveTaxRate({ ...form.value })
    form.value = null
    await load()
    if (!error.value) success.value = 'Taux enregistré. Il est disponible dans les fiches produits et prestations.'
  } catch (e) { error.value = e.message || 'Impossible d’enregistrer ce taux de TVA.' }
  finally { saving.value = false }
}
</script>

<template>
  <div>
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div><h1 class="font-display text-2xl font-bold text-slate-900">TVA</h1><p class="mt-1 text-slate-500">Configurez les taux à sélectionner dans vos produits et prestations.</p></div>
      <button class="btn-primary" :disabled="loading || saving || !zones.length" @click="open()">Ajouter un taux</button>
    </div>
    <p v-if="error" role="alert" class="mt-5 rounded-xl bg-rose-50 p-4 text-rose-700">{{ error }}</p>
    <p v-if="success" role="status" class="mt-5 rounded-xl bg-emerald-50 p-4 text-emerald-700">{{ success }}</p>
    <Spinner v-if="loading" />
    <template v-else>
      <p v-if="!zones.length" class="mt-5 rounded-xl bg-amber-50 p-4 text-amber-800">Aucune zone d’application n’est configurée pour cet établissement.</p>
      <form v-if="form" class="card mt-6 space-y-4 p-6" @submit.prevent="save">
        <h2 class="text-lg font-semibold">{{ form.code ? 'Modifier le taux' : 'Nouveau taux' }}</h2>
        <div class="grid gap-4 sm:grid-cols-2">
          <label class="label">Nom<input v-model.trim="form.name" required minlength="2" maxlength="255" class="input mt-1" placeholder="TVA standard" /></label>
          <label class="label">Taux (%)<input v-model.number="form.percentage" required type="number" min="0" max="100" step="0.0001" class="input mt-1" /></label>
          <label class="label">Zone d’application<select v-model="form.zone" required class="input mt-1"><option v-for="zone in zones" :key="zone.code" :value="zone['@id']">{{ zone.name }}</option></select></label>
          <label v-if="!form.code" class="label">Catégorie<select v-model="form.category" class="input mt-1"><option value="">Créer une catégorie avec ce nom</option><option v-for="category in unusedCategories" :key="category.code" :value="category['@id']">{{ category.name }}</option></select></label>
          <label class="label">Applicable à partir du (facultatif)<input v-model="form.startDate" type="date" class="input mt-1" /></label>
          <label class="label">Fin d’application, date exclue (facultatif)<input v-model="form.endDate" type="date" :min="form.startDate || undefined" class="input mt-1" /></label>
        </div>
        <label class="flex items-start gap-3 text-sm"><input v-model="form.includedInPrice" type="checkbox" class="mt-1" /><span>TVA comprise dans le prix renseigné (TTC)<span class="block text-xs text-slate-500">Si cette option est décochée, la TVA est ajoutée au prix lors de la commande.</span></span></label>
        <p class="text-sm text-slate-500">Les modifications s’appliquent aux nouvelles commandes des produits utilisant cette catégorie. Les factures déjà émises conservent leurs montants.</p>
        <div class="flex gap-3"><button class="btn-primary" :disabled="saving">{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</button><button type="button" class="btn-outline" :disabled="saving" @click="form = null">Annuler</button></div>
      </form>
      <p v-if="!rates.length" class="mt-6 rounded-xl border border-dashed border-slate-300 p-8 text-center text-slate-500">Aucun taux de TVA configuré. Ajoutez un taux pour le proposer dans vos fiches produits.</p>
      <div v-else class="mt-6 space-y-3">
        <div v-for="rate in rates" :key="rate.code" class="card flex flex-wrap items-center justify-between gap-4 p-5">
          <div><p class="font-semibold text-slate-900">{{ rate.name }} · {{ percent(rate.amount) }} %</p><p class="mt-1 text-sm text-slate-500">{{ categoryName(rate) }} · {{ zoneName(rate) }} · {{ rate.includedInPrice ? 'Comprise dans le prix (TTC)' : 'Ajoutée au prix (HT)' }}</p><p v-if="rate.startDate || rate.endDate" class="mt-1 text-xs text-slate-500">{{ rate.startDate ? `À partir du ${new Date(rate.startDate).toLocaleDateString('fr-FR')}` : '' }}{{ rate.endDate ? ` · Avant le ${new Date(rate.endDate).toLocaleDateString('fr-FR')}` : '' }}</p></div>
          <button class="btn-outline" :disabled="saving" @click="open(rate)">Modifier</button>
        </div>
      </div>
    </template>
  </div>
</template>
