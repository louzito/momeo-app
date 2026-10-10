# AutoTicket #145 — vérification

## Modifications

La modale Nouveau rendez-vous explique l’absence de prestations et propose un lien vers Catalogue > Prestations. Le select et la validation sont désactivés dans ce cas ; le gestionnaire de soumission refuse également la création. Le formulaire est limité à la hauteur dynamique de la fenêtre moins 32 px : seul le corps défile, l’en-tête et les boutons restent visibles. Les boutons peuvent revenir à la ligne sur mobile.

## Contrôles locaux

- `cd frontend && npm run test:unit` : réussi, y compris les nouveaux cas de création, liste vide et refus serveur dans `test/admin-agenda.test.mjs`.
- `cd frontend && npm run test:production` : réussi (compilation frontend et SSR, contrôle du bundle de production).
- Syntaxe PHP du test modifié et `git diff --check` : réussis.
- Les dépendances frontend ont été récupérées depuis un cache local après échec de `npm ci` (DNS du registre npm indisponible). Aucun manifeste ni verrou de dépendances n’a été modifié.

## Scénarios ajoutés, non exécutables ici

- `frontend/tests/e2e/admin-agenda-booking.spec.js` : liste vide, lien catalogue, boutons dans la fenêtre et défilement à 1280 × 670 et 390 × 670 ; création, affichage Agenda / Réservations À venir / fiche client / Tableau de bord avec API simulée ; conservation du formulaire après erreur.
- `backend/tests/Controller/CustomerBookingChangesContractTest.php`, cas `testManualCreationAndResourceRefusal` : création persistée puis lecture par l’API commune Agenda/Réservations, fiche client et variation des indicateurs (un rendez-vous et 60 minutes occupées supplémentaires, recettes encaissées inchangées pour le paiement sur place).

Playwright n’a pas pu démarrer : ouverture de port local refusée (`listen EPERM`), et Chromium disponible manque de `libcups.so.2`. PHPUnit n’a pas pu charger le bootstrap, car `backend/vendor/autoload.php` est absent. Ces scénarios ne sont pas annoncés comme réussis.

## Vérification restante dans un environnement équipé

1. Exécuter `cd frontend && npm run test:e2e -- tests/e2e/admin-agenda-booking.spec.js tests/e2e/service-catalog-creation.spec.js`.
2. Avec les dépendances Composer et la base de test isolée configurées, exécuter `cd backend && vendor/bin/phpunit --filter 'CustomerBookingChangesContractTest::testManualCreationAndResourceRefusal'`.
3. Vérifier le parcours réel après création d’une prestation : l’affecter à un collaborateur actif avec des horaires disponibles, créer un rendez-vous futur, vérifier son affichage dans l’Agenda, Réservations > À venir et la fiche client. Sur le Tableau de bord, sélectionner une plage incluant sa date : Rendez-vous et minutes occupées augmentent ; un paiement sur place non encaissé ne doit pas augmenter le revenu encaissé.

Aucun déploiement ni modification de données distantes n’a été effectué.
