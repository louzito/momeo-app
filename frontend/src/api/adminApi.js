// =============================================================================
// Client API ADMIN Sylius (pilotage depuis le front)
// -----------------------------------------------------------------------------
// Permet au front de :
//   - se connecter a Sylius avec un utilisateur admin -> recupere un JWT
//   - creer / modifier / supprimer des PRODUITS "saut"  (code prefixe jump_)
//   - creer / modifier / supprimer des OPTIONS / UPSELLS (code prefixe opt_)
//       * opt_pj_...  -> rattachee au SAUT     (PER_JUMP)
//       * opt_po_...  -> rattachee a la COMMANDE (PER_ORDER)
//
// Le JWT est stocke (memoire + localStorage) et rejoue en Bearer sur chaque appel.
// Chaque requete porte le tenant et cible /api/v2 sur l'origine courante.
// =============================================================================

import { API_BASE, TENANT_SLUG, displayImageUrl, tenantHeaders } from './config'
import { normalizeShopColors } from '@/composables/useBranding'
import { migrateLocalStorageKey } from '@/utils/persistedIdentifier'

const TOKEN_KEY = `todatempo.sylius.jwt.${TENANT_SLUG}`
const DEFAULT_CHANNEL = 'FASHION_WEB' // channel du Sylius de demo (a rendre configurable plus tard)
// Type d'association Sylius servant a lier une option PER_JUMP a des sauts precis.
const JUMP_ASSOC_TYPE = 'todatempo_services'
const LEGACY_JUMP_ASSOC_TYPES = new Set(['skybook_jumps'])

let token = null
try {
  token = migrateLocalStorageKey(TOKEN_KEY, [`momeo.sylius.jwt.${TENANT_SLUG}`])
} catch {
  /* ignore */
}

export function getToken() {
  return token
}
function setToken(t) {
  token = t
  try {
    if (t) localStorage.setItem(TOKEN_KEY, t)
    else localStorage.removeItem(TOKEN_KEY)
  } catch {
    /* ignore */
  }
}
export function isAuthenticated() {
  return !!token
}
export function logout() {
  setToken(null)
}

function headers(contentType = 'application/ld+json', withAuth = true) {
  const h = { Accept: 'application/ld+json' }
  if (contentType) h['Content-Type'] = contentType
  if (withAuth && token) h['Authorization'] = 'Bearer ' + token
  return tenantHeaders(h)
}

async function request(method, path, body, contentType, { auth = true } = {}) {
  const res = await fetch(`${API_BASE}${path}`, {
    method,
    headers: headers(contentType, auth),
    body: body ? JSON.stringify(body) : undefined,
  })
  const text = await res.text()
  const data = text ? JSON.parse(text) : null
  if (!res.ok) {
    // JWT expire / invalide : on purge le token stocke pour que la prochaine
    // tentative de connexion reparte proprement (sinon l'app rejoue un Bearer
    // mort a l'infini).
    if (res.status === 401 && auth && token) setToken(null)
    const msg = data?.['hydra:description'] || data?.detail || data?.error || data?.message || `HTTP ${res.status}`
    const err = new Error(msg)
    err.status = res.status
    err.violations = data?.violations
    throw err
  }
  return data
}

// --- Auth ------------------------------------------------------------------
export async function login(email, password) {
  // IMPORTANT : pas de header Authorization sur la route du token. Si on rejoue
  // un vieux JWT expire ici, Sylius repond 401 "Expired JWT Token" AVANT de
  // verifier email / mot de passe, et la connexion devient impossible.
  const data = await request(
    'POST',
    '/admin/administrators/token',
    { email, password },
    'application/json',
    { auth: false },
  )
  if (!data?.token) throw new Error('Authentification echouee (pas de token).')
  setToken(data.token)
  return { email, token: data.token }
}

/** Finalise la connexion TodaTempo depuis le cookie temporaire HttpOnly. */
export async function exchangeSsoSession() {
  const data = await request(
    'POST',
    '/admin/todatempo/sso/session',
    null,
    'application/json',
    { auth: false },
  )
  if (!data?.token) throw new Error('Connexion automatique impossible.')
  setToken(data.token)
  return data.admin || {}
}

// --- Helpers ---------------------------------------------------------------
function slugify(str) {
  return String(str)
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
}
function codeFrom(prefix, name) {
  return prefix + slugify(name).replace(/-/g, '_')
}
const channelIri = (code) => `/api/v2/admin/channels/${code}`
const productIri = (code) => `/api/v2/admin/products/${code}`
const assocTypeIri = (code) => `/api/v2/admin/product-association-types/${code}`
const codeFromIri = (iri) => String(iri || '').split('/').pop()

