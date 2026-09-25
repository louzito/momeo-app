# Contrat d’architecture custom TodaTempo

État établi pour AutoTicket #97, le 25 septembre 2026, à partir de la tête
fournie du dépôt. Référence de l’analyse initiale de la série :
`06886c65241f43cb4a16ba695ccbd997ad1f0ba4` (`autoticket/todatempo-v1`).
Branche commune prévue : `autoticket/todatempo-back-refacto-controller-entity-service`.
Mise à jour AutoTicket #98 : le prérequis #97 est présent dans le commit
`a45137f`. Les 29 classes/interfaces des 12 domaines ci-dessous sont maintenant
sous `App\Service`. Les domaines Tenant, Observability, Security et Reminder
sont traités dans #99 (voir livraison ci-dessous). Aucun ticket suivant n’est activé.

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

Tous les chemins de cette table sont relatifs à `backend/src`. La première
colonne garde les emplacements historiques. Les lignes marquées **fait #98** ou **fait #99**
utilisent désormais la destination ; les autres extractions restent **à faire**. `Entity`, `Repository` et les adaptateurs indiqués
« conservé » restent à leur emplacement, avec délégation à compléter si besoin.

| Source actuelle | Destination et responsabilité |
| --- | --- |
| `Availability/{AvailabilitySlotGenerator,CenterTimeZoneProvider,PlanningProvider}` | `Service/Availability/` : créneaux, fuseau du centre, lecture du planning publié — **fait #98** |
| `Booking/{BookingRules,BookingSlotGuard,CustomerBookingChangePolicy,SlotUnavailable}` | `Service/Booking/` : règles, annulation/déplacement, capacité et coordination des verrous — **fait #98** |
| `Planning/PlanningInput` | `Service/Planning/` : normalisation métier du planning ; parsing HTTP dans Controller — **fait #98** |
| `Staff/{StaffEligibility,WorkingHours}` | `Service/Staff/` : compétences, affectation et calendrier — **fait #98** |
| `Resource/ResourceAvailability` | `Service/Resource/` : sélection et capacité des ressources — **fait #98** |
| `Payment/{StripeCheckout,ServicePaymentTerms,ConfiguredRefundProvider,RefundProvider}` | `Service/Payment/` : acompte, transitions Sylius, Stripe, remboursement et interface fournisseur existante — **fait #98** |
| `GiftVoucher/*` | `Service/GiftVoucher/` : création, activation, codes, QR, marquage de commande et envoi — **fait #98** |
| `Email/BookingEmailDispatcher` | `Service/Email/` : préparation et déclenchement des emails transactionnels — **fait #98** |
| `Waitlist/WaitlistNotifier` | `Service/Waitlist/` : sélection et notification des demandes — **fait #98** |
| `Reminder/ReminderConfiguration`, `Reminder/Sms/*` | `Service/Reminder/` : règles des rappels et frontière SMS existante — **fait #99** |
| `Reminder/Message/SendBookingReminder`, `Reminder/MessageHandler/SendBookingReminderHandler` | Adaptateurs Messenger conservés ; orchestration dans `Service/Reminder/ReminderSender` — **fait #99, nom harmonisé #110** |
| `Gdpr/{CustomerDataManager,RetentionPolicy}` | `Service/Gdpr/` : export, anonymisation, purge et rétention — **fait #98** |
| `Dashboard/DashboardMetricsCalculator` | `Service/Dashboard/` : calcul des indicateurs — **fait #98** |
| `Configuration/{SiteConfigDocument,ProductionConfigurationValidator}` | `Service/Configuration/` : lecture publiée et validation de configuration — **fait #98** |
| `Tenant/{TenantProvisioner,TenantDatabaseCloner,MinimalSyliusInitializer,ProvisionedTenant}` | `Service/Tenant/` : provisionnement et résultat non persisté ; préserver les étapes de reprise — **fait #99** |
| `Tenant/{TenantContext,TenantRegistry,TenantRegistryWriter,TenantIdentifierResolver,TenantUrlGenerator}` | `Service/Tenant/` : identité courante, registre, résolution et URLs ; préserver cache et portée du contexte — **fait #99** |
| `Tenant/{CustomDomainManager,DomainName,DomainOwnershipVerifier,CaddyConfigDumper}` | `Service/Tenant/` : domaines et génération Caddy ; aucune nouvelle entité pour un objet non persisté — **fait #99** |
| `Tenant/{AdminLoginTicketStore,TenantDoctor,TenantDoctorInterface,TenantWorkerGuard}` | `Service/Tenant/` : SSO à usage unique, diagnostic et garde worker — **fait #99** |
| `Tenant/{TenantConnectionMiddleware,TenantAwareCachePool,TenantImagePathGenerator,TenantInvoicePdfStorageFactory,JwtTenantListener}` | Adaptateurs DBAL/cache/Sylius/JWT conservés et documentés ; règles déléguées à `Service/Tenant/` — **fait #99** |
| `Observability/{HealthChecker,MetricsRegistry}` | `Service/Observability/` : sondes et métriques — **fait #99** |
| `Observability/{CorrelationIdListener,LogContextProcessor}` | Adaptateurs événements/Monolog conservés — **fait #99** |
| `Security/{TeamPermissions,TeamPermission,TeamRole}` | Politique dans `Service/Security/` ; enums gardés près de la politique, références Entity à mettre à jour ensemble — **fait #99** |
| `Security/{ImageUploadValidator,SensitiveEndpointRateLimiter}` | Validation métier dans `Service/Security/` ; intégration Request/RateLimiter conservée dans les listeners HTTP — **fait #99** |
| `Security/{AdminApiPermissionSubscriber,HttpSecurityHeadersSubscriber}` | Adaptateurs HTTP conservés : sélection de permission, rejet et en-têtes — **fait #99** |
| `Command/ScheduleBookingRemindersCommand` | Adaptateur CLI conservé ; sélection, déduplication et publication dans `Service/Reminder/ReminderScheduler` — **fait #110** |
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
| AdminPlanningApi, AdminBookableResourceApi | `Service/Planning/PlanningManagementService`, `Service/Resource/BookableResourceManagementService` — **fait #104** |
| AdminStaffMemberApi, AdminStaffTimeOffApi | `Service/Staff/{StaffManagementService,StaffAccountService,StaffTimeOffService}` — **fait #103** |
| ShopStripePayment, ShopPaymentTerms | `Service/Payment/{StripePaymentService,StripeWebhookProcessor,OrderPaymentTermsService}` : session, annulation, signature, transaction/déduplication, conditions de paiement — **fait #106** |
| AdminRefundApi | `Service/Payment/{RefundService,RefundView}` : orchestration et projection — **fait #107** |
| ShopGiftVoucherApi, AdminGiftVoucherApi, ShopGiftOrderMarker | `Service/GiftVoucher/{GiftVoucherAccess,GiftVoucherView,GiftOrderMarking}` : accès, projections et marquage — **fait #108** |
| ShopWaitlistApi, AdminWaitlistApi | `Service/Waitlist/WaitlistManagement` : inscription, liste, désinscription ; `WaitlistNotifier` réutilisé — **fait #110** |
| ShopCustomerAccountApi, AdminClientApi | `Service/Customer/{ClientDirectoryService,ClientProfileService,CustomerAccountReadService,CustomerAccountAccess}` : annuaire, profils, lectures et propriété — **fait #105** ; mutations Booking faites #102 ; RGPD/PDF conservés pour le ticket dédié |
| ShopPhysicalOrderApi, AdminPhysicalCommerceApi | `Service/Commerce/{PhysicalCheckoutService,PhysicalProductManagementService,PhysicalPreparationService,PhysicalProductReadService}` : remise, catalogue et préparation — **fait #109** |
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
| Security/SecurityHardeningContractTest | Rate limiter, upload, headers et configuration JWT/firewall ; converti en appels des listeners/services dans #99 |
| Controller/ShopCustomerAccountSecurityContractTest | Converti #105 : lectures réelles, absence de profil/réservations, refus de propriété (détail et mutations), commandes et factures payées |
| Controller/StaffPreferenceContractTest | Converti en #100 : payload réel, ordre, sélection déterministe et validation de créneau ; fixture DB transactionnelle |
| Controller/WaitlistContractTest | Converti #110 : appels HTTP/services réels, consentement strict, validations, inscription/liste/désinscription ; autorisations dans AdminApiPermissionContractTest ; assertion migration conservée |
| Controller/InvoiceSecurityContractTest | Listener, propriété du client, configuration PDF ; à convertir lors de Invoice |
| Controller/GiftVoucherRedemptionContractTest | Converti en #101 : créations commande/cadeau réelles, rejeu, refus, relecture verrouillée, rollback et emails interceptés |
| Controller/PhysicalCheckoutContractTest | Converti #109 : appels HTTP directs avec services réels, réponses et erreurs checkout/admin, projection publique ; doubles Doctrine |
| Controller/AdminRefundContractTest | Contrôleur, fournisseur, opération, permission et migration ; à convertir lors de Payment |
| Controller/AdminClientApiContractTest | Converti #105 : agrégations, historique, filtres/tri, modification du profil et stabilité de la clé email |
| Controller/ObservabilityContractTest | Contrôleur et HealthChecker ; converti en appels contrôleur/sondes dans #99 |
| Controller/BookableResourceContractTest | Converti en #104 : routes par attributs, CRUD HTTP/Doctrine, affectations, refus, disponibilité calculée ; verrou de capacité conservé |
| Controller/CustomerBookingChangesContractTest | Converti en #102 : contrôleurs/services réels, transactions Doctrine isolées, propriété, historique, conflits, rollback et effets après commit |
| Email/TransactionalEmailContractTest | Twig, dispatcher, contrôleurs et transports ; à convertir lors de Email (rendu et messages interceptés) |
| Gdpr/GdprContractTest | Manager, commande et documentation ; à convertir lors de Gdpr |
| Controller/AdminPlanningApiContractTest | Complété en #104 : routes nommées, CRUD HTTP/Doctrine, refus sans mutation, calendrier historique, portée collaborateur et filtrage repository |

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

