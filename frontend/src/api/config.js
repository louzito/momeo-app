// =============================================================================
// Configuration de la source de donnees du front — MULTI-CENTRES PAR SLUG
// -----------------------------------------------------------------------------
// L'URL porte le centre : localhost:5173/{slug}/... Le premier segment du
// chemin est le slug du tenant ; toute l'API passe par /{slug}/api/v2 (le
// middleware Vite — ou le reverse proxy en prod — reecrit vers /api/v2 en
// ajoutant l'en-tete X-Skybook-Tenant, si bien que Sylius ne voit jamais le
// prefixe). Le slug etant fixe pour toute la duree de vie de la page (une
// navigation inter-centres recharge l'app), API_BASE reste une constante.
// =============================================================================

export const USE_REAL_API = true

// Slug par defaut (centre historique) : utilise quand l'URL n'a pas encore de
// slug — le router redirige alors vers /skyline/.
export const DEFAULT_SLUG = 'skyline'

const SLUG_RE = /^[a-z0-9][a-z0-9-]{0,62}$/

/** Slug du centre courant, lu dans le premier segment de l'URL. */
export function resolveTenantSlug() {
  if (typeof window === 'undefined') return DEFAULT_SLUG
  const seg = window.location.pathname.split('/')[1] || ''
  return SLUG_RE.test(seg) ? seg : DEFAULT_SLUG
}

export const TENANT_SLUG = resolveTenantSlug()

// Chemin de l'API cote front : /{slug}/api/v2, proxifie par Vite vers Sylius
// (voir vite.config.js) avec l'en-tete X-Skybook-Tenant -> pas de CORS.
export const API_BASE = `/${TENANT_SLUG}/api/v2`

// Base des medias Sylius (fallback si un chemin d'image n'est pas absolu).
// Racine PARTAGEE : les chemins d'images des tenants contiennent deja le slug
// (public/media/image/{slug}/... — voir TenantImagePathGenerator cote back).
export const MEDIA_BASE = 'http://localhost:8080'

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
  const abs = path.startsWith('http') ? path : `${MEDIA_BASE}${path}`
  return abs.replace(/\/media\/cache\/(?:resolve\/)?sylius_\w+\//, '/media/cache/resolve/photo/')
}