// Cree un produit simple Sylius (produit + variante + prix par channel).
async function createSimpleProduct({ code, name, price, shortDescription = '', description = '', channelCode = DEFAULT_CHANNEL }) {
  const priceCents = Math.round(Number(price || 0) * 100)
  await request('POST', '/admin/products', {
    code,
    enabled: true,
    channels: [channelIri(channelCode)],
    translations: { en_US: { name, slug: slugify(name), shortDescription, description } },
  })
  await request('POST', '/admin/product-variants', {
    code: `${code}-variant`,
    product: productIri(code),
    translations: { en_US: { name } },
    channelPricings: { [channelCode]: { price: priceCents } },
    shippingRequired: false,
    tracked: false,
  })
  return { code, name, price: priceCents / 100 }
}

// NB : l'API admin Sylius n'expose PAS PATCH sur products / product-variants
// (Allow: PUT, DELETE, GET). On met donc a jour via PUT. Pour que PUT modifie la
// sous-ressource EXISTANTE (au lieu d'en creer une 2e -> violation d'unicite), on
// reference son @id, qui est deterministe (derivable du code / de la locale).
async function patchProduct(code, { name, shortDescription, description }, locale = 'en_US') {
  const tr = { '@id': `/api/v2/admin/products/${code}/translations/${locale}` }
  if (name) {
    tr.name = name
    tr.slug = slugify(name)
  }
  if (shortDescription != null) tr.shortDescription = shortDescription
  if (description != null) tr.description = description
  if (Object.keys(tr).length <= 1) return // rien a changer hormis l'@id
  await request('PUT', `/admin/products/${code}`, { translations: { [locale]: tr } })
}
async function patchVariantPrice(code, price, channelCode = DEFAULT_CHANNEL) {
  const priceCents = Math.round(Number(price) * 100)
  const cpIri = `/api/v2/admin/product-variants/${code}-variant/channel-pricings/${channelCode}`
  await request('PUT', `/admin/product-variants/${code}-variant`, {
    channelPricings: { [channelCode]: { '@id': cpIri, price: priceCents } },
  })
}

// --- Attributs de saut (caracteristiques + regles d'eligibilite) -------------
// Valeurs structurees portees par le produit via les ATTRIBUTS Sylius, crees par
// le provisionnement (back/skybook-provision.mjs). Attention : dans le PUT
// /admin/products/{code}, la collection `attributes` REMPLACE l'existante ->
// on renvoie donc TOUJOURS toutes les valeurs d'un coup (le formulaire admin
// les envoie toutes). Attributs non traduisibles => pas de localeCode.
//   kind 'int'  : envoye si nombre fini > 0 (0 / vide = non renseigne)
//   kind 'bool' : envoye tel quel (checkbox Sylius)
//   from 'eligibility' : lu dans data.eligibility.<field>
const JUMP_ATTRIBUTE_DEFS = [
  { field: 'altitudeM', code: 'jump_altitude', kind: 'int' },
  { field: 'durationMin', code: 'jump_duration', kind: 'int' },
  { field: 'capacityPerSlot', code: 'jump_capacity', kind: 'int' },
  { field: 'ageMin', code: 'jump_age_min', kind: 'int', from: 'eligibility' },
  { field: 'ageMax', code: 'jump_age_max', kind: 'int', from: 'eligibility' },
  { field: 'weightMaxKg', code: 'jump_weight_max', kind: 'int', from: 'eligibility' },
  { field: 'heightMinCm', code: 'jump_height_min', kind: 'int', from: 'eligibility' },
  { field: 'bmiMax', code: 'jump_bmi_max', kind: 'int', from: 'eligibility' },
  { field: 'medicalCertificateRequired', code: 'jump_medical_cert', kind: 'bool', from: 'eligibility' },
  { field: 'waiverRequired', code: 'jump_waiver', kind: 'bool', from: 'eligibility' },
]
const TODATEMPO_ATTRIBUTE_DEFS = [
  { field: 'durationMin', code: 'todatempo_duration', kind: 'integer', name: 'Duree de la prestation (min)' },
  { field: 'capacityPerSlot', code: 'todatempo_capacity', kind: 'integer', name: 'Capacite par creneau' },
  { field: 'requirements', code: 'todatempo_requirements', kind: 'textarea', name: 'Conditions de reservation' },
  { field: 'paymentMode', code: 'todatempo_payment_mode', kind: 'text', name: 'Mode de paiement de la prestation' },
  { field: 'paymentValue', code: 'todatempo_payment_value', kind: 'integer', name: 'Valeur de l’acompte (centimes ou pourcentage)' },
]
const attributeIri = (code) => `/api/v2/admin/product-attributes/${code}`

