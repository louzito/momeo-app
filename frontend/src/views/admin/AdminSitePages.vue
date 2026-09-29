<script setup>
import { useRouter } from 'vue-router'
import SitePageImagesEditor from '@/components/site/SitePageImagesEditor.vue'
import { nextTick, onMounted, ref } from 'vue'
import { getSitePages, createSitePage, updateSitePage, duplicateSitePage, archiveSitePage, restoreSitePage } from '@/api/adminApi'

const router = useRouter()
const templates = { blank: 'Page vide', home: 'Accueil', presentation: 'Présentation de l’établissement', contact: 'Contact' }
const pages = ref([])
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const notice = ref('')
const editing = ref(null)
const titleInput = ref(null)
const newButton = ref(null)
const message = (error, fallback) => [409, 422].includes(error?.status) ? error.message : fallback
const roles = { home: 'Accueil', terms: 'Conditions générales', mentions: 'Mentions légales' }

async function load() {
  loading.value = true
  error.value = ''
  try { pages.value = (await getSitePages()).member }
  catch (e) { error.value = message(e, 'Impossible de charger les pages.') }
  finally { loading.value = false }
}
onMounted(load)
async function edit(page = null, duplicate = false, images = false) {
  error.value = ''; notice.value = ''
  editing.value = { page, duplicate, images, blocks: page ? JSON.parse(JSON.stringify(page.draft.document.blocks)) : [], title: page ? page.draft.title + (duplicate ? ' (copie)' : '') : '', slug: page ? page.draft.slug + (duplicate ? '-copie' : '') : '', role: '', template: 'blank' }
  await nextTick(); titleInput.value?.focus()
}
function chooseTemplate() {
  const value = editing.value
  if (!value.title && value.template !== 'blank') value.title = templates[value.template]
  if (!value.slug) value.slug = { home: 'bienvenue', presentation: 'presentation', contact: 'contact', blank: '' }[value.template]
}
async function close() {
  editing.value = null
  await nextTick(); newButton.value?.focus()
}
async function save() {
  saving.value = true; error.value = ''; notice.value = ''
  const value = editing.value
  try {
    const fields = { title: value.title, slug: value.slug }
    if (value.duplicate) await duplicateSitePage(value.page.id, fields)
    else if (value.page) await updateSitePage(value.page.id, { ...value.page.draft, ...fields, revision: value.page.revision, document: { ...value.page.draft.document, blocks: value.blocks } })
    else {
      const created = await createSitePage({ ...fields, role: value.role || null, template: value.template })
      await router.push({ name: 'admin-site-page-editor', params: { id: created.id } })
      return
    }
    await close()
    await load()
    notice.value = 'Page enregistrée.'
  } catch (e) { error.value = message(e, 'Impossible d’enregistrer la page.') }
  finally { saving.value = false }
}
async function action(page, restore = false) {
  const question = restore ? `Remplacer le brouillon de « ${page.draft.title} » par sa dernière version publiée ?` : `Archiver « ${page.draft.title} » ? Elle ne sera plus accessible aux visiteurs.`
  if (!window.confirm(question)) return
  saving.value = true; error.value = ''; notice.value = ''
  try {
    if (restore) await restoreSitePage(page.id)
    else await archiveSitePage(page.id)
    await load()
    notice.value = restore ? 'Dernière version publiée restaurée dans le brouillon.' : 'Page archivée.'
  } catch (e) { error.value = message(e, 'Impossible de modifier la page.') }
  finally { saving.value = false }
}
</script>

