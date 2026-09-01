// Établissements de démonstration utilisés lorsque l’API réelle est désactivée.

export const tenants = [
  {
    id: 'dz_skyline', slug: 'skyline', name: 'Institut Lumière',
    tagline: 'Des soins précis, une parenthèse rien qu’à vous',
    country: 'FR', city: 'Paris', currency: 'EUR', locale: 'fr-FR',
    phone: '+33 1 84 80 20 20', email: 'bonjour@institut-lumiere.example',
    branding: {
      brandPalette: 'emerald', accent: 'amber', logoEmoji: '✨',
      heroImage: 'https://images.unsplash.com/photo-1560750588-73207b1ef5b8?auto=format&fit=crop&w=1600&q=75',
    },
    voucherValidityMonths: 12,
    extensionOption: { available: true, price: 15, addedMonths: 3 },
    highlights: ['Soins personnalisés', 'Produits soigneusement sélectionnés', 'Rendez-vous du lundi au samedi'],
    about: 'Institut Lumière réunit soins du visage, massages et beauté des mains dans un lieu calme au cœur de Paris.',
  },
  {
    id: 'dz_chutelibre', slug: 'chute-libre', name: 'Atelier Nova',
    tagline: 'La coiffure pensée pour votre quotidien',
    country: 'FR', city: 'Lyon', currency: 'EUR', locale: 'fr-FR',
    phone: '+33 4 28 29 10 10', email: 'contact@atelier-nova.example',
    branding: {
      brandPalette: 'violet', accent: 'rose', logoEmoji: '✂️',
      heroImage: 'https://images.unsplash.com/photo-1560066984-138dadb4c035?auto=format&fit=crop&w=1600&q=75',
    },
    voucherValidityMonths: 12,
    extensionOption: { available: true, price: 12, addedMonths: 3 },
    highlights: ['Diagnostic personnalisé', 'Colorations sur mesure', 'Équipe experte'],
    about: 'Atelier Nova propose coupes, couleurs et soins capillaires dans une ambiance contemporaine et attentive.',
  },
  {
    id: 'dz_andes', slug: 'andes', name: 'Maison Sauge',
    tagline: 'Le bien-être dans son rythme le plus simple',
    country: 'FR', city: 'Bordeaux', currency: 'EUR', locale: 'fr-FR',
    phone: '+33 5 56 20 30 40', email: 'bonjour@maison-sauge.example',
    branding: {
      brandPalette: 'emerald', accent: 'amber', logoEmoji: '🌿',
      heroImage: 'https://images.unsplash.com/photo-1540555700478-4be289fbecef?auto=format&fit=crop&w=1600&q=75',
    },
    voucherValidityMonths: 12,
    extensionOption: { available: false, price: 0, addedMonths: 0 },
    highlights: ['Rituels corps et visage', 'Cabines privatives', 'Praticiens qualifiés'],
    about: 'Maison Sauge imagine des massages et rituels accessibles, pensés pour offrir une vraie respiration dans la semaine.',
  },
]

export const DEFAULT_TENANT_SLUG = 'skyline'
