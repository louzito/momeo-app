import { BRAND_PALETTES, ACCENT_PALETTES } from './palettes.js'

export const TYPOGRAPHIES = {
  modern: { label: 'Moderne', body: 'Inter, ui-sans-serif, system-ui, sans-serif', heading: '"Plus Jakarta Sans", Inter, sans-serif' },
  classic: { label: 'Classique', body: 'Inter, ui-sans-serif, system-ui, sans-serif', heading: 'Georgia, serif' },
  system: { label: 'Simple', body: 'system-ui, sans-serif', heading: 'system-ui, sans-serif' },
}
export const SHOP_DEFAULT_COLORS = { header: '#020617', footer: '#020617', textHeader: '#ffffff', textFooter: '#ffffff' }
export function normalizeShopColors(raw = {}) {
  const c = raw || {}
  const color = (value, fallback) => /^#[a-f0-9]{6}$/i.test(value || '') ? value : fallback
  return { header: color(c.header, SHOP_DEFAULT_COLORS.header), footer: color(c.footer, SHOP_DEFAULT_COLORS.footer), textHeader: color(c.textHeader || c.text, SHOP_DEFAULT_COLORS.textHeader), textFooter: color(c.textFooter || c.text, SHOP_DEFAULT_COLORS.textFooter) }
}
// The same CSS variables are scoped to a preview or applied to the public document.
export function siteThemeStyle(config = {}) {
  const colors = normalizeShopColors(config?.colors)
  const font = TYPOGRAPHIES[config?.typography] || TYPOGRAPHIES.modern
  const style = { '--sb-header-bg': colors.header, '--sb-header-text': colors.textHeader, '--sb-footer-bg': colors.footer, '--sb-footer-text': colors.textFooter, '--site-font-body': font.body, '--site-font-heading': font.heading, fontFamily: font.body }
  for (const [key, palette] of [['brand', BRAND_PALETTES[config?.branding?.brandPalette] || BRAND_PALETTES.sky], ['accent', ACCENT_PALETTES[config?.branding?.accent] || ACCENT_PALETTES.orange]]) {
    for (const [shade, value] of Object.entries(palette)) style[`--${key}-${shade}`] = value
  }
  return style
}
