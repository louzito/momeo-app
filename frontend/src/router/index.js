import { clearSiteMetadata } from '@/utils/siteMetadata'
import { createRouter, createWebHistory } from 'vue-router'
import { TENANT_SLUG, TENANT_ERROR, APP_BASE } from '@/api/config'

// MULTI-CENTRES : le premier segment de l'URL est le slug du centre
// (localhost:5173/{slug}/...). Il n'existe volontairement aucun fallback vers
// un centre par defaut : cela risquerait d'exposer les donnees d'un autre centre.

// Lazy-loading de toutes les vues pour garder un bundle leger.
//
// Chaque instance de l'application vit sous la base /{slug}/. Les noms de
// routes historiques sont conserves et leurs chemins restent relatifs a cette
// base, y compris lors d'un acces direct ou d'un refresh.
const tenantRoutes = [
  // --- Vitrine du centre ----------------------------------------------------
  {
    path: '/',
    name: 'tenant-home',
    component: () => import('@/views/SitePublicPage.vue'),
    meta: { title: 'Accueil' },
  },
  // Alias historique : certains liens pointent encore le nom 'home'.
  {
    path: '/accueil',
    name: 'home',
    redirect: { name: 'tenant-home' },
  },
  {
    path: '/services/:jumpTypeId',
    name: 'jump-detail',
    component: () => import('@/views/JumpTypeDetail.vue'),
    meta: { title: 'Détail de la prestation' },
  },
  {
    path: '/cart', name: 'cart', component: () => import('@/views/checkout/CartCheckout.vue'), meta: { title: 'Votre panier' },
  },
  {
    path: '/products',
    name: 'physical-products',
    component: () => import('@/views/PhysicalProducts.vue'),
    meta: { title: 'Produits' },
  },
  {
    path: '/jump/:jumpTypeId',
    redirect: (to) => ({ name: 'jump-detail', params: { jumpTypeId: to.params.jumpTypeId } }),
  },
  {
    path: '/calendar',
    name: 'calendar',
    redirect: { name: 'shop' },
  },
  {
    path: '/waitlist/unsubscribe/:token',
    name: 'waitlist-unsubscribe',
    component: () => import('@/views/WaitlistUnsubscribe.vue'),
    meta: { title: 'Désinscription de la liste d’attente' },
  },

  {
    path: '/gift-card',
    name: 'gift-card-purchase',
    component: () => import('@/views/GiftCardPurchase.vue'),
    meta: { title: 'Offrir une carte cadeau' },
  },
  {
    path: '/gift-card/print',
    name: 'gift-card-print',
    component: () => import('@/views/GiftCardPrint.vue'),
    meta: { title: 'Votre carte cadeau' },
  },

  // --- Tunnel d'achat ------------------------------------------------------
  {
    path: '/checkout/options',
    name: 'checkout-options',
    redirect: { name: 'checkout-schedule' },
  },
  {
    path: '/checkout/mode',
    name: 'checkout-mode',
    redirect: { name: 'checkout-schedule' },
  },
  {
    path: '/checkout/schedule',
    name: 'checkout-schedule',
    component: () => import('@/views/checkout/ScheduleStep.vue'),
    meta: { title: 'Choix du creneau' },
  },
  {
    path: '/checkout/details',
    name: 'checkout-eligibility',
    component: () => import('@/views/checkout/EligibilityStep.vue'),
    meta: { title: 'Coordonnées et paiement' },
  },
  {
    path: '/checkout/eligibility',
    redirect: { name: 'checkout-eligibility' },
  },
  {
    path: '/checkout/gift',
    name: 'checkout-gift',
    component: () => import('@/views/checkout/GiftRecipient.vue'),
    meta: { title: 'Beneficiaire du cadeau' },
  },
  {
    path: '/checkout/summary',
    name: 'checkout-summary',
    redirect: { name: 'checkout-eligibility' },
  },
  {
    path: '/checkout/payment',
    name: 'checkout-payment',
    redirect: { name: 'checkout-eligibility' },
  },
  {
    path: '/checkout/confirmation/:bookingId',
    name: 'checkout-confirmation',
    component: () => import('@/views/checkout/OrderConfirmation.vue'),
    meta: { title: 'Commande confirmee', requiresCustomer: true },
  },
  {
    path: '/checkout/shop-confirmation/:orderToken',
    name: 'checkout-shop-confirmation',
    component: () => import('@/views/checkout/ShopOrderConfirmation.vue'),
    meta: { title: 'Suivi de commande' },
  },
  {
    path: '/checkout/gift-confirmation',
    name: 'checkout-gift-confirmation',
    component: () => import('@/views/checkout/GiftConfirmation.vue'),
    meta: { title: 'Cheque cadeau genere' },
  },

  // Anciennes URLs multi-tenant /t/<slug>/... -> redirigees sans le prefixe.
  {
    path: '/t/:slug/:rest(.*)*',
    redirect: (to) => '/' + (Array.isArray(to.params.rest) ? to.params.rest.join('/') : to.params.rest || ''),
  },

  // --- Espace beneficiaire (cheque cadeau) ---------------------------------
  {
    path: '/beneficiary/login',
    name: 'beneficiary-login',
    component: () => import('@/views/beneficiary/BeneficiaryLogin.vue'),
    meta: { title: 'Connexion beneficiaire' },
  },
  {
    path: '/beneficiary',
    name: 'beneficiary-dashboard',
    component: () => import('@/views/beneficiary/BeneficiaryDashboard.vue'),
    meta: { title: 'Mes cheques cadeaux', requiresBeneficiary: true },
  },
  {
    path: '/beneficiary/voucher/:code/schedule',
    name: 'beneficiary-schedule',
    component: () => import('@/views/beneficiary/VoucherSchedule.vue'),
    meta: { title: 'Choisir un creneau', requiresBeneficiary: true },
  },
  {
    path: '/beneficiary/voucher/:code/expired',
    name: 'beneficiary-expired',
    component: () => import('@/views/beneficiary/VoucherExpired.vue'),
    meta: { title: 'Cheque expire', requiresBeneficiary: true },
  },
  {
    path: '/beneficiary/voucher/:code/confirmation',
    name: 'beneficiary-confirmation',
    component: () => import('@/views/beneficiary/VoucherConfirmation.vue'),
    meta: { title: 'Reservation confirmee', requiresBeneficiary: true },
  },

  // --- Espace client (compte) ----------------------------------------------
  {
    path: '/account/login',
    name: 'account-login',
    component: () => import('@/views/account/AccountLogin.vue'),
    meta: { title: 'Connexion / inscription' },
  },
  {
    path: '/account',
    name: 'account-dashboard',
    component: () => import('@/views/account/AccountDashboard.vue'),
    meta: { title: 'Mon compte', requiresCustomer: true },
  },
  {
    path: '/account/booking/:bookingId',
    name: 'booking-detail',
    component: () => import('@/views/account/BookingDetail.vue'),
    meta: { title: 'Detail de la reservation', requiresCustomer: true },
  },
  {
    path: '/boarding-pass/:bookingId',
    name: 'boarding-pass',
    component: () => import('@/views/account/BoardingPassView.vue'),
    meta: { title: 'Confirmation de rendez-vous', requiresCustomer: true },
  },

  // --- Espace professionnel TodaTempo --------------------------------------
  {
    path: '/admin/login',
    name: 'admin-login',
    component: () => import('@/views/admin/AdminLogin.vue'),
    meta: { title: 'Espace professionnel', layout: 'admin' },
  },
  {
    path: '/admin',
    component: () => import('@/views/admin/AdminLayout.vue'),
    meta: { layout: 'admin', requiresAdmin: true },
    children: [
      { path: '', name: 'admin-dashboard', component: () => import('@/views/admin/AdminDashboard.vue'), meta: { title: 'Tableau de bord', layout: 'admin', requiresAdmin: true } },
      { path: 'products', name: 'admin-products', component: () => import('@/views/admin/AdminProducts.vue'), meta: { title: 'Produits', layout: 'admin', requiresAdmin: true, permission: 'catalog' } },
      { path: 'products/new', name: 'admin-product-new', component: () => import('@/views/admin/AdminProductEdit.vue'), meta: { title: 'Nouvelle prestation', layout: 'admin', requiresAdmin: true, permission: 'catalog' } },
      { path: 'products/:id', name: 'admin-product-edit', component: () => import('@/views/admin/AdminProductEdit.vue'), meta: { title: 'Modifier la prestation', layout: 'admin', requiresAdmin: true, permission: 'catalog' } },
      { path: 'physical-products', name: 'admin-physical-products', component: () => import('@/views/admin/AdminPhysicalProducts.vue'), meta: { title: 'Produits physiques', layout: 'admin', requiresAdmin: true, permission: 'catalog' } },
      { path: 'staff', name: 'admin-staff', component: () => import('@/views/admin/AdminStaff.vue'), meta: { title: 'Équipe', layout: 'admin', requiresAdmin: true, permission: 'settings' } },
      { path: 'options', name: 'admin-options', component: () => import('@/views/admin/AdminOptions.vue'), meta: { title: 'Upsells & options', layout: 'admin', requiresAdmin: true, permission: 'catalog' } },
      { path: 'options/new', name: 'admin-option-new', component: () => import('@/views/admin/AdminOptionEdit.vue'), meta: { title: 'Nouvelle option', layout: 'admin', requiresAdmin: true, permission: 'catalog' } },
      { path: 'options/:id', name: 'admin-option-edit', component: () => import('@/views/admin/AdminOptionEdit.vue'), meta: { title: 'Modifier l\'option', layout: 'admin', requiresAdmin: true, permission: 'catalog' } },
      { path: 'plannings', name: 'admin-plannings', component: () => import('@/views/admin/AdminPlannings.vue'), meta: { title: 'Plannings', layout: 'admin', requiresAdmin: true, permission: 'agenda' } },
      { path: 'resources', name: 'admin-resources', component: () => import('@/views/admin/AdminResources.vue'), meta: { title: 'Ressources', layout: 'admin', requiresAdmin: true, permission: 'catalog' } },
      { path: 'agenda', name: 'admin-agenda', component: () => import('@/views/admin/AdminAgenda.vue'), meta: { title: 'Agenda', layout: 'admin', requiresAdmin: true, permission: 'agenda' } },
      // Ancienne URL "Horaires" -> redirige vers les plannings.
      { path: 'schedule', redirect: { name: 'admin-agenda' } },
      { path: 'bookings', name: 'admin-bookings', component: () => import('@/views/admin/AdminBookings.vue'), meta: { title: 'Réservations', layout: 'admin', requiresAdmin: true, permission: 'agenda' } },
      { path: 'waitlist', name: 'admin-waitlist', component: () => import('@/views/admin/AdminWaitlist.vue'), meta: { title: 'Liste d’attente', layout: 'admin', requiresAdmin: true, permission: 'agenda' } },
      { path: 'clients', name: 'admin-clients', component: () => import('@/views/admin/AdminClients.vue'), meta: { title: 'Clients', layout: 'admin', requiresAdmin: true, permission: 'clients' } },
      { path: 'orders', name: 'admin-orders', component: () => import('@/views/admin/AdminOrders.vue'), meta: { title: 'Commandes', layout: 'admin', requiresAdmin: true, permission: 'finances' } },
      { path: 'vouchers', name: 'admin-vouchers', component: () => import('@/views/admin/AdminVouchers.vue'), meta: { title: 'Chèques cadeaux', layout: 'admin', requiresAdmin: true, permission: 'finances' } },
      { path: 'payments', name: 'admin-payments', component: () => import('@/views/admin/AdminPayments.vue'), meta: { title: 'Moyens de paiement', layout: 'admin', requiresAdmin: true, permission: 'finances' } },
      { path: 'site/images', name: 'admin-site-images', component: () => import('@/views/admin/AdminSiteImages.vue'), meta: { title: 'Images du site', layout: 'admin', requiresAdmin: true, permission: 'settings' } },
      { path: 'site/appearance', name: 'admin-site-appearance', component: () => import('@/views/admin/AdminSiteAppearance.vue'), meta: { title: 'Apparence', layout: 'admin', requiresAdmin: true, permission: 'settings' } },
      { path: 'site/menus', name: 'admin-site-menus', component: () => import('@/views/admin/AdminSiteMenus.vue'), meta: { title: 'Menus du site', layout: 'admin', requiresAdmin: true, permission: 'settings' } },
      { path: 'site/pages', name: 'admin-site-pages', component: () => import('@/views/admin/AdminSitePages.vue'), meta: { title: 'Pages du site', layout: 'admin', requiresAdmin: true, permission: 'settings' } },
      { path: 'site/pages/:id/edit', name: 'admin-site-page-editor', component: () => import('@/views/admin/AdminSitePageEditor.vue'), meta: { title: 'Composer la page', layout: 'admin', requiresAdmin: true, permission: 'settings' } },
      { path: 'settings', name: 'admin-settings', component: () => import('@/views/admin/AdminSettings.vue'), meta: { title: 'Configuration boutique', layout: 'admin', requiresAdmin: true, permission: 'settings' } },
    ],
  },

  {
    path: '/admin/site/preview/:id', name: 'admin-site-preview', component: () => import('@/views/admin/AdminSitePreview.vue'),
    meta: { title: 'Aperçu privé', layout: 'admin', requiresAdmin: true, permission: 'settings' },
  },
  {
    path: '/:slug([a-z0-9-]+)', name: 'site-page', component: () => import('@/views/SitePublicPage.vue'), meta: { title: 'Page' },
  },

  // Facture imprimable : HORS layout admin (pas de sidebar a l'impression),
  // mais protegee comme le reste de l'espace centre.
  {
    path: '/admin/orders/:token/invoice',
    name: 'admin-invoice',
    component: () => import('@/views/admin/AdminInvoice.vue'),
    // layout 'admin' = App.vue masque le chrome public (navbar/footer) ; la route
    // etant top-level, elle n'herite pas non plus de la sidebar AdminLayout ->
    // page nue, propre a imprimer.
    meta: { title: 'Facture', layout: 'admin', requiresAdmin: true, permission: 'finances' },
  },

  // --- Pages d'etat / erreurs ----------------------------------------------
  {
    path: '/status/slot-unavailable',
    name: 'slot-unavailable',
    component: () => import('@/views/errors/SlotUnavailable.vue'),
    meta: { title: 'Creneau indisponible' },
  },
  {
    path: '/status/eligibility-blocked',
    name: 'eligibility-blocked',
    component: () => import('@/views/errors/EligibilityBlocked.vue'),
    meta: { title: 'Eligibilite non respectee' },
  },
  {
    path: '/status/voucher-invalid',
    name: 'voucher-invalid',
    component: () => import('@/views/errors/VoucherInvalid.vue'),
    meta: { title: 'Cheque invalide' },
  },
  {
    // Page Boutique : tous les produits (l'accueil n'affiche que la selection).
    path: '/shop',
    name: 'shop',
    component: () => import('@/views/ShopPage.vue'),
    meta: { title: 'Boutique' },
  },
  {
    // Pages legales configurables (CGV / mentions) — liens auto dans le footer.
    path: '/legal/:page(terms|mentions)',
    name: 'legal-page',
    component: () => import('@/views/SitePublicPage.vue'),
    meta: { title: 'Informations legales' },
  },
  {
    path: '/:pathMatch(.*)*',
    name: 'not-found',
    component: () => import('@/views/errors/NotFound.vue'),
    meta: { title: 'Page introuvable' },
  },
]

