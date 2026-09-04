import { defineStore } from 'pinia'
import api from '@/api'
import { TENANT_SLUG } from '@/api/config'
import { applyBranding } from '@/composables/useBranding'
import { fetchPublicCatalog } from '@/api/publicCatalog'

export const useTenantStore = defineStore('tenant', {
  state: () => ({
    tenants: [],
    current: null,
    jumpTypes: [],
    options: [],
    loading: false,
    error: null,
  }),
  getters: {
    currency: (s) => s.current?.currency || 'EUR',
    slug: (s) => s.current?.slug || null,
    perJumpOptions: (s) => s.options.filter((o) => o.scope === 'PER_JUMP'),
    perOrderOptions: (s) => s.options.filter((o) => o.scope === 'PER_ORDER'),
    getJumpTypeById: (s) => (id) => s.jumpTypes.find((j) => j.id === id) || null,
  },
  actions: {
    async loadTenants() {
      if (this.tenants.length) return this.tenants
      const tenant = await this.loadDefaultTenant()
      this.tenants = [tenant]
      return this.tenants
    },

    // MULTI-CENTRES : charge le centre designe par l'URL (/{slug}/...).
    async loadDefaultTenant() {
      if (this.current) return this.current
      return this.loadTenant(TENANT_SLUG)
    },

    async loadTenant(slug, { force = false } = {}) {
      if (this.current && !force) return this.current
      this.loading = true
      this.error = null
      this.current = null
      this.jumpTypes = []
      this.options = []
      try {
        const { tenant, jumpTypes, options } = await fetchPublicCatalog(api, slug)
        this.current = tenant
        applyBranding(tenant)
        this.jumpTypes = jumpTypes
        this.options = options
        return tenant
      } catch (e) {
        this.error = e?.message || 'Impossible de charger le catalogue.'
        console.error('[Momeo] Échec du chargement du catalogue public', e)
        throw e
      } finally {
        this.loading = false
      }
    },

    retryPublicCatalog() {
      return this.loadTenant(TENANT_SLUG, { force: true })
    },
  },
})
