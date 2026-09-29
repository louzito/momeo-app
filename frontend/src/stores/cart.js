import { defineStore } from 'pinia'
import api from '@/api'
import { TENANT_SLUG } from '@/api/config'

const pendingCheckouts = new WeakMap()

// Etat du tunnel d'achat. Les noms `jumpType` et `jumper` restent presents dans
// le payload pour compatibilite avec les commandes historiques, mais portent
// desormais une prestation et les coordonnees du client.
function emptyJumper() {
  return {
    firstName: '',
    lastName: '',
    email: '',
    phone: '',
    smsReminderConsent: false,
    notes: '',
    street: '', postcode: '', city: '', countryCode: 'FR',
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

const useInternalCartStore = defineStore('cart', {
  state: () => ({
    tenantId: null,
    products: [],
    gifts: [],
    fulfillmentMode: 'pickup',
    checkoutPayload: null,
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
    giftCardCode: '',
    eligibilityChecked: false,
    lastResult: null, // { order, booking, voucher }
  }),
  getters: {
    hasItems: s => !!s.jumpType || s.products.length > 0 || s.gifts.length > 0,
    productsTotal: s => s.products.reduce((sum, p) => sum + Math.round(p.price * 100) * p.quantity, 0) / 100,
    giftsTotal: s => s.gifts.reduce((sum, g) => sum + g.amount, 0) / 100,
    deliveryFee: s => s.fulfillmentMode === 'delivery' ? Math.max(0, ...s.products.map(p => p.deliveryFee || 0)) : 0,
    eligibleCents() { return Math.max(0, this.dueNowCents - Math.round(this.giftsTotal * 100)) },
    hasJumpType: (s) => !!s.jumpType,
    selectedOptions: (s) => [...s.perJumpOptions, ...s.perOrderOptions],
    optionsTotal: (s) =>
      [...s.perJumpOptions, ...s.perOrderOptions].reduce((sum, o) => sum + o.price, 0),
    subtotal() {
      return (this.jumpType?.basePrice || 0) + this.optionsTotal
    },
    total() {
      return this.subtotal + this.productsTotal + this.giftsTotal + this.deliveryFee
    },
    dueNowCents() {
      const total = Math.round(this.subtotal * 100)
      const other = Math.round((this.productsTotal + this.giftsTotal + this.deliveryFee) * 100)
      if (this.isGift) return total + other
      const mode = this.jumpType?.paymentMode || 'full'
      const value = Number(this.jumpType?.paymentValue) || 0
      if (mode === 'none') return other
      if (mode === 'fixed') return other + Math.min(total, Math.round(value * 100))
      if (mode === 'percentage') return other + Math.min(total, Math.floor((total * Math.round(value) + 50) / 100))
      return total + other
    },
    dueNow() { return this.dueNowCents / 100 },
    balanceDue() { return (Math.round(this.total * 100) - this.dueNowCents) / 100 },
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
      if (this.lastResult || this.tenantId && this.tenantId !== tenantId) this.$reset()
      if (this.checkoutPayload) return false
      if (this.jumpType && this.jumpType.id !== jumpType.id && !window.confirm('Une seule prestation est possible par commande. Remplacer la prestation actuelle ?')) return false
      this.tenantId = tenantId
      this.jumpType = jumpType
      this.perJumpOptions = []
      this.perOrderOptions = []
      this.slot = null
      this.gift = { name: '', email: '', message: '', purchaserName: '', purchaserEmail: '' }
      this.kind = 'direct'
      this.paymentMethod = 'bank_transfer'
      this.giftCardCode = ''
      this.eligibilityChecked = false
      this.lastResult = null
      return true
    },
    addProduct(tenantId, product) {
      if (this.lastResult || this.tenantId && this.tenantId !== tenantId) this.$reset()
      if (this.checkoutPayload) return
      this.tenantId = tenantId
      const item = this.products.find(p => p.id === product.id)
      if (item && item.quantity < product.stock) item.quantity++
      else if (!item && product.stock > 0) this.products.push({ ...product, quantity: 1 })
      if (!product.pickupEnabled) this.fulfillmentMode = 'delivery'
    },
    addGift(tenantId, gift) {
      if (this.lastResult || this.tenantId && this.tenantId !== tenantId) this.$reset()
      if (this.checkoutPayload || this.gifts.length >= 10) return false
      this.tenantId = tenantId; this.gifts.push({ ...gift }); return true
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
      if (this.lastResult) return this.lastResult
      if (pendingCheckouts.has(this)) return pendingCheckouts.get(this)
      const payload = {
        tenantId: this.tenantId,
        kind: this.kind,
        jumpTypeId: this.jumpType?.id,
        jumpTypeName: this.jumpType?.name,
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
        giftCardCode: this.isGift || this.dueNowCents === 0 ? '' : this.giftCardCode,
        customerId,
      }
      if (!this.isGift && !this.checkoutPayload) {
        this.checkoutPayload = {
          key: crypto.randomUUID().replaceAll('-', ''),
          items: [...(this.jumpType ? [{ code: this.jumpType.id, quantity: 1 }, ...this.selectedOptions.map(o => ({ code: o.id, quantity: 1 }))] : []), ...this.products.map(p => ({ code: p.id, quantity: p.quantity }))],
          gifts: this.gifts.map(g => ({ ...g })), customer: { ...this.jumper },
          slot: this.slot ? { ...this.slot } : null, mode: this.fulfillmentMode,
          paymentMethod: this.paymentMethod, giftCardCode: this.giftCardCode,
        }
      }
      const pending = (this.isGift ? api.createOrder(payload) : api.createUnifiedOrder(JSON.parse(JSON.stringify(this.checkoutPayload)))).then((result) => {
        this.lastResult = result
        return result
      }).catch(error => {
        // An explicit validation failure rolled back. A lost response retains the exact request.
        if ([409, 422].includes(error?.status)) this.checkoutPayload = null
        throw error
      }).finally(() => pendingCheckouts.delete(this))
      pendingCheckouts.set(this, pending)
      return pending
    },

    reset() {
      this.$reset()
    },
  },
})

// Keep one tab's cart and retry reference across reloads, scoped to the established tenant.
const attached = new WeakSet()
export function useCartStore(pinia) {
  const cart = useInternalCartStore(pinia)
  if (!attached.has(cart)) {
    attached.add(cart)
    const key = `todatempo.cart.${TENANT_SLUG}`
    try { const value = JSON.parse(sessionStorage.getItem(key) || 'null'); if (value?.version === 1) cart.$patch(value.state) } catch {}
    cart.$subscribe((_mutation, state) => { try { sessionStorage.setItem(key, JSON.stringify({ version: 1, state })) } catch {} }, { detached: true, flush: 'sync' })
  }
  return cart
}
