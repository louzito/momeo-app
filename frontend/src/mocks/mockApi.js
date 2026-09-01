// =============================================================================
// FAUSSE API — couche isolee et remplacable
// -----------------------------------------------------------------------------
// Simule un backend (API Platform / Sylius a venir). Interface de service
// asynchrone coherente. Les composants Vue passent TOUJOURS par src/api/index.js.
//
// Pour brancher le vrai backend : creer un `httpApi.js` avec la MEME signature
// de methodes et changer l'import dans `src/api/index.js`. Aucun composant Vue a
// modifier.
// =============================================================================

import { tenants, DEFAULT_TENANT_SLUG } from './fixtures/tenants'
import { catalog } from './fixtures/catalog'
import { generateSlots } from './fixtures/slots'
import { customers, beneficiaries } from './fixtures/accounts'
import {
  vouchers as seedVouchers,
  bookings as seedBookings,
  orders as seedOrders,
  boardingPasses as seedBoardingPasses,
} from './fixtures/commerce'
import { admins, schedules, paymentMethods } from './fixtures/operations'

// --- Etat mutable en memoire (reinitialise a chaque rechargement de page) -----
const db = {
  tenants: clone(tenants),
  catalog: clone(catalog), // MUTABLE : editable par l'admin du centre
  schedules: clone(schedules),
  paymentMethods: clone(paymentMethods),
  admins: clone(admins),
  customers: clone(customers),
  beneficiaries: clone(beneficiaries),
  vouchers: clone(seedVouchers),
  bookings: clone(seedBookings),
  orders: clone(seedOrders),
  boardingPasses: clone(seedBoardingPasses),
  staffMembers: {},
  slotsByTenant: {},
}

function clone(v) {
  return typeof structuredClone === 'function' ? structuredClone(v) : JSON.parse(JSON.stringify(v))
}
function delay(data, ms = 340) {
  return new Promise((resolve) => setTimeout(() => resolve(clone(data)), ms))
}
function cat(tenantId) {
  return db.catalog[tenantId]
}
function tenantSlots(tenantId) {
  if (!db.slotsByTenant[tenantId]) {
    db.slotsByTenant[tenantId] = generateSlots(tenantId, {
      jumpTypes: cat(tenantId)?.jumpTypes || [],
      schedule: db.schedules[tenantId],
    })
  }
  return db.slotsByTenant[tenantId]
}
function invalidateSlots(tenantId) {
  delete db.slotsByTenant[tenantId]
}
function genId(prefix) {
  const rand = Math.floor(Math.random() * 1e6).toString().padStart(6, '0')
  return `${prefix}_${Date.now().toString(36)}${rand}`
}
function genCode(prefix) {
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'
  let s = ''
  for (let i = 0; i < 6; i++) s += chars[Math.floor(Math.random() * chars.length)]
  return `${prefix}-${s}`
}

// Enrichissement (denormalisation comme le ferait un vrai backend).
function tenantName(id) {
  return db.tenants.find((t) => t.id === id)?.name || ''
}
function tenantSlug(id) {
  return db.tenants.find((t) => t.id === id)?.slug || ''
}
function jumpTypeName(tenantId, jumpTypeId) {
  return cat(tenantId)?.jumpTypes.find((j) => j.id === jumpTypeId)?.name || 'Saut'
}
function enrichVoucher(v) {
  return { ...v, tenantName: tenantName(v.tenantId), tenantSlug: tenantSlug(v.tenantId), jumpTypeName: jumpTypeName(v.tenantId, v.jumpTypeId) }
}
function enrichBooking(b) {
  return { ...b, tenantName: tenantName(b.tenantId), tenantSlug: tenantSlug(b.tenantId), jumpTypeName: jumpTypeName(b.tenantId, b.jumpTypeId) }
}

function defaultEligibility() {
  return {
    ageMin: 18,
    ageMax: 70,
    weightMaxKg: 100,
    heightMinCm: 140,
    medicalCertificateRequired: false,
    waiverRequired: true,
    customRules: [
      { key: 'notPregnant', label: 'Je declare ne pas etre enceinte', type: 'checkbox', required: true },
      { key: 'noHeartCondition', label: 'Je ne declare aucun probleme cardiaque', type: 'checkbox', required: true },
    ],
  }
}

