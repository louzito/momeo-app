// =============================================================================
// VRAIE API — client HTTP vers Sylius (API Platform / Shop API v2)
// -----------------------------------------------------------------------------
// Pattern "strangler" pour les fonctions encore en migration. Le catalogue
// public (canal, configuration, produits et options) est toutefois strictement
// branche sur Sylius : aucune fixture ne peut prendre le relais en cas d'echec.
//
// Branche pour l'instant : le CATALOGUE. Les "types de saut" du front sont, a ce
// stade, les PRODUITS Sylius (boutique de demo). Mapping cale sur la forme reelle
// de GET /api/v2/shop/products (verifiee en direct) :
//   - name, slug, shortDescription, description
//   - defaultVariantData.price  -> prix en CENTIMES
//   - images[].path             -> URL absolue deja prete (filtre LiipImagine)
//
// Le reste (creneaux, cheques cadeaux, back-office) reste en mock tant que les
// endpoints metier sur mesure (plugin Sylius) n'existent pas.
// =============================================================================

import { mockApi } from '@/mocks/mockApi'
import { API_BASE, TENANT_SLUG, displayImageUrl, isServiceProductCode, tenantHeaders } from './config'
import * as sylius from './adminApi'
import { customerRequest } from './customerAuth'

// --- client HTTP minimal ---------------------------------------------------
async function apiGet(path, params = {}) {
  const origin = typeof window !== 'undefined' ? window.location.origin : 'http://localhost:5173'
  const url = new URL(API_BASE + path, origin)
  Object.entries(params).forEach(([k, v]) => {
    if (v !== undefined && v !== null) url.searchParams.set(k, v)
  })
  const res = await fetch(url.toString(), { headers: tenantHeaders({ Accept: 'application/ld+json' }) })
  const text = await res.text()
  const data = text ? JSON.parse(text) : null
  if (!res.ok) {
    const msg = data?.['hydra:description'] || data?.detail || data?.error || `API ${res.status} sur ${path}`
    const err = new Error(msg)
    err.status = res.status
    throw err
  }
  return data
}

// Ecritures sur le SHOP API (panier / checkout) — public, sans auth.
// PATCH -> content-type merge-patch impose par API Platform.
async function apiWrite(method, path, body = {}) {
  const contentType = method === 'PATCH' ? 'application/merge-patch+json' : 'application/ld+json'
  const res = await fetch(API_BASE + path, {
    method,
    headers: tenantHeaders({ Accept: 'application/ld+json', 'Content-Type': contentType }),
    body: JSON.stringify(body),
  })
  const text = await res.text()
  const data = text ? JSON.parse(text) : null
  if (!res.ok) {
    // hydra:description/detail -> erreurs API Platform ; error -> nos
    // controleurs custom (ShopGiftOrderMarkerController et consorts).
    const msg = data?.['hydra:description'] || data?.detail || data?.error || `API ${res.status} sur ${path}`
    const err = new Error(msg)
    err.status = res.status
    throw err
  }
  return data
}

async function createPersistentBooking(payload, commercial = {}) {
  if (!payload.slot?.start || !payload.slot?.end) {
    throw new Error('Le créneau sélectionné est incomplet.')
  }
  return apiWrite('POST', '/shop/bookings', {
    source: commercial.source || 'direct',
    serviceCode: payload.jumpTypeId,
    serviceName: payload.jumpTypeName,
    planningCode: payload.slot.planningCode || null,
    resourceCode: payload.slot.resourceCode || null,
    staffMemberId: payload.slot.staffMemberId || null,
    start: payload.slot.start,
    end: payload.slot.end,
    customer: {
      firstName: payload.jumper?.firstName || '',
      lastName: payload.jumper?.lastName || '',
      email: payload.jumper?.email || '',
      phone: payload.jumper?.phone || '',
      smsReminderConsent: payload.jumper?.smsReminderConsent === true,
      notes: payload.jumper?.notes || '',
    },
    options: (payload.options || []).map((option) => ({ name: option.name, price: option.price })),
    orderNumber: commercial.orderNumber || null,
    orderToken: commercial.orderToken || null,
    voucherCode: commercial.voucherCode || null,
  })
}

// GiftVoucher (App\Controller\ShopGiftVoucherApiController::normalize()) ->
// forme voucher attendue par le front (espace beneficiaire, VoucherCard...).
function mapVoucherFromApi(v) {
  return {
    code: v.code,
    status: v.status, // awaiting_payment | active | used | expired
    jumpTypeId: v.serviceCode ?? v.jumpTypeCode,
    jumpTypeName: v.serviceName ?? v.jumpTypeName,
    amount: (v.amount ?? 0) / 100, // Sylius/GiftVoucher stocke en centimes
    currency: v.currencyCode,
    beneficiaryName: v.beneficiaryName,
    beneficiaryEmail: v.beneficiaryEmail,
    personalMessage: v.personalMessage,
    purchaserName: v.purchaserName,
    expiresAt: v.expiresAt,
    booking: v.booking
      ? { reference: v.booking.reference, jumpTypeName: v.booking.jumpTypeName, slotStart: v.booking.slotStart, slotEnd: v.booking.slotEnd }
      : null,
  }
}

// GiftVoucher vu par l'admin (App\Controller\AdminGiftVoucherApiController) ->
// forme attendue par AdminVouchers.vue / le tableau de bord centre.
function mapAdminVoucherFromApi(v) {
  return {
    code: v.code,
    status: v.status, // awaiting_payment | active | used | expired
    jumpTypeId: v.serviceCode ?? v.jumpTypeCode,
    jumpTypeName: v.serviceName ?? v.jumpTypeName,
    amount: (v.amount ?? 0) / 100,
    currency: v.currencyCode,
    purchaserName: v.purchaserName,
    purchaserEmail: v.purchaserEmail,
    beneficiaryName: v.beneficiaryName,
    beneficiaryEmail: v.beneficiaryEmail,
    personalMessage: v.personalMessage,
    purchaseOrderNumber: v.purchaseOrderNumber,
    usageOrderNumber: v.usageOrderNumber,
    expiresAt: v.expiresAt,
    createdAt: v.createdAt,
    activatedAt: v.activatedAt,
    usedAt: v.usedAt,
  }
}

function membersOf(collection) {
  return collection['hydra:member'] || collection.member || []
}

