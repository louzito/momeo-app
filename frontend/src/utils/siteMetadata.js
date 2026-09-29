export function clearSiteMetadata() {
  document.querySelectorAll('link[rel="canonical"], meta[property^="og:"], meta[name^="twitter:"], meta[name="description"], meta[name="robots"]').forEach(node => node.remove())
  const robots = document.createElement('meta')
  robots.name = 'robots'; robots.content = 'noindex, nofollow'
  document.head.append(robots)
}
export function applySiteMetadata(meta) {
  clearSiteMetadata()
  if (!meta) return
  document.title = meta.title
  document.querySelector('meta[name="robots"]').content = 'index, follow'
  const link = document.createElement('link'); link.rel = 'canonical'; link.href = meta.canonical; document.head.append(link)
  for (const [key, value] of Object.entries({ description: meta.description, 'og:type': 'website', 'og:title': meta.title, 'og:description': meta.description, 'og:url': meta.canonical, 'og:image': meta.image, 'twitter:card': meta.image ? 'summary_large_image' : 'summary', 'twitter:title': meta.title, 'twitter:description': meta.description, 'twitter:image': meta.image })) {
    if (!value) continue
    const node = document.createElement('meta'); node.setAttribute(key.startsWith('og:') ? 'property' : 'name', key); node.content = value; document.head.append(node)
  }
}
