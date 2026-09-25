# Contrat d’architecture custom TodaTempo

État établi pour AutoTicket #97, le 25 septembre 2026, à partir de la tête
fournie du dépôt. Référence de l’analyse initiale de la série :
`06886c65241f43cb4a16ba695ccbd997ad1f0ba4` (`autoticket/todatempo-v1`).
Branche commune prévue : `autoticket/todatempo-back-refacto-controller-entity-service`.
Ce ticket ne déplace aucune classe de production. Les destinations ci-dessous
sont des cibles à réaliser progressivement, pas des namespaces déjà disponibles.
Aucun ticket suivant n’est activé ; #98 vient après succès de #97.

## Responsabilités

- **Controller** : routes, lecture de la requête, authentification et adaptation
  des autorisations HTTP, validation de forme, appel des services, sérialisation
  et traduction des résultats/erreurs en réponses. Garder noms et méthodes des
  routes, formats JSON, statuts et messages d’erreur existants.
- **Entity** : état persisté, relations Doctrine et invariants locaux à l’objet.
  Pas d’accès HTTP, de repository, d’envoi, de workflow ou de transaction dans
  une entité. Les extensions des modèles Sylius restent dans `Entity`.
- **Service/<domaine>** : décisions métier, calculs, orchestration entre objets,
  transactions et appels aux fournisseurs. Séparer les cas d’usage par
  responsabilité ; ne pas regrouper toute une API dans un service géant.
  Les exceptions métier et petits objets non persistés restent proches du
  domaine qui les utilise, sans les transformer artificiellement en entités.
- **Repository** : requêtes, filtres, accès Doctrine et verrous nécessaires aux
  lectures/écritures ; pas de décision HTTP, d’email ou d’orchestration métier.
- **Adaptateurs** : conserver les points d’extension nécessaires à Symfony,
  Doctrine et Sylius. Command, EventListener, Security, Twig et Messenger
  délèguent au domaine et documentent leur rôle, tags, priorité et contexte.
  Les adaptateurs techniques spécifiques (cache, middleware DBAL, stockage PDF,
  images) peuvent rester près de leur intégration si leur déplacement ne rend
  pas le code plus clair ; documenter cette exception lors de l’extraction.

Pas de couche générique Application/Domain/Infrastructure, de bus métier, de
factory ou d’interface systématique. Garder les interfaces de fournisseurs
existantes lorsqu’elles représentent une véritable frontière externe.

## Cartographie source → destination

Tous les chemins de cette table sont relatifs à `backend/src`. Statut de toutes
les extractions : **à faire**. `Entity`, `Repository` et les adaptateurs indiqués
« conservé » restent à leur emplacement, avec délégation à compléter si besoin.

