# Référencement et partage — #133

## Choix après inspection

Le prérequis #132 est intégré : instantanés publiés, aperçus autorisés, transactions,
anciens slugs, tests et documentation `publication.md`. Le dépôt héberge une API
Symfony/PHP et un build Vite statique derrière Apache/Nginx (README et script
`deploy.sh`) ; il conserve également un générateur de proxy Caddy. Il n’y a pas
de serveur Node persistant existant.

Le choix est un **rendu Vue limité, à la requête**, appelé par Symfony Process.
Un pré-rendu stocké demanderait de gérer les invalidations des liens, médias,
domaines et publications ; un serveur SSR complet ajouterait un service à exploiter.
Ici, `SiteHtmlController` relit Doctrine et `SiteHtmlRenderer` envoie uniquement le
document public à un processus Node isolé, par stdin JSON, sans commande shell.
Le bundle utilise `SitePageContent`, `SiteSections`, `SiteRichText`,
`SitePublicLink` et `SiteMediaImage`, comme le navigateur. Aucun deuxième moteur
de texte riche ni template des sections en PHP. Vue échappe les textes ; aucun
HTML fourni par le professionnel n’est injecté. Pas de CMS ni de dépendance ajoutée :
`vue/server-renderer` est l’export de Vue 3.5.40, avec son renderer de même version
déjà présent dans le lockfile. Vite 5.4.21 produit un bundle Node autonome.

`npm run build` produit `frontend/dist` et `frontend/dist-ssr/site-server.mjs`.
Le build SSR ne contient aucun document d’établissement. Une publication ne
nécessite **aucune reconstruction**. Les réponses HTML, sitemap et API restent
`private, no-store` : ni cache partagé ni fichier HTML contenant des brouillons.
Chaque appel lit la connexion du tenant courant ; les modifications des pages,
liens, archives, médias et du registre sont donc prises en compte à la requête
suivante. Il ne faut pas ajouter de cache CDN sur ces réponses.

## Champs et comportement

Dans Pages → Composer → Référencement et partage : titre (160 caractères),
description (320), image de la médiathèque du même établissement. La validation,
les permissions `settings`, les révisions et la publication existantes s’appliquent.
L’image est retenue par les usages de médias tant que brouillon ou version publiée
la référence ; une référence étrangère est refusée.

Valeurs automatiques : titre de page, premiers textes éditoriaux visibles,
première image éditoriale visible. Sans texte, la description reprend le titre ;
sans image, les balises image sont omises et la carte de partage utilise `summary`.
Les sections cachées et commerciales ne fournissent pas ces valeurs automatiques.
Balises : description, canonical, Open Graph et Twitter. Les métadonnées sont aussi
mises à jour à la navigation Vue et effacées en quittant une page éditoriale.

Les URL viennent exclusivement de `TenantUrlGenerator` : `DEFAULT_URI/<tenant>/`
(préfixe compris), ou domaine vérifié `https://domaine/<tenant>/`. Le Host reçu ne
sert jamais à fabriquer une balise. Le HTML fournit également la base de routage
Vue pour qu’un domaine personnalisé fonctionne avec un build d’assets préfixé.
Un domaine vérifié d’un autre tenant est refusé par le contrôleur HTML.

`/<base>/<tenant>/sitemap.xml` contient uniquement les URL canoniques des pages
publiées non archivées, sans ancien slug, compte, BO, aperçu ou brouillon. Il peut
être déclaré dans le robots.txt du virtual host ou soumis au moteur ; il n’est
pas fusionné avec les sites d’autres établissements. Pas de `lastmod` artificiel.
Les anciennes adresses publiées reçoivent 301 ; une page absente/archivée reçoit
404 avec `noindex`. Les écrans métier conservent la SPA et sont `noindex` ; une URL
imbriquée inconnue hors de ces espaces reçoit 404. La validation d’un identifiant
métier reste effectuée par ses API. Les routes système encore sans publication
conservent le repli historique, avec une coque non indexable.

Le contenu initial reste visible sans JavaScript. Avec JavaScript, la SPA remplace
ce contenu lorsque la page publique a été chargée. Il s’agit d’un remplacement
progressif du corps éditorial, pas d’une hydratation de toute l’application.
Les styles des sections font partie de la feuille initiale. Le chrome/navigation,
le thème interactif et les écrans historiques restent gérés par l’application.

## Catalogue et fraîcheur