const invalidTenantRoutes = [{
  path: '/:pathMatch(.*)*',
  name: 'invalid-tenant',
  component: () => import('@/views/errors/InvalidTenant.vue'),
  props: { message: TENANT_ERROR },
  meta: { title: 'Centre invalide' },
}]

const router = createRouter({
  // Base multi-centres : toutes les routes vivent sous /{slug}/ (les noms de
  // routes et les paths relatifs ci-dessus ne changent pas).
  history: createWebHistory(TENANT_SLUG ? `${APP_BASE}${TENANT_SLUG}/` : APP_BASE),
  routes: TENANT_SLUG ? tenantRoutes : invalidTenantRoutes,
  scrollBehavior(to, from, savedPosition) {
    if (savedPosition) return savedPosition
    if (to.name === 'shop' && from.name === 'shop') return false
    return { top: 0 }
  },
})

// La garde améliore l'UX ; l'autorisation réelle reste appliquée par l'API.
router.beforeEach(async (to) => {
  if (to.name === 'admin-settings' && (to.query.section === 'appearance' || to.query.tab === 'appearance')) return { name: 'admin-site-appearance' }
  clearSiteMetadata()
  document.title = to.meta?.title ? `${to.meta.title} · TodaTempo` : 'TodaTempo'

  if (['checkout-schedule', 'checkout-eligibility', 'checkout-gift'].includes(to.name)) {
    const { useCartStore } = await import('@/stores/cart')
    const cart = useCartStore()
    if (!cart.jumpType) return { name: 'shop' }
    if (cart.lastResult?.booking) {
      return { name: 'checkout-confirmation', params: { bookingId: cart.lastResult.booking.id } }
    }
    cart.setKind('direct')
    if (to.name === 'checkout-gift' || (to.name === 'checkout-eligibility' && !cart.slot)) {
      return { name: 'checkout-schedule' }
    }
  }

  if (to.meta?.requiresBeneficiary) {
    const { useBeneficiaryStore } = await import('@/stores/beneficiary')
    const store = useBeneficiaryStore()
    if (!store.isLoggedIn) {
      return { name: 'beneficiary-login', query: { redirect: to.fullPath } }
    }
  }
  if (to.meta?.requiresCustomer) {
    const { useSessionStore } = await import('@/stores/session')
    const store = useSessionStore()
    await store.restore()
    if (!store.isLoggedIn) {
      return { name: 'account-login', query: { redirect: to.fullPath } }
    }
  }
  if (to.meta?.requiresAdmin) {
    const { useAdminStore } = await import('@/stores/admin')
    const store = useAdminStore()
    if (!store.isLoggedIn) {
      return { name: 'admin-login', query: { redirect: to.fullPath } }
    }
    if (to.meta.permission && !store.can(to.meta.permission)) {
      const fallback = ['agenda', 'clients', 'finances', 'catalog', 'settings'].find((permission) => store.can(permission))
      const destinations = { agenda: 'admin-agenda', clients: 'admin-clients', finances: 'admin-orders', catalog: 'admin-products', settings: 'admin-settings' }
      return fallback ? { name: destinations[fallback] } : { name: 'admin-login' }
    }
  }
  return true
})

export default router

router.afterEach((to) => {
  if (!['tenant-home', 'legal-page', 'site-page'].includes(to.name)) document.getElementById('site-initial')?.remove()
})
