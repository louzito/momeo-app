# Catalogue unifié — ticket #122

Dépendances vérifiées dans l’historique de `main` avant modification : #115
(`1a4d59e`), #117 (`46c32c4`) et #120 (`91ee3b0`). Leurs pages et routes sont présentes.

La route `/shop` présente les catégories Prestations, Produits et Cartes cadeaux.
Le paramètre `categorie` conserve le filtre au rechargement et au retour navigateur ;
les autres paramètres de recherche sont conservés lors d’un changement de catégorie.
Les liens de navigation ouvrent directement les prestations ou les produits.

Les API publiques existantes fournissent les offres visibles. L’ordre `shopOrder`
est conservé et les offres sélectionnées dans `home.featured` portent la mention
« À la une ». Les prix des prestations incluent les options obligatoires selon le
calcul existant. Les produits affichent le stock disponible après réservations,
les modes de remise et les frais de livraison. Une offre sans stock ou sans mode
de remise ne propose pas d’action d’achat depuis le catalogue.

Le choix d’un produit ouvre `/products?produit=…` et présélectionne une unité si
l’article est encore disponible ; le mode livraison est sélectionné pour un produit
sans retrait. `/products` sans paramètre conserve son fonctionnement. Les fiches
prestations et `/gift-card` conservent également leurs routes et leurs parcours.
Aucun panier commun ni changement de commande ou de paiement n’est introduit.

L’offre cadeau fournit sa devise, son montant minimum/maximum et sa validité.
La catégorie disparaît lorsque la vente est désactivée. Chaque catégorie possède
ses états vide, chargement et erreur récupérable ; une erreur produits ou cadeaux
ne bloque pas la consultation des autres catégories.

## Vérifications

- `npm run build` : réussi.
- Tests Node ciblés (`publicCatalog`, `serviceDetails`, `physicalCheckoutFlow`,
  `cart-checkout`, `stripePaymentFlow`, `siteConfig`) : 22 tests réussis.
- `npm run test:unit` : 17 fichiers réussis sur 18. L’échec préexistant de
  `publicBookingFlow.test.mjs` recherche `params.bookingId = result.booking.id`
  dans `Payment.vue`, qui ne contient plus cette instruction. Ces fichiers ne
  sont pas modifiés par ce ticket.
- `git diff --check` : réussi.
- Sept scénarios Playwright ajoutés dans `shop-catalog.spec.js` : parcours à
  360/1280 px, prix, ordre, mises en avant, stock, filtre, isolation des requêtes,
  cadeaux désactivés, états vides et reprise après erreur.
  Exécution tentée mais impossible ici : Vite échoue avec
  `listen EPERM 127.0.0.1:4173`. Ces scénarios et le rendu visuel restent à valider
  dans un environnement autorisant un serveur local.

Les dépendances npm ont été installées hors ligne depuis le cache disponible,
avec les scripts d’installation désactivés après une restriction d’exécution
sur le contrôle esbuild. Le build Vite lui-même a ensuite réussi.
