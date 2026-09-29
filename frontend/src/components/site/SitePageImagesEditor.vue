<script setup>
import { computed, onMounted, ref } from 'vue'
import { getSiteMedia } from '@/api/adminApi'
import SiteMediaPicker from './SiteMediaPicker.vue'
import SiteMediaImage from './SiteMediaImage.vue'
const props = defineProps({ modelValue: { type: Array, required: true }, disabled: Boolean })
const emit = defineEmits(['update:modelValue'])
const media = ref({})
const error = ref('')
const loading = ref(true)
const kind = ref('image')
const labels = { image: 'Image', banner: 'Bannière', gallery: 'Galerie' }
const blocks = computed(() => props.modelValue.filter(block => labels[block.type]))
async function load() {
  loading.value = true; error.value = ''
  try { media.value = Object.fromEntries((await getSiteMedia()).member.map(image => [image.id, image])) }
  catch { error.value = 'Impossible de charger les aperçus des images.' }
  finally { loading.value = false }
}
onMounted(load)
function change(block, transform) { emit('update:modelValue', props.modelValue.map(item => item.id === block.id ? transform(JSON.parse(JSON.stringify(item))) : item)) }
function reference(image) { media.value[image.id] = image; return { mediaId: image.id, alt: image.alt } }
function add(image) {
  const value = reference(image)
  emit('update:modelValue', [...props.modelValue, { id: crypto.randomUUID(), type: kind.value, props: kind.value === 'gallery' ? { images: [value] } : value }])
}
function choose(block, image, index) {
  const value = reference(image)
  change(block, item => { if (item.type === 'gallery') item.props.images[index] = value; else Object.assign(item.props, value); return item })
}
function text(block, index, alt) { change(block, item => { if (item.type === 'gallery') item.props.images[index].alt = alt; else item.props.alt = alt; return item }) }
function remove(block) { emit('update:modelValue', props.modelValue.filter(item => item.id !== block.id)) }
</script>
<template>
  <fieldset class="space-y-4" :disabled="disabled">
    <legend class="text-lg font-semibold">Images de la page</legend>
    <p class="text-sm text-slate-500">Vos modifications seront enregistrées dans le brouillon. Une image peut servir dans plusieurs sections.</p>
    <p v-if="loading" role="status">Chargement des aperçus…</p>
    <p v-if="error" role="alert">{{ error }} <button type="button" class="btn-ghost" @click="load">Réessayer</button></p>
    <p v-if="!blocks.length">Cette page ne contient pas encore d’image.</p>
    <article v-for="block in blocks" :key="block.id" class="rounded-xl border p-4">
      <div class="mb-3 flex flex-wrap justify-between gap-2"><h3 class="font-semibold">{{ labels[block.type] }}</h3><button type="button" class="btn-ghost text-rose-700" @click="remove(block)">Retirer la section</button></div>
      <div :class="block.type === 'gallery' ? 'grid gap-4 sm:grid-cols-2' : ''">
        <div v-for="(image, index) in (block.type === 'gallery' ? block.props.images : [block.props])" :key="index" class="space-y-2">
          <SiteMediaImage v-if="media[image.mediaId]" :media="media[image.mediaId]" :alt="image.alt" />
          <p v-else-if="!loading">Aperçu indisponible. Sélectionnez une image de votre bibliothèque.</p>
          <label :for="`${block.id}-${index}`" class="label">Texte alternatif dans cette page</label><input :id="`${block.id}-${index}`" class="input" :value="image.alt" maxlength="300" @input="text(block, index, $event.target.value)" />
          <SiteMediaPicker :disabled="disabled" @select="choose(block, $event, index)" />
          <button v-if="block.type === 'gallery' && block.props.images.length > 1" type="button" class="btn-ghost" @click="change(block, item => { item.props.images.splice(index, 1); return item })">Retirer cette image</button>
        </div>
      </div>
      <div v-if="block.type === 'gallery' && block.props.images.length < 20" class="mt-4"><p>Ajouter à la galerie</p><SiteMediaPicker :disabled="disabled" @select="choose(block, $event, block.props.images.length)" /></div>
    </article>
    <div v-if="modelValue.length < 100" class="space-y-2 rounded-xl bg-slate-50 p-4">
      <label for="image-section-kind" class="label">Nouvelle section</label><select id="image-section-kind" v-model="kind" class="input"><option value="image">Image</option><option value="banner">Bannière</option><option value="gallery">Galerie</option></select>
      <SiteMediaPicker :disabled="disabled" @select="add" />
    </div>
  </fieldset>
</template>
