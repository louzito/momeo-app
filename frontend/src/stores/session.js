import { defineStore } from 'pinia'
import api from '@/api'
import { migrateLocalStorageKey } from '@/utils/persistedIdentifier'

// Session client (espace "mon compte"). Persistee en localStorage pour survivre
// a un rechargement de page (le compte reste dans les fixtures). Mock uniquement.
const STORAGE_KEY = 'todatempo.session'

function loadPersisted() {
  try {
    const raw = migrateLocalStorageKey(STORAGE_KEY, ['skybook.session'])
    return raw ? JSON.parse(raw) : null
  } catch {
    return null
  }
}

export const useSessionStore = defineStore('session', {
  state: () => ({
    customer: loadPersisted(),
    loading: false,
    error: null,
  }),
  getters: {
    isLoggedIn: (s) => !!s.customer,
    fullName: (s) =>
      s.customer ? `${s.customer.firstName} ${s.customer.lastName}`.trim() : '',
  },
  actions: {
    persist() {
      try {
        if (this.customer) localStorage.setItem(STORAGE_KEY, JSON.stringify(this.customer))
        else localStorage.removeItem(STORAGE_KEY)
      } catch {
        /* stockage indisponible : on ignore */
      }
    },
    async login(email, password) {
      this.loading = true
      this.error = null
      try {
        this.customer = await api.login(email, password)
        this.persist()
        return this.customer
      } catch (e) {
        this.error = e.message
        throw e
      } finally {
        this.loading = false
      }
    },
    async register(payload) {
      this.loading = true
      this.error = null
      try {
        this.customer = await api.register(payload)
        this.persist()
        return this.customer
      } catch (e) {
        this.error = e.message
        throw e
      } finally {
        this.loading = false
      }
    },
    logout() {
      this.customer = null
      this.persist()
    },
  },
})