Les sections catalogue et carte cadeau donnent dans le HTML initial un lien vers
la boutique. Aucun prix, stock, disponibilité ou offre désactivée n’est gravé dans
un instantané SEO. Après démarrage de Vue, le composant connecté existant relit
les API métier à chaque montage et masque les offres indisponibles ; le checkout
conserve ses contrôles serveur. Les offres ne bénéficient donc pas d’un rendu SEO
complet ni de données structurées Product dans cette V1. La fraîcheur commerciale
reste celle de ces API ; aucun nouveau cache catalogue n’est introduit.

## Intégration à l’hébergement (à appliquer lors du déploiement habituel)

Aucun déploiement, changement de base hébergée ou rechargement de proxy n’a été
exécuté pour ce ticket. Les deux artefacts doivent être présents avant d’acheminer
les pages vers le nouveau contrôleur. Node **20 ou supérieur** doit être exécutable
par PHP ; `TODATEMPO_SITE_NODE_BINARY` permet son chemin absolu si `node` n’est pas
dans le PATH du service. Le compte PHP doit pouvoir lire les deux builds et lancer
Symfony Process. Limite : 5 secondes/processus, 2 Mo de document sérialisé ; erreur
de rendu → 503, `noindex`, `Retry-After: 60`, jamais un faux succès vide.

Le point d’entrée interne est `GET /api/v2/shop/site/html/{chemin}` avec un tenant
explicite. Le reverse proxy doit **remplacer son fallback index.html** pour les
URL de documents par ce point d’entrée, retirer le préfixe et le tenant, et poser
`X-TodaTempo-Tenant`. Les API, médias et callbacks métier existants gardent leur
routage. Les statuts 301/404/503 et en-têtes doivent traverser le proxy sans page
d’erreur transformée en 200. L’accès direct au backend doit rester derrière le
contrat de proxy décrit dans `tenant-resolution.md`.

- Caddy : le générateur fourni route désormais ces documents vers Symfony et
  accepte le préfixe de `DEFAULT_URI`, ainsi que les domaines vérifiés.
- Nginx : exemple à adapter dans `backend/etc/nginx/site-html.conf.example`.
- Apache : dans le virtual host existant, remplacer la règle de fallback SPA par
  une réécriture/proxy vers `/api/v2/shop/site/html/<chemin>`, en conservant le
  tenant capturé dans l’en-tête canonique. Conserver les règles API/médias avant
  celle-ci et rejeter les domaines/tenants inconnus selon le contrat existant.

Les nouveaux assets Vite sont sous `site-assets/` pour éviter le répertoire
`assets/` de Sylius. Servir ce préfixe depuis `frontend/dist`, y compris sous le
préfixe compilé sur les domaines personnalisés. **Ne pas servir dist-ssr** : il est
privé, lu par PHP/Node. Les routes API et médias de `VITE_API_BASE`/`VITE_MEDIA_BASE`
doivent aussi rester accessibles sur chaque domaine, comme précédemment.

Par défaut, l’URL serveur des médias est relative à la base publique sans tenant.
Si les médias passent par un autre chemin du même domaine, définir
`TODATEMPO_SITE_MEDIA_PATH` (chemin seulement), par exemple
`/todatempo-app/backend`, cohérent avec `VITE_MEDIA_BASE`. Aucune URL externe n’est
acceptée pour ce réglage. Le domaine reste celui du tenant canonique.

Le serveur Vite de développement conserve son fallback SPA : valider le rendu
HTTP avec le gateway Symfony et les builds, pas avec `vite dev` seul. Cette V1
lance un processus par page : elle privilégie la simplicité pour de petits sites.
Une montée en charge pourra justifier un pool mesuré, avec isolation des requêtes.
Le référencement rend les pages lisibles et partageables ; il ne garantit ni
indexation immédiate ni position dans les résultats Google.

## Vérification

Tests SQLite et vrai bundle SSR Node : contenu sans navigateur, description,
image explicite/automatique, échappement HTML, absence des brouillons/sections
cachées, publication sans build, changement de slug, archivage, 301/404/503,
URLs système, préfixe, domaines, tenant étranger, médias et routes privées.
Les tests existants couvrent permissions et publication transactionnelle.
Voir le résumé du ticket pour les résultats d’exécution et limites environnementales.

Résultats dans l’espace de travail : **394 tests backend, 885 assertions**, sans
échec (6 dépréciations signalées par les suites existantes) ; **22 suites unitaires
frontend**, build client + SSR et contrôle du bundle de production réussis.
Syntaxe PHP et `git diff --check` réussis. Les paquets installés depuis les caches
locaux ont les versions du lockfile ; l’installation npm complète hors réseau
reste indisponible faute d’archive Playwright. Le lint du conteneur Symfony n’a
pas pu démarrer car `backend/.env` n’est pas fourni. Aucune visite navigateur,
validation du proxy réel ou vérification d’une base hébergée n’est annoncée réussie.