## Livraison et vérifications du ticket #98

Les 29 classes/interfaces ont été déplacées sans modification de leurs corps.
`RefundProvider`, `SlotUnavailable` et `GiftOrderMarker` restent des objets de
service non persistés. Aucun alias de compatibilité avec les anciens namespaces
n’est nécessaire : aucune consommation externe documentée n’a été trouvée.
L’alias DI de remboursement, les imports, les appels statiques, le Kernel,
les sous-processus du test de concurrence, les commentaires et le contrôle PHP
du script de déploiement utilisent les nouveaux noms. Le script n’a pas été
exécuté. Les adaptateurs et repositories conservent leurs emplacements.

Tests de comportement adaptés aux nouveaux imports : disponibilité, règles,
configuration publiée, métriques, plannings, horaires, éligibilité, conditions de
paiement, Stripe et concurrence. La suite métier inclut maintenant aussi les
tests unitaires pertinents des domaines déplacés. Les lectures PHP remplacées
par des appels réels couvrent :

- cinq types d’email, destinataire, tenant courant, token encodé et fuseau publié,
  avec un expéditeur doublé, sans envoi ;
- limite de modification à la seconde précédant l’échéance et à l’échéance ;
- filtres actif/réservable/compétence du personnel ;
- capacité de ressource saturée dans une transaction, avec connexion doublée ;
- remboursement manuel et refus de Stripe sans configuration, sans réseau.

Un test RGPD supplémentaire vérifie les échéances et compteurs du dry-run,
l’exclusion des réservations déjà anonymisées et l’absence d’écriture/d’audit.
Les anciens contrôles statiques RGPD restent complémentaires ; ils ne prouvent
pas l’isolation réelle de la base. Les assertions de contrôleurs non extraits
restent à convertir lors de leurs tickets respectifs. Les doubles de connexion
ne valident pas les verrous MySQL ni les contraintes de concurrence.

Contrôles réellement exécutés dans cet environnement :

| Contrôle | Résultat |
| --- | --- |
| Syntaxe PHP sur `src/` et `tests/` | 258 fichiers valides |
| `composer dump-autoload --optimize --strict-psr --no-scripts --no-plugins` | Succès, 206 classes indexées ; dépendances tierces absentes |
| `class_exists` / `interface_exists` via cet autoloader pour les 29 symboles déplacés | Succès ; ceci ne constitue pas une compilation du conteneur |
| Comparaison des 205 fichiers PHP de production avec HEAD, après normalisation des seuls namespaces/imports | Identiques ; aucun changement de logique, mapping, route ou transaction |
| Recherche des anciens namespaces, y compris formes échappées, et chemins actifs | Aucune référence active restante |
| `git diff --check` | Succès |
| `composer install --no-interaction --no-scripts --no-plugins --prefer-dist` | Échec code 1 : DNS `api.github.com` indisponible (curl 6), repli source impossible dans le cache non inscriptible |
| `php vendor/bin/phpunit --configuration phpunit.business.xml` | Échec code 1 : binaire absent, aucun test exécuté |
| `php vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'Availability\|Booking\|Configuration\|Dashboard\|Email\|GiftVoucher\|Payment\|Planning\|Resource\|Staff\|Waitlist\|Gdpr\|AdminRefund'` | Échec code 1 : binaire absent, aucun test exécuté |
| `APP_ENV=test php bin/console lint:container` | Échec code 255 : Symfony Runtime absent ; DI non validée |

Les suites métier et le lint du conteneur restent à exécuter dans l’environnement
jetable décrit plus haut, une fois les dépendances disponibles. Aucune connexion
DB, aucun email/SMS/paiement réel, aucune migration ni aucun déploiement effectués.

## Livraison du ticket #99

Prérequis présents : #97 (`a45137f`) et #98 (`117ef31`) à la tête fournie.
Aucune opération de branche/commit/push, activation du ticket suivant ou action
sur le site. 26 classes/interfaces/enums déplacés dans Service : 17 Tenant,
2 Observability, 3 Security et 4 Reminder. Trois services extraits en plus :
`Service/Reminder/BookingReminderSender`, `Service/Security/ImageUploadValidator`
et `Service/Security/SensitiveEndpointRateLimiter`.

Les imports (y compris Entity/AdminUser et fixtures), alias TenantDoctor/SMS et
arguments de HealthChecker/MetricsRegistry suivent les nouveaux namespaces.
Les valeurs scalaires persistées de TeamRole et le mapping Doctrine restent
identiques. `Security/TeamRole.php` conserve un alias de compatibilité pour les
enums déjà sérialisés dans les sessions ; le test utilise un payload historique
littéral. Pas d’alias systématique pour les autres services internes.

### Exceptions techniques conservées

| Emplacement | Contrat conservé |
| --- | --- |
| Tenant/TenantConnectionMiddleware | Adaptateur DBAL `doctrine.middleware` autoconfiguré ; résolution de dbname au connect, aucune modification des connexions sans dbname |
| Tenant/TenantAwareCachePool | Décorateur `cache.app`, préfixe recalculé à chaque appel, sous-namespaces et délégation reset inchangés |
| Tenant/TenantImagePathGenerator | Décorateur Sylius `sylius.generator.image_path`, préfixe et exception tenant par défaut inchangés |
| Tenant/TenantInvoicePdfStorageFactory | Factory Sylius/Gaufrette toujours référencée dans services.yaml ; racine historique du tenant par défaut, préfixe et cache PDF par slug inchangés |
| Tenant/JwtTenantListener | Adaptation Lexik, claim tenant obligatoire, rejet cross-tenant et TTL administrateur inchangés |
| EventListener/TenantRequestListener, TenantSessionNameListener | Adaptation HTTP, fermeture de connexion lors du changement, priorités 512/500 et nom de cookie conservés |
| EventListener/TenantMessengerWorkerListener | Priorité console 1024 ; garde dans Service/Tenant, tenant explicite figé pendant la vie du worker |
| Observability/CorrelationIdListener, LogContextProcessor, EventListener/ObservabilityWorkerListener | Événements HTTP/Messenger et tag monolog.processor conservés ; corrélation request à priorité 1024 |
| Security/AdminApiPermissionSubscriber, HttpSecurityHeadersSubscriber | Sélection des ressources HTTP, 403, en-têtes ; politique dans Service/Security |
| Security/ImageUploadValidator, SensitiveEndpointRateLimiter | Sélection des requêtes/fichiers et réponses 422/429 ; règles déléguées aux services, priorités 48/40 conservées |
| Reminder/Message/SendBookingReminder | **FQCN et propriété deliveryId inchangés**, routage Messenger async inchangé ; compatible avec les messages déjà en file |
| Reminder/MessageHandler/SendBookingReminderHandler | Unique attribut AsMessageHandler ; délègue l’identifiant au service, qui garde ordre des effets/flush, états, tentative et propagation des erreurs |
| Twig/SkybookEmailExtension | Adaptateur Twig conservé ; extraction propre à Email hors de #99 |

Le contexte tenant ne reçoit pas de nouveau reset : le worker existant reste
lié à son tenant explicite. Les resets Symfony/Doctrine existants et le reset
par délégation du cache ne sont ni retirés ni déplacés. La factory PDF conserve
sa durée de vie existante ; ces tests ne prétendent pas ajouter un worker qui
changerait de tenant entre deux messages.

### Couverture

Les tests Tenant unitaires/intégration, TenantWorkerGuard, ReminderMessenger,
Observability et permissions utilisent les nouveaux services. La suite métier
inclut désormais les tests techniques concernés. Ajouts/comportements vérifiés
par les tests (leur exécution effective est détaillée ci-dessous) :

- message PHP historique littéral relu puis décodé par PhpSerializer et passé
  au vrai handler : email/SMS envoyé une fois malgré rejeu, annulation/consentement,
  fournisseur désactivé, erreur persistée puis propagée à chaque tentative ;
- cache et connexion doublée successivement alpha/beta/alpha, fermeture avant
  reconnexion, clés identiques isolées, clear limité au tenant et accès après
  reset ; cookie session alpha puis beta dans un processus séparé ;
- limites login (5) et voucher (30), séparation des tenants/clients, réponse
  429 et Retry-After ; upload PNG accepté, extensions incompatibles et taille
  excessive refusées, en-têtes HTTP ;
- vraies réponses de santé, tenant inconnu, connexion fermée avant SELECT 1,
  erreurs DB/HTTP rendues sans secrets ; tests de métriques et corrélation
  conservés ; ancien enum de rôle encore désérialisable.

Les tests utilisent des doubles DB/réseau/SMS/email et des fichiers temporaires.
Ils ne prouvent pas une connexion MySQL réelle, le stockage PDF réel ou la
compilation DI. L’inventaire de sécurité frontend pointe vers le nouveau fichier
Caddy ; aucun code frontend fonctionnel n’est modifié.

### Contrôles exécutés pour #99 (25 septembre 2026)