| Source actuelle | Destination et responsabilité |
| --- | --- |
| `Availability/{AvailabilitySlotGenerator,CenterTimeZoneProvider,PlanningProvider}` | `Service/Availability/` : créneaux, fuseau du centre, lecture du planning publié |
| `Booking/{BookingRules,BookingSlotGuard,CustomerBookingChangePolicy,SlotUnavailable}` | `Service/Booking/` : règles, annulation/déplacement, capacité et coordination des verrous |
| `Planning/PlanningInput` | `Service/Planning/` : normalisation métier du planning ; parsing HTTP dans Controller |
| `Staff/{StaffEligibility,WorkingHours}` | `Service/Staff/` : compétences, affectation et calendrier |
| `Resource/ResourceAvailability` | `Service/Resource/` : sélection et capacité des ressources |
| `Payment/{StripeCheckout,ServicePaymentTerms,ConfiguredRefundProvider,RefundProvider}` | `Service/Payment/` : acompte, transitions Sylius, Stripe, remboursement et interface fournisseur existante |
| `GiftVoucher/*` | `Service/GiftVoucher/` : création, activation, codes, QR, marquage de commande et envoi |
| `Email/BookingEmailDispatcher` | `Service/Email/` : préparation et déclenchement des emails transactionnels |
| `Waitlist/WaitlistNotifier` | `Service/Waitlist/` : sélection et notification des demandes |
| `Reminder/ReminderConfiguration`, `Reminder/Sms/*` | `Service/Reminder/` : règles des rappels et frontière SMS existante |
| `Reminder/Message/SendBookingReminder`, `Reminder/MessageHandler/SendBookingReminderHandler` | Adaptateurs Messenger conservés ; extraire l’orchestration du handler vers `Service/Reminder/` |
| `Gdpr/{CustomerDataManager,RetentionPolicy}` | `Service/Gdpr/` : export, anonymisation, purge et rétention |
| `Dashboard/DashboardMetricsCalculator` | `Service/Dashboard/` : calcul des indicateurs |
| `Configuration/{SiteConfigDocument,ProductionConfigurationValidator}` | `Service/Configuration/` : lecture publiée et validation de configuration |
| `Tenant/{TenantProvisioner,TenantDatabaseCloner,MinimalSyliusInitializer,ProvisionedTenant}` | `Service/Tenant/` : provisionnement et résultat non persisté ; préserver les étapes de reprise |
| `Tenant/{TenantContext,TenantRegistry,TenantRegistryWriter,TenantIdentifierResolver,TenantUrlGenerator}` | `Service/Tenant/` : identité courante, registre, résolution et URLs ; préserver cache et portée du contexte |
| `Tenant/{CustomDomainManager,DomainName,DomainOwnershipVerifier,CaddyConfigDumper}` | `Service/Tenant/` : domaines et génération Caddy ; aucune nouvelle entité pour un objet non persisté |
| `Tenant/{AdminLoginTicketStore,TenantDoctor,TenantDoctorInterface,TenantWorkerGuard}` | `Service/Tenant/` : SSO à usage unique, diagnostic et garde worker |
| `Tenant/{TenantConnectionMiddleware,TenantAwareCachePool,TenantImagePathGenerator,TenantInvoicePdfStorageFactory,JwtTenantListener}` | Adaptateurs DBAL/cache/Sylius/JWT conservés et documentés ; règles déléguées à `Service/Tenant/` |
| `Observability/{HealthChecker,MetricsRegistry}` | `Service/Observability/` : sondes et métriques |
| `Observability/{CorrelationIdListener,LogContextProcessor}` | Adaptateurs événements/Monolog conservés |
| `Security/{TeamPermissions,TeamPermission,TeamRole}` | Politique dans `Service/Security/` ; enums gardés près de la politique, références Entity à mettre à jour ensemble |
| `Security/{ImageUploadValidator,SensitiveEndpointRateLimiter}` | Validation métier dans `Service/Security/` ; intégration Request/RateLimiter fine si nécessaire |
| `Security/{AdminApiPermissionSubscriber,HttpSecurityHeadersSubscriber}` | Adaptateurs HTTP conservés : sélection de permission, rejet et en-têtes |
| `Command/*`, `EventListener/*`, `Twig/SkybookEmailExtension` | Adaptateurs conservés ; cas d’usage dans les services de leur domaine |
| `Entity/*`, dont Booking, BookingLock, StripeWebhookEvent, GiftVoucher, RefundOperation, AdminUser et extensions Sylius | Conservé : mapping, état persistant, invariants locaux ; aucune migration de table induite par le rangement |
| `Repository/*` | Conservé : accès persistants et requêtes tenant-scopées |
| `Kernel.php` | Intégration Symfony conservée |

### Extraction des contrôleurs

Les fichiers `Controller/*` restent les points d’entrée. Les blocs métier sont
à extraire vers les domaines suivants (sans déplacer leurs routes) :

| Contrôleurs | Domaine destinataire |
| --- | --- |
| ShopBookingApi, AdminBookingApi | Availability / Booking : recherche, allocation, création, déplacement, annulation |
| AdminPlanningApi, AdminStaffMemberApi, AdminStaffTimeOffApi, AdminBookableResourceApi | Planning / Staff / Resource |
| ShopStripePayment, ShopPaymentTerms, AdminRefundApi | Payment : paiement, webhook, idempotence, remboursement |
| ShopGiftVoucherApi, AdminGiftVoucherApi, ShopGiftOrderMarker | GiftVoucher |
| ShopWaitlistApi, AdminWaitlistApi | Waitlist |
| ShopCustomerAccountApi, AdminClientApi | Customer : compte, accès aux réservations/factures, dossier client ; Booking pour les changements de réservation |
| ShopPhysicalOrderApi, AdminPhysicalCommerceApi | Commerce : orchestration commande et catalogue Sylius |
| AdminInvoiceApi | Invoice : sélection et génération/téléchargement, stockage tenant conservé |
| AdminDashboardApi | Dashboard |
| InternalProvisioning, InternalAdminLoginTicket, AdminSso, AdminSsoHandoff, AdminTeamSession | Tenant / Security |
| Observability | Observability |