let momeoAttributesReady = false
async function ensureMomeoAttributes() {
  if (momeoAttributesReady) return
  for (const def of TODATEMPO_ATTRIBUTE_DEFS) {
    try {
      await request('GET', `/admin/product-attributes/${def.code}`)
    } catch (e) {
      if (e.status !== 404) throw e
      await request('POST', '/admin/product-attributes', {
        code: def.code,
        type: def.kind,
        translatable: false,
        translations: { en_US: { name: def.name } },
        configuration: {},
      })
    }
  }
  momeoAttributesReady = true
}

async function setMomeoAttributes(code, values = {}) {
  await ensureMomeoAttributes()
  const requirements = (values.requirements || [])
    .filter((item) => item?.key && item?.label)
    .map((item) => ({ key: String(item.key), label: String(item.label) }))
  const attributes = [
    { attribute: attributeIri('todatempo_duration'), value: Math.max(5, Math.round(Number(values.durationMin) || 60)) },
    { attribute: attributeIri('todatempo_capacity'), value: Math.max(1, Math.round(Number(values.capacityPerSlot) || 1)) },
    { attribute: attributeIri('todatempo_requirements'), value: JSON.stringify(requirements) },
    { attribute: attributeIri('todatempo_payment_mode'), value: ['none', 'fixed', 'percentage', 'full'].includes(values.paymentMode) ? values.paymentMode : 'full' },
    { attribute: attributeIri('todatempo_payment_value'), value: values.paymentMode === 'fixed'
      ? Math.max(1, Math.round(Number(values.paymentValue) * 100))
      : (values.paymentMode === 'percentage' ? Math.max(1, Math.min(100, Math.round(Number(values.paymentValue)))) : 0) },
  ]
  await request('PUT', `/admin/products/${code}`, { attributes })
}

async function setJumpAttributes(code, values = {}) {
  const attributes = []
  for (const def of JUMP_ATTRIBUTE_DEFS) {
    const src = def.from === 'eligibility' ? values.eligibility || {} : values
    const raw = src[def.field]
    if (def.kind === 'bool') {
      if (raw === true || raw === false) {
        attributes.push({ attribute: attributeIri(def.code), value: raw })
      }
    } else {
      const v = Number(raw)
      if (Number.isFinite(v) && v > 0) {
        attributes.push({ attribute: attributeIri(def.code), value: Math.round(v) })
      }
    }
  }
  if (!attributes.length) return
  await request('PUT', `/admin/products/${code}`, { attributes })
}

// --- Liaison option -> sauts precis (via Product Associations Sylius) -------
// Une option PER_JUMP peut cibler des sauts precis. On modelise ce lien comme
// une ASSOCIATION de produits Sylius :
//   owner = produit option, type = skybook_jumps, associatedProducts = sauts.
// Aucune association = option proposee sur TOUS les sauts.
let _assocTypeReady = false
async function ensureJumpAssociationType() {
  if (_assocTypeReady) return
  try {
    await request('GET', `/admin/product-association-types/${JUMP_ASSOC_TYPE}`)
  } catch (e) {
    if (e.status === 404) {
      await request('POST', '/admin/product-association-types', {
        code: JUMP_ASSOC_TYPE,
        translations: { en_US: { name: 'Sauts concernes' } },
      })
    } else {
      throw e
    }
  }
  _assocTypeReady = true
}

// IRIs (+ ids) des associations "skybook_jumps" dont ce produit option est owner.
async function jumpAssociationsOf(ownerCode) {
  let product
  try {
    product = await request('GET', `/admin/products/${ownerCode}`)
  } catch {
    return []
  }
  const found = []
  for (const iri of product?.associations || []) {
    const id = codeFromIri(iri)
    try {
      const a = await request('GET', `/admin/product-associations/${id}`)
      if (codeFromIri(a?.type) === JUMP_ASSOC_TYPE || LEGACY_JUMP_ASSOC_TYPES.has(codeFromIri(a?.type))) found.push(id)
    } catch {
      /* ignore */
    }
  }
  return found
}

