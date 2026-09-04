# Checklist de mise en production V1

Checklist exécutable pour un déploiement ou une mise à jour en production.
Chaque étape indique des commandes de **contrôle en lecture seule**
(diagnostics, `--dry-run`, lecture de statut) ; les étapes qui écrivent ou
modifient un état sont marquées **⚠️ DESTRUCTIF / IRRÉVERSIBLE**. Aucune valeur
de secret ne doit apparaître dans les commandes ci-dessous ni dans leur sortie
copiée ailleurs (logs partagés, tickets…) : les secrets viennent uniquement du
gestionnaire de secrets de la plateforme ou de `/etc/todatempo/backend.env`
(lisible par le seul utilisateur du service), jamais du dépôt.

Toutes les commandes `bin/console` s'exécutent depuis `backend/` avec
`APP_ENV=prod`.

## 1. Configuration

- [ ] Comparer `.env.example` à la configuration réellement injectée par le
  gestionnaire de secrets de la plateforme : aucune valeur factice
  (`change-me`, `local-password`, `generate-a-random-value`…) ne doit
  subsister.
- [ ] Contrôle en lecture seule — le process refuse de démarrer si une
  variable obligatoire manque, si `APP_DEBUG` est actif, si les clés
  JWT/paiement sont illisibles ou vides, ou si `config/tenants.json` est
  absent, vide ou ne contient pas `SKYBOOK_DEFAULT_TENANT` :

  ```bash
  APP_ENV=prod bin/console about --env=prod
  ```

  (Le lancement de n'importe quelle commande en `APP_ENV=prod` déclenche déjà
  `ProductionConfigurationValidator` ; une configuration invalide fait échouer
  la commande avec la liste précise des variables en cause.)
- [ ] Vérifier que `config/tenants.json` (jamais versionné) déclare bien
  chaque centre actif avec sa base, et que `SKYBOOK_DEFAULT_TENANT` correspond
  à une entrée existante :

  ```bash
  APP_ENV=prod bin/console todatempo:tenant:list
  ```
- [ ] **Go/no-go** : la commande `about` doit se terminer sans erreur. Toute
  erreur de configuration bloque le déploiement.

## 2. Permissions

Voir [`runtime-permissions.md`](runtime-permissions.md) pour le détail complet
(utilisateur système, droits fichiers, réseau sortant). Contrôles en lecture
seule à rejouer après tout changement de permissions ou d'infrastructure :

- [ ] PHP-FPM, les commandes planifiées et chaque worker Messenger tournent
  sous un utilisateur système non privilégié, sans shell interactif.
- [ ] `config/tenants.json`, les clés JWT et la clé de chiffrement des
  paiements sont en lecture seule pour ce compte (`0400`/`0440`) ; aucune
  écriture n'est possible sur `config/`, `src/`, `templates/`, `vendor/`.
- [ ] Seuls `var/cache/`, `var/log/`, `var/sessions/`, `var/observability/`,
  `private/invoices/` et `public/media/image/` sont accessibles en écriture :

  ```bash
  find config/jwt config/encryption config/tenants.json -maxdepth 1 -exec ls -l {} \;
  ```
- [ ] Aucun port d'administration (base de données, transport Messenger…)
  n'est exposé publiquement ; seuls le web et les dépendances déclarées le
  sont en sortie.

## 3. Migrations

- [ ] Contrôle en lecture seule des migrations en attente, tenant par tenant
  ou globalement via le registre :

  ```bash
  APP_ENV=prod bin/console doctrine:migrations:status
  ```
- [ ] ⚠️ **DESTRUCTIF / IRRÉVERSIBLE (schéma)** — sauvegarder chaque base
  concernée avant toute migration en production (voir section 9). Appliquer
  les migrations en attente sur tous les tenants du registre, y compris
  `template` et le pool (nécessaire dès qu'une migration touche le schéma
  partagé) :

  ```bash
  ./scripts/migrate-all.sh
  ```

  Ou, pour un seul tenant :

  ```bash
  TODATEMPO_TENANT=<slug> APP_ENV=prod bin/console doctrine:migrations:migrate -n --allow-no-migration
  ```
- [ ] Après migration, revérifier `doctrine:migrations:status` sur un
  échantillon de tenants : aucune migration ne doit rester en attente.
- [ ] **Go/no-go** : `migrate-all.sh` doit se terminer sans tenant en échec.
  Un échec partiel bloque le déploiement tant que la base concernée n'est pas
  investiguée (le script liste les slugs en échec).

## 4. Initialisation tenant

- [ ] Contrôle en lecture seule d'un tenant existant (registre, base
  effectivement ouverte par Doctrine, dépendances) :

  ```bash
  APP_ENV=prod bin/console todatempo:tenant:doctor <slug>
  APP_ENV=prod bin/console skybook:tenant:database-diagnose <slug>
  ```
