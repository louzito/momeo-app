<script setup>
import { onMounted, computed } from 'vue'
import { RouterView, useRoute } from 'vue-router'
import AppNavbar from '@/components/AppNavbar.vue'
import AppFooter from '@/components/AppFooter.vue'
import { useTenantStore } from '@/stores/tenant'
import { applyBranding } from '@/composables/useBranding'

const route = useRoute()
const tenantStore = useTenantStore()

// Le back-office (admin) fournit sa propre ossature : on masque le chrome public.
const isAdmin = computed(() => route.meta?.layout === 'admin')

onMounted(async () => {
  await tenantStore.loadTenants()
  if (!tenantStore.current && tenantStore.tenants.length) {
    applyBranding(tenantStore.tenants[0])
  }
})
</script>

<template>
  <div class="flex min-h-screen flex-col bg-slate-50">
    <template v-if="isAdmin">
      <RouterView />
    </template>
    <template v-else>
      <AppNavbar />
      <main class="flex-1">
        <RouterView v-slot="{ Component }">
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
