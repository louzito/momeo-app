# TodaTempo App

Application métier TodaTempo destinée aux établissements de soin et réunissant :

- `backend/` : API et administration Sylius ;
- `frontend/` : interface Vue 3 construite avec Vite.

## Documentation applicative

- [Contrat canonique de résolution du tenant](docs/tenant-resolution.md) :
  HTTP, CLI, workers, erreurs et compatibilité SkyBook.
- [Checklist de mise en production V1](docs/production-checklist.md) :
  configuration, permissions, migrations, tenants, worker, cron,
  paiement/webhooks, SMTP, PDF, sauvegardes, santé, smoke test et rollback.

## Configuration locale

Les dépendances installées, fichiers générés, clés privées, factures et configurations locales ne sont pas enregistrés dans Git.

Créer la configuration du backend à partir du modèle :

```bash
cp backend/.env.example backend/.env
cp backend/.env.test.example backend/.env.test
cp backend/config/tenants.example.json backend/config/tenants.json
```

Les valeurs privées doivent être adaptées localement ou sur le serveur, sans être ajoutées à Git.

## Installation

```bash
cd backend
composer install

cd ../frontend
npm ci
npm run build
```

La procédure de déploiement sans Docker (permissions, migrations, workers,
cron, sauvegardes, contrôles de santé, smoke test et rollback) est décrite
dans la [checklist de mise en production V1](docs/production-checklist.md).

## Commandes à la racine (sans Docker)

```bash
make build   # Compile Vue et les assets Sylius avec les dépendances installées
make test    # Tests unitaires frontend et contrôle du bundle de production
make deploy  # Installe les dépendances verrouillées et déploie les deux parties
make deploy-backend # Déploie uniquement Sylius, migrations BDD incluses
```

Prérequis : PHP et ses extensions compatibles avec `backend/composer.lock`,
Composer, Node.js, npm, Corepack, Bash et `flock`. Exécuter sur le serveur,
depuis le checkout de la branche à publier, avec un compte disposant des droits
sur les dépendances, les assets et le cache Symfony. Yarn 1.22.22 est fixé dans
`backend/package.json` et appelé par Corepack.

`make deploy` utilise `APP_ENV=prod` et `APP_DEBUG=0`, vérifie la configuration
privée du backend, construit `frontend/dist/` et `backend/public/build/`,
recrée le cache Symfony, installe les assets des bundles puis demande l'arrêt
propre des workers Messenger. Leur superviseur doit les relancer automatiquement.
Apache/Nginx doit déjà servir ces répertoires et acheminer les appels API vers
`backend/public/index.php`. Aucun transfert SSH ni configuration du serveur web
n'est réalisé. La publication se fait sur place : prévoir une fenêtre de
maintenance, car les deux builds ne basculent pas atomiquement.

Pour un hébergement sous `/todatempo-app/`, créer `frontend/.env.local` avec :

```dotenv
VITE_APP_BASE=/todatempo-app/
VITE_API_BASE=/todatempo-app/backend/api/v2
VITE_MEDIA_BASE=/todatempo-app/backend
```

Le préfixe doit commencer et finir par `/`. Sans ces valeurs, l'application
utilise `/<tenant>/` et `/api/v2`. Les variables `VITE_*` sont publiques et
intégrées au build : ne jamais y placer de secrets.

Les migrations sont optionnelles et concernent **tous les tenants du registre**,
pool et template compris. Après sauvegarde des bases :

```bash
MIGRATE=1 make deploy
```

Pour déployer uniquement le backend, après sauvegarde des bases :

```bash
make deploy-backend
```

Cette commande installe les dépendances PHP et JavaScript du backend, valide
la configuration de production, compile les assets Sylius, vide et réchauffe
le cache Symfony, installe les assets des bundles, puis applique les migrations
sur **tous les tenants du registre, pool et template compris**. Elle demande
ensuite l'arrêt propre des workers Messenger pour que leur superviseur les
relance. Les migrations sont systématiques pour cette commande, sans variable
`MIGRATE` à définir. Elle ne réinitialise pas les bases et ne supprime pas leurs
données ; les modifications de schéma et de données dépendent des migrations.
Le frontend n'est ni installé ni compilé. Les prérequis serveur et le déploiement
sur place décrits ci-dessus restent applicables.

La commande s'arrête à la première étape en échec. Une configuration de production
incomplète bloque la publication ; suivre la checklist ci-dessus pour les clés,
le PDF, les permissions, les sauvegardes et les contrôles de santé.

Le [guide SSO](docs/sso.md) décrit les URL internes/publiques, la sélection du
centre, le cookie et la coexistence des JWT avec une protection HTTP Basic.
