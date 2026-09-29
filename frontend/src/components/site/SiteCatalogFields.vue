<script setup>
import { onMounted, ref } from 'vue'
import api from '@/api'
import { TENANT_SLUG } from '@/api/config'
import { fetchSiteCatalog } from '@/api/siteCatalog'
const props = defineProps({ block: Object })
const offers = ref([]), loading = ref(true), error = ref('')
async function load() {
  loading.value = true; error.value = ''
  try {
    const data = await fetchSiteCatalog(api, TENANT_SLUG)
    offers.value = [...data.jumpTypes, ...data.products]
  } catch { error.value = 'Impossible de charger les offres. Réessayez.' }
  finally { loading.value = false }
}
onMounted(load)
</script>
<template>
  <div class="space-y-3" :aria-busy="loading">
    <label class="block">Afficher <select v-model="block.props.mode" class="input" @change="block.props.codes = []"><option value="selection">Une sélection</option><option value="category">Une catégorie</option></select></label>
    <label v-if="block.props.mode === 'category'" class="block">Catégorie <select v-model="block.props.category" class="input"><option value="prestations">Prestations</option><option value="produits">Produits</option></select></label>
    <template v-else>
      <p v-if="loading" role="status">Chargement des offres…</p>
      <div v-else-if="error" role="alert">{{ error }} <button type="button" class="underline" @click="load">Réessayer</button></div>
      <template v-else>
        <p v-if="!offers.length">Aucune offre disponible. Ajoutez des prestations ou des produits dans votre catalogue.</p>
        <label v-for="offer in offers" :key="offer.id" class="flex items-center gap-2"><input v-model="block.props.codes" type="checkbox" :value="offer.id" :disabled="block.props.codes.length >= 12 && !block.props.codes.includes(offer.id)" />{{ offer.name }}</label>
        <div v-for="code in block.props.codes.filter(code => !offers.some(offer => offer.id === code))" :key="code">Une offre sélectionnée est indisponible. <button type="button" class="underline" @click="block.props.codes = block.props.codes.filter(item => item !== code)">Retirer cette offre</button></div>
      </template>
    </template>
    <label class="block">Nombre maximum d’offres <input v-model.number="block.props.limit" type="number" min="1" max="12" required class="input" /></label>
    <p class="text-sm text-slate-600">Les noms, images et tarifs sont actualisés depuis votre catalogue. Les offres retirées sont masquées automatiquement.</p>
  </div>
</template>