function imageUrl(images) {
  const list = Array.isArray(images) ? images : []
  const main = list.find((i) => i.type === 'main') || list[0]
  if (!main?.path) return ''
  return displayImageUrl(main.path)
}

// Valeurs par defaut d'une regle d'eligibilite (utilisees quand le produit n'a
// pas encore l'attribut correspondant renseigne dans Sylius).
function defaultEligibility() {
  return {
    ageMin: 18, ageMax: 70, weightMaxKg: 100, heightMinCm: 140, bmiMax: null,
    medicalCertificateRequired: false, waiverRequired: true, customRules: [],
  }
}

// Type d'association Sylius portant le lien option -> sauts precis.
const JUMP_ASSOC_TYPES = new Set(['todatempo_services', 'skybook_jumps'])

// --- Plannings publics (taxons Sylius, lisibles sans auth) -------------------
const PLANNINGS_ROOTS = ['todatempo_plannings', 'skybook_plannings']

function parsePublicPlanningTaxon(t) {
  let cfg = {}
  try {
    cfg = JSON.parse(t.description || '{}')
  } catch {
    return null
  }
  return {
    code: t.code,
    name: cfg.name || t.name || t.code,
    // v2 : jours dates explicites { "YYYY-MM-DD": ["09:00", ...] }
    days: cfg.days && typeof cfg.days === 'object' && !Array.isArray(cfg.days) ? cfg.days : {},
    // v1 legacy : pattern hebdomadaire
    openDays: Array.isArray(cfg.openDays) ? cfg.openDays : [],
    times: Array.isArray(cfg.times) ? cfg.times : [],
    capacity: Number(cfg.capacity) || 8,
    jumpCodes: Array.isArray(cfg.jumpCodes) ? cfg.jumpCodes : [],
  }
}

// Un taxon desactive n'est pas servi par le shop API (404) -> seuls les
// plannings ACTIFS produisent des creneaux.
async function fetchPublicPlannings() {
  try {
    let root = null
    for (const code of PLANNINGS_ROOTS) {
      try { root = await apiGet(`/shop/taxons/${code}`); break } catch { /* compatibility fallback */ }
    }
    if (!root) return []
    const codes = (root.children || []).map((iri) => String(iri).split('/').pop())
    const taxons = await Promise.all(
      codes.map((c) => apiGet(`/shop/taxons/${encodeURIComponent(c)}`).catch(() => null)),
    )
    return taxons.filter(Boolean).map(parsePublicPlanningTaxon).filter(Boolean)
  } catch {
    return []
  }
}

// Occupation en memoire de session : slotId -> nb de sauts reserves.
// (1 saut = 1 place ; decompte multi-session a venir avec le plugin metier.)
const sessionBookedBySlot = new Map()

// `compatCodes` = codes des sauts compatibles avec ce planning (sauts cibles,
// ou TOUS les sauts si le planning ne cible rien) — utilise par SlotCalendar
// pour griser les creneaux incompatibles avec le saut selectionne.
// Deux formats : v2 = jours dates explicites (p.days, edite au calendrier
// annuel), v1 legacy = pattern hebdo (openDays + times). Horizon d'affichage
// cote client : `daysAhead` jours.
function makeSlot(p, tenantId, compatCodes, dateStr, time) {
  const [hh, mm] = String(time).split(':').map(Number)
  if (!Number.isFinite(hh)) return null
  const start = new Date(`${dateStr}T00:00:00`)
  if (Number.isNaN(start.getTime())) return null
  start.setHours(hh, mm || 0, 0, 0)
  if (start.getTime() < Date.now()) return null
  const end = new Date(start)
  end.setMinutes(end.getMinutes() + 90)
  const id = `slot_${p.code}_${dateStr}_${String(time).replace(':', '')}`
  const booked = sessionBookedBySlot.get(id) || 0
  return {
    id,
    tenantId,
    planningCode: p.code,
    start: start.toISOString(),
    end: end.toISOString(),
    capacity: p.capacity,
    booked,
    remaining: Math.max(0, p.capacity - booked),
    compatibleJumpTypeIds: compatCodes,
    instructor: p.name, // affiche sous l'heure : le nom du planning
  }
}

function generatePlanningSlots(p, tenantId, compatCodes = [], daysAhead = 45) {
  const slots = []
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  const horizon = new Date(today)
  horizon.setDate(horizon.getDate() + daysAhead)

  if (p.days && Object.keys(p.days).length) {
    // v2 : jours dates explicites
    for (const [dateStr, times] of Object.entries(p.days)) {
      const day = new Date(`${dateStr}T00:00:00`)
      if (Number.isNaN(day.getTime()) || day > horizon) continue
      for (const time of times || []) {
        const s = makeSlot(p, tenantId, compatCodes, dateStr, time)
        if (s) slots.push(s)
      }
    }
    return slots
  }

  // v1 legacy : pattern hebdomadaire
  for (let d = 0; d < daysAhead; d++) {
    const day = new Date(today)
    day.setDate(today.getDate() + d)
    if (!p.openDays.includes(day.getDay())) continue
    const dateStr = `${day.getFullYear()}-${String(day.getMonth() + 1).padStart(2, '0')}-${String(day.getDate()).padStart(2, '0')}`
    for (const time of p.times) {
      const s = makeSlot(p, tenantId, compatCodes, dateStr, time)
      if (s) slots.push(s)
    }
  }
  return slots
}

// Resout, pour une liste de produits option, la liaison vers des sauts precis.
// Les produits shop exposent `associations` (IRIs). On resout chaque IRI via
// /shop/product-associations/{id} (public, sans auth) et on ne garde que les
// associations de type skybook_jumps -> Map(code option -> [codes de saut]).
async function resolveOptionLinks(products) {
  const map = new Map()
  const jobs = []
  for (const p of products) {
    map.set(p.code, [])
    const iris = Array.isArray(p.associations) ? p.associations : []
    for (const iri of iris) {
      const id = String(iri).split('/').pop()
      jobs.push(
        apiGet(`/shop/product-associations/${id}`)
          .then((a) => {
            if (!JUMP_ASSOC_TYPES.has(String(a?.type).split('/').pop())) return
            const codes = (a.associatedProducts || []).map((x) => String(x).split('/').pop())
            map.set(p.code, [...(map.get(p.code) || []), ...codes])
          })
          .catch(() => {}),
      )
    }
  }
  await Promise.all(jobs)
  return map
}

