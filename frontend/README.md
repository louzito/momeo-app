# TodaTempo — application de réservation (Vue 3)

Frontend de **TodaTempo**, solution de réservation de prestations pour les établissements de soin.
L'application utilise exclusivement l'API Symfony/API Platform/Sylius. Une erreur réseau reste
visible : aucune donnée factice ne prend le relais dans l'application ou dans son bundle.

## Stack

- **Vue 3** (Composition API, `<script setup>`) + **Vite**
- **Vue Router** (toutes les pages)
- **Pinia** (panier, session client, session bénéficiaire, établissement courant)
- **Tailwind CSS** (branding dynamique par tenant via variables CSS)
- **Node test runner** (tests unitaires) et **Playwright** (tests E2E Chromium)

## Démarrage

```bash
npm install
npx playwright install chromium
npm run dev
```

Puis ouvrez l'URL affichée (par défaut http://localhost:5173).

Autres commandes :

```bash
npm run build     # build de production
npm run preview   # prévisualise le build
npm test                 # tests unitaires rapides
npm run test:unit        # idem, sans build ni navigateur
npm run test:e2e         # scénarios navigateur ; démarre Vite automatiquement
npm run test:production  # build puis vérification de l'absence de mocks
npm run test:ci          # suite complète destinée à la CI
```

## Tests frontend

Les tests unitaires couvrent notamment la résolution du tenant, le catalogue et sa propagation
d'erreurs, l'authentification, l'espace client, les permissions admin et le tunnel de commande.
Les scénarios Playwright complètent cette couverture dans un navigateur avec les disponibilités,
les gardes de navigation et les pages d'état.

Chaque scénario E2E intercepte uniquement ses propres appels `/api/v2/**` et construit ses données
dans le test : il ne lit ni le backend, ni les fixtures de démonstration, ni les données d'un autre
test. Le fuseau `UTC`, la locale et le navigateur sont fixés dans `playwright.config.js`. Pour une CI
Linux fraîche :

```bash
npm ci
npx playwright install --with-deps chromium
npm run test:ci
```

Les traces Playwright sont conservées au premier retry en CI. Aucun service backend ou secret n'est
nécessaire à cette suite.

## Exécution de commandes locales

Le serveur Vite n'expose aucun endpoint permettant d'exécuter des commandes. Les commandes de
développement doivent être lancées explicitement depuis un terminal local, à la racine du projet.
Cette fonctionnalité n'est donc activable ni dans un build, ni dans une preview, ni via une variable
d'environnement.

## Comptes / codes de test

👉 Voir **`IDENTIFIANTS_TEST.md`** à la racine de ce dossier : il liste tous les comptes clients,
codes de chèques cadeaux et emails de bénéficiaires nécessaires pour tout tester.

## Architecture

```
src/
├── api/index.js          ← point d'entrée unique de l'API réelle
├── mocks/                ← anciennes données de démonstration, hors graphe de production
├── stores/               ← Pinia (tenant, cart, session, beneficiary)
├── router/               ← toutes les routes
├── components/           ← composants réutilisables (cartes, calendrier, formulaires…)
├── composables/          ← branding dynamique, contexte tenant, gardes de tunnel
├── utils/                ← formatage (devise/date), palettes, statuts
└── views/                ← les pages (vitrine, checkout, beneficiary, account, errors)
```

Les tests E2E vivent dans `tests/e2e`, les contrôles spécifiques au bundle dans
`tests/production`, et les tests unitaires historiques dans `test` et `tests`.
