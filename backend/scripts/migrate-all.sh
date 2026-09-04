#!/bin/sh
# =============================================================================
# SkyBook — applique les migrations Doctrine en attente sur TOUTES les BDD du
# registre (chaque tenant a sa propre table sylius_migrations, la source des
# fichiers de migration est commune). A relancer a chaque migration touchant
# le schema partage (ex. chantier cheques cadeaux) : boucle sur
# skybook:tenant:list --json, SANS filtre de statut — `template` et les
# `pool-NNN` sont INCLUS expres (sinon un futur clone du pool, ou le template
# lui-meme, n'aurait pas la nouvelle table/colonne).
# =============================================================================
set -e
cd "$(dirname "$0")/.."

SLUGS=$(bin/console todatempo:tenant:list --json | php -r '
    $data = json_decode(stream_get_contents(STDIN), true) ?: [];
    foreach (array_keys($data) as $slug) {
        echo $slug . "\n";
    }
')

FAILED=""
for slug in $SLUGS; do
  echo "== $slug =="
  if ! TODATEMPO_TENANT="$slug" bin/console doctrine:migrations:migrate -n --allow-no-migration; then
    FAILED="$FAILED $slug"
  fi
done

if [ -n "$FAILED" ]; then
  echo "ECHEC de migration sur :$FAILED" >&2
  exit 1
fi
echo "Migrations a jour sur tous les tenants du registre."
