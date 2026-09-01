import { defineStore } from 'pinia'
import api from '@/api'
import { TENANT_SLUG } from '@/api/config'

// Session du back-office Momeo, isolee par etablissement.
const STORAGE_KEY = `momeo.admin.${TENANT_SLUG}`

function loadPersisted() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
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
