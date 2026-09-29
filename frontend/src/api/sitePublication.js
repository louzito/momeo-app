import { API_BASE, TENANT_SLUG, tenantHeaders } from './config'
export async function getPublishedSitePage(kind, value) {
  const response = await fetch(`${API_BASE}/shop/site/${kind}/${encodeURIComponent(value)}`, { headers: tenantHeaders({ Accept: 'application/json' }), cache: 'no-store' })
  if (response.status === 404) return null
  if (!response.ok) throw new Error('Impossible de charger cette page.')
  const page = await response.json()
  if (!page?.document || !Array.isArray(page.document.blocks) || typeof page.slug !== 'string') throw new Error('Impossible de charger cette page.')
  return page
}

export const sitePublicationKey = `todatempo.site.publication.${TENANT_SLUG}`
export function invalidateSite() {
  try { localStorage.setItem(sitePublicationKey, String(Date.now())) } catch { /* Current tab still refreshes. */ }
  window.dispatchEvent(new Event('site-published'))
}
