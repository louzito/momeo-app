import { computed } from 'vue'
import { storeToRefs } from 'pinia'
import { useTenantStore } from '@/stores/tenant'

// MONO-CENTRE : un deploiement du front = UN centre (sa propre base Sylius).
// Ce composable charge LE centre (plus de resolution par slug de route) et
// garde la meme interface qu'avant (tenant, jumpTypes, options, slug...) pour
// ne pas toucher aux vues qui l'utilisent.
export function useTenantContext() {
  const tenantStore = useTenantStore()
  const { current, jumpTypes, options, loading, error } = storeToRefs(tenantStore)

  // Conserve pour compat avec les liens `params: { slug }` (ignores par le
  // router : les routes n'ont plus de parametre slug).
  const slug = computed(() => current.value?.slug || null)

  if (!tenantStore.current && !tenantStore.loading) {
    tenantStore.loadDefaultTenant().catch(() => {})
  }

  return { tenantStore, tenant: current, jumpTypes, options, loading, error, slug }
}