export const mockApi = {
  DEFAULT_TENANT_SLUG,

  // ===========================================================================
  // TENANTS & CATALOGUE (public)
  // ===========================================================================
  async getTenants() {
    return delay(db.tenants, 200)
  },
  async getTenantBySlug(slug) {
    const t = db.tenants.find((x) => x.slug === slug)
    if (!t) throw new NotFound(`Tenant "${slug}" introuvable`)
    return delay(t, 180)
  },
  async getJumpTypes(tenantId) {
    return delay(cat(tenantId)?.jumpTypes || [])
  },
  async getJumpType(tenantId, jumpTypeId) {
    const jt = cat(tenantId)?.jumpTypes.find((j) => j.id === jumpTypeId)
    if (!jt) throw new NotFound('Type de saut introuvable')
    return delay(jt)
  },
  async getOptions(tenantId, jumpTypeId = null) {
    const c = cat(tenantId)
    if (!c) return delay([])
    let list = c.options
    // Filtrage par produit lie (si l'option cible des sauts precis).
    if (jumpTypeId) {
      list = list.filter((o) => !o.linkedJumpTypeIds?.length || o.linkedJumpTypeIds.includes(jumpTypeId))
    }
    return delay(list)
  },

  // ===========================================================================
  // CRENEAUX
  // ===========================================================================
  async getSlots(tenantId, { jumpTypeId = null } = {}) {
    let slots = tenantSlots(tenantId)
    if (jumpTypeId) slots = slots.filter((s) => s.compatibleJumpTypeIds.includes(jumpTypeId))
    return delay(slots, 280)
  },
  async getSlot(tenantId, slotId) {
    const s = tenantSlots(tenantId).find((x) => x.id === slotId)
    if (!s) throw new NotFound('Creneau introuvable')
    return delay(s, 120)
  },

  // ===========================================================================
  // ELIGIBILITE
  // ===========================================================================
  async checkEligibility(tenantId, jumpTypeId, form) {
    const jt = cat(tenantId)?.jumpTypes.find((j) => j.id === jumpTypeId)
    if (!jt) throw new NotFound('Type de saut introuvable')
    const r = jt.eligibility
    const violations = []
    const age = Number(form.age)
    const weight = Number(form.weightKg)
    const height = Number(form.heightCm)
    if (form.age !== '' && !Number.isNaN(age)) {
      if (age < r.ageMin) violations.push(`Age minimum requis : ${r.ageMin} ans (declare : ${age}).`)
      if (age > r.ageMax) violations.push(`Age maximum autorise : ${r.ageMax} ans (declare : ${age}).`)
    }
    if (form.weightKg !== '' && !Number.isNaN(weight) && weight > r.weightMaxKg)
      violations.push(`Poids maximum : ${r.weightMaxKg} kg (declare : ${weight} kg).`)
    if (form.heightCm !== '' && !Number.isNaN(height) && height < r.heightMinCm)
      violations.push(`Taille minimum : ${r.heightMinCm} cm (declaree : ${height} cm).`)
    if (r.medicalCertificateRequired && !form.medicalCertificate)
      violations.push('Un certificat medical est obligatoire pour ce saut.')
    if (r.waiverRequired && !form.waiverAccepted)
      violations.push('La decharge de responsabilite doit etre signee.')
    ;(r.customRules || []).forEach((cr) => {
      if (cr.required && !form.customAnswers?.[cr.key]) violations.push(`Declaration requise : "${cr.label}".`)
    })
    return delay({ eligible: violations.length === 0, violations, rule: r }, 220)
  },

  // ===========================================================================
  // COMMANDE / PAIEMENT (mock)
  // ===========================================================================
  async createOrder(payload) {
    const tenant = db.tenants.find((t) => t.id === payload.tenantId)
    const jt = cat(payload.tenantId)?.jumpTypes.find((j) => j.id === payload.jumpTypeId)
    const currency = tenant?.currency || 'USD'
    const orderId = genId('ord')
    const number = `${tenant?.slug?.toUpperCase().slice(0, 3) || 'ORD'}-2026-${Math.floor(1000 + Math.random() * 8999)}`
    const lines = [
      { label: jt?.name || 'Saut', qty: 1, price: jt?.basePrice || 0 },
      ...(payload.options || []).map((o) => ({ label: o.name, qty: 1, price: o.price })),
    ]
    const total = lines.reduce((sum, l) => sum + l.price * l.qty, 0)
    const order = {
      id: orderId, tenantId: payload.tenantId, number, customerId: payload.customerId || null,
      createdAt: new Date().toISOString(), status: 'paid', kind: payload.kind, currency, lines, total,
      bookingId: null, voucherCodes: [],
    }
    let booking = null
    let voucher = null

    if (payload.kind === 'gift') {
      const code = genCode(tenant?.slug?.toUpperCase().slice(0, 3) || 'GFT')
      const expiresAt = new Date()
      expiresAt.setMonth(expiresAt.getMonth() + (tenant?.voucherValidityMonths || 12))
      voucher = {
        code, tenantId: payload.tenantId, jumpTypeId: payload.jumpTypeId, amount: jt?.basePrice || 0, currency,
        status: 'issued', beneficiaryEmail: payload.gift?.email || '', beneficiaryName: payload.gift?.name || '',
        purchaserName: payload.jumper?.fullName || 'Acheteur', personalMessage: payload.gift?.message || '',
        issuedAt: new Date().toISOString(), expiresAt: expiresAt.toISOString(), bookingId: null,
      }
      db.vouchers.push(voucher)
      order.voucherCodes = [code]
    } else {
      const slot = tenantSlots(payload.tenantId).find((s) => s.id === payload.slotId)
      if (slot && slot.remaining > 0) { slot.booked += 1; slot.remaining -= 1 }
      const ref = `BK-${tenant?.slug?.toUpperCase().slice(0, 3) || 'BK'}-${Math.floor(10000 + Math.random() * 89999)}`
      const bpId = genId('bp')
      booking = {
        id: genId('bk'), tenantId: payload.tenantId, reference: ref, source: 'direct', orderId,
        customerId: payload.customerId || null, jumpTypeId: payload.jumpTypeId,
        jumperName: payload.jumper?.fullName || 'Sauteur', slotStart: slot?.start || null, slotEnd: slot?.end || null,
        status: 'confirmed', options: (payload.options || []).filter((o) => o.scope === 'PER_JUMP').map((o) => ({ name: o.name, price: o.price })),
        boardingPassId: bpId, weightDeclaredKg: payload.jumper?.weightKg || null,
      }
      db.bookings.push(booking)
      db.boardingPasses.push({
        id: bpId, bookingId: booking.id, tenantId: payload.tenantId, reference: ref, jumperName: booking.jumperName,
        jumpTypeName: jt?.name || 'Saut', slotStart: booking.slotStart, options: booking.options.map((o) => o.name),
        weightDeclaredKg: booking.weightDeclaredKg, waiverSigned: true, checkedInAt: null,
      })
      order.bookingId = booking.id
    }
    db.orders.push(order)
    return delay({ order, booking: booking ? enrichBooking(booking) : null, voucher: voucher ? enrichVoucher(voucher) : null }, 600)
  },

  async processPayment({ amount, currency, card }) {
    await delay(null, 850)
    return { success: true, transactionId: genId('tx'), amount, currency, last4: (card?.number || '').replace(/\s/g, '').slice(-4) }
  },

  // ===========================================================================
  // AUTH CLIENT
  // ===========================================================================
  async login(email, password) {
    const c = db.customers.find((x) => x.email.toLowerCase() === String(email).toLowerCase() && x.password === password)
    if (!c) throw new Unauthorized('Email ou mot de passe incorrect.')
    return delay(publicUser(c), 380)
  },
  async register({ email, password, firstName, lastName, phone, tenantId }) {
    if (db.customers.some((x) => x.email.toLowerCase() === String(email).toLowerCase()))
      throw new Conflict('Un compte existe deja avec cet email.')
    const c = { id: genId('cust'), tenantId: tenantId || null, email, password, firstName: firstName || '', lastName: lastName || '', phone: phone || '', createdAt: new Date().toISOString() }
    db.customers.push(c)
    return delay(publicUser(c), 480)
  },
  async getCustomerOrders(customerId) {
    return delay(db.orders.filter((o) => o.customerId === customerId).sort(byDateDesc('createdAt')))
  },
  async getCustomerBookings(customerId) {
    return delay(db.bookings.filter((b) => b.customerId === customerId).map(enrichBooking))
  },
  async getBooking(bookingId) {
    const b = db.bookings.find((x) => x.id === bookingId)
    if (!b) throw new NotFound('Reservation introuvable')
    return delay(enrichBooking(b))
  },
  async getBoardingPass(bookingId) {
    const bp = db.boardingPasses.find((x) => x.bookingId === bookingId)
    if (!bp) throw new NotFound("Carte d'embarquement introuvable")
    return delay(bp)
  },

  // ===========================================================================
  // ESPACE BENEFICIAIRE
  // ===========================================================================
  async beneficiaryLogin(code, email) {
    const voucher = db.vouchers.find((v) => v.code.toUpperCase() === String(code).trim().toUpperCase())
    if (!voucher) throw new Unauthorized('Code de cheque cadeau invalide.')
    if (voucher.beneficiaryEmail.toLowerCase() !== String(email).trim().toLowerCase())
      throw new Unauthorized('Ce code ne correspond pas a cet email.')
    const profile = db.beneficiaries.find((b) => b.email.toLowerCase() === email.toLowerCase()) || {
      email, firstName: voucher.beneficiaryName?.split(' ')[0] || '', lastName: '',
    }
    return delay({ profile, primaryCode: voucher.code }, 380)
  },
  async getVouchersForEmail(email) {
    return delay(db.vouchers.filter((v) => v.beneficiaryEmail.toLowerCase() === String(email).toLowerCase()).map(enrichVoucher))
  },
  async getVoucherByCode(code) {
    const v = db.vouchers.find((x) => x.code.toUpperCase() === String(code).trim().toUpperCase())
    if (!v) throw new NotFound('Cheque cadeau introuvable')
    return delay(enrichVoucher(v))
  },
  async reserveVoucher(code, { slotId, jumper }) {
    const v = db.vouchers.find((x) => x.code.toUpperCase() === String(code).trim().toUpperCase())
    if (!v) throw new NotFound('Cheque cadeau introuvable')
    if (v.status === 'expired') throw new Conflict('Ce cheque cadeau est expire.')
    if (v.status === 'used') throw new Conflict('Ce cheque cadeau a deja ete utilise.')
    const slot = tenantSlots(v.tenantId).find((s) => s.id === slotId)
    if (!slot || slot.remaining <= 0) throw new Conflict('Ce creneau est complet.')
    slot.booked += 1; slot.remaining -= 1
    const jt = cat(v.tenantId)?.jumpTypes.find((j) => j.id === v.jumpTypeId)
    const tenant = db.tenants.find((t) => t.id === v.tenantId)
    const ref = `BK-${tenant?.slug?.toUpperCase().slice(0, 3) || 'BK'}-${Math.floor(10000 + Math.random() * 89999)}`
    const bpId = genId('bp')
    const booking = {
      id: genId('bk'), tenantId: v.tenantId, reference: ref, source: 'voucher', voucherCode: v.code, customerId: null,
      jumpTypeId: v.jumpTypeId, jumperName: jumper?.fullName || v.beneficiaryName, slotStart: slot.start, slotEnd: slot.end,
      status: 'confirmed', options: [], boardingPassId: bpId, weightDeclaredKg: jumper?.weightKg || null,
    }
    db.bookings.push(booking)
    db.boardingPasses.push({
      id: bpId, bookingId: booking.id, tenantId: v.tenantId, reference: ref, jumperName: booking.jumperName,
      jumpTypeName: jt?.name || 'Saut', slotStart: booking.slotStart, options: [], weightDeclaredKg: booking.weightDeclaredKg,
      waiverSigned: true, checkedInAt: null,
    })
    v.status = 'reserved'; v.bookingId = booking.id
    return delay({ booking: enrichBooking(booking), voucher: enrichVoucher(v) }, 520)
  },
  async extendVoucher(code) {
    const v = db.vouchers.find((x) => x.code.toUpperCase() === String(code).trim().toUpperCase())
    if (!v) throw new NotFound('Cheque cadeau introuvable')
    const tenant = db.tenants.find((t) => t.id === v.tenantId)
    const opt = tenant?.extensionOption
    if (!opt?.available) throw new Conflict("La prolongation n'est pas proposee par ce centre.")
    const base = new Date(Math.max(Date.now(), new Date(v.expiresAt).getTime()))
    base.setMonth(base.getMonth() + opt.addedMonths)
    v.expiresAt = base.toISOString(); v.status = 'issued'
    return delay({ voucher: enrichVoucher(v), price: opt.price, addedMonths: opt.addedMonths }, 680)
  },

  // ===========================================================================
  // ADMIN DROPZONE (back-office centre)
  // ===========================================================================
  async adminLogin(email, password) {
    const a = db.admins.find((x) => x.email.toLowerCase() === String(email).toLowerCase() && x.password === password)
    if (!a) throw new Unauthorized('Email ou mot de passe incorrect.')
    const tenant = db.tenants.find((t) => t.id === a.tenantId)
    return delay({ admin: publicUser(a), tenant }, 400)
  },

  async getAdminOverview(tenantId) {
    const bookings = db.bookings.filter((b) => b.tenantId === tenantId)
    const now = Date.now()
    const upcoming = bookings.filter((b) => ['confirmed'].includes(b.status) && new Date(b.slotStart) >= now)
    const vouchers = db.vouchers.filter((v) => v.tenantId === tenantId)
    const orders = db.orders.filter((o) => o.tenantId === tenantId)
    const revenue = orders.filter((o) => o.status === 'paid').reduce((s, o) => s + o.total, 0)
    const tenant = db.tenants.find((t) => t.id === tenantId)
    return delay({
      currency: tenant?.currency || 'USD',
      upcomingCount: upcoming.length,
      completedCount: bookings.filter((b) => b.status === 'completed').length,
      postponedCount: bookings.filter((b) => b.status === 'postponed').length,
      revenue,
      ordersCount: orders.length,
      vouchers: {
        issued: vouchers.filter((v) => v.status === 'issued').length,
        reserved: vouchers.filter((v) => v.status === 'reserved').length,
        used: vouchers.filter((v) => v.status === 'used').length,
        expired: vouchers.filter((v) => v.status === 'expired').length,
      },
      jumpTypesCount: cat(tenantId)?.jumpTypes.length || 0,
      optionsCount: cat(tenantId)?.options.length || 0,
    }, 300)
  },

  // --- Produits : types de saut ---
  async createJumpType(tenantId, data) {
    const c = cat(tenantId)
    if (!c) throw new NotFound('Centre introuvable')
    const jt = {
      id: genId('jt'), tenantId, name: data.name || 'Nouveau saut', summary: data.summary || '',
      description: data.description || '', basePrice: Number(data.basePrice) || 0, durationMin: Number(data.durationMin) || 20,
      capacityPerSlot: Number(data.capacityPerSlot) || 6, image: data.image || 'https://images.unsplash.com/photo-1517649763962-0c623066013b?auto=format&fit=crop&w=1200&q=70',
      popular: !!data.popular, eligibility: data.eligibility || defaultEligibility(),
    }
    c.jumpTypes.push(jt)
    invalidateSlots(tenantId)
    return delay(jt, 400)
  },
  async updateJumpType(tenantId, id, patch) {
    const jt = cat(tenantId)?.jumpTypes.find((j) => j.id === id)
    if (!jt) throw new NotFound('Type de saut introuvable')
    Object.assign(jt, patch)
    invalidateSlots(tenantId)
    return delay(jt, 350)
  },
  async deleteJumpType(tenantId, id) {
    const c = cat(tenantId)
    const idx = c?.jumpTypes.findIndex((j) => j.id === id)
    if (idx == null || idx < 0) throw new NotFound('Type de saut introuvable')
    c.jumpTypes.splice(idx, 1)
    invalidateSlots(tenantId)
    return delay({ ok: true }, 300)
  },
  async getStaffMembers(tenantId) {
    return delay(db.staffMembers[tenantId] || [])
  },
  async createStaffMember(tenantId, data) {
    db.staffMembers[tenantId] ||= []
    const member = { id: genId('staff'), active: true, bookable: true, serviceCodes: [], workingHours: {}, ...data }
    db.staffMembers[tenantId].push(member)
    return delay(member)
  },
  async updateStaffMember(tenantId, id, data) {
    const member = (db.staffMembers[tenantId] || []).find((item) => item.id === id)
    if (!member) throw new NotFound('Collaborateur introuvable')
    Object.assign(member, data)
    return delay(member)
  },
  async archiveStaffMember(tenantId, id) {
    const member = (db.staffMembers[tenantId] || []).find((item) => item.id === id)
    if (!member) throw new NotFound('Collaborateur introuvable')
    member.active = false
    member.bookable = false
    return delay({ ok: true })
  },

  // --- Produits : options / upsells ---
  async createOption(tenantId, data) {
    const c = cat(tenantId)
    if (!c) throw new NotFound('Centre introuvable')
    const opt = {
      id: genId('opt'), tenantId, name: data.name || 'Nouvelle option', description: data.description || '',
      price: Number(data.price) || 0, scope: data.scope === 'PER_ORDER' ? 'PER_ORDER' : 'PER_JUMP',
      mandatory: !!data.mandatory, maxQuantity: Number(data.maxQuantity) || 1,
      linkedJumpTypeIds: Array.isArray(data.linkedJumpTypeIds) ? data.linkedJumpTypeIds : [],
    }
    c.options.push(opt)
    return delay(opt, 400)
  },
  async updateOption(tenantId, id, patch) {
    const opt = cat(tenantId)?.options.find((o) => o.id === id)
    if (!opt) throw new NotFound('Option introuvable')
    Object.assign(opt, patch)
    return delay(opt, 350)
  },
  async deleteOption(tenantId, id) {
    const c = cat(tenantId)
    const idx = c?.options.findIndex((o) => o.id === id)
    if (idx == null || idx < 0) throw new NotFound('Option introuvable')
    c.options.splice(idx, 1)
    return delay({ ok: true }, 300)
  },

  // --- Horaires (schedule) ---
  async getSchedule(tenantId) {
    return delay(db.schedules[tenantId] || { openDays: [], times: [], capacity: 0 })
  },
  async updateSchedule(tenantId, patch) {
    db.schedules[tenantId] = { ...db.schedules[tenantId], ...patch }
    invalidateSlots(tenantId)
    return delay(db.schedules[tenantId], 400)
  },

  // --- Reservations (vue centre + actions) ---
  async getTenantBookings(tenantId) {
    return delay(
      db.bookings
        .filter((b) => b.tenantId === tenantId)
        .map(enrichBooking)
        .sort((a, b) => new Date(a.slotStart) - new Date(b.slotStart)),
    )
  },
  async getTenantVouchers(tenantId) {
    return delay(db.vouchers.filter((v) => v.tenantId === tenantId).map(enrichVoucher))
  },
  async rescheduleBooking(bookingId, slotId) {
    const b = db.bookings.find((x) => x.id === bookingId)
    if (!b) throw new NotFound('Reservation introuvable')
    const slots = tenantSlots(b.tenantId)
    // Libere l'ancien creneau si retrouve.
    const old = slots.find((s) => s.start === b.slotStart)
    if (old && old.booked > 0) { old.booked -= 1; old.remaining += 1 }
    const next = slots.find((s) => s.id === slotId)
    if (!next || next.remaining <= 0) throw new Conflict('Ce creneau est complet.')
    next.booked += 1; next.remaining -= 1
    b.slotStart = next.start; b.slotEnd = next.end; b.status = 'confirmed'; b.postponedReason = undefined
    const bp = db.boardingPasses.find((x) => x.bookingId === b.id)
    if (bp) bp.slotStart = next.start
    return delay(enrichBooking(b), 450)
  },
  async postponeBooking(bookingId, reason) {
    const b = db.bookings.find((x) => x.id === bookingId)
    if (!b) throw new NotFound('Reservation introuvable')
    b.status = 'postponed'
    b.postponedReason = reason || 'Report meteo. Nouvelle date a choisir.'
    // Libere le creneau et prolonge l'echeance du cheque le cas echeant.
    const slots = tenantSlots(b.tenantId)
    const old = slots.find((s) => s.start === b.slotStart)
    if (old && old.booked > 0) { old.booked -= 1; old.remaining += 1 }
    if (b.voucherCode) {
      const v = db.vouchers.find((x) => x.code === b.voucherCode)
      const tenant = db.tenants.find((t) => t.id === b.tenantId)
      if (v) {
        const base = new Date(Math.max(Date.now(), new Date(v.expiresAt).getTime()))
        base.setDate(base.getDate() + (tenant?.weatherHoldExtraDays || 30))
        v.expiresAt = base.toISOString()
      }
    }
    return delay(enrichBooking(b), 400)
  },
  async completeBooking(bookingId) {
    const b = db.bookings.find((x) => x.id === bookingId)
    if (!b) throw new NotFound('Reservation introuvable')
    b.status = 'completed'
    const bp = db.boardingPasses.find((x) => x.bookingId === b.id)
    if (bp && !bp.checkedInAt) bp.checkedInAt = new Date().toISOString()
    if (b.voucherCode) {
      const v = db.vouchers.find((x) => x.code === b.voucherCode)
      if (v) v.status = 'used'
    }
    return delay(enrichBooking(b), 380)
  },
  async cancelBooking(bookingId) {
    const b = db.bookings.find((x) => x.id === bookingId)
    if (!b) throw new NotFound('Reservation introuvable')
    b.status = 'cancelled'
    const slots = tenantSlots(b.tenantId)
    const old = slots.find((s) => s.start === b.slotStart)
    if (old && old.booked > 0) { old.booked -= 1; old.remaining += 1 }
    return delay(enrichBooking(b), 380)
  },
  async refundBooking(bookingId) {
    const b = db.bookings.find((x) => x.id === bookingId)
    if (!b) throw new NotFound('Reservation introuvable')
    b.status = 'cancelled'
    if (b.orderId) {
      const o = db.orders.find((x) => x.id === b.orderId)
      if (o) o.status = 'refunded'
    }
    const slots = tenantSlots(b.tenantId)
    const old = slots.find((s) => s.start === b.slotStart)
    if (old && old.booked > 0) { old.booked -= 1; old.remaining += 1 }
    return delay(enrichBooking(b), 450)
  },

  // --- Moyens de paiement ---
  async getPaymentMethods(tenantId) {
    return delay(db.paymentMethods[tenantId] || [])
  },
  async updatePaymentMethod(tenantId, id, patch) {
    const pm = (db.paymentMethods[tenantId] || []).find((p) => p.id === id)
    if (!pm) throw new NotFound('Moyen de paiement introuvable')
    Object.assign(pm, patch)
    return delay(pm, 320)
  },
  async addPaymentMethod(tenantId, data) {
    db.paymentMethods[tenantId] ||= []
    const pm = { id: genId('pm'), type: data.type || 'stripe', label: data.label || 'Nouveau moyen', enabled: !!data.enabled, publicKey: data.publicKey || '', account: data.account || '' }
    db.paymentMethods[tenantId].push(pm)
    return delay(pm, 350)
  },
  async deletePaymentMethod(tenantId, id) {
    const list = db.paymentMethods[tenantId] || []
    const idx = list.findIndex((p) => p.id === id)
    if (idx < 0) throw new NotFound('Moyen de paiement introuvable')
    list.splice(idx, 1)
    return delay({ ok: true }, 280)
  },
}

// --- Helpers ---------------------------------------------------------------
function publicUser(u) {
  const { password, ...rest } = u
  return rest
}
function byDateDesc(key) {
  return (a, b) => new Date(b[key]) - new Date(a[key])
}

// --- Erreurs typees --------------------------------------------------------
class ApiError extends Error {
  constructor(message, status) { super(message); this.status = status; this.name = 'ApiError' }
}
class NotFound extends ApiError { constructor(m) { super(m, 404) } }
class Unauthorized extends ApiError { constructor(m) { super(m, 401) } }
class Conflict extends ApiError { constructor(m) { super(m, 409) } }
