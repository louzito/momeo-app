#!/usr/bin/env node
/**
 * =============================================================================
 * SkyBook — Provisionnement Sylius (idempotent)
 * =============================================================================
 * A QUOI CA SERT
 *   Applique, de facon rejouable, TOUTES les personnalisations "donnees" que
 *   SkyBook ajoute a une installation Sylius standard (types d'association,
 *   attributs produit, ...). Lance automatiquement par init.cmd apres
 *   `sylius:install`, et rejouable a la main quand on veut.
 *
 *   >>> REGLE D'EQUIPE : chaque fois qu'on ajoute un "truc custom" sur Sylius,
 *       on l'ajoute au MANIFEST ci-dessous. Il est alors recree AUTOMATIQUEMENT
 *       a chaque (re)installation. On ne cree plus rien "a la main" dans l'admin.
 *       Ce fichier est donc a la fois le JOURNAL et l'INSTALLEUR des customs.
 *
 * IDEMPOTENT
 *   Chaque ressource est verifiee (GET par code) puis creee seulement si absente.
 *   Relancer le script autant de fois qu'on veut est sans risque.
 *
 * USAGE
 *   node skybook-provision.mjs
 *   Variables d'environnement (optionnelles) :
 *     SKYBOOK_API             defaut http://localhost:8080/api/v2
 *     SKYBOOK_ADMIN_EMAIL     defaut sylius@example.com
 *     SKYBOOK_ADMIN_PASSWORD  defaut sylius
 *
 * PERSONNALISATIONS HORS-API (appliquees ailleurs, listees ici pour memoire)
 *   - config/packages/security.yaml : role_hierarchy
 *         ROLE_ADMINISTRATION_ACCESS: [ROLE_API_ACCESS]
 *       -> autorise l'utilisateur admin a utiliser l'API admin. (fichier versionne)
 *   - Cles JWT : `bin/console lexik:jwt:generate-keypair`  (fait par init.cmd)
 *   - compose.override.yml : ports 8080/3307, volumes nommes var/ & vendor/,
 *         XDEBUG_MODE off  (perf + specifiques Windows/WAMP)
 * =============================================================================
 */

const API = (process.env.TODATEMPO_API || process.env.SKYBOOK_API || 'http://localhost:8080/api/v2').replace(/\/+$/, '')
const ADMIN_EMAIL = process.env.TODATEMPO_ADMIN_EMAIL || process.env.SKYBOOK_ADMIN_EMAIL || 'sylius@example.com'
const ADMIN_PASSWORD = process.env.TODATEMPO_ADMIN_PASSWORD || process.env.SKYBOOK_ADMIN_PASSWORD || 'sylius'
// Multi-centres : cible un tenant precis (en-tete X-Skybook-Tenant sur chaque
// appel API). Ex. : SKYBOOK_TENANT=template node skybook-provision.mjs
const TENANT = process.env.TODATEMPO_TENANT || process.env.SKYBOOK_TENANT || ''

// =============================================================================
// MANIFEST  —  la SEULE chose a editer quand on ajoute une perso Sylius
// =============================================================================

// 1) Types d'association de produits (relient des produits entre eux).
const PRODUCT_ASSOCIATION_TYPES = [
  {
    code: 'todatempo_services',
    name: 'Sauts concernes',
    // Porte le lien "option PER_JUMP -> sauts precis" (upsell cible).
    // owner = produit option (opt_pj_*), associatedProducts = produits saut (jump_*).
    // Aucune association sur une option = option proposee sur TOUS les sauts.
  },
]

// 2) Attributs de produit (caracteristiques structurees d'un saut / produit).
//    Types Sylius : text, textarea, integer, percent, checkbox, date, datetime, select.
const PRODUCT_ATTRIBUTES = [
  // Attributs generiques Momeo pour toutes les nouvelles prestations.
  { code: 'todatempo_duration', type: 'integer', name: 'Duree de la prestation (min)' },
  { code: 'todatempo_capacity', type: 'integer', name: 'Capacite par creneau' },
  { code: 'todatempo_requirements', type: 'textarea', name: 'Conditions de reservation' },
  // Attributs "type de saut" (valeurs portees par les produits jump_*, non
  // traduisibles -> localeCode null). Le front les lit via
  // GET /shop/products/{code}/attributes et les ecrit via PUT /admin/products/{code}.
  { code: 'jump_altitude', type: 'integer', name: 'Altitude (m)' },
  { code: 'jump_duration', type: 'integer', name: 'Duree sur site (min)' },
  { code: 'jump_capacity', type: 'integer', name: 'Capacite par creneau' },
  // Regles d'eligibilite du saut (editees dans l'espace centre, verifiees au checkout).
  { code: 'jump_age_min', type: 'integer', name: 'Age minimum (ans)' },
  { code: 'jump_age_max', type: 'integer', name: 'Age maximum (ans)' },
  { code: 'jump_weight_max', type: 'integer', name: 'Poids maximum (kg)' },
  { code: 'jump_height_min', type: 'integer', name: 'Taille minimum (cm)' },
  { code: 'jump_bmi_max', type: 'integer', name: 'IMC maximum' },
  { code: 'jump_medical_cert', type: 'checkbox', name: 'Certificat medical requis' },
  { code: 'jump_waiver', type: 'checkbox', name: 'Decharge de responsabilite requise' },
]