## Invariants de chaque extraction

1. Caractériser le comportement avant déplacement, puis conserver les mêmes
   tests de résultat après. Modifier les imports/assemblages de fixture quand
   un namespace change ; ne pas remplacer des chemins dans des assertions PHP.
2. Préserver les frontières transactionnelles, l’ordre des verrous et le moment
   des effets externes. `BookingSlotGuard` exige une transaction et verrouille
   planning/staff/ressource dans un ordre déterministe. Le webhook revendique
   l’événement unique avant toute transition et envoie l’email après commit.
3. Préserver l’isolation tenant pour DB, JWT, sessions, cache, fichiers et workers.
   Ne pas déplacer une lecture avant la résolution du tenant. Préserver les
   priorités des listeners, les noms des workflows et les transitions Sylius.
4. Conserver les alias de `config/services.yaml` (RefundProvider, SmsProvider,
   TenantDoctorInterface), les arguments scalaires, factories et tags. `App\`
   charge déjà `src/` sauf Entity et Kernel : `Service/` sera découvert sans
   nouvelle couche DI. Vérifier aussi les références dans `config/packages/`,
   attributs Autowire et sous-processus des tests de concurrence.
5. Après changement de namespace : autoload optimisé, lint du conteneur et
   suites ciblées. Aucun déplacement/migration dans #97. Chaque ticket suivant
   met à jour cette table avec classes réellement déplacées, tests et résultats.

## Inventaire des tests couplés aux fichiers

Recherche reproductible :
`rg -n 'file_get_contents|assertFile|Reflection' tests` depuis `backend/`.
L’inventaire ci-dessous couvre les lectures initiales ; ce n’est pas une liste
à supprimer. Les vérifications de configuration/migrations peuvent rester
statiques ; une règle métier nécessite une assertion sur son résultat.

| Test (chemin relatif à tests/) | Couplage initial et traitement |
| --- | --- |
| Controller/StripePaymentContractTest | Controller + Payment/StripeCheckout : remplacé dans #97 par appels réels au webhook avec HMAC, réponses JSON, états, transitions, rejeu et ordre commit/email |
| Controller/BookingRulesContractTest | Controller + BookingSlotGuard : remplacé par fixtures Doctrine transactionnelles, résultat disponibilité, erreur 409 booking_rule_violation et conflits avec buffers |
| Security/AdminApiPermissionContractTest | Subscriber : remplacé par événements RequestEvent et décisions 403/autorisations ; assertion PHP du provisionneur remplacée dans Integration/Tenant/MinimalSyliusInitializerTest par rôle Owner après deux initialisations ; assertion migration conservée |
| Security/SecurityHardeningContractTest | Rate limiter, upload, headers et configuration JWT/firewall ; à convertir lors du ticket Security |
| Controller/ShopCustomerAccountSecurityContractTest | Propriété du client et accès booking ; à convertir lors de Customer/Booking |
| Controller/StaffPreferenceContractTest | Affectation et StaffEligibility ; à convertir lors de Staff/Booking |
| Controller/WaitlistContractTest | Création, autorisation et migration ; à convertir lors de Waitlist |
| Controller/InvoiceSecurityContractTest | Listener, propriété du client, configuration PDF ; à convertir lors de Invoice |
| Controller/GiftVoucherRedemptionContractTest | Consommation verrouillée, absence de bypass et invariant Entity ; à convertir lors de GiftVoucher |
| Controller/PhysicalCheckoutContractTest | Checkout physique ; à convertir lors de Commerce |
| Controller/AdminRefundContractTest | Contrôleur, fournisseur, opération, permission et migration ; à convertir lors de Payment |
| Controller/AdminClientApiContractTest | CRUD et historique du dossier ; à convertir lors de Customer |
| Controller/ObservabilityContractTest | Contrôleur et HealthChecker ; à convertir lors de Observability |
| Controller/BookableResourceContractTest | Contrôleurs et verrou de capacité ; à convertir lors de Resource |
| Controller/CustomerBookingChangesContractTest | Contrôleur et politique de changement ; à convertir lors de Booking |
| Email/TransactionalEmailContractTest | Twig, dispatcher, contrôleurs et transports ; à convertir lors de Email (rendu et messages interceptés) |
| Gdpr/GdprContractTest | Manager, commande et documentation ; à convertir lors de Gdpr |
| Controller/AdminPlanningApiContractTest | Réflexion des routes et noms des actions, pas de lecture PHP ; conserver le contrat de route, compléter CRUD comportemental lors de Planning |

`Unit/Tenant/CustomDomainTest` lit un **fichier généré** Caddy : assertion de sortie
utile, pas de couplage au chemin source. La réflexion dans DashboardMetricsCalculatorTest
et Entity/WaitlistRequestTest initialise des identifiants d’entités ; elle ne lit
pas les services et reste distincte de cet inventaire.

## Couverture représentative après #97

- Disponibilité : AvailabilitySlotGeneratorTest (fuseau/DST, passé, service),
  BookingRulesTest (normalisation), BookingRulesContractTest (délai/horizon,
  contrôleur réel avec deux créneaux staff/sans préférence comme témoin positif,
  buffers avant/après et frontière exacte). BookingSlotConcurrencyTest reste
  la preuve MySQL à deux processus ; les doubles ne prouvent pas les verrous.
- Paiement : StripePaymentContractTest utilise le vrai vérificateur Stripe et
  StripeCheckout, doubles uniquement aux frontières DB/workflow/email. Sept
  scénarios : payé signé, non payé, signature invalide, événement déjà revendiqué,
  expiré, échec asynchrone, événement sans effet. Le conflit unique y est simulé ;
  StripeWebhookIdempotencyTest conserve la preuve de contrainte DB et le smoke
  conserve les transitions Sylius/factures réelles.
- Autorisations : décisions du subscriber selon ressource, méthode et rôle,
  matrice TeamPermissions existante, JWT cross-tenant existant, owner provisionné.
  Les appels directs au subscriber/contrôleur ne prouvent pas le routage ou le
  firewall ; les tests HTTP existants restent nécessaires.

Les nouveaux contrats BookingRules, AdminApiPermission, le test BookingRules et
le test de provisionnement sont inclus dans `phpunit.business.xml`. Aucun test
n’est supprimé ou neutralisé, aucune exclusion ni skip ajouté pour rendre vert.

## Prérequis et exécution sûre

Les tests sans kernel utilisent des doubles ou fichiers temporaires. Les tests
KernelTestCase/WebTestCase écrivent en DB, même s’ils nettoient ou rollbackent.
La suite complète contient le smoke (initialisation, utilisateurs, commandes,
factures), les consommations concurrentes et le provisionnement.

Avant toute suite DB, préparer **une instance MySQL 8.4/InnoDB jetable**, sans
accès aux bases de production, avec des droits limités aux bases de test :

1. Installer les dépendances verrouillées avec PHP compatible `^8.3`, extensions
   Composer requises (dont pdo_mysql), et `composer install --no-interaction
   --no-scripts`. Fournir `.env` et `.env.test.local` de test : le bootstrap actuel
   appelle Dotenv sur `.env`, les fichiers `.example` ne sont pas chargés seuls.
2. Définir APP_ENV=test, secrets fictifs, tenant `demo`, DATABASE_URL de test et
   **un registre `config/tenants.json` exclusivement de test** dans l’environnement
   isolé. Exemple d’entrée : `{"demo":{"db":"todatempo_test","enabled":true,
   "status":"active"}}`. Déclarer les autres tenants des fixtures si nécessaire.
   Supprimer les variables TODATEMPO_TENANT/SKYBOOK_TENANT héritées du déploiement.
   Le middleware remplace le dbname de DATABASE_URL par celui du registre :
   `APP_ENV=test` ou le nom de la DB dans l’URL seuls ne garantissent pas l’isolation.
3. Installer le schéma Sylius, toutes les migrations custom et les données minimales
   sur cette instance seulement. Vérifier notamment momeo_booking,
   momeo_booking_lock, todatempo_stripe_webhook_event et leur index unique,
   plannings, staff, ressources, vouchers et tables Sylius. Les tests de concurrence
   demandent `proc_open` et deux connexions à la même DB ; SQLite ne les remplace pas.
4. Générer des clés JWT et une clé de chiffrement de paiement **de test** aux
   chemins indiqués par `.env.test.example`. Utiliser MAILER_DSN=null://null,
   transports Messenger de test (async in-memory défini dans la configuration),
   SMS désactivé, aucune clé de paiement réelle. Les tests Stripe signent localement
   un payload ; ils n’appellent pas l’API Stripe. Le smoke PDF nécessite le moteur
   configuré, voir `docs/invoices.md` et `docs/e2e-smoke-test.md`.

`make test-business` n’a pas été exécuté : cette cible lance
`doctrine:schema:drop --full-database --force`. Le compose de test définit une URL
mais ne suffit pas à isoler le registre, les bind mounts et les volumes existants.
Lancer cette cible uniquement dans l’instance jetable préparée ci-dessus, jamais
contre l’environnement du site. Aucune correction d’infrastructure ou migration
destructive n’est introduite par ce ticket.

Commandes depuis `backend/`, une fois l’environnement préparé :

```bash
# Ciblé sans connexion DB (bootstrap .env toujours nécessaire)
php vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'StripePaymentContractTest|AdminApiPermissionContractTest|AvailabilitySlotGeneratorTest|BookingRulesTest|TeamPermissions|JwtTenantIsolationTest'
# Ciblé DB
php vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'BookingRulesContractTest|BookingSlotConcurrencyTest|StripeWebhookIdempotencyTest|MinimalSyliusInitializerTest'
# Suites existantes
php vendor/bin/phpunit --configuration phpunit.business.xml
php vendor/bin/phpunit --configuration phpunit.xml.dist
# Après déplacement de namespaces
composer dump-autoload --optimize --strict-psr --no-scripts
APP_ENV=test php bin/console lint:container
```

## Résultat initial et contrôles du ticket #97

Environnement observé le 25 septembre 2026 : PHP CLI 8.5.9, Composer disponible,
Docker absent, `vendor` et fichiers `.env` opérationnels absents à l’arrivée.
Aucune connexion à une DB ou service de production n’a été effectuée.

| Contrôle | Résultat réel |
| --- | --- |
| Installation verrouillée `composer install --no-interaction --no-scripts --no-plugins --prefer-dist` | Échec, code 1 : DNS `api.github.com` indisponible (curl 6) ; repli source également impossible, cache Composer hors zone inscriptible. Pas d’autoloader ou PHPUnit utilisable |
| Suite générale `php vendor/bin/phpunit --configuration phpunit.xml.dist` | Tentée, code 1 : `Could not open input file: vendor/bin/phpunit` ; aucun test exécuté |
| Suite métier `php vendor/bin/phpunit --configuration phpunit.business.xml` | Même blocage, aucun test exécuté |
| Ciblage StripePaymentContractTest / BookingRulesContractTest / AdminApiPermissionContractTest / MinimalSyliusInitializerTest | Tenté, même absence du binaire ; aucun test exécuté |
| `APP_ENV=test php bin/console lint:container` | Tenté, code 255 : `Symfony Runtime is missing` ; DI non validée |
| Autoload effectif et compilation après namespace | Aucun namespace modifié ; chargement effectif non vérifiable sans vendor |
| Syntaxe des quatre fichiers de test modifiés | `php -l` : validée (voir contrôle local du ticket) |
| XML PHPUnit et fichiers référencés | XML parsé, chemins de la suite métier vérifiés localement |
| Diff | `git diff --check` exécuté sans erreur |

Il n’existe donc **aucun résultat PHPUnit vert initial ou après modification**
dans cet environnement. Les résultats fonctionnels, DB, concurrence, DI et smoke
restent à établir dans l’environnement isolé décrit ci-dessus. Ce sont des
limitations environnementales documentées, pas des tests déclarés réussis.
Les tickets suivants doivent conserver cette distinction et compléter leur
périmètre de tests ainsi que cette cartographie à chaque extraction.
