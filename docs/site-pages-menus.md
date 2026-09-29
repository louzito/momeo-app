# Pages et menus — socle #126

Prérequis #125 vérifié dans `docs/admin-navigation.md`, les routes et la navigation
BO. Les réglages historiques, leurs documents dans les taxons et toutes les
routes publiques restent en place. Le BO ajoute seulement la rubrique Pages.
Aucune migration du contenu historique ni bascule du rendu public.

## Données et isolation

La migration `Version20260928120000` ajoute trois tables dans chaque base tenant :
`SitePage`, `SiteMenu`, `SiteMenuItem`. Elle ne modifie ni ne supprime les tables
existantes. Son retour arrière est volontairement irréversible pour conserver
les documents. Elle doit suivre le mécanisme habituel de migrations tenant,
avec `TODATEMPO_TENANT` explicite, pour chaque établissement. Aucune migration
n’est exécutée sur les bases hébergées par ce ticket.

Les requêtes utilisent la connexion tenant Doctrine existante. Les nouvelles
API refusent le repli sur l’établissement par défaut : en-tête tenant validé ou
domaine vérifié requis. Les JWT restent liés au tenant ; toutes les API admin
`site` exigent la permission `settings` (propriétaire). Les réponses publiques
et admin portent `private, no-store` : aucun cache de contenu partagé.

Les pages utilisent des identifiants aléatoires et des slugs uniques, conservés
même après archivage. Les premières composantes des routes métier et des URLs
historiques sont réservées. Une page publiée conserve son adresse ; le titre
reste modifiable. Les rôles optionnels `home`, `terms`, `mentions` sont uniques,
immuables et interdisent archivage et changement d’adresse. Une copie perd le
rôle et la publication. Le contenu publié est un instantané séparé incluant titre,
slug, document et SEO. Restaurer remplace seulement le brouillon.

## Contrat JSON v1

Un brouillon complet contient `title`, `slug`, `document`, `seo`.
`seo` contient exactement `title` et `description`.
`document` contient `schemaVersion: 1` et une liste `blocks`.
Chaque bloc contient `id` unique, `type` et `props` :

- `heading` : `text`, `level` (2 ou 3).
- `text` : `content`, document Tiptap `doc` avec paragraphes, nœuds texte et
  marques `bold` / `italic` uniquement. Aucun attribut arbitraire.
- `button` : `label`, `link`.

Les autres versions, types et propriétés sont refusés. Le ticket #128 étend
ce contrat aux images, bannières et galeries : voir `docs/site-media.md`.
Les futures sections et médias doivent étendre explicitement ce contrat et
valider leurs références dans le tenant courant. Aucun paquet Tiptap n’est
nécessaire pour le CRUD de cette étape ; son éditeur Vue sera ajouté avec les
sections, après vérification de compatibilité. Aucune chaîne HTML n’est acceptée.
Les textes doivent toujours être rendus par interpolation échappée.

Un lien contient exactement `type` et `target` : `page` (ID), `route` (clé de
la liste `SiteDocumentValidator::ROUTES`), `external` (URL HTTP(S) sans
identifiants, espaces ou caractères dangereux). Les chemins internes résolus
sont relatifs à la base frontend du tenant ; ils ne doivent pas être interprétés
comme une URL à la racine de l’hôte. Les formulaires métier ne sont pas éditables.

Les menus `main` / `footer` contiennent une liste ordonnée `items`, chaque entrée
ayant `label`, `link`, et éventuellement `children`. Les enfants ne peuvent pas
avoir d’enfants. Les entrées principales sont des `SiteMenuItem`, avec leur
position et un document validé qui contient au plus un niveau d’enfants.

## API et publication future

Base admin `/api/v2/admin/site` :

- `GET/POST /pages` ; création avec `title`, `slug`, `role` optionnel.
- `GET/PUT/DELETE /pages/{id}` ; PUT reçoit le brouillon complet, DELETE archive.
- `POST /pages/{id}/duplicate` avec `title`, `slug`.
- `POST /pages/{id}/restore` restaure la dernière publication dans le brouillon.
- `GET/PUT/DELETE /menus/{main|footer}` ; PUT remplace le brouillon avec `{items}`,
  DELETE retire le menu, y compris sa publication.

Les refus de validation donnent 422, une ressource absente 404, les conflits de
unicité/concurrence détectés par Doctrine 409. Le frontend garde le formulaire
et ses valeurs en cas d’échec. Les pages système sont protégées côté serveur.

