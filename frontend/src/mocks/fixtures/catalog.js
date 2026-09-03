// Données de démonstration TodaTempo. Le format reste compatible avec l’ancienne
// couche mock pendant que les écrans sont progressivement reliés à Sylius.

const IMG = {
  facial: 'https://images.unsplash.com/photo-1570172619644-dfd03ed5d881?auto=format&fit=crop&w=1200&q=75',
  massage: 'https://images.unsplash.com/photo-1544161515-4ab6ce6db874?auto=format&fit=crop&w=1200&q=75',
  nails: 'https://images.unsplash.com/photo-1604654894610-df63bc536371?auto=format&fit=crop&w=1200&q=75',
  hair: 'https://images.unsplash.com/photo-1560066984-138dadb4c035?auto=format&fit=crop&w=1200&q=75',
  wellness: 'https://images.unsplash.com/photo-1600334089648-b0d9d3028eb2?auto=format&fit=crop&w=1200&q=75',
}

function service(overrides) {
  return {
    popular: false,
    legacyEligibility: false,
    requirements: [],
    eligibility: {
      ageMin: 0,
      ageMax: 120,
      weightMaxKg: 500,
      heightMinCm: 0,
      medicalCertificateRequired: false,
      waiverRequired: false,
      customRules: [],
    },
    ...overrides,
  }
}

export const catalog = {
  dz_skyline: {
    jumpTypes: [
      service({
        id: 'service_soin_eclat', tenantId: 'dz_skyline', name: 'Soin visage éclat',
        summary: 'Un protocole complet pour hydrater, lisser et raviver le teint.',
        description: 'Diagnostic de peau, nettoyage doux, exfoliation, masque sur mesure et massage du visage. Un soin pensé pour retrouver une peau lumineuse et confortable.',
        basePrice: 75, durationMin: 60, capacityPerSlot: 1, image: IMG.facial, popular: true,
        requirements: [{ key: 'arrive_early', label: 'Merci d’arriver 5 minutes avant le rendez-vous.' }],
      }),
      service({
        id: 'service_massage_relaxant', tenantId: 'dz_skyline', name: 'Massage relaxant',
        summary: 'Une heure de détente profonde adaptée à vos besoins.',
        description: 'Un massage enveloppant du corps, avec une pression ajustée et une attention particulière portée aux zones de tension.',
        basePrice: 90, durationMin: 60, capacityPerSlot: 1, image: IMG.massage,
        requirements: [{ key: 'contraindications', label: 'Signalez toute contre-indication ou grossesse avant le rendez-vous.' }],
      }),
      service({
        id: 'service_manucure_gel', tenantId: 'dz_skyline', name: 'Manucure gel',
        summary: 'Préparation soignée des ongles et couleur longue tenue.',
        description: 'Mise en forme, soin des cuticules et pose de vernis gel parmi notre sélection de teintes.',
        basePrice: 48, durationMin: 45, capacityPerSlot: 1, image: IMG.nails,
      }),
    ],
    options: [
      { id: 'opt_sky_mask', tenantId: 'dz_skyline', name: 'Masque premium', description: 'Masque expert ajouté à votre soin.', price: 15, scope: 'PER_JUMP', mandatory: false, maxQuantity: 1 },
      { id: 'opt_sky_nailart', tenantId: 'dz_skyline', name: 'Nail art discret', description: 'Décoration personnalisée sur deux ongles.', price: 8, scope: 'PER_JUMP', mandatory: false, maxQuantity: 1 },
      { id: 'opt_sky_giftwrap', tenantId: 'dz_skyline', name: 'Coffret cadeau', description: 'Présentation cadeau prête à offrir.', price: 6, scope: 'PER_ORDER', mandatory: false, maxQuantity: 1 },
    ],
  },

  dz_chutelibre: {
    jumpTypes: [
      service({
        id: 'service_coupe_signature', tenantId: 'dz_chutelibre', name: 'Coupe signature',
        summary: 'Conseil, coupe et coiffage adaptés à votre style.',
        description: 'Un rendez-vous personnalisé qui commence par un diagnostic et se termine par des conseils simples pour entretenir votre coupe.',
        basePrice: 58, durationMin: 60, capacityPerSlot: 1, image: IMG.hair, popular: true,
      }),
      service({
        id: 'service_balayage', tenantId: 'dz_chutelibre', name: 'Balayage lumière',
        summary: 'Des reflets naturels et un résultat fondu sur mesure.',
        description: 'Diagnostic couleur, technique de balayage, patine, soin et coiffage inclus.',
        basePrice: 145, durationMin: 180, capacityPerSlot: 1, image: IMG.hair,
        requirements: [{ key: 'diagnostic', label: 'Un diagnostic préalable peut être demandé selon votre historique couleur.' }],
      }),
    ],
    options: [
      { id: 'opt_cl_care', tenantId: 'dz_chutelibre', name: 'Soin profond', description: 'Protocole réparateur adapté au cheveu.', price: 22, scope: 'PER_JUMP', mandatory: false, maxQuantity: 1 },
      { id: 'opt_cl_headmassage', tenantId: 'dz_chutelibre', name: 'Massage du cuir chevelu', description: 'Dix minutes de massage relaxant.', price: 12, scope: 'PER_JUMP', mandatory: false, maxQuantity: 1 },
    ],
  },

  dz_andes: {
    jumpTypes: [
      service({
        id: 'service_rituel_detente', tenantId: 'dz_andes', name: 'Rituel détente',
        summary: 'Un parcours corps et visage pour ralentir vraiment.',
        description: 'Gommage doux, massage relaxant et soin visage hydratant dans une atmosphère apaisante.',
        basePrice: 160, durationMin: 120, capacityPerSlot: 1, image: IMG.wellness, popular: true,
      }),
      service({
        id: 'service_massage_30', tenantId: 'dz_andes', name: 'Massage ciblé 30 min',
        summary: 'Une pause efficace sur la zone qui en a le plus besoin.',
        description: 'Dos, jambes ou nuque : choisissez votre priorité avec le praticien au début de la séance.',
        basePrice: 52, durationMin: 30, capacityPerSlot: 1, image: IMG.massage,
      }),
    ],
    options: [
      { id: 'opt_an_oil', tenantId: 'dz_andes', name: 'Huile précieuse', description: 'Une huile de soin premium sélectionnée avec vous.', price: 10, scope: 'PER_JUMP', mandatory: false, maxQuantity: 1 },
      { id: 'opt_an_tea', tenantId: 'dz_andes', name: 'Pause infusion', description: 'Infusion et temps calme après le soin.', price: 8, scope: 'PER_ORDER', mandatory: false, maxQuantity: 1 },
    ],
  },
}