// Remplace la liaison de l'option : supprime l'ancienne association skybook_jumps
// puis en recree une si des sauts sont cibles (liste vide = tous les sauts).
async function setOptionJumpLinks(ownerCode, jumpCodes = []) {
  await ensureJumpAssociationType()
  const existing = await jumpAssociationsOf(ownerCode)
  for (const id of existing) {
    try {
      await request('DELETE', `/admin/product-associations/${id}`)
    } catch {
      /* ignore */
    }
  }
  const codes = (jumpCodes || []).filter(Boolean)
  if (!codes.length) return
  await request('POST', '/admin/product-associations', {
    type: assocTypeIri(JUMP_ASSOC_TYPE),
    owner: productIri(ownerCode),
    associatedProducts: codes.map((c) => productIri(c)),
  })
}

// --- Produits (types de saut) ---------------------------------------------
export async function createJump(data) {
  const code = data.code || codeFrom('service_', data.name)
  const res = await createSimpleProduct({
    code,
    name: data.name,
    price: data.basePrice,
    shortDescription: data.summary || '',
    description: data.description || '',
    channelCode: data.channelCode,
  })
  await setMomeoAttributes(code, data)
  return res
}
export async function updateJump(code, patch) {
  await patchProduct(code, { name: patch.name, shortDescription: patch.summary, description: patch.description })
  if (patch.basePrice != null) await patchVariantPrice(code, patch.basePrice, patch.channelCode)
  if (code.startsWith('service_')) await setMomeoAttributes(code, patch)
  else if (patch.eligibility) await setJumpAttributes(code, patch)
  return { code }
}
export async function deleteJump(code) {
  await request('DELETE', `/admin/products/${code}`)
  return { ok: true }
}

// --- Moyens de paiement ------------------------------------------------------
// Le centre gere TOUT depuis l'espace admin du front (jamais le panel Sylius) :
// activation, libelle affiche au client, et instructions de reglement
// (coordonnees bancaires pour le virement). Les instructions vivent dans la
// translation Sylius `instructions` du payment-method -> elles remontent telles
// quelles sur le shop API (affichees en fin de commande).
export async function getPaymentMethods() {
  const data = await request('GET', '/admin/payment-methods?itemsPerPage=50')
  const list = data['hydra:member'] || data.member || []
  return list.map((m) => ({
    id: m.code,
    code: m.code,
    gateway: m.gatewayConfig?.factoryName || 'offline',
    // @id du gatewayConfig : NON deterministe (/gateway-configs/{id}) -> on le
    // garde pour pouvoir mettre a jour les cles (PUT exige cet @id, sinon 422).
    gatewayConfigId: m.gatewayConfig?.['@id'] || null,
    config: m.gatewayConfig?.config || {},
    label: m.translations?.en_US?.name || m.code,
    description: m.translations?.en_US?.description || '',
    instructions: m.translations?.en_US?.instructions || '',
    enabled: !!m.enabled,
  }))
}

// patch.config : cles de la passerelle (publishable_key, client_id, ...) —
// fusionnees dans gatewayConfig.config. Necessite gatewayConfigId (fourni par
// getPaymentMethods) ; s'il manque on le recupere via un GET.
export async function updatePaymentMethod(code, patch = {}) {
  const body = {}
  if (patch.enabled != null) body.enabled = !!patch.enabled
  if (patch.label != null || patch.instructions != null || patch.description != null) {
    const tr = { '@id': `/api/v2/admin/payment-methods/${code}/translations/en_US` }
    if (patch.label != null) tr.name = patch.label
    if (patch.instructions != null) tr.instructions = patch.instructions
    if (patch.description != null) tr.description = patch.description
    body.translations = { en_US: tr }
  }
  if (patch.config != null) {
    let gcId = patch.gatewayConfigId
    if (!gcId) {
      const m = await request('GET', `/admin/payment-methods/${code}`)
      gcId = m.gatewayConfig?.['@id']
    }
    body.gatewayConfig = { '@id': gcId, config: patch.config }
  }
  if (!Object.keys(body).length) return { code }
  await request('PUT', `/admin/payment-methods/${code}`, body)
  return { code }
}

// --- Plannings récurrents (ressource métier backend) --------------------------
// Les taxons planning_* sont uniquement une source de migration historique :
// aucune création, modification ou suppression n'est désormais faite dessus.

export async function getPlannings() {
  const data = await request('GET', '/admin/plannings')
  return data.member || []
}

export async function createPlanning(data) {
  const code = data.code || codeFrom('planning_', data.name)
  return request('POST', '/admin/plannings', { ...data, code, timezone: data.timezone || 'Europe/Paris' }, 'application/json')
}

export async function updatePlanning(code, data) {
  return request('PUT', `/admin/plannings/${encodeURIComponent(code)}`, { ...data, timezone: data.timezone || 'Europe/Paris' }, 'application/json')
}