- [ ] ⚠️ **DESTRUCTIF (création de données)** — nouveau centre : utiliser le
  pool pré-provisionné (rapide) plutôt qu'un clonage à la volée en heure de
  pointe :

  ```bash
  ./scripts/new-center.sh <slug> "<Nom>" [email-admin]
  ```

  Reconstituer ensuite le pool consommé :

  ```bash
  ./scripts/pool-refill.sh [N]
  ```
- [ ] Le compte administrateur créé par `todatempo:tenant:initialize` (utilisé
  en interne par `new-center.sh`/`claim-center.sh`) provient exclusivement de
  `TODATEMPO_ADMIN_EMAIL` / `TODATEMPO_ADMIN_PASSWORD` fournis par
  l'environnement — jamais d'identifiants en dur.
- [ ] **Go/no-go** : `todatempo:tenant:doctor <slug>` ne doit renvoyer aucune
  ligne `ERROR` avant d'ouvrir l'accès au centre.

## 5. Worker Messenger

Voir [`etc/systemd/README.md`](../backend/etc/systemd/README.md) pour le
détail de l'unité systemd par tenant.

- [ ] Contrôle en lecture seule de l'état des workers :

  ```bash
  systemctl status 'todatempo-messenger@*.service'
  ```
- [ ] Chaque tenant actif a bien une unité activée :

  ```bash
  sudo systemctl enable --now todatempo-messenger@<slug>.service
  ```
- [ ] Vérifier qu'aucun message n'échoue silencieusement (transport
  `*_failed`) :

  ```bash
  TODATEMPO_TENANT=<slug> APP_ENV=prod bin/console messenger:failed:show
  ```
- [ ] **Go/no-go** : au moins un worker actif par tenant servi, aucun message
  en échec non investigué.

## 6. Cron

- [ ] Contrôle en lecture seule : lister les tâches planifiées actives sur
  l'hôte (`crontab -l` pour l'utilisateur applicatif, ou équivalent
  orchestrateur) et confirmer qu'elles couvrent au minimum :
  - rappels de rendez-vous (voir
    [`appointment-reminders.md`](../backend/docs/appointment-reminders.md)),
    par tenant, fréquence recommandée toutes les 5 minutes :

    ```bash
    TODATEMPO_TENANT=<slug> APP_ENV=prod bin/console todatempo:reminders:schedule --window=5
    ```
  - purge RGPD (voir
    [`rgpd-retention.md`](../backend/docs/rgpd-retention.md)), par tenant,
    fréquence recommandée quotidienne :

    ```bash
    TODATEMPO_TENANT=<slug> APP_ENV=prod bin/console todatempo:gdpr:purge --dry-run
    ```
- [ ] ⚠️ **DESTRUCTIF (purge de données, action réelle sans `--dry-run`)** —
  ne planifier `todatempo:gdpr:purge` sans `--dry-run` qu'après validation
  juridique des durées de rétention (section « Validation juridique requise »
  de `rgpd-retention.md`).
- [ ] **Go/no-go** : `--dry-run` s'exécute sans erreur sur chaque tenant actif
  avant d'activer la purge réelle en cron.

## 7. Paiement / webhooks Stripe

Voir [`stripe-sandbox.md`](stripe-sandbox.md) pour la procédure complète par
centre.

- [ ] Chaque centre a son propre endpoint Stripe
  (`/api/v2/shop/payments/stripe/webhook/<slug-centre>`) et son propre secret
  `whsec_…`, saisis dans la configuration du moyen de paiement Sylius (jamais
  dans le dépôt).
- [ ] Basculer les clés Stripe de `pk_test_…`/`sk_test_…` vers les clés live
  uniquement après recette (voir section « Recette » du document
  stripe-sandbox) et confirmation métier.
- [ ] Contrôle en lecture seule côté Stripe (dashboard ou CLI) : l'endpoint du
  centre est actif, souscrit à `checkout.session.completed`,
  `checkout.session.expired` et `checkout.session.async_payment_failed`, et
  ses derniers événements sont en statut « livré ».
- [ ] **Go/no-go** : un paiement de test (ou le smoke test, section 11)
  confirme un webhook signé accepté et un paiement/réservation qui passent
  bien à `paid`/`confirmed`.

## 8. SMTP

