# TodaTempo App

Application métier TodaTempo destinée aux établissements de soin et réunissant :

- `backend/` : API et administration Sylius ;
- `frontend/` : interface Vue 3 construite avec Vite.

## Documentation applicative

- [Contrat canonique de résolution du tenant](docs/tenant-resolution.md) :
  HTTP, CLI, workers, erreurs et compatibilité SkyBook.

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

La procédure de déploiement sans Docker sera documentée lors de l’installation du VPS.
