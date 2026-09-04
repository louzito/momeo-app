export const SITE_CONFIG_SCHEMA_VERSION = 1
export const HOME_SECTION_KEYS = ['highlights', 'catalog', 'gift']
export const DEFAULT_SHOP_COLORS = Object.freeze({
  header: '#ffffff', textHeader: '#0f172a', footer: '#0f172a', textFooter: '#ffffff',
})

const clone = (value) => JSON.parse(JSON.stringify(value))

export function normalizeSiteConfig(value = {}) {
  const cfg = value && typeof value === 'object' ? clone(value) : {}
  const sections = Array.isArray(cfg.home?.sections)
    ? cfg.home.sections.filter((key, index, all) => HOME_SECTION_KEYS.includes(key) && all.indexOf(key) === index)
    : []
  return {
    ...cfg,
    colors: { ...DEFAULT_SHOP_COLORS, ...(cfg.colors || {}) },
    socials: { instagram: '', facebook: '', x: '', youtube: '', ...(cfg.socials || {}) },
    home: {
      title: '', subtitle: '', highlights: [], catalogTitle: '', catalogText: '', featured: [],
      ...(cfg.home || {}), sections: [...sections, ...HOME_SECTION_KEYS.filter((key) => !sections.includes(key))],
    },
    shopOrder: Array.isArray(cfg.shopOrder) ? cfg.shopOrder : [],
    legal: {
      terms: { enabled: false, content: '', ...(cfg.legal?.terms || {}) },
      mentions: { enabled: false, content: '', ...(cfg.legal?.mentions || {}) },
    },
  }
}

export function readSiteConfigDocument(raw = {}) {
  if (raw?.schemaVersion === SITE_CONFIG_SCHEMA_VERSION && raw.draft && raw.published) {
    return { ...raw, draft: normalizeSiteConfig(raw.draft), published: normalizeSiteConfig(raw.published) }
  }
  const legacy = normalizeSiteConfig(raw)
  return { schemaVersion: SITE_CONFIG_SCHEMA_VERSION, revision: 0, draft: clone(legacy), published: legacy, publishedAt: null }
}

export function publishSiteConfigDocument(document, draft, now = new Date().toISOString()) {
  const normalized = normalizeSiteConfig(draft)
  return {
    schemaVersion: SITE_CONFIG_SCHEMA_VERSION,
    revision: Math.max(0, Number(document?.revision) || 0) + 1,
    draft: clone(normalized), published: normalized, publishedAt: now,
  }
}

function luminance(hex) {
  const rgb = hex.slice(1).match(/.{2}/g).map((part) => parseInt(part, 16) / 255)
    .map((part) => part <= 0.03928 ? part / 12.92 : ((part + 0.055) / 1.055) ** 2.4)
  return 0.2126 * rgb[0] + 0.7152 * rgb[1] + 0.0722 * rgb[2]
}

export function contrastRatio(a, b) {
  const [lighter, darker] = [luminance(a), luminance(b)].sort((x, y) => y - x)
  return (lighter + 0.05) / (darker + 0.05)
}

export function validateSiteConfig(cfg) {
  const errors = []
  if (!String(cfg?.name || '').trim()) errors.push('Le nom de la boutique est requis.')
  const colors = { ...DEFAULT_SHOP_COLORS, ...(cfg?.colors || {}) }
  for (const [key, value] of Object.entries(colors)) {
    if (!/^#[0-9a-f]{6}$/i.test(value)) errors.push(`La couleur ${key} doit être au format #RRGGBB.`)
  }
  if (!errors.some((error) => error.includes('couleur'))) {
    if (contrastRatio(colors.header, colors.textHeader) < 4.5) errors.push("Le contraste du header doit être d'au moins 4,5:1.")
    if (contrastRatio(colors.footer, colors.textFooter) < 4.5) errors.push("Le contraste du footer doit être d'au moins 4,5:1.")
  }
  for (const [key, page] of Object.entries(cfg?.legal || {})) {
    if (page?.enabled && !String(page.content || '').trim()) errors.push(`Le texte légal « ${key} » est activé mais vide.`)
  }
  for (const [key, url] of Object.entries(cfg?.socials || {})) {
    if (url && !/^https:\/\//i.test(url)) errors.push(`Le lien ${key} doit utiliser HTTPS.`)
  }
  return errors
}