// Sylius product (code opt_...) -> option attendue par le front.
function mapProductToOption(p, tenantId, linkedJumpTypeIds = []) {
  const code = p.code || ''
  const scope = code.startsWith('opt_po_') ? 'PER_ORDER' : 'PER_JUMP'
  const priceCents = p.defaultVariantData?.price ?? 0
  return {
    id: code,
    tenantId,
    name: p.name || code,
    description: p.shortDescription || '',
    price: priceCents / 100,
    scope,
    mandatory: false,
    maxQuantity: 1,
    // Sauts precis cibles par l'option (vide = proposee sur tous les sauts).
    linkedJumpTypeIds,
  }
}

// --- Attributs de saut (caracteristiques + regles d'eligibilite) -------------
// Lus sur le shop API : GET /shop/products/{code}/attributes renvoie les
// ProductAttributeValue avec `code` + `value` (crees via l'admin front, types
// provisionnes par back/skybook-provision.mjs).
const JUMP_ATTRIBUTE_FIELDS = {
  jump_altitude: 'altitudeM',
  jump_duration: 'durationMin',
  jump_capacity: 'capacityPerSlot',
  jump_age_min: 'ageMin',
  jump_age_max: 'ageMax',
  jump_weight_max: 'weightMaxKg',
  jump_height_min: 'heightMinCm',
  jump_bmi_max: 'bmiMax',
  jump_medical_cert: 'medicalCertificateRequired',
  jump_waiver: 'waiverRequired',
  todatempo_duration: 'durationMin',
  todatempo_capacity: 'capacityPerSlot',
  todatempo_requirements: 'requirements',
  todatempo_payment_mode: 'paymentMode',
  todatempo_payment_value: 'paymentValue',
  momeo_duration: 'durationMin', // read-only compatibility
  momeo_capacity: 'capacityPerSlot',
  momeo_requirements: 'requirements',
}
const BOOL_ATTRIBUTES = new Set(['jump_medical_cert', 'jump_waiver'])

async function fetchJumpAttributes(code) {
  try {
    const data = await apiGet(`/shop/products/${encodeURIComponent(code)}/attributes`)
    const out = {}
    const values = membersOf(data)
    // Legacy first, canonical second: the TodaTempo value always wins even if
    // the API changes collection ordering.
    values.sort((a, b) => Number(String(a.code).startsWith('todatempo_')) - Number(String(b.code).startsWith('todatempo_')))
    for (const av of values) {
      const field = JUMP_ATTRIBUTE_FIELDS[av.code]
      if (!field || av.value == null) continue
      if (av.code === 'todatempo_requirements' || av.code === 'momeo_requirements') {
        try {
          const parsed = JSON.parse(String(av.value || '[]'))
          out[field] = Array.isArray(parsed) ? parsed.filter((item) => item?.key && item?.label) : []
        } catch {
          out[field] = []
        }
      } else if (av.code === 'todatempo_payment_mode') {
        out[field] = String(av.value)
      } else {
        out[field] = BOOL_ATTRIBUTES.has(av.code) ? !!av.value : Number(av.value)
      }
    }
    return out
  } catch {
    return {} // pas bloquant : on retombe sur les valeurs par defaut
  }
}

// Regle d'eligibilite du saut = attributs produit reels, completes par les
// defauts pour tout ce qui n'est pas renseigne.
function buildEligibility(attrs = {}) {
  const d = defaultEligibility()
  return {
    ageMin: attrs.ageMin ?? d.ageMin,
    ageMax: attrs.ageMax ?? d.ageMax,
    weightMaxKg: attrs.weightMaxKg ?? d.weightMaxKg,
    heightMinCm: attrs.heightMinCm ?? d.heightMinCm,
    bmiMax: attrs.bmiMax ?? d.bmiMax,
    medicalCertificateRequired: attrs.medicalCertificateRequired ?? d.medicalCertificateRequired,
    waiverRequired: attrs.waiverRequired ?? d.waiverRequired,
    customRules: [],
  }
}

// Sylius product -> jumpType attendu par le front.
// `attrs` = valeurs reelles des attributs de saut (fetchJumpAttributes) ;
// les defauts ne servent que si l'attribut n'est pas renseigne sur le produit.
function mapProductToJumpType(p, tenantId, attrs = {}) {
  const variant = p.defaultVariantData || {}
  const priceCents = typeof variant.price === 'number' ? variant.price : 0
  return {
    id: p.code,
    tenantId,
    name: p.name || p.code,
    summary: p.shortDescription || '',
    description: p.description || '',
    basePrice: priceCents / 100, // Sylius stocke les montants en centimes
    altitudeM: attrs.altitudeM ?? null,
    durationMin: attrs.durationMin ?? 20,
    capacityPerSlot: attrs.capacityPerSlot ?? 6,
    image: imageUrl(p.images),
    popular: false,
    legacyEligibility: String(p.code || '').startsWith('jump_'),
    requirements: attrs.requirements || [],
    paymentMode: attrs.paymentMode || 'full',
    paymentValue: attrs.paymentMode === 'fixed' ? (attrs.paymentValue || 0) / 100 : (attrs.paymentValue || 0),
    eligibility: buildEligibility(attrs),
  }
}

function mapPhysicalProduct(p, tenantId) {
  const variant = p.defaultVariantData || {}
  return {
    id: p.code, tenantId, type: 'physical', name: p.name || p.code,
    summary: p.shortDescription || '', description: p.description || '', image: imageUrl(p.images),
    price: (variant.price || 0) / 100,
    stock: Math.max(0, Number(variant.onHand ?? p.onHand ?? 0) - Number(variant.onHold ?? 0)),
    pickupEnabled: !!p.pickupEnabled,
    deliveryEnabled: !!p.deliveryEnabled,
    deliveryFee: (p.deliveryFee || 0) / 100,
  }
}

