import { defineStore } from 'pinia'
import api from '@/api'
import { TENANT_SLUG } from '@/api/config'
import { applyBranding } from '@/composables/useBranding'

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
      this.tenants = await api.getTenants()
      return this.tenants
    },

    // MULTI-CENTRES : charge le centre designe par l'URL (/{slug}/...).
    async loadDefaultTenant() {
      if (this.current) return this.current
      return this.loadTenant(TENANT_SLUG)
    },

    async loadTenant(slug) {
      this.loading = true
      this.error = null
      try {
        // 1. Base mock si le slug y figure (branding de demo)…
        let tenant = null
        try {
          await this.loadTenants()
          tenant = await api.getTenantBySlug(slug)
        } catch { /* centre absent du mock */ }
        // …sinon squelette neutre : l'identite reelle vient de Sylius juste apres.
        if (!tenant) {
          tenant = {
            id: `dz_${slug}`,
            slug,
            name: slug,
            tagline: '',
            country: 'FR',
            city: '',
            currency: 'EUR',
            locale: 'fr-FR',
            phone: '',
            email: '',
            branding: { brandPalette: 'sky', accent: 'orange', logoEmoji: '🪂', heroImage: '' },
            voucherValidityMonths: 12,
            extensionOption: { available: false, price: 0, addedMonths: 0 },
            weatherHoldExtraDays: 30,
            highlights: [],
            about: '',
          }
        }
        // 2. Identite REELLE du centre — channel Sylius : nom, devise.
        try {
          const ch = await api.getShopChannel?.()
          if (ch) {
            tenant = { ...tenant, name: ch.name || tenant.name, currency: ch.currency || tenant.currency }
          }
        } catch { /* channel non expose : defauts */ }
        // 3. Configuration boutique (taxon skybook_config) : contact, adresse,
        //    logo, couleurs, reseaux sociaux — par-dessus le tout.
        try {
          const cfg = await api.getPublicShopConfig?.()
          if (cfg) {
            tenant = {
              ...tenant,
              name: cfg.name || tenant.name,
              email: cfg.contactEmail || tenant.email,
              phone: cfg.contactPhone || tenant.phone,
              city: cfg.address?.city || tenant.city,
              address: cfg.address || null,
              socials: cfg.socials || {},
              logoUrl: cfg.logoUrl || '',
              colors: cfg.colors || null,
              // Peaufinage vitrine (config par centre) :
              home: cfg.home || null,
              shopOrder: Array.isArray(cfg.shopOrder) ? cfg.shopOrder : [],
              // Points forts : config du centre si renseignes, sinon defauts (mock/generiques).
              highlights: cfg.home?.highlights?.length ? cfg.home.highlights : tenant.highlights,
              giftVouchersEnabled: cfg.giftVouchersEnabled !== false,
              legal: cfg.legal || null,
              bannerUrl: cfg.bannerUrl || '',
              bannerMobileUrl: cfg.bannerMobileUrl || '',
            }
          }
        } catch { /* config non disponible : defauts */ }
        this.current = tenant
        applyBranding(tenant)
        const [jumpTypes, options] = await Promise.all([
          api.getJumpTypes(tenant.id),
          api.getOptions(tenant.id),
        ])
        this.jumpTypes = jumpTypes
        this.options = options
        return tenant
      } catch (e) {
        this.error = e.message
        throw e
      } finally {
        this.loading = false
      }
    },
  },
})
