# Contrat de résolution du tenant

Ce document est la source canonique pour déterminer l'établissement (le
« tenant ») utilisé par l'application TodaTempo. Il s'applique aux requêtes
HTTP, aux commandes CLI et aux workers. Les exemples utilisent uniquement des
valeurs fictives.

## Identifiant et registre

Un tenant est identifié par son **slug**. Après suppression des espaces en
début et fin de chaîne et passage en minuscules, un slug doit respecter
`^[a-z0-9][a-z0-9-]{0,62}$` (1 à 63 caractères). Exemple :
`institut-demo`.

Le fichier local `backend/config/tenants.json` est l'unique source de vérité
pour associer un slug à une base de données. Le nom de la base ne doit jamais
être déduit du slug. `backend/config/tenants.example.json` documente la forme
du registre sans contenir de configuration de production.

Un tenant est utilisable lorsque :

- son slug existe dans le registre ;
- son entrée possède un champ `db` non vide ;
- `enabled` n'est pas à `false` ;
- son `status` vaut `active`.

En environnement de debug seulement, les statuts techniques (`pool`,
`template`, etc.) peuvent être utilisés pour le provisionnement et les tests.
Ils ne sont jamais considérés comme publiquement accessibles en production.

## HTTP

Pour les URL publiques, le slug est le premier segment :
`https://example.test/institut-demo/...`. Le reverse proxy valide ce slug,
retire le segment avant de transmettre la requête au backend et pose l'en-tête
canonique :

```http
X-TodaTempo-Tenant: institut-demo
```

Un client interne qui appelle directement le backend doit poser ce même
en-tête. L'application backend résout le tenant à partir de l'en-tête, puis
vérifie le registre avant tout accès à la session ou à la base de données.

Réponses attendues :

| Situation | Résultat |
| --- | --- |
| slug valide, actif et enregistré | la requête utilise la base indiquée par le registre |
| slug d'URL inconnu | `404 Not Found` au proxy |
| en-tête mal formé, inconnu, désactivé ou non publiable | `404 Not Found` (`Centre inconnu`) |
| en-têtes canonique et historique présents avec des valeurs différentes | `400 Bad Request` ; la requête est ambiguë |
| en-tête vide | traité comme absent |

### HTTP sans tenant

L'absence de tenant n'autorise pas un choix implicite pour une route publique
multi-tenant. Les URL de vitrine doivent contenir le slug et les appels directs
à leurs API doivent porter l'en-tête.

Pendant la V1, les routes historiques sans slug (`/admin`, `/api`,
`/_profiler`, ainsi que les assets et médias gérés par Sylius) continuent
d'utiliser `skybook.default_tenant`. Cette exception de compatibilité est
réservée à ces routes et ne doit pas être étendue à une nouvelle intégration.
Une autre route sans contexte explicite doit répondre `404 Not Found`.

## CLI

Une commande qui lit ou écrit les données d'un établissement reçoit le slug
avec la variable canonique `TODATEMPO_TENANT` :

```bash
cd backend
TODATEMPO_TENANT=institut-demo bin/console doctrine:migrations:migrate --dry-run
```

La valeur est normalisée et validée comme pour HTTP, puis résolue par le
registre. Une valeur mal formée ou absente du registre fait échouer la commande
avec un code de sortie non nul, avant toute opération métier. Une commande de
gestion globale du registre, telle que la liste ou l'enregistrement des
tenants, n'a pas besoin de tenant courant.

Pendant la V1, une commande historique sans variable utilise encore
`skybook.default_tenant`. Ce repli est déprécié : toute nouvelle commande
tenant-aware doit exiger un tenant explicite et échouer avec un message
indiquant `TODATEMPO_TENANT` lorsqu'il manque.

## Workers

Un worker est **mono-tenant pendant toute sa durée de vie**. Son environnement
doit définir `TODATEMPO_TENANT` avant son démarrage :

```bash
cd backend
TODATEMPO_TENANT=institut-demo bin/console messenger:consume async
```

Le tenant est validé dans le registre avant de commencer à consommer. Une
valeur absente, mal formée, inconnue, désactivée ou non utilisable doit arrêter
le worker avec un code non nul. Un worker ne doit ni utiliser le tenant par
défaut, ni changer de tenant en fonction du contenu d'un message. Il faut un
processus (et une configuration de transport adaptée) par tenant.

## Ordre de résolution

Les sources ne sont pas cumulées entre contextes :

1. HTTP : `X-TodaTempo-Tenant`, ou temporairement son alias historique ;
2. CLI et workers : `TODATEMPO_TENANT`, ou temporairement son alias historique ;
3. repli sur `skybook.default_tenant` uniquement pour les exceptions V1
   explicitement décrites ci-dessus.

Si le nom canonique et son alias historique sont tous deux renseignés avec la
même valeur normalisée, cette valeur est acceptée. S'ils diffèrent, le
contexte est ambigu : HTTP répond `400 Bad Request`, tandis qu'une commande ou
un worker s'arrête avec un code non nul. Le nom canonique ne doit donc jamais
silencieusement écraser une autre valeur.

## Compatibilité et dépréciation SkyBook

`X-Skybook-Tenant` et `SKYBOOK_TENANT` sont des alias temporaires. Ils ne sont
plus les noms à employer dans du nouveau code, des scripts ou de la
documentation.

La stratégie de retrait est liée aux versions afin de ne pas dépendre d'une
date de déploiement :

| Période | Comportement |
| --- | --- |
| toute la V1 | les noms TodaTempo sont canoniques ; les alias SkyBook restent acceptés ; leur utilisation isolée produit un avertissement de dépréciation dans les logs, sans exposer leur valeur |
| préparation de la V2 | tous les proxies, scripts, unités de service et pipelines doivent être migrés vers les noms TodaTempo ; les doubles valeurs contradictoires restent des erreurs |
| V2 | `X-Skybook-Tenant`, `SKYBOOK_TENANT` et le repli CLI sans tenant sont supprimés ; les workers continuent d'exiger un tenant explicite |

Les journaux peuvent mentionner le **nom** de l'alias déprécié et le slug
normalisé, mais ne doivent jamais recopier d'autres en-têtes, variables
d'environnement, DSN ou secrets.
