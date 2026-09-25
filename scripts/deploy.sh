#!/usr/bin/env bash
set -euo pipefail

ROOT=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
MODE=${1:-deploy}
case "$MODE" in
  build|deploy|deploy-backend) ;;
  *) echo "Usage: $0 [build|deploy|deploy-backend]" >&2; exit 2 ;;
esac
cd "$ROOT"
exec 9>"$ROOT/.deploy.lock"
flock -n 9 || { echo 'Un build ou déploiement est déjà en cours.' >&2; exit 1; }

if [[ "$MODE" != build ]]; then
  export APP_ENV=prod APP_DEBUG=0
  # Installer avant Encore : ses paquets Sylius proviennent de vendor/.
  (cd backend && composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader --no-scripts)
  # Valider la configuration avant de modifier les fichiers publiés.
  (cd backend && php -r 'require "vendor/autoload.php"; (new Symfony\Component\Dotenv\Dotenv())->bootEnv(".env"); (new App\Service\Configuration\ProductionConfigurationValidator())->validate(getcwd());')
  (cd backend && corepack yarn install --frozen-lockfile --non-interactive --production=false)
  if [[ "$MODE" == deploy ]]; then
    npm --prefix frontend ci --include=dev
  fi
fi

if [[ "$MODE" != deploy-backend ]]; then
  npm --prefix frontend run build
fi
npm --prefix backend run build:prod

if [[ "$MODE" != build ]]; then
  # Un conteneur compilé pour une ancienne signature PHP peut empêcher cache:clear.
  rm -rf -- "$ROOT/backend/var/cache/prod"
  (cd backend && php bin/console cache:clear --no-interaction)
  (cd backend && php bin/console assets:install public --no-interaction)
  if [[ "$MODE" == deploy-backend || "${MIGRATE:-0}" == 1 ]]; then
    (cd backend && bash scripts/migrate-all.sh)
  fi
  (cd backend && php bin/console messenger:stop-workers --no-interaction)
  if [[ "$MODE" == deploy-backend ]]; then
    echo 'Backend déployé dans backend/public, migrations à jour.'
  else
    echo 'Frontend et backend déployés dans frontend/dist et backend/public.'
  fi
else
  echo 'Builds frontend et backend terminés.'
fi
