// Donnees d'exploitation cote centre (back-office) : comptes admin, horaires de
// creneaux par centre, et moyens de paiement. Tout est factice.

// --- Comptes de l'équipe --------------------------------------------------
export const admins = [
  {
    id: 'adm_skyline',
    tenantId: 'dz_skyline',
    email: 'admin@institut-lumiere.example',
    password: 'admin123',
    name: 'Camille Martin',
    role: 'Responsable établissement',
  },
  {
    id: 'adm_chutelibre',
    tenantId: 'dz_chutelibre',
    email: 'admin@atelier-nova.example',
    password: 'quebec',
    name: 'Geneviève Roy',
    role: 'Responsable établissement',
  },
  {
    id: 'adm_andes',
    tenantId: 'dz_andes',
    email: 'admin@maison-sauge.example',
    password: 'andes',
    name: 'Camila Torres',
    role: 'Responsable établissement',
  },
]

// --- Horaires de creneaux par centre --------------------------------------
// openDays : 0=dimanche .. 6=samedi. times : plages de depart. capacity : places.
export const schedules = {
  dz_skyline: { openDays: [0, 1, 3, 4, 5, 6], times: ['09:00', '11:30', '14:00', '16:30'], capacity: 8 },
  dz_chutelibre: { openDays: [4, 5, 6, 0], times: ['09:00', '11:00', '13:30', '15:30'], capacity: 6 },
  dz_andes: { openDays: [1, 2, 3, 4, 5, 6], times: ['08:30', '10:30', '12:30'], capacity: 6 },
}

// --- Moyens de paiement par centre (pilotes par le centre) -----------------
export const paymentMethods = {
  dz_skyline: [
    { id: 'pm_sky_stripe', type: 'stripe', label: 'Stripe (CB, Apple Pay, Google Pay)', enabled: true, publicKey: 'pk_live_sky_9f2A...', account: 'acct_sky_1042' },
    { id: 'pm_sky_paypal', type: 'paypal', label: 'PayPal', enabled: true, publicKey: '', account: 'paypal-skyline@dz.example' },
    { id: 'pm_sky_offline', type: 'offline', label: 'Virement / sur place (B2B, CE)', enabled: false, publicKey: '', account: '' },
  ],
  dz_chutelibre: [
    { id: 'pm_cl_stripe', type: 'stripe', label: 'Stripe (CB, Apple Pay)', enabled: true, publicKey: 'pk_live_cl_7b1Z...', account: 'acct_cl_2201' },
    { id: 'pm_cl_interac', type: 'interac', label: 'Interac (via passerelle)', enabled: true, publicKey: '', account: 'interac-chutelibre' },
    { id: 'pm_cl_paypal', type: 'paypal', label: 'PayPal', enabled: false, publicKey: '', account: '' },
  ],
  dz_andes: [
    { id: 'pm_an_mp', type: 'mercadopago', label: 'Mercado Pago', enabled: true, publicKey: 'APP_USR-an-...', account: 'mp_andes' },
    { id: 'pm_an_stripe', type: 'stripe', label: 'Stripe (CB)', enabled: true, publicKey: 'pk_live_an_3c8...', account: 'acct_an_5510' },
    { id: 'pm_an_offline', type: 'offline', label: 'Especes sur place', enabled: false, publicKey: '', account: '' },
  ],
}

export const PAYMENT_TYPES = [
  { type: 'stripe', label: 'Stripe', icon: '💳' },
  { type: 'paypal', label: 'PayPal', icon: '🅿️' },
  { type: 'mercadopago', label: 'Mercado Pago', icon: '🛒' },
  { type: 'interac', label: 'Interac', icon: '🍁' },
  { type: 'offline', label: 'Virement / sur place', icon: '🏦' },
]
