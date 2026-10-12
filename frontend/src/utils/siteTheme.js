import { validFontFamily } from './googleFonts.js'
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
export function normalizeTypography(value) {
  if (value && typeof value === 'object') return { heading: validFontFamily(value.heading) ? value.heading : 'Plus Jakarta Sans', body: validFontFamily(value.body) ? value.body : 'Inter' }
  return { heading: value === 'classic' ? 'Georgia' : value === 'system' ? 'system-ui' : 'Plus Jakarta Sans', body: value === 'system' ? 'system-ui' : 'Inter' }
}
export function brandingColor(value, palettes, fallback) {
  if (/^#[a-f0-9]{6}$/i.test(value || '')) return value
  const palette = palettes[value] || palettes[fallback]
  return '#' + palette[palettes === ACCENT_PALETTES ? 500 : 600].split(' ').map(v => Number(v).toString(16).padStart(2, '0')).join('')
}
export function customPalette(hex, baseShade) {
  const rgb = hex.slice(1).match(/../g).map(v => parseInt(v, 16))
  return Object.fromEntries([50, 100, 200, 300, 400, 500, 600, 700, 800, 900].map(shade => {
    const amount = shade < baseShade ? (baseShade - shade) / baseShade * .95 : (shade - baseShade) / (1000 - baseShade) * .65
    return [shade, rgb.map(v => Math.round(shade < baseShade ? v + (255 - v) * amount : v * (1 - amount))).join(' ')]
  }))
}
// The same CSS variables are scoped to a preview or applied to the public document.
export function siteThemeStyle(config = {}) {
  const colors = normalizeShopColors(config?.colors)
  const selected = config?.typography
  const families = normalizeTypography(selected)
  const font = selected && typeof selected === 'object' ? { body: `"${families.body}", system-ui, sans-serif`, heading: `"${families.heading}", sans-serif` } : TYPOGRAPHIES[selected] || TYPOGRAPHIES.modern
  const style = { '--sb-header-bg': colors.header, '--sb-header-text': colors.textHeader, '--sb-footer-bg': colors.footer, '--sb-footer-text': colors.textFooter, '--site-font-body': font.body, '--site-font-heading': font.heading, fontFamily: font.body }
  for (const [key, palette] of [['brand', BRAND_PALETTES[config?.branding?.brandPalette] || BRAND_PALETTES.sky], ['accent', ACCENT_PALETTES[config?.branding?.accent] || ACCENT_PALETTES.orange]]) {
    const color = config?.branding?.[key === 'brand' ? 'brandPalette' : 'accent']
    const resolved = /^#[a-f0-9]{6}$/i.test(color || '') ? customPalette(color, key === 'brand' ? 600 : 500) : palette
    for (const [shade, value] of Object.entries(resolved)) style[`--${key}-${shade}`] = value
  }
  return style
}