// 3) Taxons techniques SkyBook : racine des PLANNINGS + un planning par defaut.
//    Un planning = taxon code planning_* sous skybook_plannings, config JSON
//    dans la description (openDays 0=dim..6=sam, times, capacity, jumpCodes).
//    Edite ensuite depuis l'espace centre du front (onglet Plannings).
const TAXONS = [
  {
    code: 'todatempo_plannings',
    name: 'TodaTempo Plannings',
    slug: 'todatempo-plannings',
    description: 'Conteneur technique TodaTempo — plannings de creneaux. Ne pas supprimer.',
  },
  {
    code: 'todatempo_config',
    name: 'TodaTempo Config',
    slug: 'todatempo-config',
    description: '{}', // rempli depuis l'espace centre (Configuration boutique)
  },
  {
    code: 'planning_standard',
    parent: 'skybook_plannings',
    name: 'Planning standard',
    slug: 'planning-standard',
    description: JSON.stringify({
      name: 'Planning standard',
      openDays: [0, 1, 3, 4, 5, 6],
      times: ['09:00', '11:30', '14:00', '16:30'],
      capacity: 8,
      jumpCodes: [],
    }),
  },
]

// 4) Moyens de paiement (checkout). `bank_transfer` existe deja dans les
//    fixtures `sylius:install` par defaut ; on le garantit ici pour les installs
//    ou les fixtures changeraient. Gateway "offline" = aucun PSP : la commande
//    passe en paymentState=awaiting_payment et le centre valide a reception.
//    Les factories stripe_checkout / stripe_web_elements / sylius_paypal sont
//    fournies par les plugins Stripe & PayPal DEJA INSTALLES dans le back
//    (sylius-standard 2.x). Les cles (publishable_key, client_id, ...) se
//    saisissent ensuite dans l'espace centre du front (Moyens de paiement),
//    qui affiche aussi la marche a suivre pour le webhook Stripe :
//    URL = <back public>/payment-methods/stripe_web_elements
//    (route sylius_payment_method_notify), events payment_intent.succeeded
//    + payment_intent.canceled, secret whsec_ a recopier dans la config.
const PAYMENT_METHODS = [
  {
    code: 'bank_transfer',
    name: 'Virement bancaire',
    channelCode: 'FASHION_WEB',
    factoryName: 'offline',
    enabled: true,
  },
  {
    code: 'stripe_web_elements',
    name: 'Carte bancaire (Stripe)',
    description: 'Paiement securise par carte via Stripe.',
    channelCode: 'FASHION_WEB',
    factoryName: 'stripe_web_elements',
    enabled: false, // a activer depuis l'espace centre une fois les cles saisies
    config: { publishable_key: '', secret_key: '', webhook_secret_key: '' },
  },
  {
    code: 'paypal',
    name: 'PayPal',
    description: 'Paiement via votre compte PayPal.',
    channelCode: 'FASHION_WEB',
    factoryName: 'sylius_paypal',
    enabled: false,
    config: { client_id: '', client_secret: '', merchant_id: '' },
  },
]

// =============================================================================
// Implementation  —  rien a toucher en temps normal
// =============================================================================

let token = null
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