async function buildAdminSession(identity = {}) {
  const [channel, shopConfig] = await Promise.all([
    httpApi.getShopChannel(),
    httpApi.getPublicShopConfig(),
  ])
  const email = identity.email || ''
  const name = identity.name || email.split('@')[0] || 'Propriétaire'

  return {
    admin: {
      id: `admin_${TENANT_SLUG}`,
      name,
      email,
      role: identity.role || 'practitioner',
      permissions: identity.permissions || [],
      staffMemberId: identity.staffMemberId || null,
    },
    tenant: {
      id: `workspace_${TENANT_SLUG}`,
      slug: TENANT_SLUG,
      name: shopConfig?.name || channel?.name || 'Mon établissement',
      currency: channel?.currency || 'EUR',
      city: shopConfig?.address?.city || '',
      email: shopConfig?.contactEmail || email,
    },
  }
}

// --- API reelle (strangler sur mockApi) ------------------------------------
export const httpApi = {
  // Tout le reste (tenants, creneaux, cheques, commandes, admin...) reste en mock.
  ...mockApi,

  async register({ email, password, firstName, lastName, phone }) {
    return customerRequest('POST', '/shop/customers', {
      email: String(email || '').trim(), plainPassword: password,
      firstName: String(firstName || '').trim(), lastName: String(lastName || '').trim(),
      phoneNumber: String(phone || '').trim() || null,
    }, { auth: false })
  },

  async requestPasswordReset(email) {
    return customerRequest('POST', '/shop/customers/password-reset-requests', { email: String(email || '').trim() }, { auth: false })
  },

  async getCustomerOrders() {
    const data = await customerRequest('GET', '/shop/account/orders')
    return data.member || []
  },

  async getCustomerBookings() {
    const data = await customerRequest('GET', '/shop/account/bookings')
    return data.member || []
  },

  async cancelCustomerBooking(bookingId) {
    return customerRequest('POST', `/shop/account/bookings/${encodeURIComponent(bookingId)}/cancel`, {})
  },

  async rescheduleCustomerBooking(bookingId, slot) {
    return customerRequest('POST', `/shop/account/bookings/${encodeURIComponent(bookingId)}/reschedule`, {
      planningCode: slot.planningCode, resourceCode: slot.resourceCode || null,
      staffMemberId: slot.staffMemberId, start: slot.start, end: slot.end,
    })
  },

  // --- CATALOGUE : branche sur Sylius ---
  async getJumpTypes(tenantId) {
    const data = await apiGet('/shop/products', { itemsPerPage: 100 })
    let items = membersOf(data)
    items = items.filter((p) => isServiceProductCode(p.code))
    // Attributs reels (altitude, duree, capacite) en parallele — 1 requete par
    // saut, acceptable a l'echelle d'un catalogue de centre (< 20 sauts).
    const attrs = await Promise.all(items.map((p) => fetchJumpAttributes(p.code)))
    return items.map((p, i) => mapProductToJumpType(p, tenantId, attrs[i]))
  },

  async getPhysicalProducts(tenantId) {
    const data = await apiGet('/shop/physical-products')
    return membersOf(data).map((p) => mapPhysicalProduct(p, tenantId))
  },
  async createPhysicalProduct(tenantId, data) { return sylius.createPhysicalProduct(data) },
  async updatePhysicalProduct(tenantId, code, data) { return sylius.updatePhysicalProduct(code, data) },
  async deletePhysicalProduct(tenantId, code) { return sylius.deletePhysicalProduct(code) },

  async getJumpType(tenantId, jumpTypeId) {
    // jumpTypeId = code produit Sylius (ex. "jump_tandem_discovery")
    const [p, attrs] = await Promise.all([
      apiGet(`/shop/products/${encodeURIComponent(jumpTypeId)}`),
      fetchJumpAttributes(jumpTypeId),
    ])
    return mapProductToJumpType(p, tenantId, attrs)
  },

  // Les disponibilites sont integralement calculees par le backend.
  async getSlots(tenantId, { jumpTypeId = null } = {}) {
    const serviceCodes = jumpTypeId
      ? [jumpTypeId]
      : (await this.getJumpTypes(tenantId)).map((service) => service.id)
    const responses = await Promise.all(serviceCodes.map((serviceCode) =>
      apiGet('/shop/availability', { serviceCode }),
    ))
    return responses.flatMap((availability) => availability.member || [])
  },

  async joinWaitlist(tenantId, data) {
    return apiWrite('POST', '/shop/waitlist', data)
  },

  async leaveWaitlist(token) {
    return apiWrite('POST', `/shop/waitlist/${encodeURIComponent(token)}/unsubscribe`)
  },

  // --- ELIGIBILITE : regle REELLE du saut (attributs produit Sylius) ---------
  // La regle vient des attributs jump_age_min / jump_age_max / jump_weight_max /
  // jump_height_min / jump_bmi_max / jump_medical_cert / jump_waiver du produit
  // (edites dans l'espace centre), completes par les defauts. Evaluation cote
  // front tant qu'il n'y a pas d'endpoint metier dedie.
  async checkEligibility(tenantId, jumpTypeId, form) {
    const attrs = await fetchJumpAttributes(jumpTypeId)
    const r = buildEligibility(attrs)
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
    // IMC (poids / taille^2) : verifie seulement si la regle le fixe ET que
    // poids + taille sont renseignes.
    if (r.bmiMax && Number.isFinite(weight) && Number.isFinite(height) && height > 0) {
      const bmi = weight / Math.pow(height / 100, 2)
      if (bmi > r.bmiMax)
        violations.push(
          `IMC maximum : ${r.bmiMax} (declare : ${Math.round(bmi * 10) / 10}). ` +
            'Contactez le centre pour etudier votre situation.',
        )
    }
    if (r.medicalCertificateRequired && !form.medicalCertificate)
      violations.push('Un justificatif médical est obligatoire pour cette prestation.')
    if (r.waiverRequired && !form.waiverAccepted)
      violations.push('La decharge de responsabilite doit etre signee.')
    return { eligible: violations.length === 0, violations, rule: r }
  },

  // --- MOYENS DE PAIEMENT ----------------------------------------------------
  // Espace centre : liste + edition des payment-methods Sylius (activation,
  // libelle, instructions de reglement). Necessite le JWT admin (present dans
  // l'espace centre). Ajout / suppression : pas encore expose (les methodes
  // sont garanties par le provisionnement ; Stripe & co viendront avec Payum).
  async getPaymentMethods() {
    return sylius.getPaymentMethods()
  },
  async updatePaymentMethod(tenantId, code, patch) {
    return sylius.updatePaymentMethod(code, patch)
  },
  async addPaymentMethod() {
    throw new Error(
      "L'ajout de passerelles (Stripe, PayPal...) n'est pas encore disponible — les moyens actuels sont geres automatiquement.",
    )
  },
  async deletePaymentMethod() {
    throw new Error('La suppression passe par la desactivation (toggle) pour l\'instant.')
  },

  // --- PLANNINGS : CRUD depuis l'espace centre (taxons Sylius) ---------------
  async getPlannings() {
    return sylius.getPlannings()
  },
  async createPlanning(tenantId, data) {
    return sylius.createPlanning(data)
  },
  async updatePlanning(tenantId, code, data) {
    return sylius.updatePlanning(code, data)
  },
  async deletePlanning(tenantId, code) {
    return sylius.deletePlanning(code)
  },

  async getBookableResources() {
    return sylius.getBookableResources()
  },
  async createBookableResource(data) {
    return sylius.createBookableResource(data)
  },
  async updateBookableResource(code, data) {
    return sylius.updateBookableResource(code, data)
  },
  async deleteBookableResource(code) {
    return sylius.deleteBookableResource(code)
  },
  async getServiceBookableResources(code) {
    return sylius.getServiceBookableResources(code)
  },
  async setServiceBookableResources(code, data) {
    return sylius.setServiceBookableResources(code, data)
  },

  // Commandes Sylius vues par le centre (espace admin front). Sert notamment a
  // encaisser les virements : getSyliusOrders({paymentState:'awaiting_payment'})
  // puis markOrderPaid(paymentId) quand le virement arrive sur le compte.
  async getSyliusOrders(filter = {}) {
    return sylius.getOrders(filter)
  },
  async getSyliusOrder(tokenValue) {
    return sylius.getOrder(tokenValue)
  },
  async markOrderPaid(paymentId) {
    return sylius.completePayment(paymentId)
  },
  async refundPayment(paymentId, amount, reason, idempotencyKey) {
    return sylius.refundPayment(paymentId, amount, reason, idempotencyKey)
  },
  async getPaymentRefunds(paymentId) {
    return sylius.getPaymentRefunds(paymentId)
  },
  async updatePreparationState(tokenValue, state) { return sylius.updatePreparationState(tokenValue, state) },

  // Cheques cadeaux REELS vus par le centre (Phase 3) — liste + stats pour
  // AdminVouchers.vue et le tableau de bord.
  async getAdminVouchers(tenantId, { status = null } = {}) {
    const { member, stats } = await sylius.getGiftVouchers({ status })
    return { vouchers: member.map(mapAdminVoucherFromApi), stats }
  },

  // Upload / remplacement de l'image d'un produit saut (admin).
  async uploadJumpImage(tenantId, code, file) {
    return sylius.replaceProductImage(code, file)
  },

  // --- CONFIGURATION BOUTIQUE ------------------------------------------------
  // Lecture PUBLIQUE (vitrine) : le taxon skybook_config porte le JSON de
  // config (nom, contact, couleurs, reseaux sociaux) + l'image "logo".
  // Channel Sylius du centre (shop API) : nom + devise reels.
  async getShopChannel() {
    const res = await apiGet('/shop/channels')
    const ch = res['hydra:member']?.[0] || res.member?.[0] || null
    if (!ch) throw new Error("Aucun canal de vente n'est disponible pour cet établissement.")
    const cur = typeof ch.baseCurrency === 'string' ? ch.baseCurrency.split('/').pop() : (ch.baseCurrency?.code || null)
    return { code: ch.code || null, name: ch.name || null, currency: cur }
  },

  async getPublicShopConfig() {
    try {
      let t
      try { t = await apiGet('/shop/taxons/todatempo_config') }
      catch { t = await apiGet('/shop/taxons/skybook_config') }
      let cfg = {}
      try {
        cfg = JSON.parse(t.description || '{}')
      } catch { /* pas encore configure */ }
      const imgOf = (type) => (t.images || []).find((i) => i.type === type)
      return {
        ...cfg,
        logoUrl: displayImageUrl(imgOf('logo')?.path),
        bannerUrl: displayImageUrl(imgOf('banner')?.path),
        bannerMobileUrl: displayImageUrl(imgOf('banner_mobile')?.path),
      }
    } catch (error) {
      if (error.status === 404) return null
      throw error
    }
  },
  // Ecriture (espace centre)
  async getShopConfig() {
    return sylius.getShopConfig()
  },
  async saveShopConfig(tenantId, cfg) {
    return sylius.saveShopConfig(cfg)
  },
  async uploadShopLogo(tenantId, file) {
    return sylius.uploadShopLogo(file)
  },
  async uploadShopImage(tenantId, file, type) {
    return sylius.uploadShopImage(file, type)
  },

  // Factures Sylius (plugin invoicing, si installe) — null = plugin absent.
  async getSyliusInvoices(orderNumber) {
    return sylius.findInvoicesForOrder(orderNumber)
  },
  async downloadSyliusInvoice(invoiceId) {
    return sylius.downloadInvoiceBlob(invoiceId)
  },

  // Cote client (checkout) : methodes actives sur le channel, avec leurs
  // instructions (coordonnees bancaires du virement).
  async getCheckoutPaymentMethods() {
    const data = await apiGet('/shop/payment-methods')
    return membersOf(data).map((m) => ({
      code: m.code,
      name: m.name || m.code,
      description: m.description || '',
      instructions: m.instructions || '',
    }))
  },

  // --- COMMANDE : vrai tunnel Sylius (panier -> checkout -> virement) --------
  // Cree une VRAIE commande Sylius via le shop API quand l'achat est direct et
  // paye par VIREMENT. Etapes (verifiees en direct sur Sylius 2.2) :
  //   1. POST  /shop/orders                    -> tokenValue (panier)
  //   2. POST  /shop/orders/{t}/items          -> saut + options (1 item / produit)
  //   3. PUT   /shop/orders/{t}                -> email invite + adresse de facturation
  //        (nos variantes sont shippingRequired:false -> Sylius SAUTE la livraison,
  //         checkoutState passe direct a "shipping_skipped")
  //   4. PATCH /shop/orders/{t}/payments/{id}  -> methode bank_transfer (merge-patch)
  //   5. PATCH /shop/orders/{t}/complete       -> state=new, paymentState=awaiting_payment
  //
  // Le creneau / le sauteur n'existent pas encore cote Sylius (metier custom a
  // venir) : on les consigne dans les `notes` de la commande, et la reservation
  // front (creneau, carte d'embarquement) reste portee par le mock.
  async createOrder(payload) {
    // Cheque cadeau reel : vraie commande Sylius + GiftVoucher cree par le
    // backend (listener sur checkoutState=completed). Le virement est le SEUL
    // moyen de paiement propose pour un cadeau par le front (voir Payment.vue) ;
    // si ce n'est malgre tout pas le cas on ne cree pas de faux cheque -> mock.
    if (payload.kind === 'gift' && payload.paymentMethod === 'bank_transfer') {
      return this._createGiftOrder(payload)
    }
    if (payload.kind !== 'direct' || !['none', 'bank_transfer', 'stripe_web_elements'].includes(payload.paymentMethod)) {
      throw new Error('Ce moyen de paiement ne permet pas de créer une réservation réelle.')
    }

    // 1. Panier
    const cart = await apiWrite('POST', '/shop/orders', {})
    const t = cart.tokenValue

    // 2. Items : le saut puis chaque option (id = code produit Sylius)
    const productCodes = [payload.jumpTypeId, ...(payload.options || []).map((o) => o.id)]
    for (const code of productCodes) {
      await apiWrite('POST', `/shop/orders/${t}/items`, {
        productVariant: `/api/v2/shop/product-variants/${code}-variant`,
        quantity: 1,
      })
    }

    // 3. Email + adresse de facturation (checkout invite ; adresse minimale tant
    //    que le front ne collecte pas d'adresse — le sauteur, lui, est connu).
    //    Prenom / nom collectes separement dans le tunnel (fallback : decoupage
    //    de l'ancien fullName).
    const fullName = payload.jumper?.fullName?.trim() || 'Client TodaTempo'
    const [splitFirst, ...rest] = fullName.split(/\s+/)
    const firstName = payload.jumper?.firstName?.trim() || splitFirst
    const lastName = payload.jumper?.lastName?.trim() || rest.join(' ') || firstName
    await apiWrite('PUT', `/shop/orders/${t}`, {
      email: payload.jumper?.email || 'client@todatempo.local',
      billingAddress: {
        firstName,
        lastName,
        countryCode: 'US', // pays du channel de demo FASHION_WEB
        street: 'Adresse non collectee',
        city: 'N/A',
        postcode: '00000',
      },
    })

    // 4. Moyen de paiement : virement (gateway offline)
    const addressed = await apiGet(`/shop/orders/${t}`)
    const paymentId = addressed.payments?.[0]?.id
    const paymentTerms = await apiWrite('POST', `/shop/orders/${t}/payment-terms`, {})
    if (paymentTerms.dueNow > 0 && paymentId != null) {
      await apiWrite('PATCH', `/shop/orders/${t}/payments/${paymentId}`, {
        paymentMethod: `/api/v2/shop/payment-methods/${payload.paymentMethod}`,
      })
    }

    // 5. Finalisation, avec le contexte metier en notes
    const slotTxt = payload.slotId ? `creneau ${payload.slotId}` : 'sans creneau'
    const optTxt = (payload.options || []).map((o) => o.name).join(', ') || 'aucune'
    const completed = await apiWrite('PATCH', `/shop/orders/${t}/complete`, {
      notes: `TodaTempo — prestation ${payload.jumpTypeId}, ${slotTxt}, client ${fullName}, options : ${optTxt}`,
    })

    // Instructions de reglement (coordonnees bancaires) configurees par le
    // centre dans son espace admin (payment-method Sylius, champ instructions)
    // -> affichees telles quelles sur la page de confirmation.
    let paymentInstructions = ''
    try {
      const pms = membersOf(await apiGet('/shop/payment-methods'))
      paymentInstructions = pms.find((m) => m.code === 'bank_transfer')?.instructions || ''
    } catch {
      /* non bloquant */
    }

    const booking = await createPersistentBooking(payload, {
      source: 'direct',
      orderNumber: completed.number,
      orderToken: completed.tokenValue,
    })

    return {
      booking,
      order: {
        id: completed.tokenValue,
        number: completed.number,
        total: (completed.total ?? 0) / 100,
        currency: completed.currencyCode,
        status: completed.paymentState || 'awaiting_payment',
        paymentMethod: payload.paymentMethod,
        paymentId,
        orderToken: completed.tokenValue,
        paymentInstructions,
        paymentTerms,
        syliusState: completed.state,
      },
    }
  },

  async createPhysicalOrder(payload) {
    if (!payload.items?.length) throw new Error('Votre panier est vide.')
    const cart = await apiWrite('POST', '/shop/orders', {})
    const token = cart.tokenValue
    for (const item of payload.items) {
      await apiWrite('POST', `/shop/orders/${token}/items`, {
        productVariant: `/api/v2/shop/product-variants/${item.id}-variant`,
        quantity: item.quantity,
      })
    }
    const address = payload.address || {}
    const billingAddress = {
      firstName: address.firstName, lastName: address.lastName,
      countryCode: address.countryCode || 'FR', street: address.street,
      city: address.city, postcode: address.postcode,
    }
    await apiWrite('PUT', `/shop/orders/${token}`, {
      email: payload.email, billingAddress,
      ...(payload.mode === 'delivery' ? { shippingAddress: billingAddress } : {}),
    })
    await apiWrite('PATCH', `/shop/orders/${token}/physical-fulfillment`, { mode: payload.mode })
    let addressed = await apiGet(`/shop/orders/${token}`)
    for (const shipment of addressed.shipments || []) {
      const shipmentId = shipment.id ?? String(shipment['@id'] || shipment).split('/').pop()
      await apiWrite('PATCH', `/shop/orders/${token}/shipments/${shipmentId}`, {
        shippingMethod: '/api/v2/shop/shipping-methods/standard',
      })
    }
    addressed = await apiGet(`/shop/orders/${token}`)
    const paymentId = addressed.payments?.[0]?.id
    if (paymentId != null) {
      await apiWrite('PATCH', `/shop/orders/${token}/payments/${paymentId}`, {
        paymentMethod: `/api/v2/shop/payment-methods/${payload.paymentMethod || 'bank_transfer'}`,
      })
    }
    const completed = await apiWrite('PATCH', `/shop/orders/${token}/complete`, {})
    return { id: completed.tokenValue, number: completed.number, total: (completed.total || 0) / 100,
      currency: completed.currencyCode, preparationState: 'pending', fulfillmentMode: payload.mode }
  },

  async createStripeCheckoutSession({ orderToken, paymentId, bookingToken, successUrl, cancelUrl }) {
    return apiWrite('POST', '/shop/payments/stripe/checkout-session', {
      orderToken, paymentId, bookingToken, successUrl, cancelUrl,
    })
  },

  async cancelStripePayment(bookingToken) {
    return apiWrite('POST', `/shop/payments/stripe/cancel/${bookingToken}`, {})
  },

  // --- CHEQUE CADEAU REEL : vraie commande Sylius -> GiftVoucher backend -----
  // Meme squelette que createOrder (direct + virement) ci-dessus, avec deux
  // differences : (a) l'email/adresse de commande sont ceux de l'ACHETEUR
  // (payload.gift.purchaserName/Email), pas du sauteur — le beneficiaire n'a
  // pas de compte a ce stade ; (b) un appel dedie POSE le marqueur cadeau
  // (App\GiftVoucher\GiftOrderMarker, App\Controller\ShopGiftOrderMarkerController)
  // AVANT le PATCH .../complete, pour que le listener backend
  // (CreateGiftVoucherOnOrderPlacedListener, sur postUpdate/checkoutState=completed)
  // le lise et cree le GiftVoucher (statut awaiting_payment) au moment meme ou
  // la commande passe "completed". Le cheque est ensuite recupere via
  // GET /shop/gift-vouchers/by-order/{orderNumber} pour la page de confirmation.
  async _createGiftOrder(payload) {
    // 1. Panier
    const cart = await apiWrite('POST', '/shop/orders', {})
    const t = cart.tokenValue

    // 2. Items : le saut offert puis chaque option (id = code produit Sylius)
    const productCodes = [payload.jumpTypeId, ...(payload.options || []).map((o) => o.id)]
    for (const code of productCodes) {
      await apiWrite('POST', `/shop/orders/${t}/items`, {
        productVariant: `/api/v2/shop/product-variants/${code}-variant`,
        quantity: 1,
      })
    }

    // 3. Email + adresse de facturation : ceux de l'ACHETEUR.
    const purchaserFullName = payload.gift?.purchaserName?.trim() || 'Client TodaTempo'
    const [pFirst, ...pRest] = purchaserFullName.split(/\s+/)
    await apiWrite('PUT', `/shop/orders/${t}`, {
      email: payload.gift?.purchaserEmail,
      billingAddress: {
        firstName: pFirst,
        lastName: pRest.join(' ') || pFirst,
        countryCode: 'US', // pays du channel de demo FASHION_WEB
        street: 'Adresse non collectee',
        city: 'N/A',
        postcode: '00000',
      },
    })

    // 4. Marqueur cadeau (beneficiaire) — lu par le listener backend au
    //    passage checkoutState=completed (etape 6).
    await apiWrite('PATCH', `/shop/orders/${t}/gift-marker`, {
      beneficiaryName: payload.gift?.name || null,
      beneficiaryEmail: payload.gift?.email,
      personalMessage: payload.gift?.message || null,
    })

    // 5. Moyen de paiement : virement (seul canal produisant une vraie
    //    commande tant que Stripe n'est pas branche)
    const addressed = await apiGet(`/shop/orders/${t}`)
    const paymentId = addressed.payments?.[0]?.id
    if (paymentId != null) {
      await apiWrite('PATCH', `/shop/orders/${t}/payments/${paymentId}`, {
        paymentMethod: '/api/v2/shop/payment-methods/bank_transfer',
      })
    }

    // 6. Finalisation -> checkoutState=completed -> le backend cree le
    //    GiftVoucher (awaiting_payment) a partir du marqueur pose en (4).
    const completed = await apiWrite('PATCH', `/shop/orders/${t}/complete`, {})

    // 7. Recuperation du cheque cadeau REEL cree par le backend.
    const voucher = await apiGet(`/shop/gift-vouchers/by-order/${completed.number}`)

    let paymentInstructions = ''
    try {
      const pms = membersOf(await apiGet('/shop/payment-methods'))
      paymentInstructions = pms.find((m) => m.code === 'bank_transfer')?.instructions || ''
    } catch {
      /* non bloquant */
    }

    return {
      booking: null,
      order: {
        id: completed.tokenValue,
        number: completed.number,
        total: (completed.total ?? 0) / 100,
        currency: completed.currencyCode || voucher.currencyCode,
        status: 'awaiting_payment', // paymentState Sylius : en attente du virement
        paymentMethod: 'bank_transfer',
        paymentInstructions,
        syliusState: completed.state,
      },
      voucher: {
        code: voucher.code,
        status: voucher.status, // 'awaiting_payment' tant que le virement n'est pas encaisse
        awaitingPayment: voucher.status === 'awaiting_payment',
        jumpTypeCode: voucher.jumpTypeCode,
        jumpTypeName: voucher.jumpTypeName,
        amount: (voucher.amount ?? 0) / 100, // Sylius stocke en centimes
        currency: voucher.currencyCode,
        beneficiaryName: voucher.beneficiaryName,
        beneficiaryEmail: voucher.beneficiaryEmail,
        personalMessage: voucher.personalMessage,
        purchaserName: voucher.purchaserName,
        expiresAt: voucher.expiresAt,
      },
    }
  },

  // --- ESPACE BENEFICIAIRE REEL (Phase 2) ------------------------------------
  // GiftVoucher -> forme attendue par le front (mock historique) : on garde
  // le nom `jumpTypeId` cote front (au lieu de `jumpTypeCode` cote back) pour
  // ne pas toucher aux composants qui l'utilisent deja (SlotCalendar...).
  // Plus de tenantId/tenantSlug/tenantName : un cheque reel n'est consulte
  // QUE depuis le site du centre qui l'a vendu (URL /{slug}/...), le tenant
  // courant est donc toujours le bon (voir VoucherSchedule/VoucherExpired).
  async beneficiaryLogin(code, email) {
    const data = await apiWrite('POST', '/shop/gift-vouchers/login', {
      code: String(code || '').trim(),
      email: String(email || '').trim(),
    })
    return { profile: { email: data.email, firstName: data.firstName || '', lastName: '' } }
  },

  async getVouchersForEmail(email) {
    const list = await apiGet(`/shop/gift-vouchers/by-email/${encodeURIComponent(String(email || '').trim())}`)
    return (Array.isArray(list) ? list : []).map(mapVoucherFromApi)
  },

  async getVoucherByCode(code) {
    const v = await apiGet(`/shop/gift-vouchers/${encodeURIComponent(String(code || '').trim())}`)
    return mapVoucherFromApi(v)
  },

  // Redemption et réservation réelles, effectuées atomiquement par le backend
  // afin qu'un échec de créneau laisse le chèque disponible.
  async reserveVoucher(code, { tenantId, jumpTypeId, jumpTypeName, slotId, slot, jumper }) {
    const result = await apiWrite('POST', `/shop/bookings/from-voucher/${encodeURIComponent(code)}`, {
      serviceCode: jumpTypeId,
      serviceName: jumpTypeName,
      planningCode: slot?.planningCode || null,
      resourceCode: slot?.resourceCode || null,
      staffMemberId: slot?.staffMemberId || null,
      start: slot?.start,
      end: slot?.end,
      customer: {
        firstName: jumper?.firstName || '',
        lastName: jumper?.lastName || '',
        email: jumper?.email || '',
        phone: jumper?.phone || '',
        smsReminderConsent: jumper?.smsReminderConsent === true,
        notes: jumper?.notes || '',
      },
    })

    return {
      booking: result.booking,
      voucher: mapVoucherFromApi(result.voucher),
    }
  },

  async getBooking(bookingId) {
    const booking = await customerRequest('GET', `/shop/account/bookings/${encodeURIComponent(bookingId)}`)
    return { ...booking, boardingPassId: booking.id }
  },

  async getBoardingPass(bookingId) {
    const booking = await customerRequest('GET', `/shop/account/bookings/${encodeURIComponent(bookingId)}`)
    return {
        id: booking.id,
        bookingId: booking.id,
        reference: booking.reference,
        jumperName: booking.jumperName,
        jumpTypeName: booking.jumpTypeName,
        slotStart: booking.slotStart,
        options: (booking.options || []).map((option) => option.name || option),
    }
  },

  // --- ADMIN : connexion + CRUD produit via l'API admin Sylius ---
  async adminLogin(email, password) {
    await sylius.login(email, password)
    const identity = await sylius.getTeamSession()
    return buildAdminSession(identity)
  },

  async adminSsoLogin() {
    const identity = await sylius.exchangeSsoSession()
    return buildAdminSession(identity)
  },

  adminLogout() {
    sylius.logout()
  },

  async getAdminOverview(_tenantId, range = {}) {
    return sylius.getDashboardOverview(range)
  },

  async createJumpType(tenantId, data) {
    const res = await sylius.createJump(data)
    return { id: res.code, tenantId, name: res.name, basePrice: res.basePrice }
  },

  async updateJumpType(tenantId, id, patch) {
    await sylius.updateJump(id, patch)
    return { id, tenantId, ...patch }
  },

  async deleteJumpType(tenantId, id) {
    return sylius.deleteJump(id)
  },

  async getStaffMembers() {
    return sylius.getStaffMembers()
  },

  async createStaffMember(tenantId, data) {
    return sylius.createStaffMember(data)
  },

  async updateStaffMember(tenantId, id, data) {
    return sylius.updateStaffMember(id, data)
  },

  async archiveStaffMember(tenantId, id) {
    return sylius.archiveStaffMember(id)
  },

  async getTenantBookings() {
    return sylius.getBookings()
  },

  async getClients(tenantId, search = '') {
    return sylius.getClients(search)
  },

  async updateClient(tenantId, id, data) {
    return sylius.updateClient(id, data)
  },

  async createManualBooking(tenantId, data) {
    return sylius.createManualBooking(data)
  },

  async getStaffTimeOffs(tenantId, range) {
    return sylius.getStaffTimeOffs(range)
  },

  async createStaffTimeOff(tenantId, data) {
    return sylius.createStaffTimeOff(data)
  },

  async deleteStaffTimeOff(tenantId, id) {
    return sylius.deleteStaffTimeOff(id)
  },

  async rescheduleBooking(bookingId, slot) {
    return sylius.rescheduleBooking(bookingId, slot)
  },

  async postponeBooking(bookingId, reason) {
    return sylius.postponeBooking(bookingId, reason)
  },

  async completeBooking(bookingId) {
    return sylius.completeBooking(bookingId)
  },

  async markBookingNoShow(bookingId) {
    return sylius.markBookingNoShow(bookingId)
  },

  async cancelBooking(bookingId) {
    return sylius.cancelBooking(bookingId)
  },

  async refundBooking(bookingId) {
    throw new Error(`Le remboursement de la réservation ${bookingId} doit être effectué depuis le paiement associé.`)
  },

  // --- OPTIONS / UPSELLS (produits Sylius code opt_...) ---
  async getOptions(tenantId, jumpTypeId = null) {
    const data = await apiGet('/shop/products', { itemsPerPage: 100 })
    const opts = membersOf(data).filter((p) => (p.code || '').startsWith('opt_'))
    const links = await resolveOptionLinks(opts)
    let list = opts.map((p) => mapProductToOption(p, tenantId, links.get(p.code) || []))
    // Filtre optionnel par saut : une option PER_JUMP n'est proposee que si elle
    // ne cible aucun saut (toutes) ou cible explicitement ce saut.
    if (jumpTypeId) {
      list = list.filter(
        (o) =>
          o.scope !== 'PER_JUMP' ||
          !o.linkedJumpTypeIds.length ||
          o.linkedJumpTypeIds.includes(jumpTypeId),
      )
    }
    return list
  },

  async createOption(tenantId, data) {
    const res = await sylius.createOption(data)
    return {
      id: res.code, tenantId, name: res.name, description: data.description || '',
      price: res.price, scope: res.scope, mandatory: false, maxQuantity: 1,
      linkedJumpTypeIds: res.linkedJumpTypeIds || [],
    }
  },

  async updateOption(tenantId, id, patch) {
    await sylius.updateOption(id, patch)
    return { id, tenantId, ...patch }
  },

  async deleteOption(tenantId, id) {
    return sylius.deleteOption(id)
  },
}
