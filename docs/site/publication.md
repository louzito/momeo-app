# Publication et reprise — #132

Prérequis vérifiés sur la branche fournie : #127 (menus et publication groupée),
#130 (sections connectées), #131 (modèles et apparence). Aucun changement de
branche, commit, push, déploiement ou modification de base hébergée.

## Parcours professionnel

Dans **Mon site internet → Pages**, enregistrer reste une action de brouillon.
**Aperçu privé** affiche le brouillon enregistré avec le même composant que le
public, les offres actuelles et l’apparence actuellement publiée. Les menus
cochés dans la sélection sont également prévisualisés ; sinon la navigation
publiée est utilisée. Les liens vers d’autres pages restent dans l’aperçu privé.
L’aperçu exige la connexion et la permission `settings` du tenant. L’API répond
`private, no-store`, `X-Robots-Tag: noindex, nofollow, noarchive` et
`Referrer-Policy: no-referrer` ; la vue ajoute également la balise robots.
Aucun lien public de brouillon ni jeton dans une URL n’est généré.

**Publier cette page** publie uniquement son brouillon enregistré. Pour publier
ensemble des pages liées et les menus, cocher les pages et les emplacements,
puis **Publier la sélection**. Un conflit garde la sélection et propose de
recharger. La navigation personnalisée devient active avec la publication de
la page portant le rôle Accueil, conformément au contrat #127.

**Restaurer la version publiée** remplace le brouillon par le dernier instantané
publié (ou l’ancien publié conservé lors de la reprise, avant la première
publication). Dans Menus, **Restaurer le menu publié** restaure les entrées et
le bouton principal. Ces actions n’altèrent pas le contenu public déjà publié.

## Transactions et HTTP

- `POST /api/v2/admin/site/publish` : `{pages: [{id, revision}], menus: [{id, revision}]}`.
- `GET /api/v2/admin/site/pages/{id}/preview?menus=main,footer` : rendu privé ;
  le paramètre menus est optionnel.
- `POST /api/v2/admin/site/pages/{id}/restore` : `{revision}`.
- `POST /api/v2/admin/site/menus/{main|footer}/restore` : `{revision}`.
- `POST /api/v2/admin/site/import` : reprise explicite, sans publication.

La publication démarre la transaction avant de relire et valider les contenus.
Les pages du petit site sont verrouillées dans un ordre stable, y compris les
cibles des liens ; les menus sélectionnés sont également relus sous verrou.
Les versions reçues du navigateur doivent correspondre. Le schéma fermé,
les références de médias/catalogue, les anciennes adresses et les liens sont
revalidés avant d’écrire le moindre instantané. Une référence doit être déjà
publiée ou appartenir au lot. Une erreur annule tout le lot. Les modifications
des entrées seules incrémentent aussi la version du menu. Les conflits de
versions, d’unicité et les erreurs transactionnelles réessayables donnent 409.
Les médias conservent des usages pour brouillon, publié et ancien publié repris.

La base Doctrine du tenant reste la frontière de données. Toutes ces API restent
sous les autorisations BO `settings` et refusent le tenant implicite. Le rendu
public ne lit jamais `draft` ni `legacyPublished`.

## URLs et caches

Les pages libres sont accessibles à `/{tenant}/{slug}`. Accueil et pages légales
conservent `/`, `/accueil`, `/legal/terms`, `/legal/mentions` sous la base tenant.
Une ancienne adresse libre reste résolue par l’API et la vue remplace l’URL par
l’adresse canonique. Les routes métier existantes gardent la priorité ; `cart`
rejoint les slugs réservés. Les liens de pages système utilisent leur URL
historique, indépendamment du slug de stockage.

Pages et navigation ne possèdent aucun cache de contenu serveur : chaque lecture
consulte la base et interdit le stockage HTTP public ou privé (`no-store`).
Les requêtes frontend utilisent également `cache: no-store`. Après publication,
restauration ou archivage, la navigation et la page éditoriale courante sont
rechargées sans remonter les formulaires métier ; les autres
onglets du même établissement reçoivent une notification ne contenant qu’un
horodatage, dans une clé préfixée par le tenant. Les clés d’autres établissements
sont ignorées. La navigation est relue lors des déplacements publics. Les prix
et disponibilités restent fournis par les API métier, sans instantané commercial.

## Reprise sans bascule automatique

La migration tenant `Version20260929120000` ajoute `legacy_published` aux pages
et `draft_version` aux menus. Elle est à appliquer par le mécanisme habituel
AutoTicket ; elle n’a pas été exécutée sur une base hébergée ici.

La reprise verrouille le taxon de configuration existant et crée uniquement les
rôles système absents. Une adresse déjà prise reçoit un suffixe ; aucune page
créée auparavant n’est remplacée. Le brouillon historique alimente le nouveau
brouillon ; l’ancien publié alimente un instantané séparé, restaurable, sans
activer le nouveau rendu public. Deux reprises réussies ne créent ni page ni
média supplémentaire.

L’accueil utilise les blocs disponibles : bannière, textes/points forts,
catalogue dans l’ordre des sections existantes, carte cadeau et lien vers les
anciens chèques cadeaux. Les offres supprimées ne deviennent pas des références
invalides. Les pages légales reprennent le texte, ses paragraphes et son état
masqué. Les bannières sont copiées depuis les images du taxon courant vers la
médiathèque ; leur suppression future ne peut pas effacer les fichiers historiques.
Une image indisponible ou un contenu hors limites bloque la reprise entière
sans modifier l’ancien site. Le document historique, ses deux versions, son
thème, ses logos et ses images restent intacts. Le thème conserve son stockage
et sa publication existants ; aucun second document d’apparence n’est créé.
La mise en page utilise les sections V1, et peut donc différer de l’ancien accueil.

Tant qu’une page système n’a pas été publiée, sa route utilise le composant
historique. Une erreur de lecture permet également ce repli. Le tunnel de
réservation, les commandes, le compte et les cartes cadeaux restent inchangés.

## Vérification

Les tests Site utilisent SQLite : publication HTTP atomique, aperçu distinct du
public, conflit de deux connexions sur pages et menus, restauration, reprise
répétée avec anciennes versions distinctes, conservation des pages existantes
et des configurations, réponses sans cache et bases de tenants séparées. Les
tests de permissions et d’absence de tenant couvrent les nouvelles routes.
La configuration Sylius de reprise est une fixture ; les pages importées sont
réellement persistées et relues dans SQLite.

Les scénarios Playwright livrés couvrent le parcours depuis l’accueil publié
vers une prestation, la réservation et la carte cadeau, sur mobile/ordinateur,
ainsi que l’aperçu authentifié et le conflit de publication avec saisie conservée.
Leur exécution est empêchée dans cet environnement : ouverture du port Vite
refusée (`EPERM`) ; l’essai sans serveur, sur le bundle construit et avec le
Playwright disponible en cache, échoue au lancement de Chromium (`libcups.so.2`
absente). Aucun scénario navigateur n’est annoncé réussi. Le contrôle complet
du conteneur est également indisponible (Symfony Runtime non généré dans
l’installation Composer partielle hors réseau). Les tests ciblés restent
exécutables avec les paquets du lockfile extraits du cache local.

Résultats finaux : **371 tests backend / 758 assertions**, **22 suites unitaires
frontend**, **build Vite et contrôle du bundle de production réussis**.
`git diff --check` est également réussi. Les tests MySQL de verrouillage réel,
la migration sur un tenant hébergé, le conteneur complet et les visites navigateur
ne sont pas annoncés validés.
