// Applique dynamiquement le branding d'un tenant en injectant les variables CSS
// --brand-* et --accent-* sur <html>. Tailwind (brand-xxx / accent-xxx) suit
// automatiquement (voir tailwind.config.js + assets/main.css).
//
// S'y ajoutent les COULEURS DE LA BOUTIQUE configurees dans l'espace centre
// (Configuration boutique -> taxon Sylius skybook_config) :
//   --sb-header-bg / --sb-header-text : fond et texte du header
//   --sb-footer-bg / --sb-footer-text : fond et texte du footer
// Des defauts sont toujours poses (l'apparence historique du site), et
// l'ancien champ unique `text` est migre vers les deux couleurs de texte.

import { BRAND_PALETTES, ACCENT_PALETTES } from '@/utils/palettes'

export const SHOP_DEFAULT_COLORS = {
  header: '#020617',
  footer: '#020617',
  textHeader: '#ffffff',
  textFooter: '#ffffff',
}

// Normalise un objet colors (defauts + migration du legacy `text`).
export function normalizeShopColors(raw = {}) {
  const c = raw || {}
  return {
    header: c.header || SHOP_DEFAULT_COLORS.header,
    footer: c.footer || SHOP_DEFAULT_COLORS.footer,
    textHeader: c.textHeader || c.text || SHOP_DEFAULT_COLORS.textHeader,
    textFooter: c.textFooter || c.text || SHOP_DEFAULT_COLORS.textFooter,
  }
}

export function applyBranding(tenant) {
  if (typeof document === 'undefined') return
  const root = document.documentElement

  if (tenant?.branding) {
    const brand = BRAND_PALETTES[tenant.branding.brandPalette] || BRAND_PALETTES.sky
    const accent = ACCENT_PALETTES[tenant.branding.accent] || ACCENT_PALETTES.orange
    Object.entries(brand).forEach(([shade, rgb]) => {
      root.style.setProperty(`--brand-${shade}`, rgb)
    })
    Object.entries(accent).forEach(([shade, rgb]) => {
      root.style.setProperty(`--accent-${shade}`, rgb)
    })
  }

  const colors = normalizeShopColors(tenant?.colors)
  root.style.setProperty('--sb-header-bg', colors.header)
  root.style.setProperty('--sb-footer-bg', colors.footer)
  root.style.setProperty('--sb-header-text', colors.textHeader)
  root.style.setProperty('--sb-footer-text', colors.textFooter)
}
