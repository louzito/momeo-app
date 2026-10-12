<script setup>
import { computed, onMounted, onBeforeUnmount, ref, watch } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import { getShopConfig, getSiteAppearance, saveSiteAppearance, getSitePages, getSiteMedia, uploadSiteLogo } from '@/api/adminApi'
import { invalidateSite } from '@/api/sitePublication'
import { displayImageUrl } from '@/api/config'
import { siteThemeStyle, normalizeTypography, brandingColor } from '@/utils/siteTheme'
import { BRAND_PALETTES, ACCENT_PALETTES } from '@/utils/palettes'
import { getGoogleFonts, loadThemeFonts } from '@/utils/googleFonts'
import SiteLinkEditor from '@/components/site/SiteLinkEditor.vue'
import SiteSections from '@/components/site/SiteSections.vue'

const cfg = ref(null), state = ref(null), pages = ref([]), media = ref({}), saved = ref('')
const loading = ref(true), saving = ref(false), error = ref(''), notice = ref(''), mobile = ref(false), selected = ref('')
const logo = ref(null), logoPreview = ref('')
const fields = [{ key: 'header', label: 'Fond du haut de page' }, { key: 'textHeader', label: 'Texte du haut de page' }, { key: 'footer', label: 'Fond du pied de page' }, { key: 'textFooter', label: 'Texte du pied de page' }]
const fontFamilies = ref(['Inter', 'Plus Jakarta Sans']), fontError = ref(''), fontsLoading = ref(false)
const fontOptions = computed(() => [...new Set([...fontFamilies.value, cfg.value?.typography?.heading, cfg.value?.typography?.body].filter(Boolean))].sort((a, b) => a.localeCompare(b)))
async function loadFonts() {
  fontsLoading.value = true; fontError.value = ''
  try { fontFamilies.value = await getGoogleFonts() }
  catch { fontError.value = 'Le catalogue Google Fonts est indisponible. Les polices actuelles restent utilisables.' }
  finally { fontsLoading.value = false }
}
watch(() => cfg.value?.typography, value => loadThemeFonts(value), { deep: true })
const payload = () => ({ revision: state.value.revision, colors: cfg.value.colors, branding: cfg.value.branding, typography: cfg.value.typography, logo: cfg.value.assets?.logo || null, primaryLink: state.value.primaryLink })
const dirty = computed(() => cfg.value && state.value && (logo.value || JSON.stringify(payload()) !== saved.value))
const previewBlocks = computed(() => pages.value.find(p => p.id === selected.value)?.draft.document.blocks || [])
const links = computed(() => {
  const result = {}
  for (const block of previewBlocks.value) {
    const link = block.props.button?.link || block.props.link
    if (link) result[JSON.stringify(link)] = { url: '#', external: true }
  }
  return result
})
async function load() {
  if (dirty.value && !window.confirm('Abandonner vos modifications et recharger ?')) return
  loading.value = true; error.value = ''
  try {
    // Ensures the existing configuration taxon is present before reading its revision.
    const config = await getShopConfig()
    const [value, list, images] = await Promise.all([getSiteAppearance(), getSitePages(), getSiteMedia()])
    config.typography = normalizeTypography(config.typography)
    config.branding = { brandPalette: brandingColor(config.branding?.brandPalette, BRAND_PALETTES, 'sky'), accent: brandingColor(config.branding?.accent, ACCENT_PALETTES, 'orange') }
    cfg.value = config; state.value = value; pages.value = list.member.filter(p => !p.archived)
    media.value = Object.fromEntries(images.member.map(i => [i.id, i]))
    selected.value = pages.value[0]?.id || ''; saved.value = JSON.stringify(payload())
    logo.value = null; clearPreview()
  } catch { error.value = 'Impossible de charger l’apparence. Réessayez.' }
  finally { loading.value = false }
}
function clearPreview() { if (logoPreview.value) URL.revokeObjectURL(logoPreview.value); logoPreview.value = '' }
function pickLogo(event) {
  const file = event.target.files?.[0]
  if (!file) return
  if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 5 * 1024 * 1024) { error.value = 'Choisissez une image JPG, PNG ou WebP de 5 Mio maximum.'; return }
  clearPreview(); logo.value = file; logoPreview.value = URL.createObjectURL(file); error.value = ''
}
async function save() {
  saving.value = true; error.value = ''; notice.value = ''
  try {
    if (logo.value) {
      const uploaded = await uploadSiteLogo(logo.value)
      cfg.value.assets = { ...cfg.value.assets, logo: uploaded.path }
      cfg.value.logoUrl = displayImageUrl(uploaded.path); logo.value = null
    }
    state.value = await saveSiteAppearance(payload())
    invalidateSite()
    saved.value = JSON.stringify(payload()); notice.value = 'Apparence enregistrée. Les modifications sont visibles sur le site.'
  } catch (e) { error.value = e.message || 'Impossible d’enregistrer. Vos modifications sont conservées.' }
  finally { saving.value = false }
}
function beforeUnload(event) { if (dirty.value) { event.preventDefault(); event.returnValue = '' } }
onMounted(() => { load(); loadFonts(); window.addEventListener('beforeunload', beforeUnload) })
onBeforeUnmount(() => { clearPreview(); window.removeEventListener('beforeunload', beforeUnload) })
onBeforeRouteLeave(() => !dirty.value || window.confirm('Quitter sans enregistrer vos modifications ?'))
</script>
<template>
  <div class="mx-auto max-w-5xl space-y-5" :aria-busy="loading || saving">
    <h1 class="font-display text-2xl font-bold">Apparence</h1>
    <p>Un même style pour vos pages, votre catalogue et votre commande. Les modifications sont visibles sur le site dès l’enregistrement.</p>
    <p v-if="loading" role="status">Chargement de l’apparence…</p>
    <p v-if="error" role="alert" class="rounded bg-rose-50 p-3 text-rose-700">{{ error }}</p>
    <button v-if="error && !loading" class="btn-outline" @click="load">Recharger</button>
    <p v-if="notice" role="status">{{ notice }}</p>
    <template v-if="cfg && state && !loading">
      <form class="space-y-5" @submit.prevent="save">
        <fieldset class="space-y-5" :disabled="saving">
          <legend class="sr-only">Personnaliser l’apparence</legend>
          <section class="card space-y-3 p-5">
            <h2 class="text-lg font-semibold">Logo</h2>
            <img v-if="logoPreview || cfg.logoUrl" :src="logoPreview || cfg.logoUrl" alt="Logo actuel" class="h-16 max-w-full object-contain" />
            <p v-else>Aucun logo. Le nom de votre établissement sera affiché.</p>
            <label class="block">Choisir un logo <input type="file" accept="image/jpeg,image/png,image/webp" class="mt-2 block max-w-full" @change="pickLogo" /></label>
            <p class="text-sm text-slate-500">JPG, PNG ou WebP, 5 Mio maximum.</p>
          </section>
          <section class="card space-y-4 p-5">
            <h2 class="text-lg font-semibold">Couleurs et caractères</h2>
            <div class="grid gap-4 sm:grid-cols-2">
              <label v-for="field in fields" :key="field.key" class="block">{{ field.label }}<input v-model="cfg.colors[field.key]" type="color" class="mt-1 block h-11 w-full rounded border" /></label>
              <label>Couleur principale<input v-model="cfg.branding.brandPalette" type="color" class="mt-1 block h-11 w-full rounded border" /></label>
              <label>Couleur complémentaire<input v-model="cfg.branding.accent" type="color" class="mt-1 block h-11 w-full rounded border" /></label>
              <div v-for="field in [{ key: 'heading', label: 'Police des titres' }, { key: 'body', label: 'Police du texte' }]" :key="field.key" class="min-w-0">
                <label>{{ field.label }}<select v-model="cfg.typography[field.key]" class="input"><option v-for="family in fontOptions" :key="family" :value="family">{{ family }}</option></select></label>
                <p class="mt-3 break-words" :style="{ fontFamily: siteThemeStyle(cfg)[field.key === 'heading' ? '--site-font-heading' : '--site-font-body'], fontSize: field.key === 'heading' ? '1.5rem' : '1rem' }">Découvrez notre univers et prenez rendez-vous.</p>
              </div>
            </div>
            <p v-if="fontsLoading" role="status">Chargement des Google Fonts…</p>
            <p v-if="fontError" role="status">{{ fontError }} <button type="button" class="underline" @click="loadFonts">Réessayer</button></p>
          </section>
          <section class="card space-y-3 p-5">
            <h2 class="text-lg font-semibold">Bouton principal « Prendre rendez-vous »</h2>
            <label class="flex gap-2"><input type="checkbox" :checked="!!state.primaryLink" @change="state.primaryLink = $event.target.checked ? { type: 'route', target: 'booking' } : null" />Afficher le bouton</label>
            <SiteLinkEditor v-if="state.primaryLink" v-model="state.primaryLink" :pages="pages" />
            <p class="text-sm text-slate-500">Ce bouton utilise le menu principal. La réservation commence par le choix d’une prestation.</p>
          </section>
          <button class="btn-primary">{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</button>
        </fieldset>
      </form>
      <section class="rounded-xl border bg-slate-100 p-3" aria-label="Aperçu des modifications">
        <div class="mb-4 flex flex-wrap items-center gap-3"><h2 class="font-semibold">Aperçu des modifications</h2><button class="btn-outline" :aria-pressed="!mobile" @click="mobile = false">Ordinateur</button><button class="btn-outline" :aria-pressed="mobile" @click="mobile = true">Mobile</button><label v-if="pages.length">Page<select v-model="selected" class="input"><option v-for="page in pages" :key="page.id" :value="page.id">{{ page.draft.title }}</option></select></label></div>
        <div class="mx-auto max-w-full overflow-hidden rounded-xl bg-white" :style="{ ...siteThemeStyle(cfg), width: mobile ? '360px' : '100%', containerType: 'inline-size', containerName: 'site-page' }" @click.capture="($event.target.closest('a')) && $event.preventDefault()">
          <header class="flex flex-wrap items-center gap-3 p-4" style="background:var(--sb-header-bg);color:var(--sb-header-text)"><img v-if="logoPreview || cfg.logoUrl" :src="logoPreview || cfg.logoUrl" alt="" class="h-10 max-w-24 object-contain" /><strong>{{ cfg.name }}</strong><button v-if="state.primaryLink" type="button" class="btn-primary">Prendre rendez-vous</button></header>
          <main class="p-4"><SiteSections v-if="previewBlocks.length" :blocks="previewBlocks" :media="media" :links="links" editor /><div v-else class="space-y-3"><h2 class="font-display text-2xl font-bold">{{ cfg.name }}</h2><p>{{ cfg.home?.subtitle }}</p><p class="text-slate-500">Créez une page pour visualiser ses sections ici.</p></div><div class="mt-4 rounded border p-4"><h2 class="font-display text-xl">Votre commande</h2><p class="my-2 text-sm">Vos pages et votre commande partagent ces couleurs et caractères.</p><button type="button" class="btn-primary">Continuer</button></div></main>
          <footer class="p-4 text-sm" style="background:var(--sb-footer-bg);color:var(--sb-footer-text)">© {{ cfg.name }}</footer>
        </div>
      </section>
      <p class="flex flex-wrap gap-4 text-sm"><RouterLink :to="{ name: 'admin-settings', query: { section: 'terms' } }" class="underline">Conditions générales</RouterLink><RouterLink :to="{ name: 'admin-settings', query: { section: 'mentions' } }" class="underline">Mentions légales</RouterLink><RouterLink :to="{ name: 'admin-settings', query: { section: 'general' } }" class="underline">Réglages de l’établissement</RouterLink></p>
    </template>
  </div>
</template>