<template>
  <div class="mx-auto max-w-5xl" :aria-busy="loading || saving">
    <div class="flex flex-wrap items-center justify-between gap-4">
      <div><h1 class="font-display text-2xl font-bold text-slate-900">Pages du site</h1><p class="mt-1 text-slate-500">Préparez vos pages. Votre site actuel reste en ligne.</p></div>
      <button ref="newButton" class="btn-primary" :disabled="loading || saving || !!editing" @click="edit()">Nouvelle page</button>
    </div>
    <p v-if="error" role="alert" class="mt-4 rounded-xl bg-rose-50 p-3 text-rose-700">{{ error }}</p>
    <p v-if="notice" role="status" class="mt-4 text-emerald-700">{{ notice }}</p>
    <p v-if="loading" role="status" class="mt-6">Chargement des pages…</p>
    <section v-else-if="editing" class="card mt-6 space-y-4 p-5">
      <form id="site-page-edit" class="space-y-4" @submit.prevent="save">
      <h2 class="text-lg font-semibold">{{ editing.duplicate ? 'Dupliquer la page' : editing.images ? 'Images de la page' : editing.page ? 'Renommer la page' : 'Créer une page' }}</h2>
      <div v-if="!editing.page"><label for="page-template" class="label">Modèle de départ</label><select id="page-template" v-model="editing.template" class="input" @change="chooseTemplate" :disabled="saving"><option v-for="(label, key) in templates" :key="key" :value="key">{{ label }}</option></select><p class="mt-1 text-sm text-slate-500">Vos coordonnées et images disponibles seront reprises. Chaque section reste personnalisable.</p></div>
      <div><label for="page-title" class="label">Titre</label><input id="page-title" ref="titleInput" v-model="editing.title" class="input" required maxlength="160" :disabled="saving" /></div>
      <div><label for="page-slug" class="label">Adresse de la page</label><input id="page-slug" v-model="editing.slug" class="input" required pattern="[a-z0-9]+(-[a-z0-9]+)*" maxlength="120" :disabled="saving || (!editing.duplicate && !!editing.page?.role)" aria-describedby="slug-help" /><p id="slug-help" class="mt-1 text-sm text-slate-500">Lettres minuscules, chiffres et tirets. L’adresse des pages protégées est conservée. Les anciennes adresses publiées restent accessibles.</p></div>
      <div v-if="!editing.page"><label for="page-role" class="label">Type de page</label><select id="page-role" v-model="editing.role" class="input" :disabled="saving"><option value="">Page libre</option><option v-for="(label, role) in roles" :key="role" :value="role" :disabled="pages.some(p => p.role === role)">{{ label }} (protégée)</option></select></div>
      </form>
      <SitePageImagesEditor v-if="editing.images" v-model="editing.blocks" :disabled="saving" />
      <div class="flex flex-wrap gap-3"><button type="submit" form="site-page-edit" class="btn-primary" :disabled="saving">{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</button><button type="button" class="btn-ghost" :disabled="saving" @click="close">Annuler</button></div>
    </section>
    <div v-else class="mt-6 space-y-4">
      <button v-if="error" class="btn-outline" @click="load">Réessayer</button>
      <p v-if="!error && !pages.length" class="text-slate-500">Aucune page pour le moment. Créez votre première page pour préparer votre site.</p>
      <article v-for="page in pages" :key="page.id" class="card p-5">
        <h2 class="break-words text-lg font-semibold">{{ page.draft.title }}</h2>
        <p class="break-all text-sm text-slate-500">/{{ page.draft.slug }} · {{ page.archived ? 'Archivée' : page.published ? 'Publiée' : 'Brouillon' }}<span v-if="page.role"> · {{ roles[page.role] }} protégée</span></p>
        <div class="mt-4 flex flex-wrap gap-2">
          <RouterLink v-if="!page.archived" class="btn-primary" :to="{ name: 'admin-site-page-editor', params: { id: page.id } }">Modifier les sections</RouterLink>
          <button v-if="!page.archived" class="btn-outline" :disabled="saving" @click="edit(page, false, true)">Images</button>
          <button v-if="!page.archived" class="btn-outline" :disabled="saving" :aria-label="`Renommer ${page.draft.title}`" @click="edit(page)">Renommer</button>
          <button class="btn-outline" :disabled="saving" :aria-label="`Dupliquer ${page.draft.title}`" @click="edit(page, true)">Dupliquer</button>
          <button v-if="page.published && !page.archived" class="btn-ghost" :disabled="saving" @click="action(page, true)">Restaurer la version publiée</button>
          <button v-if="!page.role && !page.archived" class="btn-ghost text-rose-700" :disabled="saving" :aria-label="`Archiver ${page.draft.title}`" @click="action(page)">Archiver</button>
        </div>
      </article>
    </div>
  </div>
</template>
