// =============================================================================
// Configuration de la source de donnees du front — MULTI-CENTRES PAR SLUG
// -----------------------------------------------------------------------------
// L'URL porte le centre : localhost:5173/{slug}/... Le premier segment du
// chemin est le slug du tenant. L'API est appelee directement sur /api/v2 et
// chaque requete porte X-Skybook-Tenant : le fonctionnement de production ne
// depend donc pas d'une reecriture propre au serveur de developpement.
// =============================================================================

export const USE_REAL_API = true

const SLUG_RE = /^[a-z0-9][a-z0-9-]{0,62}$/

/** Slug canonique du centre, lu strictement dans le premier segment. */
export function resolveTenantSlug(pathname = typeof window !== 'undefined' ? window.location.pathname : '') {
  const segment = (String(pathname).split('/')[1] || '').toLowerCase()
  return SLUG_RE.test(segment) ? segment : null
}

export const TENANT_SLUG = resolveTenantSlug()
export const TENANT_ERROR = TENANT_SLUG
  ? null
  : 'Centre absent ou invalide dans l’adresse. Utilisez une URL de la forme /nom-du-centre/.'

export function requireTenantSlug() {
  if (!TENANT_SLUG) throw new Error(TENANT_ERROR)
  return TENANT_SLUG
}

/** En-tetes communs a absolument tous les appels API shop et admin. */
export function tenantHeaders(extra = {}) {
  return buildTenantHeaders(requireTenantSlug(), extra)
}

export function buildTenantHeaders(slug, extra = {}) {
  if (!SLUG_RE.test(String(slug))) throw new Error('Slug de centre invalide.')
  return { ...extra, 'X-Skybook-Tenant': slug }
}

export const API_BASE = '/api/v2'

// Base des medias Sylius (fallback si un chemin d'image n'est pas absolu).
// Racine PARTAGEE : les chemins d'images des tenants contiennent deja le slug
// (public/media/image/{slug}/... — voir TenantImagePathGenerator cote back).
export const MEDIA_BASE = typeof window !== 'undefined' ? window.location.origin : ''

// Les nouvelles prestations utilisent `service_`. Le prefixe historique
// `jump_` reste lisible le temps de migrer les anciens catalogues en securite.
export const SERVICE_CODE_PREFIXES = ['service_', 'jump_']
export function isServiceProductCode(code = '') {
  return SERVICE_CODE_PREFIXES.some((prefix) => String(code).startsWith(prefix))
}

// URL d'affichage d'une image Sylius : remplace le filtre liip technique
// (sylius_original, sylius_small…) par notre filtre neutre `photo`
// (config back : liip_imagine.yaml). On passe par /resolve/ : liip genere le
// fichier au premier appel puis redirige vers le cache statique.
export function displayImageUrl(path) {
  if (!path) return ''
  let abs = path.startsWith('http') ? path : `${MEDIA_BASE}${path.startsWith('/') ? '' : '/'}${path}`
  // Les URLs absolues generees par Sylius peuvent contenir son host interne.
  // Les medias applicatifs doivent toujours repasser par l'origine publique.
  if (MEDIA_BASE && path.startsWith('http')) {
    const parsed = new URL(path)
    if (parsed.pathname.startsWith('/media/')) abs = `${MEDIA_BASE}${parsed.pathname}${parsed.search}`
  }
  return abs.replace(/\/media\/cache\/(?:resolve\/)?sylius_\w+\//, '/media/cache/resolve/photo/')
}
