<script setup>
import { onMounted, onUnmounted, computed, ref, watch } from 'vue'
import { RouterView, useRoute } from 'vue-router'
import AppNavbar from '@/components/AppNavbar.vue'
import AppFooter from '@/components/AppFooter.vue'
import CatalogError from '@/components/ui/CatalogError.vue'
import { useTenantStore } from '@/stores/tenant'
import api from '@/api'
import { sitePublicationKey } from '@/api/sitePublication'
import { TENANT_SLUG } from '@/api/config'

const siteVersion = ref(0)
let navigationRequest = 0
async function refreshNavigation() {
  const request = ++navigationRequest
  const navigation = await api.getSiteNavigation().catch(() => null)
  if (request === navigationRequest) tenantStore.siteNavigation = navigation
}
async function refreshSite() {
  await refreshNavigation()
  // Publication must never remount a reservation/payment form with unsaved input.
  if (['tenant-home', 'legal-page', 'site-page'].includes(route.name)) ++siteVersion.value
}
function storageChanged(event) { if (event.key === sitePublicationKey) refreshSite() }
const route = useRoute()
const tenantStore = useTenantStore()

// Le back-office (admin) fournit sa propre ossature : on masque le chrome public.
const isAdmin = computed(() => route.meta?.layout === 'admin')
// Sans centre dans l'URL, seules les routes « centre invalide » existent : le
// chrome public (dont les liens vers tenant-home) n'a rien a pointer.
const hasTenant = !!TENANT_SLUG
const retryCatalog = () => tenantStore.retryPublicCatalog().catch(() => {})

watch(() => route.path, async () => {
  if (!route.meta?.requiresAdmin && hasTenant) await refreshNavigation()
})
onUnmounted(() => { window.removeEventListener('site-published', refreshSite); window.removeEventListener('storage', storageChanged) })
onMounted(async () => {
  window.addEventListener('site-published', refreshSite); window.addEventListener('storage', storageChanged)
  if (hasTenant && !tenantStore.current && !tenantStore.loading) {
    await tenantStore.loadDefaultTenant().catch(() => {})
  }
})
</script>

<template>
  <div class="flex min-h-screen flex-col bg-slate-50">
    <template v-if="isAdmin || !hasTenant">
      <RouterView />
    </template>
    <template v-else>
      <AppNavbar />
      <main class="flex-1">
        <CatalogError
          v-if="tenantStore.error && !tenantStore.loading"
          :message="tenantStore.error"
          @retry="retryCatalog"
        />
        <RouterView v-else v-slot="{ Component }">
          <transition name="fade" mode="out-in" :duration="200">
            <component :is="Component" :key="siteVersion" />
          </transition>
        </RouterView>
      </main>
      <AppFooter />
    </template>
  </div>
</template>

<style>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