export async function deletePlanning(code) {
  await request('DELETE', `/admin/plannings/${encodeURIComponent(code)}`)
  return { ok: true }
}

// --- Configuration boutique -----------------------------------------------------
// Repartition Sylius-first :
//   - nom / email / telephone -> CHANNEL Sylius (name, contactEmail,
//     contactPhoneNumber) : c'est ce que Sylius utilise nativement.
//   - logo -> IMAGE (type "logo") du taxon technique skybook_config.
//   - couleurs / reseaux sociaux / adresse -> JSON du taxon skybook_config
//     (pas de champ Sylius pour ca), lisible PUBLIQUEMENT via /shop/taxons.
const CONFIG_TAXON = 'todatempo_config'

async function ensureConfigTaxon() {
  try {
    await request('GET', `/admin/taxons/${CONFIG_TAXON}`)
  } catch (e) {
    if (e.status !== 404) throw e
    // Copy persisted legacy configuration once. Subsequent calls find the
    // canonical taxon, so this migration is idempotent.
    let legacy = null
    try { legacy = await request('GET', '/admin/taxons/skybook_config') } catch { /* first install */ }
    await request('POST', '/admin/taxons', {
      code: CONFIG_TAXON,
      enabled: true,
      translations: {
        en_US: {
          name: 'TodaTempo Config',
          slug: 'todatempo-config',
          description: legacy?.translations?.en_US?.description || '{}',
        },
      },
    })
  }
}

export async function getShopConfig() {
  await ensureConfigTaxon()
  const [t, ch] = await Promise.all([
    request('GET', `/admin/taxons/${CONFIG_TAXON}`),
    request('GET', `/admin/channels/${DEFAULT_CHANNEL}`),
  ])
  let cfg = {}
  try {
    cfg = JSON.parse(t.translations?.en_US?.description || '{}')
  } catch { /* description vide */ }
  // NB : ne JAMAIS retomber sur images[0] — le taxon porte aussi les bannieres.
  const imgOf = (type) => (t.images || []).find((i) => i.type === type)
  return {
    name: ch.name || '',
    timezone: typeof cfg.timezone === 'string' ? cfg.timezone : 'Europe/Paris',
    contactEmail: ch.contactEmail || '',
    contactPhone: ch.contactPhoneNumber || '',
    address: { street: '', postcode: '', city: '', ...(cfg.address || {}) },
    // Normalise + migre l'ancien champ unique `text` vers textHeader/textFooter.
    colors: normalizeShopColors(cfg.colors),
    socials: { instagram: '', facebook: '', x: '', youtube: '', ...(cfg.socials || {}) },
    // Page d'accueil : hero, points forts, section catalogue, produits mis en avant.
    home: {
      title: '',
      subtitle: '',
      highlights: [],
      catalogTitle: '',
      catalogText: '',
      featured: [],
      ...(cfg.home || {}),
    },
    // Ordre des produits de la page Boutique (codes ; produits absents = a la fin).
    shopOrder: Array.isArray(cfg.shopOrder) ? cfg.shopOrder : [],
    // Emails transactionnels : textes personnalises (vides = defauts du back).
    emails: {
      booking_confirmation: { subject: '', intro: '', signature: '', ...(cfg.emails?.booking_confirmation || {}) },
      payment_confirmation: { subject: '', intro: '', signature: '', ...(cfg.emails?.payment_confirmation || {}) },
      booking_cancelled: { subject: '', intro: '', signature: '', ...(cfg.emails?.booking_cancelled || {}) },
      booking_rescheduled: { subject: '', intro: '', signature: '', ...(cfg.emails?.booking_rescheduled || {}) },
      order_confirmation: { subject: '', intro: '', signature: '', ...(cfg.emails?.order_confirmation || {}) },
      invoice_generated: { subject: '', intro: '', signature: '', ...(cfg.emails?.invoice_generated || {}) },
      gift_voucher: { subject: '', intro: '', signature: '', ...(cfg.emails?.gift_voucher || {}) },
    },
    // Cheques cadeaux : ACTIFS par defaut (absent != false).
    giftVouchersEnabled: cfg.giftVouchersEnabled !== false,
    bookingChanges: {
      cancelHours: Math.max(0, Number(cfg.bookingChanges?.cancelHours ?? 24)),
      rescheduleHours: Math.max(0, Number(cfg.bookingChanges?.rescheduleHours ?? 24)),
    },
    // Pages legales activables (affichees dans le footer si enabled).
    legal: {
      terms: { enabled: false, content: '', ...(cfg.legal?.terms || {}) },
      mentions: { enabled: false, content: '', ...(cfg.legal?.mentions || {}) },
    },
    logoUrl: displayImageUrl(imgOf('logo')?.path),
    bannerUrl: displayImageUrl(imgOf('banner')?.path),
    bannerMobileUrl: displayImageUrl(imgOf('banner_mobile')?.path),
  }
}

