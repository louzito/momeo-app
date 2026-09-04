import { defineStore } from 'pinia'
import api from '@/api'

// Etat du tunnel d'achat. Les noms `jumpType` et `jumper` restent presents dans
// le payload pour compatibilite avec les commandes historiques, mais portent
// desormais une prestation et les coordonnees du client.
function emptyJumper() {
  return {
    firstName: '',
    lastName: '',
    email: '',
    phone: '',
    notes: '',
    bookingTermsAccepted: false,
    privacyAccepted: false,
    age: '',
    weightKg: '',
    heightCm: '',
    medicalCertificate: false,
    waiverAccepted: false,
    customAnswers: {},
  }
}

export const useCartStore = defineStore('cart', {
  state: () => ({
    tenantId: null,
    jumpType: null,
    kind: 'direct', // 'direct' | 'gift'
    perJumpOptions: [], // option objects
    perOrderOptions: [], // option objects
    slot: null,
    jumper: emptyJumper(),
    // purchaserName/purchaserEmail : coordonnees de l'ACHETEUR (paie la
    // commande reelle Sylius) — distinctes du beneficiaire (name/email/message)
    // qui recoit le cheque cadeau. Necessaires depuis le passage au vrai tunnel
    // d'achat (email + adresse de facturation Sylius).
    gift: { name: '', email: '', message: '', purchaserName: '', purchaserEmail: '' },
    // Moyens réels Sylius : virement ou Stripe Checkout.
    paymentMethod: 'bank_transfer',
    eligibilityChecked: false,
    lastResult: null, // { order, booking, voucher }
  }),
  getters: {
    hasJumpType: (s) => !!s.jumpType,
    selectedOptions: (s) => [...s.perJumpOptions, ...s.perOrderOptions],
    optionsTotal: (s) =>
      [...s.perJumpOptions, ...s.perOrderOptions].reduce((sum, o) => sum + o.price, 0),
    subtotal() {
      return (this.jumpType?.basePrice || 0) + this.optionsTotal
    },
    total() {
      return this.subtotal
    },
    dueNowCents() {
      const total = Math.round(this.subtotal * 100)
      const mode = this.jumpType?.paymentMode || 'full'
      const value = Number(this.jumpType?.paymentValue) || 0
      if (mode === 'none') return 0
      if (mode === 'fixed') return Math.min(total, Math.round(value * 100))
      if (mode === 'percentage') return Math.min(total, Math.floor((total * Math.round(value) + 50) / 100))
      return total
    },
    dueNow() { return this.dueNowCents / 100 },
    balanceDue() { return (Math.round(this.subtotal * 100) - this.dueNowCents) / 100 },
    isGift: (s) => s.kind === 'gift',
    // Le tunnel direct exige un creneau ; le tunnel cadeau exige les
    // coordonnees de l'acheteur (email de commande Sylius) + du beneficiaire.
    readyForPayment(s) {
      if (!s.jumpType) return false
      return s.kind === 'gift' ? !!s.gift.email && !!s.gift.purchaserEmail : !!s.slot
    },
  },
  actions: {
    startPurchase(tenantId, jumpType) {
      this.tenantId = tenantId
      this.jumpType = jumpType
      this.perJumpOptions = []
      this.perOrderOptions = []
      this.slot = null
      this.jumper = emptyJumper()
      this.gift = { name: '', email: '', message: '', purchaserName: '', purchaserEmail: '' }
      this.kind = 'direct'
      this.paymentMethod = 'bank_transfer'
      this.eligibilityChecked = false
      this.lastResult = null
    },

    // Pre-selectionne les options obligatoires (frais de dossier, etc.).
    ensureMandatoryOptions(allOptions) {
      allOptions
        .filter((o) => o.mandatory)
        .forEach((o) => {
          const bucket = o.scope === 'PER_JUMP' ? this.perJumpOptions : this.perOrderOptions
          if (!bucket.some((x) => x.id === o.id)) bucket.push({ ...o })
        })
    },

    toggleOption(option) {
      if (option.mandatory) return // non decochable
      const bucket = option.scope === 'PER_JUMP' ? this.perJumpOptions : this.perOrderOptions
      const idx = bucket.findIndex((o) => o.id === option.id)
      if (idx >= 0) bucket.splice(idx, 1)
      else bucket.push({ ...option })
    },

    isOptionSelected(optionId) {
      return this.selectedOptions.some((o) => o.id === optionId)
    },

    setSlot(slot) {
      this.slot = slot
    },
    setPaymentMethod(method) {
      this.paymentMethod = method
    },
    setKind(kind) {
      this.kind = kind
    },
    setJumper(data) {
      this.jumper = { ...this.jumper, ...data }
    },
    setGift(data) {
      this.gift = { ...this.gift, ...data }
    },
    markEligibilityChecked() {
      this.eligibilityChecked = true
    },

    async checkout(customerId = null) {
      const payload = {
        tenantId: this.tenantId,
        kind: this.kind,
        jumpTypeId: this.jumpType.id,
        jumpTypeName: this.jumpType.name,
        slotId: this.slot?.id || null,
        slot: this.slot ? { ...this.slot } : null,
        options: this.selectedOptions.map((o) => ({ ...o })),
        // fullName conserve pour compat (mock, cartes d'embarquement...) ;
        // le back Sylius recoit firstName / lastName separement.
        jumper: {
          ...this.jumper,
          fullName: `${this.jumper.firstName || ''} ${this.jumper.lastName || ''}`.trim(),
        },
        gift: this.gift,
        paymentMethod: this.paymentMethod,
        customerId,
      }
      const result = await api.createOrder(payload)
      this.lastResult = result
      return result
    },

    reset() {
      this.$reset()
    },
  },
})