- [ ] Contrôle en lecture seule : `MAILER_DSN` n'est pas `null://null` en
  production (sinon aucun email n'est réellement envoyé) :

  ```bash
  APP_ENV=prod bin/console debug:config framework mailer
  ```
- [ ] Vérifier que l'expéditeur configuré est autorisé par le fournisseur SMTP
  (SPF/DKIM en place) pour éviter un classement en spam.
- [ ] **Go/no-go** : un email transactionnel de test (ex. confirmation de
  réservation lors du smoke test) est bien reçu par une boîte de test réelle.

## 9. PDF (factures)

Voir [`invoices.md`](../backend/docs/invoices.md).

- [ ] Contrôle en lecture seule du binaire :

  ```bash
  wkhtmltopdf --version
  ```
- [ ] `WKHTMLTOPDF_PATH` / `WKHTMLTOIMAGE_PATH` pointent vers ce binaire
  (vérifié automatiquement par `ProductionConfigurationValidator`, voir
  section 1).
- [ ] `private/invoices/` existe, est hors racine web et appartient à
  l'utilisateur du service :

  ```bash
  ls -ld private/invoices
  ```
- [ ] **Go/no-go** : une facture générée pendant le smoke test (section 11)
  commence bien par `%PDF` et n'est accessible qu'au propriétaire authentifié
  de la commande.

## 10. Sauvegardes

- [ ] Confirmer qu'une sauvegarde automatisée couvre, pour chaque tenant :
  base de données MySQL, `private/invoices/<tenant>/`, `config/tenants.json`,
  les clés JWT et la clé de chiffrement des paiements. Ces éléments sont hors
  de la transaction applicative et ne sont sauvegardés par aucune commande de
  ce dépôt.
- [ ] ⚠️ Avant toute migration de schéma (section 3) ou toute opération
  destructive sur une base, prendre une sauvegarde ponctuelle à jour :

  ```bash
  mysqldump --single-transaction <base-du-tenant> | gzip > <chemin-sauvegarde>.sql.gz
  ```
- [ ] Contrôle en lecture seule : vérifier la date de la dernière sauvegarde
  réussie et qu'une restauration a déjà été testée (procédure de restauration
  documentée séparément par l'équipe infra — hors périmètre applicatif).
- [ ] **Go/no-go** : sauvegarde de moins de 24h disponible pour chaque tenant
  actif avant toute opération à risque.

## 11. Santé (health checks)

Voir [`supervision.md`](supervision.md) pour le détail complet.

- [ ] Contrôle en lecture seule, sans authentification, sans donnée sensible
  dans la réponse :

  ```bash
  curl -fsS https://<domaine>/health/live
  curl -fsS https://<domaine>/health/ready
  curl -fsS https://<domaine>/health/tenants/<slug>/live
  curl -fsS https://<domaine>/health/tenants/<slug>/ready
  ```
- [ ] `/metrics` répond et n'est idéalement exposé qu'au réseau du
  collecteur :

  ```bash
  curl -fsS https://<domaine>/metrics | head
  ```
- [ ] **Go/no-go** : `/health/ready` et `/health/tenants/<slug>/ready`
  renvoient `200` pour chaque tenant actif avant d'ouvrir le trafic.

## 12. Smoke test

Voir [`e2e-smoke-test.md`](../backend/docs/e2e-smoke-test.md).

- [ ] Suite métier isolée (base MySQL dédiée, ne touche aucune donnée de
  production) :

  ```bash
  cd backend
  make test-business
  ```
- [ ] En production, compléter par un parcours manuel réel sur un tenant de
  recette : réservation, paiement carte test, réception facture PDF, email de
  confirmation, déplacement et annulation du rendez-vous — cf. section
  « Recette V1 » de `stripe-sandbox.md`.
- [ ] Frontend : le build ne doit contenir aucun marqueur de mock/démo :

  ```bash
  cd frontend
  npm run test:production
  ```
- [ ] **Go/no-go** : `make test-business` et `npm run test:production`
  passent tous les deux, sans quoi le déploiement n'est pas validé.

## 13. Rollback

- [ ] Le rollback applicatif consiste à redéployer la version précédente du
  code (voir [`TECHNICAL_IDENTIFIERS_MIGRATION.md`](../TECHNICAL_IDENTIFIERS_MIGRATION.md)
  pour le cas particulier des migrations d'identifiants techniques) : les
  migrations Doctrine de ce projet n'ont pas de `down()` maintenu, donc un
  rollback de schéma s'appuie sur la sauvegarde prise avant migration
  (section 10), pas sur une migration inverse.
- [ ] ⚠️ **DESTRUCTIF / IRRÉVERSIBLE** — restauration d'une base depuis
  sauvegarde : à ne déclencher qu'après confirmation explicite, sur la base
  du tenant concerné uniquement, jamais sur `template` ou le pool sans
  vérification préalable.
- [ ] Après rollback, rejouer les contrôles de lecture seule des sections 1
  (`about`), 3 (`doctrine:migrations:status`) et 11 (`/health/*`) avant de
  rouvrir le trafic.
- [ ] **Go/no-go** : rollback considéré terminé seulement quand `/health/ready`
  et `/health/tenants/<slug>/ready` renvoient de nouveau `200` et que le smoke
  test (section 12) repasse.