export async function saveShopConfig(cfg) {
  await ensureConfigTaxon()
  // 1. Champs natifs Sylius sur le channel.
  await request('PUT', `/admin/channels/${DEFAULT_CHANNEL}`, {
    name: cfg.name || 'TodaTempo',
    contactEmail: cfg.contactEmail || null,
    contactPhoneNumber: cfg.contactPhone || null,
  })
  // 2. Le reste en JSON public (le front vitrine lit ce taxon sans auth).
  //    On duplique nom / contact dans le JSON : le shop API n'expose pas
  //    contactEmail / contactPhoneNumber du channel.
  await request('PUT', `/admin/taxons/${CONFIG_TAXON}`, {
    enabled: true,
    translations: {
      en_US: {
        '@id': `/api/v2/admin/taxon/${CONFIG_TAXON}/translations/en_US`,
        description: JSON.stringify({
          name: cfg.name,
          timezone: cfg.timezone || 'Europe/Paris',
          contactEmail: cfg.contactEmail,
          contactPhone: cfg.contactPhone,
          address: cfg.address,
          colors: cfg.colors,
          socials: cfg.socials,
          home: cfg.home || { title: '', subtitle: '' },
          shopOrder: Array.isArray(cfg.shopOrder) ? cfg.shopOrder : [],
          emails: cfg.emails || {},
          giftVouchersEnabled: cfg.giftVouchersEnabled !== false,
          bookingChanges: cfg.bookingChanges || { cancelHours: 24, rescheduleHours: 24 },
          legal: cfg.legal || { terms: { enabled: false, content: '' }, mentions: { enabled: false, content: '' } },
        }),
      },
    },
  })
  return { ok: true }
}

// Upload d'une image de la boutique sur le taxon de config, par TYPE
// ('logo', 'banner', 'banner_mobile'). On ne supprime QUE l'ancienne image du
// meme type (le taxon porte logo ET bannieres).
export async function uploadShopImage(file, type = 'logo') {
  await ensureConfigTaxon()
  const t = await request('GET', `/admin/taxons/${CONFIG_TAXON}`)
  for (const img of (t.images || []).filter((i) => i.type === type)) {
    try {
      await request('DELETE', String(img['@id'] || img).replace('/api/v2', ''))
    } catch { /* deja supprimee */ }
  }
  const fd = new FormData()
  fd.append('file', file)
  fd.append('type', type)
  const res = await fetch(`${API_BASE}/admin/taxons/${CONFIG_TAXON}/images`, {
    method: 'POST',
    headers: tenantHeaders({ Accept: 'application/ld+json', ...(token ? { Authorization: 'Bearer ' + token } : {}) }),
    body: fd,
  })
  const data = await res.json().catch(() => null)
  if (!res.ok) {
    const err = new Error(data?.detail || `Echec de l'upload de l'image (HTTP ${res.status}).`)
    err.status = res.status
    throw err
  }
  return { path: data.path }
}

export async function uploadShopLogo(file) {
  return uploadShopImage(file, 'logo')
}

// --- Factures Sylius (plugin sylius/invoicing-plugin) --------------------------
// Le plugin n'est PAS installe aujourd'hui (GET /admin/invoices -> 404). Des
// qu'il le sera (`composer require sylius/invoicing-plugin` cote back), ces
// fonctions renverront les factures officielles et la page facture du front
// proposera le telechargement du PDF Sylius. En attendant : renvoient null.
export async function findInvoicesForOrder(orderNumber) {
  try {
    const d = await request('GET', `/admin/invoices?orderNumber=${encodeURIComponent(orderNumber)}`)
    return d['hydra:member'] || d.member || []
  } catch (e) {
    if (e.status === 404) return null // plugin absent
    throw e
  }
}

export async function downloadInvoiceBlob(invoiceId) {
  const res = await fetch(`${API_BASE}/admin/invoices/${invoiceId}/download`, {
    headers: tenantHeaders({ ...(token ? { Authorization: 'Bearer ' + token } : {}) }),
  })
  if (!res.ok) throw new Error(`Telechargement impossible (HTTP ${res.status}).`)
  return res.blob()
}

