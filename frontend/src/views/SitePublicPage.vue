<script setup>
import { ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { getPublishedSitePage } from '@/api/sitePublication'
import SitePageContent from '@/components/site/SitePageContent.vue'
import LegacyHome from './TenantHome.vue'
import LegacyLegal from './LegalPage.vue'
import NotFound from './errors/NotFound.vue'
const route = useRoute()
const router = useRouter()
const page = ref(null), loading = ref(true), error = ref('')
let request = 0
async function load() {
  const current = ++request
  loading.value = true; error.value = ''; page.value = null
  const role = route.name === 'tenant-home' ? 'home' : route.name === 'legal-page' ? route.params.page : null
  try {
    const value = await getPublishedSitePage(role ? 'roles' : 'pages', role || route.params.slug)
    if (current !== request) return
    page.value = value
    if (value) {
      const canonical = value.role === 'home' ? '/' : ['terms', 'mentions'].includes(value.role) ? `/legal/${value.role}` : `/${value.slug}`
      if (route.path !== canonical) { await router.replace(canonical); return }
      document.title = `${value.seo.title || value.title} · TodaTempo`
    }
  } catch (e) { if (current === request) error.value = e.message }
  finally { if (current === request) loading.value = false }
}
watch(() => route.path, load, { immediate: true })
</script>
<template>
  <p v-if="loading" role="status" class="section py-12">Chargement de la page…</p>
  <SitePageContent v-else-if="page" :page="page" />
  <LegacyHome v-else-if="route.name === 'tenant-home'" />
  <LegacyLegal v-else-if="route.name === 'legal-page'" />
  <div v-else-if="error" class="section py-12"><p role="alert">{{ error }}</p><button class="btn-outline mt-4" @click="load">Réessayer</button></div>
  <NotFound v-else />
</template>
