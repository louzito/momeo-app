import { createRouter, createWebHistory } from 'vue-router'
import { TENANT_SLUG, TENANT_ERROR } from '@/api/config'

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
    component: () => import('@/views/TenantHome.vue'),
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
    path: '/jump/:jumpTypeId',
    redirect: (to) => ({ name: 'jump-detail', params: { jumpTypeId: to.params.jumpTypeId } }),
  },
  {
    path: '/calendar',
    name: 'calendar',
    component: () => import('@/views/CalendarBrowse.vue'),
    meta: { title: 'Calendrier des creneaux' },
  },

  // --- Tunnel d'achat ------------------------------------------------------
  {
    path: '/checkout/options',
    name: 'checkout-options',
    component: () => import('@/views/checkout/OptionsSelection.vue'),
    meta: { title: 'Options' },
  },
  {
    path: '/checkout/mode',
    name: 'checkout-mode',
    component: () => import('@/views/checkout/PurchaseMode.vue'),
    meta: { title: 'Pour moi ou en cadeau ?' },
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
    meta: { title: 'Coordonnees et consentements' },
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
    component: () => import('@/views/checkout/OrderSummary.vue'),
    meta: { title: 'Recapitulatif' },
  },
  {
    path: '/checkout/payment',
    name: 'checkout-payment',
    component: () => import('@/views/checkout/Payment.vue'),
    meta: { title: 'Paiement' },
  },
  {
    path: '/checkout/confirmation',
    name: 'checkout-confirmation',
    component: () => import('@/views/checkout/OrderConfirmation.vue'),
    meta: { title: 'Commande confirmee' },
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
    meta: { title: 'Confirmation de rendez-vous' },
  },

  // --- Espace professionnel Momeo ------------------------------------------
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
      { path: 'products', name: 'admin-products', component: () => import('@/views/admin/AdminProducts.vue'), meta: { title: 'Produits', layout: 'admin', requiresAdmin: true } },
      { path: 'products/new', name: 'admin-product-new', component: () => import('@/views/admin/AdminProductEdit.vue'), meta: { title: 'Nouvelle prestation', layout: 'admin', requiresAdmin: true } },
      { path: 'products/:id', name: 'admin-product-edit', component: () => import('@/views/admin/AdminProductEdit.vue'), meta: { title: 'Modifier la prestation', layout: 'admin', requiresAdmin: true } },
      { path: 'staff', name: 'admin-staff', component: () => import('@/views/admin/AdminStaff.vue'), meta: { title: 'Équipe', layout: 'admin', requiresAdmin: true } },
      { path: 'options', name: 'admin-options', component: () => import('@/views/admin/AdminOptions.vue'), meta: { title: 'Upsells & options', layout: 'admin', requiresAdmin: true } },
      { path: 'options/new', name: 'admin-option-new', component: () => import('@/views/admin/AdminOptionEdit.vue'), meta: { title: 'Nouvelle option', layout: 'admin', requiresAdmin: true } },
      { path: 'options/:id', name: 'admin-option-edit', component: () => import('@/views/admin/AdminOptionEdit.vue'), meta: { title: 'Modifier l\'option', layout: 'admin', requiresAdmin: true } },
      { path: 'plannings', name: 'admin-plannings', component: () => import('@/views/admin/AdminPlannings.vue'), meta: { title: 'Plannings', layout: 'admin', requiresAdmin: true } },
      { path: 'agenda', name: 'admin-agenda', component: () => import('@/views/admin/AdminAgenda.vue'), meta: { title: 'Agenda', layout: 'admin', requiresAdmin: true } },
      // Ancienne URL "Horaires" -> redirige vers les plannings.
      { path: 'schedule', redirect: { name: 'admin-agenda' } },
      { path: 'bookings', name: 'admin-bookings', component: () => import('@/views/admin/AdminBookings.vue'), meta: { title: 'Réservations', layout: 'admin', requiresAdmin: true } },
      { path: 'clients', name: 'admin-clients', component: () => import('@/views/admin/AdminClients.vue'), meta: { title: 'Clients', layout: 'admin', requiresAdmin: true } },
      { path: 'orders', name: 'admin-orders', component: () => import('@/views/admin/AdminOrders.vue'), meta: { title: 'Commandes', layout: 'admin', requiresAdmin: true } },
      { path: 'vouchers', name: 'admin-vouchers', component: () => import('@/views/admin/AdminVouchers.vue'), meta: { title: 'Chèques cadeaux', layout: 'admin', requiresAdmin: true } },
      { path: 'payments', name: 'admin-payments', component: () => import('@/views/admin/AdminPayments.vue'), meta: { title: 'Moyens de paiement', layout: 'admin', requiresAdmin: true } },
      { path: 'settings', name: 'admin-settings', component: () => import('@/views/admin/AdminSettings.vue'), meta: { title: 'Configuration boutique', layout: 'admin', requiresAdmin: true } },
    ],
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
    meta: { title: 'Facture', layout: 'admin', requiresAdmin: true },
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
    component: () => import('@/views/LegalPage.vue'),
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
  history: createWebHistory(TENANT_SLUG ? `/${TENANT_SLUG}/` : '/'),
  routes: TENANT_SLUG ? tenantRoutes : invalidTenantRoutes,
  scrollBehavior() {
    return { top: 0 }
  },
})

// Garde de navigation (mock) : protege les espaces beneficiaire / client.
router.beforeEach(async (to) => {
  document.title = to.meta?.title ? `${to.meta.title} · Momeo` : 'Momeo'

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
  }
  return true
})

export default router
