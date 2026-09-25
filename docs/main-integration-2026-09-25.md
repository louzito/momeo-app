# Integration sur main — 25 septembre 2026

La branche `main` de l'application a été avancée sans conflit de `674bf1c`
à `58f400d` : elle inclut les 61 commits de `autoticket/todatempo-v1`
jusqu'à `06886c6`, puis les 11 tickets #97–#107 de la refactorisation.
Le dépôt website a également intégré ses 7 commits V1, jusqu'à `775ad22`.
Les branches d'origine sont conservées.

Les anciennes branches #58 et #59 ne sont pas intégrées : elles proposent
une autre stratégie de connexion, et #59 dépend d'un endpoint de membership
absent du website. La fonctionnalité des disponibilités de #60 a été reprise
dans le commit V1 `06886c6` ; sa branche historique est conservée séparément.

## Corrections vérifiées pendant l'intégration

- Utilisation de `Adjustment::lock()` après rattachement à la commande :
  `setLocked()` n'existe pas dans la version de Sylius installée.
- Normalisation des créneaux de réservation en UTC avant persistance pour
  conserver l'instant lors d'une relecture Doctrine et détecter les conflits.
  Aucune migration des réservations existantes n'a été exécutée.
- Restauration de l'environnement après les tests de configuration ; fixtures
  de traduction, double PHPUnit, champ JSON obligatoire et comparaison
  d'historique indépendamment de l'ordre des clés JSON corrigés.
- Website : conservation du client HTTP simulé entre requêtes et vérification
  des directives de cache sans imposer l'absence d'autres directives Symfony.

## Vérifications

- Frontend : 41 tests unitaires et 9 scénarios Chromium réussis.
  Ces scénarios utilisent une API simulée.
- Compilation Vite réussie dans `/tmp/todatempo-merge-main-frontend-dist` ;
  contrôle de l'absence de marqueurs de mocks dans les 65 assets JavaScript.
- Website : 45 tests, 304 assertions réussis avec une nouvelle base SQLite
  dédiée ; l'ancienne base de test avait un schéma obsolète.
- Backend : conteneur Symfony test valide. Base MySQL `todatempo_test`
  créée avec un utilisateur dédié, configuration et clés de test ignorées
  par Git. Aucune base de production modifiée.
- Backend ciblé : 14 tests / 128 assertions réussis (6 dépréciations), couvrant
  les acomptes, déplacements, conflits de capacité et concurrence InnoDB.
- Backend complet : **355 tests, 1582 assertions, 5 erreurs, 3 échecs,
  19 dépréciations**. La validation complète n'est donc pas acquise.

## Points encore ouverts

1. Cinq tests de réservation par commande échouent lors de la création de
   leur fixture : le listener de facturation ne retrouve pas la commande.
   Il reste à distinguer le montage de test d'un défaut du parcours réel.
2. Deux tests d'annulation n'observent pas la notification de liste d'attente.
   Les périodes d'attente et les créneaux utilisent des fuseaux différents.
3. Le smoke test complet s'arrête à la création du produit : la traduction
   `en_US` est refusée par le tenant initialisé en `fr_FR`. Les étapes
   suivantes (paiement, email, PDF et annulation) ne sont pas validées par
   cette exécution.

Il ne s'agit pas d'une validation de déploiement en production. Aucun paiement
réel, envoi d'email réel, migration de production ou redémarrage des workers
n'a été réalisé.