| Commande / contrôle | Résultat observé |
| --- | --- |
| `composer install --no-interaction --no-scripts --no-plugins --prefer-dist` (backend/) | Échec code 1 : GitHub inaccessible (curl 6 DNS, puis curl 7), repli source bloqué par cache non inscriptible ; 268 dépendances non installées |
| `composer dump-autoload --optimize --strict-psr --no-scripts --no-plugins` (backend/) | Succès, 209 classes indexées ; ne valide pas les dépendances tierces |
| Chargement via vendor/autoload.php des 29 symboles des quatre domaines | Succès (`class_exists`/`interface_exists`/`enum_exists`), avec lecture des payloads PHP historiques message/enum |
| Contrôles PHP locaux sans framework | Succès : registre/context/guard alpha puis beta puis alpha, compteurs métriques séparés, permissions owner/practitioner et refus du fournisseur SMS désactivé ; ne remplacent pas PHPUnit |
| Comparaison avec HEAD des 26 éléments déplacés | Corps identiques après normalisation namespaces/imports ; les trois extractions ont été relues séparément |
| `php -l` sur src/ et tests/ | 264 fichiers valides ; fichiers modifiés ensuite relintés |
| `git diff --check` | Succès |
| `php vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'Tenant\|Reminder\|Observability\|Security\|TeamPermissions'` | Impossible, binaire vendor/bin/phpunit absent ; aucun test PHPUnit exécuté |
| `php vendor/bin/phpunit --configuration phpunit.business.xml` | Impossible pour la même raison ; aucun test exécuté |
| `APP_ENV=test php bin/console lint:container` | Échec code 255 : Symfony Runtime absent ; conteneur non compilé/non validé |
| `node --test frontend/test/security-config.test.mjs` | Impossible code 127 : Node absent ; ce test d’inventaire n’a pas été exécuté |

Après installation des dépendances, exécuter les deux commandes PHPUnit et le
lint du conteneur ci-dessus dans l’environnement jetable décrit plus haut. Les
tests d’intégration qui démarrent le kernel nécessitent également .env, clés de
test et registre/base MySQL exclusivement de test. Aucune connexion à une base,
aucun envoi réel, paiement, déploiement ou migration n’a été effectué ici.

## Livraison AutoTicket #100 — disponibilités et contrôles de créneau

Prérequis présents à la tête fournie : #97 `a45137f`, #98 `117ef31`,
#99 `e551591`. Aucun changement de branche, commit, déploiement ou activation
du ticket suivant effectué.

| Ancien point d’entrée | Extraction réalisée |
| --- | --- |
| ShopBookingApiController::availability, isBlocked, bookedOnPlanning | `Service/Availability/AvailabilityService` : génération, règles de réservation, capacités, ressources, horaires, absences et construction ordonnée des créneaux, dont « Sans préférence » |
| ShopBookingApiController::chooseAutoStaff, validateStaffSlot, isPlanned | `Service/Availability/PublicStaffSlot` : sélection déterministe par position/id, verrou pessimiste de chaque candidat, durée et départ publié, chevauchements |
| ShopBookingApiController et AdminBookingApiController::serviceDuration | `Service/Availability/ServiceDuration` : priorité todatempo_duration, repli momeo_duration, bornes 15–480 minutes et défaut 60 |
| AdminBookingApiController::planning ; ShopCustomerAccountApiController::assertPlannedSlot, assertStaffHours | `Service/Availability/PlanningSlotPolicy` : sélection admin, appartenance commune à une plage et contrôles client |

