# Médiathèque des pages — #128

Prérequis #126 vérifié dans les entités SitePage/SiteMenu, les API, les tests
et `docs/site-pages-menus.md` de la branche fournie. La médiathèque ajoute
**Mon site internet → Images**, réservée à la permission `settings`, ainsi que
l’action **Images** des pages. Les écrans et images historiques restent intacts.
Aucun ajout de dépendance, aucune bascule du site public ni publication HTTP.

## Stockage et validation

`SiteMediaService` réutilise le validateur d’upload existant, l’interface
`ImageUploaderInterface` de Sylius (alias existant `sylius.uploader.image`),
son adaptateur de stockage et `TenantImagePathGenerator`. Aucun taxon ni logo
historique n’est modifié. Les nouvelles métadonnées et usages résident dans
la base Doctrine du tenant. Les API `/api/v2/admin/site/media` refusent un tenant
absent via le listener existant ; les JWT et permissions BO restent applicables.
Toutes les réponses API sont privées et non mises en cache.

L’import accepte JPEG, PNG et WebP uniquement, avec extension et MIME concordants,
5 Mio maximum, dimensions positives, au plus 6 000 pixels par côté et 20 millions
de pixels. Le décodage réel GD est obligatoire ; le fichier est réencodé en WebP
(qualité 82), avec une grande version bornée à 1 600 pixels et une miniature à
400 pixels, sans agrandissement. L’animation et les métadonnées ne sont pas
conservées. Aucun SVG ni fichier original arbitraire n’est servi. GD avec support
WebP doit être disponible sur le serveur ; une indisponibilité renvoie une erreur
française. Les chemins aléatoires et préfixes tenant suivent le stockage existant.
Les images publiées sont des ressources publiques, comme les médias historiques.
L’accès aux métadonnées BO et leur modification restent limités au tenant.

`SiteMediaImage` rend le texte alternatif de la référence de page, les attributs
width/height, un srcset et le chargement différé (option eager pour une bannière).
Les aperçus de bibliothèque utilisent la miniature avec espace réservé.

## API et documents

- `GET /api/v2/admin/site/media` : liste des images du tenant, dimensions,
  chemins des deux tailles, texte alternatif et indicateur d’utilisation.
- `POST` sur la même URL : multipart `file` et `alt`, réponse 201.
- `PUT /api/v2/admin/site/media/{id}` : exactement `{alt: string}`, 300 caractères
  maximum. Une chaîne vide signifie image décorative.
- `DELETE` sur cette URL : 409 si utilisée, 404 si absente du tenant.

Les erreurs de validation renvoient 422, les conflits d’usage 409. Les erreurs
d’import conservent la saisie ; l’écran comporte chargement, état vide et reprise.
La sélection utilise un dialogue natif accessible au clavier, Escape et retour
du focus. Elle conserve l’ID d’une image existante sans nouvel upload.

Le contrat fermé `schemaVersion: 1` accepte désormais :

- `image` / `banner` : `props: {mediaId, alt}` ;
- `gallery` : `props: {images: [{mediaId, alt}, ...]}`, de 1 à 20 images.

Aucun chemin, HTML, CSS ou attribut libre n’est accepté dans ces références.
Le texte alternatif est copié lors de la sélection puis personnalisable par
occurrence : modifier le texte par défaut de la bibliothèque ne change pas une
page publiée. Les composants d’édition permettent ajout, remplacement et retrait
des images, bannières et galeries sans altérer les autres blocs. L’API publique
ajoute une table `media` des seules références de la version publiée pour les
composants de rendu des étapes suivantes. Aucun brouillon n’est exposé.

## Protection des usages et migration

La migration additive `Version20260928140000` crée `todatempo_site_media` et
`todatempo_site_media_usage`. À appliquer par le mécanisme habituel de migrations
de chaque tenant, après les migrations #126/#127. Aucune migration hébergée n’a
été exécutée par ce ticket et aucun contenu existant n’est transformé.

Le service de pages valide les références dans la base courante avant chaque
enregistrement, copie, restauration et publication. Il synchronise les usages
avec la page dans la même transaction Doctrine : union des références du brouillon
et de l’instantané publié. Les pages archivées conservent également leurs usages.
Retirer une image uniquement du brouillon ne permet donc pas de supprimer une
image encore publiée. La nouvelle publication libère les anciens usages.

La clé étrangère RESTRICT vers le média empêche une suppression concurrente de
casser une sauvegarde. Inversement, une sauvegarde concurrente référençant un
média supprimé est annulée. Les fichiers ne sont supprimés qu’après confirmation
de la suppression en base ; une erreur de stockage peut laisser un fichier
orphelin, mais ne retire jamais le fichier d’une page encore référencée.

## Vérifications dans l’environnement du ticket

- 281 tests backend ciblés / 517 assertions réussis : permissions BO, isolation JWT et stockage tenant,
  validation, réutilisation, restauration/publication, copie, archivage, protection
  par clé étrangère et traitement réel d’images.
- 20 suites unitaires frontend et build Vite réussis avec les versions du lockfile.
- Scénario Playwright mobile ajouté : import, refus de suppression, sélection
  clavier, retour du focus, alternative par occurrence, réutilisation et saisie
  conservée après échec.

Les dépendances disponibles ont été restaurées depuis le cache local, sans
modification des manifests/lockfiles. L’installation Composer complète reste
impossible (archive api-platform/hydra absente). Le contrôle du conteneur Symfony
est indisponible faute de fichier `.env`. Playwright n’a pas pu être exécuté :
`playwright-core@1.55.0` manque au cache. Ces contrôles, ainsi que l’exécution de
la migration sur MySQL hébergé, ne sont pas annoncés validés.
