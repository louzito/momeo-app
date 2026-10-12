// Fontsource exposes the complete Google Fonts catalogue without an API key.
export const validFontFamily = value => typeof value === 'string' && /^[A-Za-z0-9][A-Za-z0-9 -]{0,99}$/.test(value)
export function loadThemeFonts(typography) {
  if (typeof document === 'undefined' || !typography || typeof typography !== 'object') return
  for (const family of new Set([typography.heading, typography.body])) {
    if (!validFontFamily(family)) continue
    const href = `https://fonts.googleapis.com/css2?family=${encodeURIComponent(family)}&display=swap`
    if (Array.from(document.querySelectorAll('link[data-site-font]')).some(link => link.href === href)) continue
    const link = document.createElement('link')
    link.rel = 'stylesheet'; link.href = href; link.dataset.siteFont = family
    document.head.appendChild(link)
  }
}
export async function getGoogleFonts() {
  const response = await fetch('https://api.fontsource.org/v1/fonts?type=google', { signal: AbortSignal.timeout(15000) })
  if (!response.ok) throw new Error('Catalogue indisponible')
  const fonts = await response.json()
  const families = [...new Set(fonts.filter(font => font.type === 'google' && validFontFamily(font.family)).map(font => font.family))].sort((a, b) => a.localeCompare(b))
  if (!families.length) throw new Error('Catalogue vide')
  return families
}
