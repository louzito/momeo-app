<script setup>
import { onMounted, computed } from 'vue'
import { RouterView, useRoute } from 'vue-router'
import AppNavbar from '@/components/AppNavbar.vue'
import AppFooter from '@/components/AppFooter.vue'
import CatalogError from '@/components/ui/CatalogError.vue'
import { useTenantStore } from '@/stores/tenant'
import { TENANT_SLUG } from '@/api/config'

const route = useRoute()
const tenantStore = useTenantStore()

// Le back-office (admin) fournit sa propre ossature : on masque le chrome public.
const isAdmin = computed(() => route.meta?.layout === 'admin')
// Sans centre dans l'URL, seules les routes « centre invalide » existent : le
// chrome public (dont les liens vers tenant-home) n'a rien a pointer.
const hasTenant = !!TENANT_SLUG
const retryCatalog = () => tenantStore.retryPublicCatalog().catch(() => {})

onMounted(async () => {
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
            <component :is="Component" />
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