Les contrôleurs gardent parsing, routes, autorisations et réponses HTTP. Les
transactions et leur gestion d’erreur ne sont pas déplacées ; PublicStaffSlot
verrouille dans la transaction déjà ouverte par la création directe ou voucher.
BookingSlotGuard reste le contrôle final avec buffers/capacités et verrous.
Les repositories et services de #98/#99 sont réutilisés sans changer leur portée
tenant ; aucune nouvelle interface, configuration scalaire ou migration.
Les quatre nouveaux services sont découverts par la ressource `App\` existante.

Différences intentionnellement conservées :

- Public : grille générée depuis PlanningProvider (taxons publiés), fuseau du
  centre, durée actuelle du catalogue et correspondance exacte du départ/code.
  La disponibilité applique les buffers aux réservations et absences ; la
  prévalidation d’écriture garde ses requêtes de chevauchement existantes,
  suivies du garde transactionnel. Le contrat JSON et son tri restent identiques.
- Admin : un code explicite exige seulement un planning actif compatible ;
  sans code, sélection de la première plage compatible avec le collaborateur,
  dans le fuseau du planning. Pas d’alignement imposé sur la grille publique.
  Les horaires staff restent évalués avec le fuseau du centre.
- Client : durée écoulée de la réservation conservée, plage et horaires staff
  dans le fuseau du planning. Aucun recalcul depuis le catalogue. Le calcul
  de plage partagé garde les comparaisons historiques à la minute, distinctes
  du contrôle WorkingHours à la seconde.

Tests de comportement ajoutés/adaptés : AvailabilityServiceTest (capacité,
pauses, absences, ressource obligatoire disponible/saturée, erreurs HTTP,
différence admin/client), PlanningSlotPolicyTest (fuseau, DST, durée écoulée,
pauses), ServiceDurationTest (priorité, repli et bornes). StaffPreferenceContractTest
n’inspecte plus le PHP : payload complet avec identifiants/champs/ordre, choix
sans préférence, candidat absent, aucun candidat, durée et départ invalides.
La fixture DB transactionnelle de BookingRulesContractTest est partagée dans
AvailabilityTestCase ; les scénarios délai/horizon/buffers existants sont conservés.
Les nouveaux tests sont inclus dans phpunit.business.xml. Les suites existantes
AvailabilitySlotGenerator, StaffEligibility, WorkingHours et Resource sont conservées.

Contrôles réellement effectués dans cet environnement :

- `php -l` sur les services Availability, contrôleurs et tests concernés : succès.
- `git diff --check` : succès.
- `composer dump-autoload --optimize --strict-psr --no-scripts` : succès,
  213 classes applicatives ; ceci ne valide pas les dépendances ni le conteneur.
- Vérification PHP autonome avec l’autoloader : exclusion d’une heure inexistante
  au changement d’heure, conversion Europe/Paris vers UTC et rejet d’une pause
  WorkingHours : succès. Ce contrôle limité ne remplace pas PHPUnit.
- Installation verrouillée `composer install --no-interaction --no-scripts
  --no-plugins --prefer-dist`, bornée à 40 secondes : téléchargements en échec
  curl 6, DNS `api.github.com` indisponible ; dépendances non installées.
- Depuis backend, `php vendor/bin/phpunit --configuration phpunit.xml.dist
  --filter 'Availability|BookingRules|StaffEligibility|WorkingHours|Resource|StaffPreference'` :
  impossible, `vendor/bin/phpunit` absent ; aucun scénario PHPUnit annoncé réussi.
- `APP_ENV=test php bin/console lint:container` : impossible, Symfony Runtime
  absent. DI et tests DB/concurrence restent à exécuter avec les dépendances
  et l’instance MySQL jetable décrites plus haut. Aucune connexion de production.

## Livraison #101 — création publique par commande et chèque cadeau

Prérequis : tête `169b750` (#100), précédée de #97–#99. Aucun changement de
branche, commit, activation du ticket suivant ou déploiement.

| Point d’entrée | Destination et responsabilité |
| --- | --- |
| `ShopBookingApiController::create` | `Service/Booking/BookingCreationService::createFromOrder` : validation produit/commande/paiement, sélection staff/ressource, construction, transaction et persistance — **fait #101** |
| `ShopBookingApiController::createFromVoucher` | `Service/Booking/BookingCreationService::createFromVoucher` : relecture verrouillée, validation du chèque et du bénéficiaire, création et consommation atomiques — **fait #101** |
| `Repository/GiftVoucherRepository::findOneByCodeForUpdate` | Adaptateur Doctrine conservé ; `HINT_REFRESH` relit aussi l’état d’une entité déjà chargée lors de la prise du verrou pessimiste |
| `Service/Booking/InvalidBooking` | Erreur métier de commande/produit/modalités de paiement ; traduction explicite en 422 par le contrôleur |

Les deux actions ne contiennent plus de transaction, construction de Booking,
persist ou flush. Elles conservent parsing/validation de forme, réponses JSON,
normalisation et notifications après retour du service (donc après commit),
hors des catches de création : une erreur d’email ne provoque pas de rollback.
La validation préalable de délai/horizon du parcours commande conserve sa
priorité HTTP, avant la validation des coordonnées. `PublicStaffSlot`,
`ResourceAvailability` et `BookingSlotGuard` sont réutilisés sans nouvelle
politique de créneau. Les sélections sans préférence et les verrous staff,
chèque et capacité gardent leur ordre. Les exceptions de construction du
parcours commande sont désormais également couvertes par le rollback.

Contrats conservés : commande invalide en 422 ; indisponibilité en 409 avec
`slot_unavailable` ; refus métier du chèque en 409 sans code ; différences
historiques des erreurs de ressources entre commande et chèque ; montants,
statuts carte/hors carte, champs legacy du chèque, routes et autorisations.
Aucune nouvelle clé d’idempotence : le rejeu du chèque consommé est refusé,
les protections existantes de capacité restent en place. Aucun changement
Entity, migration ou résolution du tenant.

`GiftVoucherRedemptionContractTest` est converti d’assertions sur le PHP en
appels réels du contrôleur/service avec fixture Doctrine isolée : succès et
rejeu cadeau, impayé/expiré/consommé, relecture d’un chèque déjà chargé,
capacité perdante après sélection du staff, commande absente, succès commande
puis créneau perdu, ressource non associée pour les deux entrées, exception
injectée sur flush pour les deux entrées. Les effets persistés, payloads,
statuts, rollback et absence d’email sont vérifiés. Le Sender est doublé ;
l’email de succès vérifie que le niveau transactionnel du service est terminé
(la fixture garde sa transaction externe pour nettoyage). Ces tests ne
prétendent pas prouver une concurrence entre processus.

La couverture publique de BookableResourceContractTest est remplacée par ces
scénarios comportementaux ; ses contrôles admin/client restent à convertir
lors de leurs tickets. BookingSlotConcurrencyTest et
GiftVoucherConcurrentRedemptionTest sont conservés comme preuves InnoDB
à deux processus. La suite business inclut déjà les fichiers concernés.

Contrôles effectués dans cet environnement :

- `php -l` sur les six fichiers PHP ajoutés/modifiés : succès.
- `git diff --check` : succès.
- `composer dump-autoload --optimize --strict-psr --no-scripts` : succès,
  215 classes. Chargement PHP effectif des deux nouvelles classes : succès.
- Installation verrouillée tentée avec `timeout 25 composer install
  --no-interaction --no-scripts --no-plugins --prefer-dist` : interrompue après
  la borne, téléchargements en échec curl 6 (DNS api.github.com indisponible).
- `php vendor/bin/phpunit --configuration phpunit.business.xml --filter
  'BookingSlotConcurrencyTest|GiftVoucherConcurrentRedemptionTest|GiftVoucherRedemptionContractTest|BookingRulesContractTest|BookableResourceContractTest'` :
  non exécutable, `vendor/bin/phpunit` absent. Aucun test PHPUnit annoncé réussi.
- `APP_ENV=test php bin/console lint:container` : non exécutable, Symfony
  Runtime absent. L’autoload ne valide pas la DI. Les suites ciblées, les
  verrous concurrents et la DI restent à exécuter avec les dépendances et
  l’instance MySQL jetable décrites plus haut. Aucune connexion de production.


## Livraison #102 — mutations admin et client

Prérequis constatés : #97 à #101 présents dans l’historique, tête initiale
`b42a203` (#101). Aucun changement de branche, commit, activation du ticket
suivant ou déploiement effectué.

| Points d’entrée conservés | Services extraits sous `src/Service/Booking` |
| --- | --- |
| `AdminBookingApiController::create` | `ManualBookingCreator` : création manuelle transactionnelle, sélection staff/planning/ressource, garde de capacité, confirmation après commit |
| `AdminBookingApiController::reschedule`, `ShopCustomerAccountApiController::reschedule` | `BookingRescheduler` : politiques admin/client distinctes, allocation, historique client et email après commit |
| `AdminBookingApiController::{postpone,complete,noShow,cancel}`, `ShopCustomerAccountApiController::cancel` | `BookingLifecycle` : transitions autorisées, délais client, historique client, annulation et liste d’attente après commit |
| Transactions des mutations existantes | `BookingMutation` : verrou pessimiste de réservation, relecture, contrôle de propriété client avant transaction et après relecture, flush/commit/rollback |
| Identifiants des créations publique/cadeau/manuelle | `BookingIdentity` : primitive commune extraite de `BookingCreationService`, référence MOM et jeton public inchangés |

Les deux contrôleurs ne contiennent plus de transaction, flush, changement de
statut ou historique. Ils conservent routes, authentification, parsing et
sérialisation. Les exceptions `BookingMutationFailed` (message/code métier) et
`BookingNotOwned` restent sans dépendance HTTP ; les adaptateurs traduisent
respectivement en 409 et 404. La création conserve son refus prestation en 422.
`PlanningSlotPolicy`, `ServiceDuration`, `BookingSlotGuard`, les repositories
et `ResourceAvailability` sont réutilisés ; aucun changement de résolution
tenant, mapping, migration ou workflow Sylius.

Les transitions simples admin sont désormais protégées par la même transaction
et relecture verrouillée que les déplacements. Les erreurs restaurent si
possible la réservation depuis la base puis la détachent pour écarter aussi
les mises à jour déjà planifiées par un flush échoué. Un appel réutilisable
qui échoue doit donc recharger la réservation avant une nouvelle tentative.
La création manuelle détache également son objet en cas de rollback.

Compatibilités préservées : pas d’historique admin ajouté ; acteur `customer`
et contenu ancien/nouveau créneau identiques ; pas de notification nouvelle
sur report/réalisation/absence ; annulation libérant la capacité par son statut,
email puis liste d’attente après commit, erreur de liste d’attente tolérée.
Le code historique `change_deadline_passed` reste présent même pour une
DomainException de choix de ressource côté client. Les différences des règles
admin et client ne sont pas uniformisées. Le subscriber admin reste la
frontière d’autorisation Agenda ; tous les rôles d’équipe actuels la possèdent.

Tests de comportement ajoutés/remplacés dans
`CustomerBookingChangesContractTest` : transitions admin et rejeu, annulation
d’un report, création manuelle, annulation client et historique, déplacement
admin/client, refus de ressource, conflit de capacité, échecs injectés sur
flush, délai client, 404 propriétaire différent, appels réutilisables avec
mauvais propriétaire avant transaction et après relecture, libération de
créneau, emails et liste d’attente après commit (transport intercepté, y compris
erreur de liste d’attente). La fixture conserve une transaction externe pour
nettoyage. Le contrat exact de délai existant reste couvert.

Les assertions PHP de ressources et d’emails admin ont été remplacées par ces
scénarios, sans déplacer leurs recherches de chaînes dans les services.
`AdminApiPermissionContractTest` couvre maintenant aussi chaque route de
mutation et chaque rôle. La suite business inclut déjà les fichiers concernés.
`BookingRulesContractTest` et `BookingSlotConcurrencyTest` restent inchangés :
le second demeure la preuve requise à deux processus sur MySQL/InnoDB, que les
fixtures transactionnelles ne remplacent pas.

Contrôles réellement effectués :

- `php -l` : succès sur les 14 fichiers PHP ajoutés/modifiés.
- `git diff --check` : succès ; recherche des transactions/flush/statuts/historique
  dans les deux contrôleurs : aucune occurrence.
- Depuis `backend/`, `composer dump-autoload --optimize --strict-psr --no-scripts` :
  succès, 222 classes ; chargement effectif des sept nouvelles classes : succès.
- Installation verrouillée `timeout 25 composer install --no-interaction
  --no-scripts --no-plugins --prefer-dist` : échec des téléchargements curl 6
  (DNS `api.github.com` inaccessible), arrêt à la borne (124).
- `php vendor/bin/phpunit --configuration phpunit.business.xml --filter
  'CustomerBookingChangesContractTest|BookingRulesContractTest|BookingSlotConcurrencyTest|AdminApiPermissionContractTest|BookableResourceContractTest|GiftVoucherRedemptionContractTest|TransactionalEmailContractTest|ShopCustomerAccountSecurityContractTest'` :
  non exécutable, `vendor/bin/phpunit` absent. Aucun résultat PHPUnit validé.
- `APP_ENV=test php bin/console lint:container` : non exécutable, Symfony Runtime
  absent (255). L’autoload ne constitue pas une validation DI.

Les tests ciblés, la concurrence MySQL et la DI restent à exécuter dans
l’environnement jetable décrit plus haut après installation des dépendances.
Aucun appel email/SMS/paiement réel ni accès aux données de production.

## Livraison #103 — collaborateurs, comptes et absences

Prérequis présents : tête initiale `991481c` (#102), précédée des commits
#97 à #101. Aucun changement de branche, commit, activation du ticket suivant
ou déploiement.

| Point d’entrée | Responsabilité extraite |
| --- | --- |
| `AdminStaffMemberApiController::{create,update,hydrate,archive}` | `Service/Staff/StaffManagementService` : normalisation et validation d’un brouillon non géré, application après validation complète, transaction de sauvegarde et archivage |
| `AdminStaffMemberApiController::{syncAccount,canRemoveOwner}` | `Service/Staff/StaffAccountService` : résolution du compte actif existant, association/dissociation, rôles et dernier propriétaire ; préparation sans mutation puis écriture dans la transaction appelante |
| `AdminStaffTimeOffApiController::{create,delete}` | `Service/Staff/StaffTimeOffService` : résolution du collaborateur, validation de période, raison et persistance |
| Erreurs de validation | `Service/Staff/InvalidStaffInput` : erreur métier traduite en 422 avec le message historique |

Les contrôleurs conservent routes, parsing et normalisation JSON. L’adaptateur
`Security/AdminApiPermissionSubscriber` reste la frontière HTTP : lecture des
collaborateurs et gestion des absences via Agenda, écriture des collaborateurs
via Settings (owner uniquement). L’exposition accountEmail/role reste limitée
à Settings ; les autres rôles reçoivent null. Aucun nouveau compte, mot de
passe, permission ou champ JSON. `WorkingHours`, `TeamRole` et `TeamPermissions`
sont réutilisés. Entity et mapping restent inchangés ; aucune migration.

La validation des horaires porte sur une copie non gérée : aucun champ de
l’original n’est modifié sur un refus. La validation complète du compte précède
également toute mutation, y compris dans le cas « dissocier un autre compte,
puis tenter de rétrograder le dernier owner ». Le compte absent/désactivé est
refusé et aucune création de compte implicite n’est ajoutée. L’archivage garde
son comportement : active/bookable à false, compte lié et rôle inchangés.
L’email personnel reste indépendant de l’email du compte ; les valeurs par
défaut et les règles de remplacement/réaffectation existantes sont conservées.

Les modifications StaffMember/AdminUser sont atomiques. Les mutations de
compte verrouillent et relisent les comptes du tenant courant dans l’ordre
id, avant de compter les owners actifs ou de modifier une association. Ce
verrou sérialise les modifications de comptes par ce service ; il ne prétend
pas coordonner des écritures externes qui ne suivent pas ce protocole. Les
requêtes utilisent le même EntityManager tenant-scopé, sans connexion globale.
Un remplacement libère d’abord la contrainte unique staff_member_id par un
flush interne ; l’affectation et le flush final restent dans la même
transaction. Sur exception après début des mutations, rollback puis clear de
l’EntityManager encore ouvert éliminent les écritures planifiées : les objets
doivent être rechargés avant une nouvelle tentative. Un refus de validation
ne vide pas l’EntityManager et laisse les objets d’origine intacts. L’archivage
et la création/suppression d’absence gardent leur unique flush Doctrine.
Les services sont découverts par la ressource App existante, sans alias nouveau.

`StaffManagementContractTest` appelle les vrais contrôleurs avec Request et
vérifie les réponses HTTP/JSON et les effets Doctrine dans une transaction de
fixture : horaires invalides, pause conservée, email absent/désactivé, rôle
invalide, dernier owner (dissociation/remplacement/rétrogradation, owner inactif
non compté), refus sans salissure des entités même après flush ultérieur,
création avec compte existant, remplacement/dissociation autorisés, échec de
création et échec au second flush d’un remplacement avec rollback des deux
entités, archivage, visibilité par rôle et cycle des absences. Les doubles
injectent seulement les erreurs de stockage et l’identité du lecteur.
`AdminApiPermissionContractTest` couvre POST/PUT/DELETE collaborateurs et
GET/POST/DELETE absences pour chaque rôle au niveau du subscriber réel.
Ces tests ne remplacent pas un parcours navigateur/firewall complet ni une
preuve de concurrence MySQL à deux processus. La suite business inclut le
nouveau fichier et conserve TeamPermissionsMatrixTest, TeamPermissionsTest
et WorkingHoursTest.

Contrôles réellement effectués dans cet environnement :

- `php -l` : succès sur les huit fichiers PHP ajoutés/modifiés.
- `git diff --check` : succès.
- `composer dump-autoload --optimize --strict-psr --no-scripts --no-plugins` :
  succès, 226 classes ; chargement effectif des quatre nouvelles classes : succès.
- Contrôle PHP autonome : rejet d’horaires invalides sans mutation du
  collaborateur, pause exclue par WorkingHours et Settings réservé à owner :
  succès. Ce contrôle limité ne valide ni Doctrine, ni PHPUnit, ni la DI.
- Installation verrouillée tentée avec `timeout 25 composer install
  --no-interaction --no-scripts --no-plugins --prefer-dist` : téléchargements
  en échec curl 6, DNS api.github.com indisponible ; dépendances non installées.
- `php vendor/bin/phpunit --configuration phpunit.business.xml --filter
  'StaffManagementContractTest|TeamPermissionsMatrixTest|TeamPermissionsTest|WorkingHoursTest|AdminApiPermissionContractTest'` :
  non exécutable, vendor/bin/phpunit absent. Aucun scénario PHPUnit annoncé réussi.
- `APP_ENV=test php bin/console lint:container` : non exécutable, Symfony Runtime
  absent. La compilation DI n’est pas validée par l’autoload.

Ces deux dernières commandes restent à exécuter après installation des
dépendances dans l’environnement MySQL jetable décrit plus haut. La fixture
modifie temporairement les rôles des comptes de cette base de test et annule
sa transaction ; elle ne doit jamais pointer vers une base de production.
Aucun accès production, envoi email/SMS, paiement ou déploiement réalisé.


## Livraison #104 — plannings et ressources réservables

Prérequis présents à la tête fournie : #97 à #103, dernier commit `6944430`.
Aucun changement de branche, commit, push, déploiement ou activation du ticket #105.

| Point d’entrée | Responsabilité extraite |
| --- | --- |
| `AdminPlanningApiController` | `Service/Planning/PlanningManagementService` : création/code, validation via PlanningInput, mise à jour, suppression, collaborateur et codes des prestations |
| `AdminBookableResourceApiController` | `Service/Resource/BookableResourceManagementService` : création/code, validation du calendrier, capacité/type, mise à jour, suppression ou désactivation si utilisée, lecture et affectation des ressources à un produit |
| Erreurs de validation | `InvalidPlanningInput` et `InvalidBookableResourceInput`, exceptions métier traduites en HTTP 422 par les contrôleurs |

Les contrôleurs gardent routes/méthodes/noms, parsing JSON, réponses 404,
projections explicites privées et statuts HTTP. Ces projections ne sont pas
partagées entre consommateurs ; aucun service de sérialisation supplémentaire.
Les entités, leurs invariants locaux (dont déduplication), les repositories,
PlanningInput et les calculs/verrous de disponibilité sont conservés.
Le comptage SQL historique des réservations reste identique et utilise la
connexion du même EntityManager tenant. Les écritures gardent leur unique
flush, sans ajouter de transaction explicite ou modifier les verrous existants.
Les payloads incomplets conservent leurs valeurs par défaut historiques ; le
code ne change pas lors d’une mise à jour. Les autorisations restent assurées
par les adaptateurs existants. Les deux services sont découverts par la
ressource DI `App` existante ; aucun alias ni migration ajouté.

Tests ajoutés/adaptés :

- `AdminPlanningApiContractTest` : routes nommées et verbes ; création, index,
  lecture, modification, suppression, doublon et 404 ; capacité, fuseau,
  collaborateur, calendrier vide/inversé invalides ; absence d’insertion ou de
  modification après un flush ultérieur ; jours historiques, alias jumpCodes,
  déduplication, portée staff et sélection active par prestation du repository.
- `BookableResourceContractTest` : remplacement des assertions sur le texte PHP
  par les attributs Route et appels réels du contrôleur ; CRUD, codes et 404,
  capacité/type/calendrier invalides ; affectation/déduplication des codes,
  ressource inconnue, obligation sans ressource compatible ; capacité effective
  via ResourceAvailability après mise à jour ; désactivation d’une ressource
  utilisée (comptage DBAL simulé, remove interdit et flush attendu).
  Le test du verrou transactionnel de capacité reste présent.
- Les fixtures Doctrine des nouveaux scénarios sont annulées par rollback et
  nécessitent la base MySQL jetable documentée plus haut. Aucun test ne doit
  être exécuté contre une base de production. Les assertions ne constituent
  pas un parcours complet du firewall ou une preuve de concurrence.
- La suite business inclut désormais AdminPlanningApiContractTest et
  PlanningRepositoryTest ; PlanningInputTest, les tests Resource et les suites
  de disponibilité existants restent inchangés.

Contrôles réellement effectués :

- `php -l` : succès sur les huit fichiers PHP ajoutés/modifiés.
- `git diff --check` : succès.
- `composer dump-autoload --optimize --strict-psr --no-scripts --no-plugins` :
  succès, 230 classes ; chargement des quatre nouvelles classes : succès.
- Contrôle PHP autonome limité : hydratation valide et refus de capacité nulle
  sans mutation de l’entité pour les deux services : succès. Ce contrôle par
  réflexion ne vérifie ni HTTP, ni Doctrine, ni le conteneur Symfony.
- `timeout 25 composer install --no-interaction --no-scripts --no-plugins
  --prefer-dist` : installation non aboutie, curl 6, résolution DNS de
  `api.github.com` impossible.
- `php vendor/bin/phpunit --configuration phpunit.business.xml --filter
  'AdminPlanningApiContractTest|PlanningInputTest|PlanningRepositoryTest|BookableResourceContractTest|BookableResourceTest|AvailabilityServiceTest|PlanningSlotPolicyTest'` :
  non exécutable, `vendor/bin/phpunit` absent ; aucun test PHPUnit annoncé réussi.
- `APP_ENV=test php bin/console lint:container` : non exécutable, Symfony Runtime
  absent ; la compilation DI reste à vérifier avec les dépendances installées.

Aucun accès production, envoi email/SMS ou paiement effectué. Les vérifications
PHPUnit et DI restent à exécuter dans l’environnement de test équipé.


## Livraison #105 — fiches clients et lectures de l’espace client

Prérequis présents : tête `a21fb5d` (#104), précédée des tickets #97 à #103.
Aucun changement de branche, commit, push, déploiement ou activation du suivant.

| Point d’entrée | Responsabilité extraite |
| --- | --- |
| `AdminClientApiController::index,findBookingForClient` | `Service/Customer/ClientDirectoryService` : rapprochement email, identifiant hash, historique, achats dédupliqués, statistiques, recherche et tri |
| `AdminClientApiController::update,normalizeProfile` | `Service/Customer/ClientProfileService` : validation, valeurs par défaut, consentements et persistance ; `InvalidClientProfile` traduit en 422 |
| `ShopCustomerAccountApiController::profile,bookings,orders,normalizeBooking` | `Service/Customer/CustomerAccountReadService` : projections explicites et lectures par repositories ; requête email dans `BookingRepository::findForCustomerEmail` |
| `ShopCustomerAccountApiController::ownedBooking,ownsInvoice` | `Service/Customer/CustomerAccountAccess` : résolution du token, propriété email, facture payée ; prédicat réutilisé par le détail legacy et `BookingMutation` avant et après verrou/relecture |

La clé `bookingEmail` reste stable lors de la modification de l’email affiché.
L’annuaire reste issu des réservations : un profil seul ne crée pas de fiche.
Les emails y sont trim/lowercase, contrairement à la propriété du compte qui
conserve strcasecmp sans trim. Les lectures du compte ne consultent pas les
profils admin et n’exposent pas leurs notes internes ou données médicales.
Les identifiants admin (hash email), compte (token public), les champs JSON,
montants en centimes des réservations et conversion /100 des commandes restent
identiques. Les statistiques newThisMonth/withUpcoming/recurring sont calculées
avant recherche, seul total suit le filtre. Les achats gardent la déduplication
par numéro de commande et le remplacement dans l’ordre croissant des créneaux.

Les repositories et l’EntityManager tenant existants sont réutilisés ; aucune
connexion globale, aucun mapping ni migration. La mise à jour du profil garde
un seul flush, sans nouvelle transaction. Les frontières de transaction et
verrous des mutations de réservation restent identiques. Les services sont
chargés par la ressource DI App existante. Les contrôleurs conservent parsing,
routes, autorisations et traduction HTTP. Les corps RGPD et génération/réponse
PDF sont conservés ; seule leur résolution de client/propriété est déléguée.

Tests de comportement ajoutés/adaptés :

- `AdminClientApiContractTest` : regroupement casse/espaces, profil absent,
  historique trié, achats dédupliqués, calculs avec annulation, statistiques
  avant filtre, recherche tags/email, tri prochains rendez-vous puis noms,
  email modifié avec id inchangé, consentement rejoué sans doublon, refus 422
  sans mutation et 404, profil sans réservation absent de l’annuaire.
- `ClientProfileTest` : email affiché modifiable sans modification de la clé.
- `ShopCustomerAccountSecurityContractTest` : liste et détail cohérents,
  projection exacte des réservations, autre email/espaces exclus, 404 avant
  détail/annulation/déplacement, route legacy, profil absent et listes vides,
  commandes lues par identité Customer et ordre décroissant, types direct/gift,
  factures limitées à l’email du compte et au statut payé.
- `InvoiceSecurityContractTest` : l’ancienne assertion de propriété dans le
  texte PHP est remplacée par la couverture comportementale ci-dessus ; la
  route PDF est vérifiée via ses attributs. Aucun changement du générateur PDF.
- Les trois suites demandées sont ajoutées à phpunit.business.xml. Les fixtures
  Doctrine sont annulées par rollback et exigent la base de test jetable décrite
  plus haut. Les doubles des commandes/factures vérifient lecture/propriété ;
  ils ne constituent pas un parcours complet firewall/PDF ou une preuve tenant
  multi-connexion. Les tests de mutations existants restent dans la sélection.

Contrôles réellement effectués :

- Syntaxe PHP des fichiers ajoutés/modifiés et `git diff --check` : succès.
- `composer dump-autoload --optimize --strict-psr --no-scripts --no-plugins` :
  succès, 235 classes ; chargement effectif des cinq nouvelles classes : succès.
- Contrôle PHP autonome limité, sans Doctrine/HTTP : projection du profil,
  clé stable après changement d’email, tags, trace du consentement et prédicat
  de propriété (casse acceptée, autre email/espaces refusés) : succès.
- `timeout 25 composer install --no-interaction --no-scripts --no-plugins
  --prefer-dist` : installation non aboutie, curl 6, DNS api.github.com
  indisponible, arrêt à la borne (124).
- `php vendor/bin/phpunit --configuration phpunit.business.xml --filter
  'AdminClientApiContractTest|ClientProfileTest|ShopCustomerAccountSecurityContractTest|CustomerBookingChangesContractTest|InvoiceSecurityContractTest'` :
  non exécutable, vendor/bin/phpunit absent. Aucun scénario PHPUnit validé.
- `APP_ENV=test php bin/console lint:container` : non exécutable, Symfony Runtime
  absent ; l’autoload ne valide pas la DI. Les deux dernières commandes restent
  à exécuter avec les dépendances et la base jetable disponibles.

Aucun accès production, envoi email/SMS, paiement ou déploiement réalisé.


## Livraison #106 — paiements Stripe et traitement du webhook

Prérequis présents : tête `883c41f` (#105), précédée des tickets #97 à #104.
Aucun changement de branche, commit, push, déploiement ou activation du suivant.

| Point d’entrée | Responsabilité extraite |
| --- | --- |
| `ShopStripePaymentController::session,cancel,payment,returnUrl` | `Service/Payment/StripePaymentService` : résolution commande/réservation/paiement, contrôle des montants, hôte autorisé des retours, création de session via StripeCheckout, annulation |
| `ShopStripePaymentController::webhook` | `Service/Payment/StripeWebhookProcessor` : configuration tenant, signature, revendication unique de StripeWebhookEvent, rapprochement metadata, transitions, transaction, métriques et email après commit |
| `ShopPaymentTermsController` | `Service/Payment/OrderPaymentTermsService` : sélection de prestation, calcul via ServicePaymentTerms, ajustement verrouillé, montant des paiements et rejeu |

Les contrôleurs conservent les attributs Route, parsing JSON, extraction du corps
brut et de Stripe-Signature, hôte HTTP et traduction des erreurs. PaymentNotFound,
StripeSessionUnavailable et RejectedStripeWebhook expriment les erreurs du domaine ;
leurs statuts HTTP sont choisis uniquement dans le contrôleur. Les erreurs fournisseur
restent converties en 502 uniquement autour de la création de session.
Les trois services sont découverts par la ressource DI App existante.
StripeCheckout et ServicePaymentTerms, déjà déplacés en #98, sont réutilisés sans
modification. Aucune entité, migration, connexion, autorisation ou route ajoutée.

Invariants conservés : centimes et arrondi de l’acompte, devise de commande en
minuscules chez Stripe, clé fournisseur `todatempo-order-{number}`, comparaison
insensible à la casse de l’hôte de retour, montant identique entre commande,
paiement et réservation avant création. Les metadata et payloads restent identiques.
Le webhook revendique l’event_id unique avant toute transition ; le rejeu annule
la transaction et retourne received/replayed sans notification. La confirmation
nécessite checkout.session.completed signé avec payment_status=paid et part après
commit. L’annulation HTTP d’une réservation déjà payée reste sans effet.

Cette extraction conserve aussi les limites historiques : les contrôles de montant
sont à la création de session ; le webhook rapproche les metadata sans nouvelle
validation du montant Stripe. StripeCheckout met à jour la réservation même lorsque
le workflow refuse une transition ; un événement d’expiration/échec tardif peut donc
modifier une réservation payée. Les scénarios hors ordre caractérisent ce résultat,
sans introduire une nouvelle politique de transitions. La déduplication existante
porte sur event_id, pas sur des événements distincts pour le même paiement.
Les périmètres try/catch et transactions sont conservés, y compris le rollback sur
échec du flush final/commit. Aucun verrou supplémentaire n’a été ajouté.

Tests de comportement ajoutés/adaptés :

- StripePaymentContractTest : appels HTTP directs aux nouveaux services, vrai HMAC
  Stripe, sept scénarios existants conservés ; livraison réussie suivie du même
  événement (une transition et un email), événements hors ordre avec workflow
  refusant la transition, échec de commit avec rollback et absence d’email.
- StripePaymentServiceTest : montants incohérents, paiement non associé, URL étrangère,
  relative ou schéma interdit ; création répétée avec centimes/devise/URLs/clé
  d’idempotence inchangés, état pending jusqu’au webhook ; annulation pending/paid,
  erreurs 404 et fournisseur 502. Le transport HTTP Stripe est remplacé par un double,
  jamais un appel réel ; le client statique est restauré en finally.
- OrderPaymentTermsServiceTest : acompte fixe/plafonné/pourcentage, full/none,
  ajustement verrouillé unique après rejeu, montant des paiements et payload,
  commande finalisée, absence ou multiplicité de prestation et règle invalide
  sans mutation. ServicePaymentTermsTest conserve les cas de calcul purs.
- StripeWebhookIdempotencyTest reste la preuve de contrainte unique MySQL existante.
  Les doubles du contrat webhook ne prétendent pas prouver la concurrence réelle.
- L’assertion textuelle Stripe de TransactionalEmailContractTest est remplacée par
  la couverture comportementale de StripePaymentContractTest (email après commit,
  absence sur rejeu/échec). Les deux nouvelles suites sont incluses dans business.

Contrôles réellement effectués :

- Syntaxe PHP des services, contrôleurs et tests concernés : succès.
- `git diff --check` : succès.
- `composer dump-autoload --optimize --strict-psr --no-scripts --no-plugins` :
  succès, 241 classes ; chargement effectif des six nouvelles classes : succès.
- Contrôle PHP autonome des calculs : cinq cas valides en centimes et cinq refus
  invalides/négatifs réussis. Ce contrôle ne valide ni HTTP, ni Doctrine, ni Stripe.
- Installation Composer tentée avec limite de 25 secondes, sans scripts/plugins :
  non aboutie, curl 6, résolution DNS de api.github.com impossible.
- `php vendor/bin/phpunit --configuration phpunit.xml.dist --filter
  'StripeWebhookIdempotencyTest|StripePaymentContractTest|StripePaymentServiceTest|ServicePaymentTermsTest|OrderPaymentTermsServiceTest'` :
  non exécutable, vendor/bin/phpunit absent. Aucun test PHPUnit annoncé réussi.
- `APP_ENV=test php bin/console lint:container` : non exécutable, Symfony Runtime
  absent. L’autoload ne valide pas la compilation du conteneur.

Les vérifications PHPUnit et DI restent à exécuter avec les dépendances installées ;
le test d’intégration exige la base MySQL jetable et le registre tenant isolé décrits
plus haut. Aucun accès production, envoi email/SMS ou paiement effectué.


## Livraison #107 — remboursement et projection

Prérequis présents : tête `ca56c24` (#106), précédée de #97 à #105.
Aucun changement de branche, commit, push ou activation de #108.

- `Controller/AdminRefundApiController` conserve routes, parsing/validation de
  forme (422), acteur authentifié, traduction des conflits (409), échecs (502),
  création (201) et rejeu (200).
- `Service/Payment/RefundService` porte lectures, double contrôle d’idempotence,
  transaction, verrou pessimiste puis refresh, solde, appel RefundProvider,
  transitions Sylius et synchronisation commande/réservation, historique et avoir.
- `Service/Payment/RefundView` remplace RefundOperation::normalize ; les getters
  exposent uniquement les valeurs nécessaires. Mapping, invariants locaux et
  transitions de RefundOperation/Payment restent inchangés, sans migration.
- `RefundFailed` identifie les erreurs techniques interceptées dans la transaction.
  Le contrôleur choisit le statut HTTP. Les services utilisent la découverte App
  et l’alias RefundProvider existants ; aucune connexion ou portée tenant ajoutée.
  Le subscriber de permission Finances reste l’adaptateur HTTP.

### Comportement historique conservé et limites du rejeu

La clé est globale dans la base du tenant. Une clé d’un autre paiement donne 409
avant transaction. Une opération déjà completed du même paiement donne 200 avant
le contrôle du montant : un montant différent rejoue donc aussi la réponse initiale.
Sous verrou, une clé d’un autre montant/paiement donne 409 ; une opération devenue
completed entre les lectures est rejouée après commit, sans appel fournisseur.
Les demandes pending/failed du même montant réutilisent l’opération et la clé
fournisseur ; son acteur et son motif initiaux restent inchangés, tandis que
l’historique de réservation utilise l’acteur de la demande courante.

Un seul appel fournisseur par tentative : aucun retry automatique. Une DomainException
du fournisseur reste un 409 ; toute autre erreur dans la transaction reste un 502
avec le texte JSON historique. Le rollback porte sur la base, pas sur les objets PHP.
Si le fournisseur a réussi mais que le flush final/commit échoue, le remboursement
distant n’est pas annulé et l’opération locale peut être absente. Le texte historique
du 502 ne prouve donc pas l’absence d’effet distant. Une nouvelle requête explicite
avec la même clé rappelle le fournisseur, auquel ConfiguredRefundProvider transmet
la même idempotency_key Stripe ; aucune nouvelle clé ni compensation n’est générée.
Cette extraction ne garantit pas une atomicité distribuée. Après échec, les objets
managés peuvent être modifiés et ne constituent pas une preuve de persistance ;
un rejeu doit repartir d’un contexte Doctrine/requête frais.

### Couverture et vérification

Tests de comportement avec faux fournisseur : partiel puis intégral, workflow,
acteur/motif/avoir, completed avec montant différent, pending incompatible et
compatible, rejeu observé après verrou, échec distant et échec de flush après succès
distant (un seul appel, rollback, absence de commit, objets PHP encore modifiés).
Les tests HTTP directs assemblent contrôleur et service réels : JSON complet et
types, liste/solde, en-tête prioritaire et clé du payload, 422, dépassement 409,
autre paiement 409, fournisseur 409/502 et rejeu 200 sans nouvel appel.
AdminApiPermissionContractTest couvre GET/POST refunds interdits et POST autorisé.
L’ancien test basé sur les chaînes du contrôleur est supprimé. La suite service
est ajoutée à phpunit.business.xml.

Ces tests utilisent des doubles Doctrine et des Request/JsonResponse réels :
ils ne prouvent pas la concurrence InnoDB, le routage/firewall complet ou
l’isolation entre plusieurs connexions tenant. Aucun fournisseur réel n’est appelé.

Contrôles exécutés : syntaxe PHP des fichiers concernés et git diff --check ;
autoload Composer optimisé strict PSR sans scripts/plugins (244 classes).
PHPUnit ciblé non exécutable : vendor/bin/phpunit absent.
Compilation DI non exécutable : Symfony Runtime absent. L’autoload ne valide pas
la DI. Ces contrôles doivent être repris dans l’environnement équipé, ainsi que
les tests d’intégration tenant existants sur base jetable. Aucun accès production,
paiement réel, email/SMS ou déploiement.


Commandes de vérification #107 :
- `composer dump-autoload --working-dir=backend --optimize --strict-psr --no-scripts --no-plugins` : succès ; chargement effectif de RefundService, RefundView et RefundFailed : succès.
- `php backend/vendor/bin/phpunit --configuration backend/phpunit.business.xml --filter 'Refund|AdminApiPermissionContractTest'` : non exécuté (binaire absent).
- `APP_ENV=test php backend/bin/console lint:container` : échec de démarrage (Runtime absent).
- `timeout 25 composer install --working-dir=backend --no-interaction --no-scripts --no-plugins --prefer-dist` : non abouti ; curl 6, api.github.com non résolu.


## Livraison #108 — parcours et listeners chèques cadeaux

Prérequis présents : #97 à #107 dans l’historique ; travail sur la tête fournie
`3ccdaf4`, qui conserve #107 (`58f400d`). Aucun changement de branche, commit,
push, activation du ticket suivant ou déploiement.

- `GiftVoucherAccess` porte lookup code/commande/email, comparaison code-email
  insensible à la casse, profil bénéficiaire et filtrage admin par statut effectif.
- `GiftVoucherView` conserve les projections distinctes shop/admin et la lecture
  de la réservation utilisée. Aucun champ ni alias jumpType supprimé ou ajouté.
- `GiftOrderMarking` sélectionne uniquement via `findCartByTokenValue`, réutilise
  `GiftOrderMarker` et flush au même moment. Le contrôleur conserve parsing et
  validation email ; le 404 panier reste prioritaire au 422 payload invalide.
- `GiftVoucherCreator::createFromMarkedOrder` décode le marqueur et réutilise la
  création, Config et génération de codes existantes. Le listener Doctrine garde
  postPersist/postUpdate et la détection du changeset checkoutState completed.
- `GiftVoucherActivator::activateFromPayment` reprend la sélection de la commande,
  du marqueur, du voucher et du canal. Le listener garde l’événement
  `workflow.sylius_payment.completed.complete` et le contrôle de type du sujet.
  La précondition de cet événement (Payment completed) est explicite dans le
  service : un appel avec paiement non complété n’active rien. Le passage active,
  timestamp, flush puis Mailer restent inchangés ; le rejeu n’envoie rien.

Les routes, méthodes, contraintes de code à dix chiffres, erreurs, statut HTTP,
headers PNG/cache et URL `/{tenant}/beneficiary/login?code=...` sont conservés.
La connexion code/email accepte encore les chèques expirés, utilisés ou en attente.
Les lectures publiques par code, numéro de commande et email restent accessibles
selon le contrat existant : elles ne deviennent pas authentifiées par le login.
Rate limiting et autorisations admin restent dans leurs adaptateurs actuels.
Repositories et connexion tenant inchangés ; les nouveaux services utilisent la
découverte/autowiring App existante, sans nouvelle connexion ni configuration.
La consommation reste exclusivement dans `BookingCreationService::createFromVoucher`
(#101 / ticket 05), via ShopBookingApiController : propriété, validation,
transaction, refresh sous verrou, rollback et emails après commit inchangés.
Aucun nouveau parcours de réservation, migration ou effet production.

### Tests et contrôles #108

Ajouts comportementaux à phpunit.business.xml :
- `GiftVoucherAccessContractTest` : contrôleurs/services/repositories réels sur la
  fixture transactionnelle isolée ; login expiré/utilisé/non payé, code/email
  erronés, lookup 404, payloads shop/admin complets, projection réservation,
  filtre/statistiques, URL et PNG/cache, création marquée et rejeu, activation
  répétée, paiement sans commande/canal/marqueur ou non complété. Sender mocké :
  aucun email réel. Les tests QR exigent Endroid et GD.
- `GiftOrderMarkingTest` : Request/JsonResponse et service réels, repository/EM
  doublés ; sélection par token, priorité du 404, refus email sans mutation ni
  flush, encodage et normalisation du marqueur, un seul flush en succès.

Contrôles exécutés : syntaxe PHP des 20 fichiers cadeaux (services, contrôleurs,
listeners, tests) ; `git diff --check` ; autoload optimisé strict PSR sans
scripts/plugins (247 classes), chargement des cinq services ajoutés/modifiés.

Contrôles non exécutables pour motif environnemental :
- `php backend/vendor/bin/phpunit --configuration backend/phpunit.business.xml
  --filter 'GiftVoucher|GiftOrderMarking|SecurityHardeningContractTest|AdminApiPermissionContractTest'`
  échoue avant exécution : vendor/bin/phpunit absent. Cela inclut GiftVoucherTest,
  GiftVoucherRedemptionContractTest et GiftVoucherConcurrentRedemptionTest ;
  aucun test PHPUnit annoncé réussi.
- `APP_ENV=test php backend/bin/console lint:container` échoue au démarrage :
  Symfony Runtime absent. L’autoload ne prouve pas la compilation DI.
- Installation des dépendances verrouillées tentée avec `timeout 25 composer
  install --working-dir=backend --no-interaction --no-scripts --no-plugins
  --prefer-dist` : non aboutie, téléchargement impossible (DNS api.github.com,
  curl 6). Aucun changement au lock.

Les tests avec kernel et la concurrence doivent être exécutés sur l’instance
MySQL jetable avec registre tenant isolé décrite plus haut. Aucune commande de
préparation destructive ni test connecté à une base de production n’a été lancé.


## Livraison #109 — commerce physique et règles de remise

Prérequis #97 à #108 présents dans l’historique, tête fournie `8b12d99`.
Aucune opération Git de branche/commit/push, activation de #110 ou déploiement.

- `PhysicalCheckoutService` reprend la transaction configure : début, lookup
  par token, refus completed, verrou pessimiste commande puis variantes dans
  l’ordre du panier, validations, flush et commit ; rollback puis propagation
  des exceptions. Aucun changement au workflow checkout, aucune réservation ou
  décrémentation directe de stock. Le stock disponible reste onHand - onHold,
  et une variante non suivie reste refusée.
- Le panier physique doit être homogène et chaque produit doit autoriser le
  mode choisi. La livraison exige toujours une adresse non nulle (aucune
  nouvelle validation des champs). Les frais restent le maximum des frais
  produits, jamais la somme. Les ajustements todatempo_delivery sont remplacés
  à chaque configuration, supprimés au retrait ; les autres sont conservés.
- `PhysicalProductManagementService` conserve le lookup code et les mutations
  du type, modes, frais et de toutes les variantes (shippingRequired, tracked,
  stock optionnel borné à zéro), avec un seul flush sans nouvelle transaction.
- `PhysicalPreparationService` conserve lookup token, exigence d’un état de
  préparation non nul, les quatre états autorisés et le flush. Aucune nouvelle
  restriction de transition ni de paiement n’est ajoutée.
- `PhysicalProductReadService` conserve la sélection physical/enabled et la
  projection publique, première variante et premier prix de canal compris.
- Les contrôleurs gardent routes, parsing JSON et traduction HTTP (422/404/409).
  `InvalidPhysicalCommerce` distingue les refus admin des erreurs techniques de
  persistence, qui continuent à se propager. Les invariants locaux et constantes
  restent dans Product/Order ; aucune modification Entity ni migration.

Les repositories et l’EntityManager tenant existants sont réutilisés : aucune
nouvelle connexion ou modification des adaptateurs d’autorisation. La découverte
App/autowiring existante enregistre les nouveaux services sans configuration dédiée.

### Vérification #109

Tests inscrits dans phpunit.business.xml : PhysicalCheckoutContractTest remplacé
par des appels de contrôleurs/services réels avec Request/JsonResponse ; tests
PhysicalCheckoutServiceTest et PhysicalManagementServiceTest ajoutés ;
PhysicalProductTest complété pour les frais négatifs. Couverture : configure
répété sans cumul, montants et autres ajustements préservés, ordre des verrous,
retrait sans adresse, livraison gratuite, stock insuffisant/non suivi, panier
absent/finalisé/vide/mixte, variante invalide, mode indisponible, adresse absente,
rollback sur refus et échec flush, variantes multiples et stock optionnel,
préparation répétée et refus, priorité 404, erreurs HTTP et catalogue public.

Ces tests utilisent des doubles Doctrine et des entités Sylius réelles. Ils
vérifient commit/rollback et absence de flush sur refus ; ils ne prouvent ni
rollback SQL, ni concurrence InnoDB, ni routage/firewall complet ou isolation
multi-tenant réelle. Comme avant, un rollback ne restaure pas les objets PHP.

Contrôles exécutés avec succès :
- `php -l` sur les 11 fichiers PHP concernés ; `git diff --check`.
- `composer dump-autoload --working-dir=backend --optimize --strict-psr --no-scripts --no-plugins` : 252 classes.
- Chargement effectif des quatre services Commerce et de leur exception via
  `backend/vendor/autoload.php`.

Contrôles non exécutables dans cet environnement :
- `php backend/vendor/bin/phpunit --configuration backend/phpunit.business.xml --filter 'Physical|AdminApiPermissionContractTest'` : binaire absent,
  aucun test PHPUnit annoncé réussi.
- `APP_ENV=test php backend/bin/console lint:container` : Symfony Runtime absent,
  compilation DI non validée par l’autoload.
- `timeout 25 composer install --working-dir=backend --no-interaction --no-scripts --no-plugins --prefer-dist` : installation non aboutie, curl 6,
  résolution api.github.com impossible. Aucun changement au lock.

Reprendre PHPUnit et lint:container dans l’environnement équipé, puis les
contrôles transactionnels et tenant sur base jetable isolée. Aucun test avec
base de production, paiement, email/SMS réel ou déploiement n’a été effectué.


## Livraison #110 — rappels, notifications et liste d’attente

Prérequis #97 à #109 présents dans l’historique ; tête fournie `78d7bc4`.
Aucune opération Git de branche/commit/push, activation de #111 ou déploiement.

- `ReminderScheduler` reprend exactement la sélection par canal/délai, le calcul
  dans le fuseau du centre puis UTC, la fenêtre minimale d’une minute, la
  déduplication, persist/flush et publication. La commande ne garde que les
  options, le code retour et le texte CLI. Nom `todatempo:reminders:schedule`,
  option `--window` (défaut 10), configuration et cron inchangés.
- `BookingReminderSender`, extrait en #99, devient `ReminderSender` sans
  modifier son algorithme. Le handler conserve son attribut Messenger et le
  message sérialisé `App\Reminder\Message\SendBookingReminder` avec deliveryId.
  Le dispatcher email et les fournisseurs SMS sont réutilisés. Les erreurs
  marquent la tentative puis remontent à Messenger ; fournisseur désactivé,
  absence de consentement/téléphone et réservation annulée restent skipped.
- `WaitlistManagement` porte disponibilité du produit, création/persistance,
  liste triée et désinscription publique/admin. Les contrôleurs gardent routes,
  parsing, validation de forme/consentement, traduction 404/422 et projections.
  Le notifier existant reste directement appelé par l’adaptateur admin.
- Entités, repositories, contraintes uniques, transactions, connexion tenant,
  autorisations, transports/retries et liens ne changent pas. Les emails et
  notifications d’annulation restent après le commit de BookingMutation dans
  BookingLifecycle. Aucun envoi n’est déplacé vers une transaction.

### Portée de l’idempotence conservée

Les rejeux séquentiels ne republient pas une livraison déjà enregistrée et ne
renvoient pas un rappel sent/skipped. Une erreur fournisseur est retentable ;
WaitlistNotifier retire la notification en échec avant de propager l’erreur.
Les clés uniques et la gestion du conflit concurrent Waitlist restent intactes.
Cette extraction n’ajoute ni verrou distribué ni outbox : elle ne corrige pas
les fenêtres historiques entre flush/publication ou succès distant/flush local.
Un crash à ces frontières ne bénéficie pas d’une garantie exactly-once.

### Tests et contrôles #110

Tests de comportement ajoutés/adaptés et inscrits dans phpunit.business.xml :
- ReminderSchedulerTest : réservation confirmée/annulée, configuration avec
  délai dupliqué, livraison persistée avant publication, second passage sans
  message supplémentaire, nom CLI, option et sortie.
- ReminderSenderTest (ancien BookingReminderSenderTest) : message historique
  sérialisé, email/SMS répété, fournisseur désactivé, erreur répétée et reprise
  réussie, consentement absent, téléphone absent, annulation.
- WaitlistContractTest : consentement littéral, période/email invalides, produit
  absent/désactivé, normalisation, payloads 201 et liste admin, token inconnu et
  désinscription répétée publique/admin. L’assertion de migration unique reste.
- WaitlistNotifierTest : fournisseur intercepté, suppression après erreur,
  reprise puis rejeu sans double envoi, lien de désinscription distinct par
  inscription, tenant courant dans les URLs, exclusion après désinscription.
- TransactionalEmailContractTest conserve les cinq appels réels au dispatcher
  avec tenant/URL et ajoute le canal absent sans envoi. L’ancienne assertion
  textuelle des confirmations shop est remplacée ; leurs appels après commit
  sont déjà exercés par GiftVoucherRedemptionContractTest et les contrats booking.
- AdminApiPermissionContractTest couvre aussi POST notify/unsubscribe pour les
  rôles existants. WaitlistRequestTest et ReminderMessengerTest conservés ;
  CustomerBookingChangesContractTest conserve la preuve de l’ordre commit/envoi.

Les nouveaux tests avec Doctrine utilisent AvailabilityTestCase et sa transaction
rollbackée : exécution uniquement sur MySQL jetable avec registre tenant isolé,
selon les instructions plus haut. Sender et bus sont interceptés ; aucune
connexion de test à la production ni email/SMS réel n’a été déclenché.

Contrôles réellement exécutés :
- Syntaxe PHP des fichiers concernés ; git diff --check : succès.
- `composer dump-autoload --working-dir=backend --optimize --strict-psr --no-scripts --no-plugins` :
  succès, 254 classes. Chargement effectif des trois services extraits : succès.

Contrôles non exécutables pour motif environnemental :
- `php backend/vendor/bin/phpunit --configuration backend/phpunit.business.xml --filter 'Reminder|TransactionalEmailContractTest|Waitlist|CustomerBookingChangesContractTest|AdminApiPermissionContractTest'` :
  binaire absent, aucun test PHPUnit exécuté ni annoncé réussi.
- `APP_ENV=test php backend/bin/console lint:container` : Symfony Runtime absent,
  compilation DI non vérifiée. L’autoload ne remplace pas ce contrôle.
- `timeout 25 composer install --working-dir=backend --no-interaction --no-scripts --no-plugins --prefer-dist` :
  installation non aboutie (curl 6/7, api.github.com inaccessible).
  Aucun changement de composer.lock.

Reprendre ces suites et la compilation DI dans l’environnement équipé et isolé.