Base publique `/api/v2/shop/site` : `GET /pages/{slug}` et
`GET /menus/{main|footer}`. Jamais de brouillon, de restauration ni de publication
par HTTP. Un document non publié ou une page archivée répond 404. Le menu public
résout les références vers les adresses publiées et omet les pages indisponibles.

`SiteManagementService::publish()` et `publishMenu()` constituent le contrat
transactionnel des prochaines étapes : revalidation du document, des références
et des slugs puis instantané publié. Les pages liées doivent déjà être publiées.
Aucun moteur de workflow et aucune route de publication ne sont ajoutés.
La dernière publication demeure restaurable après toute modification du brouillon.

## Vérifications

Les tests `backend/tests/Site` exercent les entités via Doctrine et des bases
SQLite distinctes : séparation des tenants, absence de brouillons publics,
publication/restauration, conservation après rejet, duplication, slugs, pages
protégées et menus. La matrice de permissions et les tests JWT existants sont
étendus ou réexécutés. Le schéma ORM des trois entités est vérifié.

Le scénario Playwright `admin-site-pages.spec.js` couvre le CRUD sur mobile,
le focus clavier et la conservation de la saisie après une erreur. Le scénario
de navigation existant tient compte de la nouvelle entrée Pages.

Résultats dans l’environnement du ticket : suite ciblée backend, tests frontend,
build Vite, syntaxe PHP et mappings ORM réussis. Dépendances utilisées depuis
les caches locaux, avec les versions Vue/Vite correspondant au lockfile ; aucun
ajout de dépendance. Le scénario Playwright n’a pas pu être exécuté (paquet
Playwright absent du cache, DNS indisponible). Le contrôle du conteneur Symfony
n’a pas pu démarrer : installation Composer partielle, archives locales
corrompues et runtime non généré. Ces contrôles ne sont pas considérés validés.
La migration SQL MySQL est livrée mais n’a pas été exécutée sur un serveur tenant.

## Éditeur des menus — #127

Prérequis #126 vérifié dans ce document, les entités, les API, le CRUD Vue et
les tests présents sur la branche fournie. Aucun changement de branche.

L’entrée **Mon site internet → Menus** exige `settings`, comme les API existantes.
Les menus principal et pied de page ont des brouillons indépendants. L’éditeur
permet les pages par ID, prestations, boutique, carte cadeau et URLs HTTP(S),
le libellé, le masquage, la suppression, un niveau enfant et l’ordre par poignée
à glisser ou boutons clavier. Les boutons permettent également de transformer
une entrée en enfant du lien précédent et de la sortir de son sous-menu.
Les erreurs conservent la saisie ; un changement de menu conserve les deux
brouillons locaux. Le départ de l’écran signale les modifications non sauvées.

Le contrat d’entrée accepte désormais `hidden` (booléen, optionnel). Un parent
masqué masque aussi ses enfants. Le menu principal accepte `primaryLink`, un
lien validé ou `null` pour masquer « Prendre rendez-vous ». Le pied de page ne
peut pas définir ce bouton. `booking` mène au choix des prestations dans la
boutique, jamais au calendrier global. Les comptes et paniers restent gérés par
leurs composants applicatifs.

La migration tenant `Version20260928130000` ajoute les destinations brouillon
et publiée du bouton et les anciennes adresses des pages. Elle doit être appliquée
par le mécanisme habituel de migrations tenant, sans exécution sur les bases
hébergées dans ce ticket. Les pages libres publiées peuvent changer de slug :
les liens de menus continuent d’utiliser l’ID et résolvent l’adresse publiée.
L’ancienne adresse reste consultable via l’API publique et réservée à cette page,
même après archivage. Les adresses des pages système restent protégées.

`publishBatch(pageIds, locations)` valide les références de toutes les pages et
menus sélectionnés, puis écrit les instantanés dans une transaction. Une page
liée doit être publiée ou incluse dans le même lot. Un échec laisse toutes les
publications précédentes intactes. Le bouton principal est soumis aux mêmes
règles. Les entrées masquées ne bloquent pas la publication. Les références
archivées ou supprimées disparaissent du rendu public et empêchent une nouvelle
publication tant qu’elles ne sont pas retirées, remplacées ou masquées (bouton :
retiré ou remplacé). L’éditeur avertit des pages indisponibles ou à publier.
`DELETE /menus/{location}` vide désormais uniquement le brouillon.