// --- Images produit ------------------------------------------------------------
// Upload en multipart : POST /admin/products/{code}/images (champs file + type).
// NB : ne PAS fixer Content-Type (le navigateur pose le boundary multipart).
// On ne garde qu'UNE image "main" par produit -> replace = delete puis upload.
async function uploadProductImage(code, file) {
  const fd = new FormData()
  fd.append('file', file)
  fd.append('type', 'main')
  const res = await fetch(`${API_BASE}/admin/products/${encodeURIComponent(code)}/images`, {
    method: 'POST',
    headers: tenantHeaders({ Accept: 'application/ld+json', ...(token ? { Authorization: 'Bearer ' + token } : {}) }),
    body: fd,
  })
  const data = await res.json().catch(() => null)
  if (!res.ok) {
    const err = new Error(data?.['hydra:description'] || data?.detail || `Echec de l'upload (HTTP ${res.status}).`)
    err.status = res.status
    throw err
  }
  return { id: data.id, path: data.path }
}

export async function replaceProductImage(code, file) {
  const p = await request('GET', `/admin/products/${encodeURIComponent(code)}`)
  for (const img of p.images || []) {
    const iri = img['@id'] || img
    try {
      await request('DELETE', String(iri).replace('/api/v2', ''))
    } catch {
      /* image deja supprimee */
    }
  }
  return uploadProductImage(code, file)
}

// --- Commandes & encaissement des virements ----------------------------------
// Liste les commandes Sylius (checkout termine) et permet de marquer un
// paiement recu : PATCH /admin/payments/{id}/complete (transition Payum) ->
// paymentState passe a "paid" (et l'order a "fulfilled", rien a expedier).
function mapOrder(o) {
  const firstPayment = (o.payments || [])[0]
  return {
    id: o.tokenValue,
    number: o.number,
    total: (o.total ?? 0) / 100,
    currency: o.currencyCode || 'USD',
    state: o.state,
    paymentState: o.paymentState,
    createdAt: o.checkoutCompletedAt || o.createdAt || null,
    notes: o.notes || '',
    paymentId: codeFromIri(firstPayment?.['@id'] || firstPayment),
  }
}

export async function getOrders({ paymentState = null, limit = 50 } = {}) {
  const data = await request('GET', `/admin/orders?itemsPerPage=${limit}`)
  let list = data['hydra:member'] || data.member || []
  if (paymentState) list = list.filter((o) => o.paymentState === paymentState)
  return list.map(mapOrder)
}

export async function completePayment(paymentId) {
  await request('PATCH', `/admin/payments/${paymentId}/complete`, {}, 'application/merge-patch+json')
  return { ok: true }
}

// Detail complet d'une commande (items, client, adresse, totaux) — utilise par
// le detail de commande et la facture de l'espace centre.
export async function getOrder(tokenValue) {
  const o = await request('GET', `/admin/orders/${encodeURIComponent(tokenValue)}`)
  const base = mapOrder(o)

  let customerEmail = ''
  if (o.customer) {
    try {
      const c = await request('GET', String(o.customer).replace('/api/v2', ''))
      customerEmail = c?.email || ''
    } catch { /* client anonyme */ }
  }

  // Libelle du moyen de paiement (ex. "Virement bancaire"). Le GET payment
  // EMBARQUE l'objet method (avec translations) — sinon on resout l'IRI.
  let paymentMethodName = ''
  try {
    if (base.paymentId) {
      const pay = await request('GET', `/admin/payments/${base.paymentId}`)
      const m = pay?.method
      if (m && typeof m === 'object') {
        paymentMethodName = m.translations?.en_US?.name || codeFromIri(m['@id'])
      } else if (m) {
        const pm = await request('GET', `/admin/payment-methods/${codeFromIri(m)}`)
        paymentMethodName = pm?.translations?.en_US?.name || codeFromIri(m)
      }
    }
  } catch { /* pas bloquant */ }

  const b = o.billingAddress
  return {
    ...base,
    items: (o.items || []).map((i) => ({
      name: i.productName || i.variantName || '',
      variant: i.variantName || '',
      quantity: i.quantity || 1,
      unitPrice: (i.unitPrice ?? 0) / 100,
      total: (i.total ?? 0) / 100,
    })),
    itemsSubtotal: (o.itemsSubtotal ?? o.itemsTotal ?? 0) / 100,
    taxTotal: (o.taxTotal ?? 0) / 100,
    customerEmail,
    paymentMethodName,
    billing: b
      ? { firstName: b.firstName || '', lastName: b.lastName || '', street: b.street || '',
          postcode: b.postcode || '', city: b.city || '', countryCode: b.countryCode || '' }
      : null,
  }
}

