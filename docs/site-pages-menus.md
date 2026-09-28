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

Les autres versions, types, propriétés et références médias sont refusés.
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