`GET /api/v2/shop/site/navigation` fournit `main`, `footer`, `primary`. Tant que
la page portant le rôle `home` n’a pas d’instantané publié, les trois valeurs
sont `null`. Cela constitue le signal de bascule à réutiliser à l’étape de
publication du site. Un menu jamais publié vaut également `null` (fallback) ;
un menu publié vide vaut `[]` (intentionnellement vide). AppNavbar et AppFooter
consomment cette réponse, sans cache partagé. Desktop et mobile utilisent le
même composant hiérarchique. Vue Router préfixe les liens internes par la base
du tenant courant. Une erreur de chargement conserve la navigation historique.
La publication HTTP et son écran restent réservés à l’étape dédiée de la série ;
l’éditeur de menus enregistre exclusivement des brouillons.

Vérifications #127 : 237 tests backend ciblés / 351 assertions réussis (quatre
dépréciations Doctrine préexistantes), 20 suites unitaires frontend réussies,
build Vite réussi. Les tests backend utilisent SQLite en mémoire et l’autoloader
sans bootstrap d’environnement, car le fichier `.env` local n’est pas fourni.
Un scénario Playwright mobile est ajouté (édition, sous-menu, ordre au clavier,
erreur, indépendance des menus) et celui de navigation BO est actualisé.
Playwright ne peut pas être exécuté : archive `playwright-core@1.55.0` absente
du cache utilisable et accès npm bloqué par le DNS. Installation Composer complète
également impossible (archives manquantes) ; les dépendances disponibles suffisent
aux tests ciblés. Ni contrôle du conteneur Symfony complet, ni migration MySQL
hébergée, ni tests navigateur ne sont annoncés validés.

Ticket #130 — sections connectées (étape 6)

Les dépendances #129, #120 et #122 sont intégrées dans l’historique de `main`.
L’éditeur propose désormais huit sections : les six sections éditoriales,
Catalogue et Carte cadeau. Les anciens blocs restent compatibles.

`catalog` contient `title`, `mode` (`selection` ou `category`), `category`
(`prestations` ou `produits`, catégories de BOUTIQUE V2), `codes` (jusqu’à
12 codes Sylius distincts), `limit` (entier de 1 à 12), `buttonLabel` (vide pour
masquer le bouton). Les références sont vérifiées dans la connexion Doctrine du
tenant lors des sauvegardes, duplications, restaurations et publications.
Les prix et autres données commerciales ne font jamais partie de l’instantané.

`giftCard` contient `title`, `text`, `image` (référence média ou `null`) et
`buttonLabel`. L’image participe aux contrôles de tenant et au suivi d’utilisation
des médias. Le bouton mène exclusivement au parcours `gift-card-purchase` ; les
anciens bons et leur utilisation ne sont pas modifiés.

`SiteSections` rend ces blocs via `SiteConnectedSection`, également dans l’aperçu
BO (`editor`). Celui-ci expose chargement, erreurs avec réessai et états vides.
Côté public, une section vide, désactivée ou dont le chargement échoue ne laisse
aucun encart. Les cartes prestations existantes et les cartes produits extraites
de `ShopPage` sont partagées avec la boutique. Une rupture conserve l’information
et désactive le bouton d’achat. La vente cadeau dépend de l’offre serveur courante
et l’achat exige un moyen de paiement disponible.

Chaque montage de section relit les API boutique avec les en-têtes du tenant,
sans reprendre le catalogue mémorisé dans Pinia. Les lectures HTTP catalogue,
configuration, canal et offre cadeau utilisent `cache: no-store` ; les pages
publiques conservent leur réponse `private, no-store`. Aucun cache commercial
n’est attaché à une publication : prix, noms, images, suppressions et paramètres
de vente sont donc pris en compte au prochain chargement sans republier.
Les catégories respectent l’ordre configuré de la boutique, les sélections l’ordre
des codes. Le routage/publication des pages reste celui prévu par la suite de la
série ; ce ticket complète le composant de rendu partagé de l’étape 5.

Vérifications #130 : 81 tests site / 159 assertions, 218 tests ciblés de
permissions, cadeaux et gestion produits / 416 assertions, 21 suites unitaires
frontend, build Vite et contrôle du bundle de production réussis. Aucun ajout de dépendance. Les scénarios
Playwright sont fournis pour mobile et ordinateur mais non exécutés : archive
`playwright-core@1.55.0` absente du cache et accès réseau indisponible.
L’installation Composer complète est également empêchée par des archives
manquantes ; les dépendances disponibles permettent les tests ciblés ci-dessus.
L’essai élargi des tests d’intégration paiement/commande ne peut pas démarrer le
kernel : `ApiPlatformBundle` manque dans l’installation partielle. Il n’est
pas annoncé comme réussi. Aucune migration, modification Git ou opération de
déploiement n’a été exécutée.
