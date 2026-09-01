// Comptes clients factices (espace "mon compte") et beneficiaires de cheques
// cadeaux. Les mots de passe sont volontairement en clair : c'est une maquette
// sans backend, uniquement destinee a la navigation. Voir IDENTIFIANTS_TEST.md.

export const customers = [
  {
    id: 'cust_lou',
    tenantId: 'dz_skyline',
    email: 'client@momeo.test',
    password: 'momeo2026',
    firstName: 'Lou',
    lastName: 'Martin',
    phone: '+1 (951) 555-0110',
    createdAt: '2025-11-04T10:00:00.000Z',
  },
  {
    id: 'cust_marie',
    tenantId: 'dz_chutelibre',
    email: 'marie@skybook.test',
    password: 'quebec2026',
    firstName: 'Marie',
    lastName: 'Tremblay',
    phone: '+1 (450) 555-0120',
    createdAt: '2026-01-15T10:00:00.000Z',
  },
]

// Beneficiaires : identifies par (code du cheque + email). Un meme email peut
// posseder plusieurs cheques (voir commerce.js). On liste ici les emails et un
// nom d'affichage pour l'espace beneficiaire.
export const beneficiaries = [
  { email: 'emma@example.com', firstName: 'Emma', lastName: 'Wilson' },
  { email: 'luc@example.com', firstName: 'Luc', lastName: 'Gagnon' },
  { email: 'sofia@example.com', firstName: 'Sofia', lastName: 'Morales' },
]
