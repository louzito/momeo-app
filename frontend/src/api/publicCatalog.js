/** Charge le catalogue public exclusivement depuis l'API injectee. */
export async function fetchPublicCatalog(api, slug) {
  const channel = await api.getShopChannel()
  if (!channel?.code || !channel?.currency) {
    throw new Error("Le canal de vente de cet établissement est indisponible.")
  }

  const tenantId = `workspace_${slug}`
  const [config, jumpTypes, options] = await Promise.all([
    api.getPublicShopConfig(),
    api.getJumpTypes(tenantId),
    api.getOptions(tenantId),
  ])
  const tenant = {
    id: tenantId,
    slug,
    name: config?.name || channel.name || channel.code,
    currency: channel.currency,
    email: config?.contactEmail || '',
    phone: config?.contactPhone || '',
    city: config?.address?.city || '',
    address: config?.address || null,
    socials: config?.socials || {},
    logoUrl: config?.logoUrl || '',
    colors: config?.colors || null,
    home: config?.home || null,
    shopOrder: Array.isArray(config?.shopOrder) ? config.shopOrder : [],
    highlights: config?.home?.highlights || [],
    giftVouchersEnabled: config?.giftVouchersEnabled !== false,
    legal: config?.legal || null,
    bookingRules: config?.bookingRules || null,
    bannerUrl: config?.bannerUrl || '',
    bannerMobileUrl: config?.bannerMobileUrl || '',
    tagline: '',
    about: '',
  }
  return { tenant, jumpTypes, options }
}
