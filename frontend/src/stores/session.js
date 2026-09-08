import { defineStore } from 'pinia'
import api from '@/api'
import { getCustomerToken, getCurrentCustomer, loginCustomer, logoutCustomer } from '@/api/customerAuth'

export const useSessionStore = defineStore('session', {
  state: () => ({
    customer: null,
    initialized: false,
    loading: false,
    error: null,
  }),
  getters: {
    isLoggedIn: (s) => !!s.customer && !!getCustomerToken(),
    fullName: (s) =>
      s.customer ? `${s.customer.firstName} ${s.customer.lastName}`.trim() : '',
  },
  actions: {
    async restore() {
      if (this.initialized) return this.customer
      this.initialized = true
      if (!getCustomerToken()) return null
      try { this.customer = await getCurrentCustomer() }
      catch { logoutCustomer(); this.customer = null }
      return this.customer
    },
    async login(email, password) {
      this.loading = true
      this.error = null
      try {
        this.customer = await loginCustomer(email, password)
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
        await api.register(payload)
        this.customer = await loginCustomer(payload.email, payload.password)
        // Le telephone ne fait pas partie des champs d'inscription Sylius : il
        // est enregistre sur la fiche client une fois la session ouverte.
        const phone = String(payload.phone || '').trim()
        if (phone && this.customer?.id) {
          try {
            await api.updateCustomerPhone(this.customer.id, phone)
            this.customer = { ...this.customer, phone }
          } catch { /* Le compte est cree : le telephone reste modifiable plus tard. */ }
        }
        return this.customer
      } catch (e) {
        this.error = e.message
        throw e
      } finally {
        this.loading = false
      }
    },
    async requestPasswordReset(email) {
      this.error = null
      try { return await api.requestPasswordReset(email) }
      catch (e) { this.error = e.message; throw e }
    },
    logout() {
      logoutCustomer()
      this.customer = null
    },
  },
})
