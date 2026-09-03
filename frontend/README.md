# TodaTempo — application de réservation (Vue 3)

Front de démonstration de **TodaTempo**, solution de réservation de prestations pour les établissements de soin.
Objectif : naviguer sur **toutes** les pages et voir le rendu visuel **avant** de connecter le
vrai backend (Symfony + API Platform + Sylius). **Aucun appel réseau réel** : toutes les données
viennent d'une fausse API mockée (`src/mocks/`).

## Stack

- **Vue 3** (Composition API, `<script setup>`) + **Vite**
- **Vue Router** (toutes les pages)
- **Pinia** (panier, session client, session bénéficiaire, établissement courant)
- **Tailwind CSS** (branding dynamique par tenant via variables CSS)

## Démarrage

```bash
npm install
npm run dev
```

Puis ouvrez l'URL affichée (par défaut http://localhost:5173).

Autres commandes :

```bash
npm run build     # build de production
npm run preview   # prévisualise le build
```

## Comptes / codes de test

👉 Voir **`IDENTIFIANTS_TEST.md`** à la racine de ce dossier : il liste tous les comptes clients,
codes de chèques cadeaux et emails de bénéficiaires nécessaires pour tout tester.

## Architecture

```
src/
├── api/index.js          ← POINT D'ENTRÉE UNIQUE de l'API (importé partout)
├── mocks/                ← toute la logique factice (À REMPLACER plus tard)
│   ├── mockApi.js        ← implémente l'interface de service (async + délais simulés)
│   └── fixtures/         ← données en dur (tenants, catalogue, créneaux, chèques, commandes…)
├── stores/               ← Pinia (tenant, cart, session, beneficiary)
├── router/               ← toutes les routes
├── components/           ← composants réutilisables (cartes, calendrier, formulaires…)
├── composables/          ← branding dynamique, contexte tenant, gardes de tunnel
├── utils/                ← formatage (devise/date), palettes, statuts
└── views/                ← les pages (vitrine, checkout, beneficiary, account, errors)
```

**Pour brancher le vrai backend** : créez `src/api/httpApi.js` qui expose exactement les mêmes
méthodes que `mockApi` (mêmes noms, mêmes formes de retour, déjà dénormalisées côté API), puis
changez la seule ligne d'import dans `src/api/index.js`. Aucun composant Vue n'a besoin d'être touché.
