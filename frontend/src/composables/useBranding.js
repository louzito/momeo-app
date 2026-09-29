import { siteThemeStyle } from '@/utils/siteTheme'
export { normalizeShopColors, SHOP_DEFAULT_COLORS } from '@/utils/siteTheme'

export function applyBranding(tenant) {
  if (typeof document === 'undefined') return
  for (const [name, value] of Object.entries(siteThemeStyle(tenant))) {
    if (name.startsWith('--')) document.documentElement.style.setProperty(name, value)
  }
}