// --- Cheques cadeaux (App\Controller\AdminGiftVoucherApiController) --------
// Entite maison (pas une ressource API Platform) -> mini-API dediee sous
// /admin/gift-vouchers, isolee par tenant automatiquement (BDD du centre
// courant, voir le controleur backend).
export async function getGiftVouchers({ status = null } = {}) {
  const qs = status ? `?status=${encodeURIComponent(status)}` : ''
  const data = await request('GET', `/admin/gift-vouchers${qs}`)
  return { member: data.member || [], stats: data.stats || {} }
}

// --- Équipe ---------------------------------------------------------------
export async function getStaffMembers() {
  const data = await request('GET', '/admin/staff-members')
  return data.member || []
}

export async function createStaffMember(data) {
  return request('POST', '/admin/staff-members', data, 'application/json')
}

export async function updateStaffMember(id, data) {
  return request('PUT', `/admin/staff-members/${encodeURIComponent(id)}`, data, 'application/json')
}

export async function archiveStaffMember(id) {
  await request('DELETE', `/admin/staff-members/${encodeURIComponent(id)}`)
  return { ok: true }
}

// --- Réservations ---------------------------------------------------------
export async function getBookings() {
  const data = await request('GET', '/admin/bookings')
  return data.member || []
}

export async function createManualBooking(data) {
  return request('POST', '/admin/bookings', data, 'application/json')
}

export async function rescheduleBooking(id, slot) {
  return request('POST', `/admin/bookings/${encodeURIComponent(id)}/reschedule`, {
    start: slot.start,
    end: slot.end,
    staffMemberId: slot.staffMemberId || null,
    planningCode: slot.planningCode || null,
    resourceCode: slot.resourceCode || null,
  }, 'application/json')
}

export async function postponeBooking(id, reason) {
  return request('POST', `/admin/bookings/${encodeURIComponent(id)}/postpone`, { reason }, 'application/json')
}

export async function completeBooking(id) {
  return request('POST', `/admin/bookings/${encodeURIComponent(id)}/complete`, {}, 'application/json')
}

export async function cancelBooking(id) {
  return request('POST', `/admin/bookings/${encodeURIComponent(id)}/cancel`, {}, 'application/json')
}

// --- Clients ---------------------------------------------------------------
export async function getClients() {
  const data = await request('GET', '/admin/clients')
  return { clients: data.member || [], stats: data.stats || {} }
}

// --- Indisponibilités de l'équipe -----------------------------------------
export async function getStaffTimeOffs({ from, to } = {}) {
  const params = new URLSearchParams()
  if (from) params.set('from', from)
  if (to) params.set('to', to)
  const data = await request('GET', `/admin/staff-time-offs${params.size ? `?${params}` : ''}`)
  return data.member || []
}

export async function createStaffTimeOff(data) {
  return request('POST', '/admin/staff-time-offs', data, 'application/json')
}

export async function deleteStaffTimeOff(id) {
  await request('DELETE', `/admin/staff-time-offs/${encodeURIComponent(id)}`)
  return { ok: true }
}

// --- Options / upsells -----------------------------------------------------
// scope : 'PER_JUMP' (rattachee au saut) | 'PER_ORDER' (rattachee a la commande)
function optionPrefix(scope) {
  return scope === 'PER_ORDER' ? 'opt_po_' : 'opt_pj_'
}
export async function createOption(data) {
  const scope = data.scope === 'PER_ORDER' ? 'PER_ORDER' : 'PER_JUMP'
  const code = data.code || codeFrom(optionPrefix(scope), data.name)
  const res = await createSimpleProduct({
    code,
    name: data.name,
    price: data.price,
    shortDescription: data.description || '',
    channelCode: data.channelCode,
  })
  // Seules les options PER_JUMP peuvent cibler des sauts precis.
  const linkedJumpTypeIds = scope === 'PER_JUMP' ? (data.linkedJumpTypeIds || []) : []
  if (scope === 'PER_JUMP') await setOptionJumpLinks(code, linkedJumpTypeIds)
  return { ...res, scope, linkedJumpTypeIds }
}
export async function updateOption(code, patch) {
  await patchProduct(code, { name: patch.name, shortDescription: patch.description })
  if (patch.price != null) await patchVariantPrice(code, patch.price, patch.channelCode)
  // La portee est portee par le prefixe du code (opt_pj_ = PER_JUMP).
  if (code.startsWith('opt_pj_') && patch.linkedJumpTypeIds !== undefined) {
    await setOptionJumpLinks(code, patch.linkedJumpTypeIds)
  }
  return { code }
}
export async function deleteOption(code) {
  await request('DELETE', `/admin/products/${code}`)
  return { ok: true }
}
