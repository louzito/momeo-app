// Existing settings URL remains the single editor and persistence boundary.
export const SETTINGS_SECTIONS = {
  general: 'Établissement',
  home: 'Page d’accueil',
  appearance: 'Apparence',
  shop: 'Commerce',
  emails: 'Emails',
  terms: 'Conditions générales',
  mentions: 'Mentions légales',
}

export function settingsSection(query = {}) {
  return typeof query.section === 'string' && Object.hasOwn(SETTINGS_SECTIONS, query.section)
    ? query.section : 'general'
}

const settings = (section) => ({ name: 'admin-settings', label: SETTINGS_SECTIONS[section], section })
const GROUPS = [
  { id: 'dashboard', label: 'Tableau de bord', items: [{ name: 'admin-dashboard', label: 'Vue d’ensemble' }] },
  { id: 'appointments', label: 'Rendez-vous', items: [
    { name: 'admin-agenda', label: 'Agenda' },
    { name: 'admin-bookings', label: 'Réservations' },
    { name: 'admin-waitlist', label: 'Liste d’attente' },
  ] },
  { id: 'clients', label: 'Clients', items: [{ name: 'admin-clients', label: 'Liste des clients' }] },
  { id: 'catalog', label: 'Catalogue', items: [
    { name: 'admin-products', label: 'Prestations', details: ['admin-product-new', 'admin-product-edit'] },
    { name: 'admin-physical-products', label: 'Produits physiques' },
    { name: 'admin-options', label: 'Options et suppléments', details: ['admin-option-new', 'admin-option-edit'] },
  ] },
  { id: 'sales', label: 'Ventes', items: [
    { name: 'admin-orders', label: 'Commandes et factures', details: ['admin-invoice'] },
    { name: 'admin-vouchers', label: 'Cartes et chèques cadeaux' },
  ] },
  { id: 'site', label: 'Mon site internet', items: [
    { name: 'admin-site-pages', label: 'Pages' },
    { name: 'admin-site-menus', label: 'Menus' },
    settings('home'), settings('appearance'), settings('terms'), settings('mentions'),
  ] },
  { id: 'settings', label: 'Réglages', items: [
    settings('general'),
    { name: 'admin-staff', label: 'Équipe' },
    { name: 'admin-plannings', label: 'Plannings' },
    { name: 'admin-resources', label: 'Ressources' },
    { name: 'admin-payments', label: 'Moyens de paiement' },
    settings('shop'), settings('emails'),
  ] },
]

export function adminNavigation(router, can) {
  return GROUPS.map((group) => ({
    ...group,
    items: group.items.filter((item) => {
      if (!router.hasRoute(item.name)) return false
      const permission = router.resolve({ name: item.name }).meta.permission
      return !permission || can(permission)
    }),
  })).filter((group) => group.items.length)
}

export function isAdminItemActive(item, route) {
  if (item.name !== route.name && !item.details?.includes(route.name)) return false
  return !item.section || item.section === settingsSection(route.query)
}

export function adminItemLocation(item) {
  return { name: item.name, ...(item.section ? { query: { section: item.section } } : {}) }
}
