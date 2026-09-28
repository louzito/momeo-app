<script setup>
import { onMounted, ref, useId } from 'vue'
import { getSiteMedia, uploadSiteMedia, updateSiteMedia, deleteSiteMedia } from '@/api/adminApi'
import { displayImageUrl } from '@/api/config'

defineProps({ selectable: Boolean })
const emit = defineEmits(['select'])
const prefix = useId()
const images = ref([])
const loading = ref(true)
const busy = ref(false)
const error = ref('')
const notice = ref('')
const fileInput = ref(null)
const alt = ref('')
const message = e => [409, 422, 401, 413, 503].includes(e?.status) ? (e.status === 413 ? 'Image trop volumineuse. Limite : 5 Mio.' : e.message) : 'Impossible de charger ou modifier les images. Réessayez.'
async function load() {
  loading.value = true; error.value = ''
  try { images.value = (await getSiteMedia()).member }
  catch (e) { error.value = message(e) }
  finally { loading.value = false }
}
onMounted(load)
async function run(operation) {
  busy.value = true; error.value = ''; notice.value = ''
  try { await operation() }
  catch (e) { error.value = message(e) }
  finally { busy.value = false }
}
async function upload() {
  const file = fileInput.value?.files?.[0]
  if (!file) { error.value = 'Choisissez une image à importer.'; return }
  if (file.size > 5242880) { error.value = 'Image trop volumineuse. Limite : 5 Mio.'; return }
  await run(async () => {
    const image = await uploadSiteMedia(file, alt.value)
    images.value.unshift(image)
    fileInput.value.value = ''; alt.value = ''
    notice.value = 'Image importée. Vous pouvez la réutiliser dans vos pages.'
  })
}
async function save(image) {
  await run(async () => { Object.assign(image, await updateSiteMedia(image.id, image.alt)); notice.value = 'Description enregistrée. Les textes déjà choisis dans les pages sont conservés.' })
}
async function remove(image) {
  if (!window.confirm('Supprimer définitivement cette image ?')) return
  await run(async () => { await deleteSiteMedia(image.id); images.value = images.value.filter(item => item.id !== image.id); notice.value = 'Image supprimée.' })
}
</script>

<template>
  <section :aria-busy="loading || busy" aria-label="Bibliothèque d’images" class="space-y-5">
    <p v-if="error" role="alert" class="rounded-xl bg-rose-50 p-3 text-rose-700">{{ error }}</p>
    <p v-if="notice" role="status" class="text-emerald-700">{{ notice }}</p>
    <form class="card space-y-3 p-4" @submit.prevent="upload">
      <div><label :for="`${prefix}-file`" class="label">Importer une image</label><input :id="`${prefix}-file`" ref="fileInput" type="file" accept="image/jpeg,image/png,image/webp" :disabled="busy" class="block w-full" :aria-describedby="`${prefix}-help`" /></div>
      <p :id="`${prefix}-help`" class="text-sm text-slate-500">JPEG, PNG ou WebP, 5 Mio maximum. Jusqu’à 6 000 pixels par côté et 20 millions de pixels.</p>
      <div><label :for="`${prefix}-alt`" class="label">Texte alternatif</label><input :id="`${prefix}-alt`" v-model="alt" class="input" maxlength="300" :disabled="busy" /><p class="text-sm text-slate-500">Décrivez l’image pour les personnes qui ne la voient pas. Laissez vide si elle est décorative.</p></div>
      <button class="btn-primary" :disabled="busy">{{ busy ? 'Veuillez patienter…' : 'Importer' }}</button>
    </form>
    <p v-if="loading" role="status">Chargement des images…</p>
    <button v-else-if="error" type="button" class="btn-outline" :disabled="busy" @click="load">Recharger les images</button>
    <p v-if="!loading && !error && !images.length" class="text-slate-500">Aucune image pour le moment. Importez votre première image.</p>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      <article v-for="(image, index) in images" :key="image.id" class="card min-w-0 space-y-3 p-4">
        <img :src="displayImageUrl(image.thumbnail)" :alt="image.alt" :width="image.width" :height="image.height" class="aspect-video w-full rounded-lg bg-slate-50 object-contain" loading="lazy" />
        <p class="text-sm text-slate-500">Image {{ index + 1 }} · {{ image.width }} × {{ image.height }}</p>
        <form class="space-y-2" @submit.prevent="save(image)">
          <label :for="`${prefix}-${image.id}`" class="label">Texte alternatif</label><input :id="`${prefix}-${image.id}`" v-model="image.alt" class="input" maxlength="300" :disabled="busy" />
          <button class="btn-outline" :disabled="busy">Enregistrer le texte</button>
        </form>
        <p v-if="image.used" class="text-sm text-slate-500">Utilisée dans une page : suppression protégée.</p>
        <div class="flex flex-wrap gap-2">
          <button v-if="selectable" type="button" class="btn-primary" :disabled="busy" :aria-label="`Choisir l’image ${index + 1}`" @click="emit('select', image)">Choisir</button>
          <button type="button" class="btn-ghost text-rose-700" :disabled="busy || image.used" :aria-label="`Supprimer l’image ${index + 1}`" @click="remove(image)">Supprimer</button>
        </div>
      </article>
    </div>
  </section>
</template>
