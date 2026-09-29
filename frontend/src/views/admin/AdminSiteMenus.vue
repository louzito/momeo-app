<script setup>
import { computed, onMounted, ref } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import { getSitePages, getSiteMenu, saveSiteMenu, restoreSiteMenu } from '@/api/adminApi'
import SiteMenuListEditor from '@/components/site/SiteMenuListEditor.vue'
import { invalidateSite } from '@/api/sitePublication'
const pages = ref([]), menus = ref({}), location = ref('main')
const loading = ref(true), saving = ref(false), error = ref(''), notice = ref('')
const dirty = ref({ main: false, footer: false })
const current = computed(() => menus.value[location.value])
let key = 0
function decorate(items) { return items.map(item => ({ ...item, _key: ++key, hidden: item.hidden ?? false, children: decorate(item.children || []) })) }
function clean(items, child = false) { return items.map(({ label, link, hidden, children }) => ({ label, link, hidden, ...(!child ? { children: clean(children, true) } : {}) })) }
function changed() { dirty.value[location.value] = true; notice.value = '' }
const warnings = computed(() => {
  const result = new Set()
  function check(link) {
    if (link?.type !== 'page') return
    const page = pages.value.find(p => p.id === link.target)
    if (!page || page.archived) result.add('Une page liée est indisponible. Remplacez ou supprimez ce lien.')
    else if (!page.published) result.add(`« ${page.draft.title} » devra être publiée avec ce menu. Sinon, sa publication sera refusée.`)
  }
  function walk(items) { items.forEach(item => { if (!item.hidden) { check(item.link); walk(item.children) } }) }
  if (current.value) { walk(current.value.items); check(current.value.primaryLink) }
  return [...result]
})
async function load() {
  loading.value = true; error.value = ''
  try {
    const [p, main, footer] = await Promise.all([getSitePages(), getSiteMenu('main'), getSiteMenu('footer')])
    pages.value = p.member
    menus.value = { main: { ...main, items: decorate(main.items) }, footer: { ...footer, items: decorate(footer.items) } }
  } catch { error.value = 'Impossible de charger les menus. Réessayez.' }
  finally { loading.value = false }
}
async function save() {
  saving.value = true; error.value = ''; notice.value = ''
  const selected = location.value
  try {
    const saved = await saveSiteMenu(selected, { items: clean(current.value.items) })
    menus.value[selected].revision = saved.revision
    dirty.value[selected] = false
    notice.value = 'Brouillon enregistré. Les visiteurs voient toujours la dernière version publiée.'
  } catch (e) { error.value = [409, 422].includes(e.status) ? e.message : 'Impossible d’enregistrer. Vos modifications sont conservées.' }
  finally { saving.value = false }
}
async function restore() {
  if (!window.confirm('Remplacer ce brouillon par le dernier menu publié ?')) return
  saving.value = true; error.value = ''
  const selected = location.value
  try {
    await restoreSiteMenu(selected, current.value.revision)
    const value = await getSiteMenu(selected)
    menus.value[selected] = { ...value, items: decorate(value.items) }
    dirty.value[selected] = false; invalidateSite()
    notice.value = 'Dernier menu publié restauré dans le brouillon.'
  } catch (e) { error.value = [409, 422].includes(e.status) ? e.message : 'Impossible de restaurer le menu.' }
  finally { saving.value = false }
}
onBeforeRouteLeave(() => !(dirty.value.main || dirty.value.footer) || window.confirm('Quitter sans enregistrer les modifications des menus ?'))
onMounted(load)
</script>
<template>
  <div class="mx-auto max-w-5xl" :aria-busy="loading || saving">
    <h1 class="font-display text-2xl font-bold">Menus du site</h1>
    <p class="mt-2 text-slate-600">Organisez vos liens. Les modifications restent en brouillon jusqu’à la publication du site.</p>
    <RouterLink class="btn-outline mt-3" :to="{ name: 'admin-site-pages' }">Publier les pages et menus</RouterLink>
    <button v-if="current?.published" type="button" class="btn-ghost mt-3" :disabled="saving" @click="restore">Restaurer le menu publié</button>
    <p v-if="error" role="alert" class="my-4 text-rose-700">{{ error }}</p>
    <p v-if="notice" role="status" class="my-4 text-emerald-700">{{ notice }}</p>
    <p v-if="loading" role="status" class="mt-6">Chargement des menus…</p>
    <button v-else-if="!current" class="btn-outline mt-4" @click="load">Réessayer</button>
    <form v-else class="mt-6 space-y-5" @submit.prevent="save">
      <fieldset :disabled="saving" class="space-y-5">
        <legend class="sr-only">Éditer les menus</legend>
        <label class="block">Menu à modifier
          <select v-model="location" class="input mt-1" @change="notice = ''; error = ''"><option value="main">Menu principal{{ dirty.main ? ' — modifié' : '' }}</option><option value="footer">Pied de page{{ dirty.footer ? ' — modifié' : '' }}</option></select>
        </label>
        <div v-if="warnings.length" role="status" class="rounded-xl bg-amber-50 p-4 text-amber-900"><p v-for="warning in warnings" :key="warning">{{ warning }}</p></div>
        <p v-if="!current.items.length">Ce menu est vide. Ajoutez votre premier lien.</p>
        <SiteMenuListEditor :key="location" :items="current.items" :pages="pages" :disabled="saving" @change="changed" />
        <RouterLink v-if="location === 'main'" class="block underline" :to="{ name: 'admin-site-appearance' }">Personnaliser le bouton principal dans Apparence</RouterLink>
        <button class="btn-primary" :disabled="!dirty[location]">{{ saving ? 'Enregistrement…' : 'Enregistrer le brouillon' }}</button>
      </fieldset>
    </form>
  </div>
</template>
