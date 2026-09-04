import { defineStore } from 'pinia'
import api from '@/api'
import { migrateLocalStorageKey } from '@/utils/persistedIdentifier'

// Session beneficiaire de cheque cadeau (connexion par code + email).
const STORAGE_KEY = 'todatempo.beneficiary'

function loadPersisted() {
  try {
    const raw = migrateLocalStorageKey(STORAGE_KEY, ['skybook.beneficiary'])
    return raw ? JSON.parse(raw) : null
  } catch {
    return null
  }
}

export const useBeneficiaryStore = defineStore('beneficiary', {
  state: () => ({
    profile: loadPersisted(),
    vouchers: [],
    loading: false,
    error: null,
    // Resultat de la derniere reservation (voucher + "booking" mock), lu par
    // VoucherConfirmation.vue juste apres reserveVoucher() — le cheque reel
    // ne porte pas la reservation (creneau/carte d'embarquement = mock, voir
    // httpApi.reserveVoucher), donc rien a re-fetcher au rechargement.
    lastReservation: null,
  }),
  getters: {
    isLoggedIn: (s) => !!s.profile,
    email: (s) => s.profile?.email || null,
  },
  actions: {
    persist() {
      try {
        if (this.profile) localStorage.setItem(STORAGE_KEY, JSON.stringify(this.profile))
        else localStorage.removeItem(STORAGE_KEY)
      } catch {
        /* ignore */
      }
    },
    async login(code, email) {
      this.loading = true
      this.error = null
      try {
        const { profile } = await api.beneficiaryLogin(code, email)
        this.profile = profile
        this.persist()
        await this.refreshVouchers()
        return profile
      } catch (e) {
        this.error = e.message
        throw e
      } finally {
        this.loading = false
      }
    },
    async refreshVouchers() {
      if (!this.profile?.email) return []
      this.vouchers = await api.getVouchersForEmail(this.profile.email)
      return this.vouchers
    },
    logout() {
      this.profile = null
      this.vouchers = []
      this.persist()
    },
    setLastReservation(data) {
      this.lastReservation = data
    },
  },
})
