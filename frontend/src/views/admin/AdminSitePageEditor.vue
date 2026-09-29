<script setup>
import { computed, onMounted, onBeforeUnmount, ref } from 'vue'
import { onBeforeRouteLeave, useRoute } from 'vue-router'
import { siteThemeStyle } from '@/utils/siteTheme'
import { getShopConfig, getSitePage, getSitePages, getSiteMedia, updateSitePage } from '@/api/adminApi'
import SiteMediaPicker from '@/components/site/SiteMediaPicker.vue'
import SiteSections from '@/components/site/SiteSections.vue'
import SiteSectionFields from '@/components/site/SiteSectionFields.vue'
const route = useRoute()
const appearance = ref({})
const page = ref(null), draft = ref(null), saved = ref(''), pages = ref([]), media = ref({})
const loading = ref(true), saving = ref(false), error = ref(''), notice = ref(''), conflict = ref(false)
const selected = ref(null), kind = ref('banner'), mobile = ref(false), dragged = ref(null)
const labels = { banner: 'Bannière', text: 'Texte riche', imageText: 'Image et texte', gallery: 'Galerie', faq: 'Questions fréquentes', practical: 'Informations pratiques', catalog: 'Catalogue', giftCard: 'Carte cadeau' }
const dirty = computed(() => draft.value && JSON.stringify(draft.value) !== saved.value)
const active = computed(() => draft.value?.document.blocks.find(b => b.id === selected.value))
const blank = () => ({ type: 'doc', content: [{ type: 'paragraph' }] })
function add() {
  const value = { catalog: { title: 'Notre sélection', mode: 'selection', category: 'prestations', codes: [], limit: 3, buttonLabel: 'Voir la boutique' }, giftCard: { title: 'Offrir une carte cadeau', text: '', image: null, buttonLabel: 'Choisir le montant' }, banner: { mediaId: null, alt: '', title: 'Bienvenue', text: '', button: null }, text: { content: blank() }, imageText: { image: null, title: '', content: blank(), position: 'left' }, gallery: { images: [] }, faq: { title: 'Questions fréquentes', items: [] }, practical: { title: 'Informations pratiques', address: '', phone: '', email: '', hours: '', link: null } }[kind.value]
  const block = { id: crypto.randomUUID(), type: kind.value, props: value, hidden: false, variant: 'light', align: 'left' }
  draft.value.document.blocks.push(block); selected.value = block.id
}
async function load() {
  if (dirty.value && !window.confirm('Abandonner vos modifications et recharger la version enregistrée ?')) return
  loading.value = true; error.value = ''
  try {
    const [value, list, images, config] = await Promise.all([getSitePage(route.params.id), getSitePages(), getSiteMedia(), getShopConfig()])
    appearance.value = config
    page.value = value; draft.value = structuredClone(value.draft); saved.value = JSON.stringify(draft.value)
    pages.value = list.member; media.value = Object.fromEntries(images.member.map(i => [i.id, i])); selected.value = draft.value.document.blocks[0]?.id
    conflict.value = false
  } catch (e) { error.value = [404, 403].includes(e.status) ? 'Cette page n’est pas accessible.' : 'Impossible de charger la page et ses images.' }
  finally { loading.value = false }
}
async function save() {
  if (saving.value || conflict.value) return
  saving.value = true; error.value = ''; notice.value = ''
  try {
    const value = await updateSitePage(page.value.id, { ...draft.value, revision: page.value.revision })
    page.value = value; saved.value = JSON.stringify(draft.value); notice.value = 'Brouillon enregistré. La version publique reste inchangée.'
  } catch (e) {
    conflict.value = e.status === 409
    error.value = conflict.value ? 'Cette page a été modifiée dans une autre fenêtre. Vos modifications sont conservées ici. Copiez votre texte avant de recharger la page.' : e.status === 422 ? e.message : 'L’enregistrement a échoué. Vos modifications sont conservées ; réessayez.'
  } finally { saving.value = false }
}
function move(index, target) {
  if (target < 0 || target >= draft.value.document.blocks.length || saving.value) return
  const [block] = draft.value.document.blocks.splice(index, 1); draft.value.document.blocks.splice(target, 0, block)
}
function duplicate(block) { const copy = JSON.parse(JSON.stringify(block)); copy.id = crypto.randomUUID(); draft.value.document.blocks.push(copy); selected.value = copy.id }
function remove(index) { draft.value.document.blocks.splice(index, 1); selected.value = draft.value.document.blocks[Math.max(0, index - 1)]?.id }
const links = computed(() => {
  const routes = { home: '', shop: 'shop', products: 'products', services: 'shop?categorie=prestations', store: 'shop?categorie=produits', booking: 'shop?categorie=prestations', account: 'account', 'gift-card': 'gift-card', terms: 'legal/terms', mentions: 'legal/mentions' }
  const result = {}
  for (const block of draft.value?.document.blocks || []) {
    const link = block.props.button?.link || block.props.link
    if (!link) continue
    const url = link.type === 'page' ? pages.value.find(p => p.id === link.target)?.draft.slug : link.type === 'route' ? routes[link.target] : link.target
    if (url !== undefined) result[JSON.stringify(link)] = { url, external: link.type === 'external' }
  }
  return result
})
function beforeUnload(event) { if (dirty.value) { event.preventDefault(); event.returnValue = '' } }
onMounted(() => { load(); window.addEventListener('beforeunload', beforeUnload) })
onBeforeUnmount(() => window.removeEventListener('beforeunload', beforeUnload))
onBeforeRouteLeave(() => !dirty.value || window.confirm('Quitter sans enregistrer vos modifications ?'))
</script>
<template>
  <div class="min-w-0 space-y-5" :aria-busy="loading || saving">
    <RouterLink :to="{ name: 'admin-site-pages' }" class="underline">Retour aux pages</RouterLink>
    <h1 class="text-2xl font-bold">Composer la page</h1>
    <p v-if="loading" role="status">Chargement de la page…</p>
    <p v-if="error" role="alert" class="rounded bg-rose-50 p-3 text-rose-700">{{ error }}</p>
    <button v-if="!loading && (!draft || conflict)" class="btn-outline" @click="load">Recharger la page</button>
    <template v-if="draft && !loading">
      <div class="flex flex-wrap items-center gap-3"><h2 class="text-xl font-semibold">{{ draft.title }}</h2><button class="btn-primary" :disabled="saving || conflict || page.archived" @click="save">{{ saving ? 'Enregistrement…' : 'Enregistrer le brouillon' }}</button><span role="status">{{ notice && !dirty ? notice : dirty ? 'Modifications non enregistrées' : 'Brouillon enregistré' }}</span></div>
      <p class="text-sm text-slate-600">Préparez vos sections ici. Elles ne seront visibles du public qu’après publication.</p>
      <fieldset class="space-y-3 rounded-xl border p-4" :disabled="saving || page.archived">
        <legend class="font-semibold">Référencement et partage</legend>
        <p class="text-sm">Sans personnalisation, le titre, le texte et la première image visible de la page seront utilisés. Ces informations suivent la publication de la page.</p>
        <label class="block">Titre dans les moteurs de recherche<input v-model="draft.seo.title" maxlength="160" class="input" :placeholder="draft.title" /></label>
        <label class="block">Description<textarea v-model="draft.seo.description" maxlength="320" class="input" rows="3" /></label>
        <p>Image de partage : {{ media[draft.seo.imageId]?.name || (draft.seo.imageId ? 'Image choisie' : 'Automatique') }}</p>
        <SiteMediaPicker @select="draft.seo.imageId = $event.id; media[$event.id] = $event" />
        <button v-if="draft.seo.imageId" type="button" class="btn-outline" @click="delete draft.seo.imageId">Utiliser l’image automatique</button>
      </fieldset>
      <div class="grid min-w-0 gap-5 xl:grid-cols-[280px_minmax(0,1fr)]">
        <fieldset class="min-w-0 space-y-3" :disabled="saving || page.archived">
          <legend class="mb-3 font-semibold">Sections</legend>
          <p v-if="!draft.document.blocks.length">Votre page est vide. Ajoutez une première section.</p>
          <ol class="space-y-3">
            <li v-for="(block, index) in draft.document.blocks" :key="block.id" class="rounded-xl border p-3" :class="selected === block.id ? 'border-brand-500 bg-brand-50' : ''" draggable="true" @dragstart="dragged = index" @dragend="dragged = null" @dragover.prevent @drop.prevent="dragged !== null && move(dragged, index)">
              <button class="w-full text-left font-semibold" @click="selected = block.id">{{ index + 1 }}. {{ labels[block.type] || block.type }}{{ block.hidden ? ' (masquée)' : '' }}</button>
              <div class="mt-2 flex flex-wrap gap-2 text-sm"><button :disabled="index === 0" :aria-label="`Monter la section ${index + 1}`" @click="move(index, index - 1)">↑ Monter</button><button :disabled="index === draft.document.blocks.length - 1" :aria-label="`Descendre la section ${index + 1}`" @click="move(index, index + 1)">↓ Descendre</button><button :disabled="draft.document.blocks.length >= 100" @click="duplicate(block)">Dupliquer</button><button @click="block.hidden = !block.hidden">{{ block.hidden ? 'Afficher' : 'Masquer' }}</button><button class="text-rose-700" @click="remove(index)">Supprimer</button></div>
            </li>
          </ol>
          <label class="block">Nouvelle section <select v-model="kind" class="input"><option v-for="(label, type) in labels" :key="type" :value="type">{{ label }}</option></select></label><button class="btn-outline" :disabled="draft.document.blocks.length >= 100" @click="add">Ajouter une section</button>
        </fieldset>
        <SiteSectionFields v-if="active" :key="active.id" :block="active" :pages="pages" :disabled="saving || page.archived" @media="media[$event.id] = $event" />
      </div>
      <section class="min-w-0 rounded-xl border bg-slate-100 p-3" aria-label="Aperçu de la page">
        <div class="mb-4 flex flex-wrap items-center gap-3"><h2 class="font-semibold">Aperçu</h2><button class="btn-outline" :aria-pressed="!mobile" @click="mobile = false">Ordinateur</button><button class="btn-outline" :aria-pressed="mobile" @click="mobile = true">Mobile</button></div>
        <div class="mx-auto max-w-full" :style="{ ...siteThemeStyle(appearance), width: mobile ? '360px' : '100%', containerType: 'inline-size', containerName: 'site-page' }"><SiteSections :blocks="draft.document.blocks" editor :media="media" :links="links" /></div>
      </section>
    </template>
  </div>
</template>
