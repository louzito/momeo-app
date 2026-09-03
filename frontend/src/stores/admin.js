import { defineStore } from 'pinia'
import api from '@/api'
import { TENANT_SLUG } from '@/api/config'
import { migrateLocalStorageKey } from '@/utils/persistedIdentifier'

// Session du back-office Momeo, isolee par etablissement.
const STORAGE_KEY = `todatempo.admin.${TENANT_SLUG}`
const LEGACY_STORAGE_KEYS = [`momeo.admin.${TENANT_SLUG}`]

function loadPersisted() {
  try {
    const raw = migrateLocalStorageKey(STORAGE_KEY, LEGACY_STORAGE_KEYS)
    return raw ? JSON.parse(raw) : null
  } catch {
    return null
  }
}

export const useAdminStore = defineStore('admin', {
  state: () => ({
    session: loadPersisted(), // { admin, tenant }
    loading: false,
    error: null,
  }),
  getters: {
    isLoggedIn: (s) => !!s.session,
    admin: (s) => s.session?.admin || null,
    tenant: (s) => s.session?.tenant || null,
    tenantId: (s) => s.session?.tenant?.id || null,
    currency: (s) => s.session?.tenant?.currency || 'USD',
  },
  actions: {
    persist() {
      try {
        if (this.session) localStorage.setItem(STORAGE_KEY, JSON.stringify(this.session))
        else localStorage.removeItem(STORAGE_KEY)
      } catch {
        /* ignore */
      }
    },
    async login(email, password) {
      this.loading = true
      this.error = null
      try {
        this.session = await api.adminLogin(email, password)
        this.persist()
        return this.session
      } catch (e) {
        this.error = e.message
        throw e
      } finally {
        this.loading = false
      }
    },
    async loginWithSso() {
      this.loading = true
      this.error = null
      try {
        this.session = await api.adminSsoLogin()
        this.persist()
        return this.session
      } catch (e) {
        this.error = e.message
        throw e
      } finally {
        this.loading = false
      }
    },
    logout() {
      api.adminLogout?.()
      this.session = null
      this.persist()
    },
  },
})
