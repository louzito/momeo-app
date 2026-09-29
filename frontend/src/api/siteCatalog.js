import { orderJumpTypes } from '../utils/catalog.js'
import { fetchPublicCatalog } from './publicCatalog.js'

// Deliberately not held in the tenant store: every page mount reads current prices.
export async function fetchSiteCatalog(api, slug) {
  const [catalog, products] = await Promise.all([
    fetchPublicCatalog(api, slug), api.getPhysicalProducts(`workspace_${slug}`),
  ])
  return { ...catalog, products }
}

export function selectSiteOffers(props, catalog) {
  const services = orderJumpTypes(catalog?.jumpTypes || [], catalog?.tenant?.shopOrder).map(value => ({ kind: 'service', value }))
  const products = orderJumpTypes(catalog?.products || [], catalog?.tenant?.shopOrder).map(value => ({ kind: 'physical', value }))
  const all = [...services, ...products]
  const selected = props.mode === 'category'
    ? (props.category === 'produits' ? products : services)
    : props.codes.map(code => all.find(item => item.value.id === code)).filter(Boolean)
  return selected.slice(0, props.limit)
}
