<script setup>
import { ref, watch, onMounted, onUnmounted } from 'vue'
import { useRoute } from 'vue-router'
import { getSitePreview } from '@/api/adminApi'
import SitePageContent from '@/components/site/SitePageContent.vue'
import AppNavbar from '@/components/AppNavbar.vue'
import AppFooter from '@/components/AppFooter.vue'
const route = useRoute()
const page = ref(null), loading = ref(true), error = ref('')
let sequence = 0
async function load() {
  const current = ++sequence
  loading.value = true; error.value = ''; page.value = null
  try { const result = await getSitePreview(route.params.id, typeof route.query.menus === 'string' ? route.query.menus : ''); if (current === sequence) page.value = result }
  catch (e) { if (current === sequence) error.value = e.status === 422 ? e.message : 'Impossible d’afficher cet aperçu. Vérifiez votre accès et réessayez.' }
  finally { if (current === sequence) loading.value = false }
}
watch(() => route.fullPath, load, { immediate: true })
let robots
onMounted(() => { robots = document.createElement('meta'); robots.name = 'robots'; robots.content = 'noindex, nofollow, noarchive'; document.head.append(robots) })
onUnmounted(() => { ++sequence; robots?.remove() })
</script>
<template>
  <div class="min-h-screen bg-slate-50">
    <div class="flex flex-wrap items-center justify-between gap-3 bg-amber-50 p-4 text-amber-950"><p>Aperçu privé du brouillon enregistré · Apparence publiée{{ route.query.menus ? ' et menus sélectionnés en brouillon' : ' et navigation publiée' }}</p><RouterLink class="underline" :to="{ name: 'admin-site-pages' }">Retour aux pages</RouterLink></div>
    <AppNavbar :navigation="page?.navigation" />
    <p v-if="loading" role="status" class="section py-12">Chargement de l’aperçu…</p>
    <div v-else-if="error" class="section py-12"><p role="alert">{{ error }}</p><button class="btn-outline mt-4" @click="load">Réessayer</button></div>
    <SitePageContent v-else-if="page" :page="page" editor />
    <AppFooter :navigation="page?.navigation" />
  </div>
</template>