async function apiFetch(method, path, body, contentType = 'application/ld+json') {
  const res = await fetch(`${API}${path}`, {
    method,
    headers: {
      Accept: 'application/ld+json',
      ...(TENANT ? { 'X-TodaTempo-Tenant': TENANT } : {}),
      ...(body ? { 'Content-Type': contentType } : {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
  })
  const text = await res.text()
  let data = null
  try {
    data = text ? JSON.parse(text) : null
  } catch {
    /* reponse non-JSON */
  }
  return { status: res.status, data }
}

// Connexion admin, avec attente de Sylius (utile juste apres `docker compose up -d`).
async function login(maxTries = 20) {
  for (let i = 0; i < maxTries; i++) {
    try {
      const res = await fetch(`${API}/admin/administrators/token`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...(TENANT ? { 'X-TodaTempo-Tenant': TENANT } : {}) },
        body: JSON.stringify({ email: ADMIN_EMAIL, password: ADMIN_PASSWORD }),
      })
      if (res.ok) {
        token = (await res.json()).token
        return
      }
      if (res.status === 400 || res.status === 401) {
        throw new Error(
          `Identifiants admin refuses (${res.status}). Verifie SKYBOOK_ADMIN_EMAIL / SKYBOOK_ADMIN_PASSWORD.`,
        )
      }
      // 5xx / 404 : l'app n'est peut-etre pas encore prete -> on retente.
    } catch (e) {
      if (e.message?.startsWith('Identifiants admin')) throw e
      // erreur reseau : Sylius pas encore joignable -> on retente.
    }
    if (i === 0) console.log('… attente de Sylius (API pas encore prete)')
    await sleep(2000)
  }
  throw new Error(
    'Sylius injoignable. Assure-toi que `docker compose up -d` tourne, puis relance le script.',
  )
}

// Cree une ressource "par code" seulement si elle n'existe pas deja.
async function ensure(label, basePath, code, buildBody) {
  const existing = await apiFetch('GET', `${basePath}/${encodeURIComponent(code)}`)
  if (existing.status === 200) {
    console.log(`  = ${label} "${code}" deja present`)
    return
  }
  const created = await apiFetch('POST', basePath, buildBody())
  if (created.status === 201) {
    console.log(`  + ${label} "${code}" cree`)
    return
  }
  const detail = created.data?.['hydra:description'] || created.data?.detail || ''
  throw new Error(`Echec creation ${label} "${code}" (HTTP ${created.status}). ${detail}`)
}

async function main() {
  console.log(`SkyBook — provisionnement Sylius sur ${API}${TENANT ? ` (tenant ${TENANT})` : ''}`)
  await login()

  // Multi-centres : le code du channel n'est pas forcement FASHION_WEB —
  // on resout le premier channel de la BDD cible (surchargable via SKYBOOK_CHANNEL).
  let channelCode = process.env.TODATEMPO_CHANNEL || process.env.SKYBOOK_CHANNEL || null
  if (!channelCode) {
    const ch = await apiFetch('GET', '/admin/channels?itemsPerPage=1')
    channelCode = ch.data?.['hydra:member']?.[0]?.code || 'FASHION_WEB'
  }

  console.log('Types d\'association de produits :')
  for (const t of PRODUCT_ASSOCIATION_TYPES) {
    await ensure('type d\'association', '/admin/product-association-types', t.code, () => ({
      code: t.code,
      translations: { en_US: { name: t.name } },
    }))
  }

  console.log('Attributs de produit :')
  if (!PRODUCT_ATTRIBUTES.length) console.log('  (aucun pour l\'instant)')
  for (const a of PRODUCT_ATTRIBUTES) {
    await ensure('attribut produit', '/admin/product-attributes', a.code, () => ({
      code: a.code,
      type: a.type,
      translatable: false,
      translations: { en_US: { name: a.name } },
      configuration: a.configuration || {},
    }))
  }

  console.log('Taxons SkyBook (plannings) :')
  for (const t of TAXONS) {
    await ensure('taxon', '/admin/taxons', t.code, () => ({
      code: t.code,
      enabled: true,
      ...(t.parent ? { parent: `/api/v2/admin/taxons/${t.parent}` } : {}),
      translations: { en_US: { name: t.name, slug: t.slug, description: t.description } },
    }))
  }

  console.log('Moyens de paiement :')
  for (const m of PAYMENT_METHODS) {
    await ensure('moyen de paiement', '/admin/payment-methods', m.code, () => ({
      code: m.code,
      enabled: m.enabled !== false,
      channels: [`/api/v2/admin/channels/${channelCode}`],
      gatewayConfig: {
        factoryName: m.factoryName || 'offline',
        gatewayName: m.code,
        config: m.config || {},
      },
      translations: { en_US: { name: m.name, ...(m.description ? { description: m.description } : {}) } },
    }))
  }

  console.log('✔ Provisionnement termine.')
}

main().catch((e) => {
  console.error(`✖ ${e.message}`)
  process.exit(1)
})
