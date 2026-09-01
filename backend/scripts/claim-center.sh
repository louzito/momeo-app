#!/bin/sh
# =============================================================================
# SkyBook — ATTRIBUTION INSTANTANEE d'un centre du pool (< quelques secondes).
# Usage : claim-center.sh <slug> "<Nom du centre>" [email-admin]
# Toute la logique est dans `bin/console skybook:tenant:claim` (reutilisable
# par la future API d'inscription) ; ici : appel + smoke-test HTTP.
# Le Caddyfile du proxy est regenere automatiquement par le claim (registre) ;
# caddy tourne avec --watch et se recharge seul.
# =============================================================================
set -e
cd "$(dirname "$0")/.."
SLUG=$1
NAME=$2
EMAIL=$3
if [ -z "$SLUG" ] || [ -z "$NAME" ]; then
  echo "Usage : claim-center.sh <slug> \"<Nom du centre>\" [email-admin]" >&2
  exit 1
fi

if [ -n "$EMAIL" ]; then
  bin/console skybook:tenant:claim "$SLUG" "$NAME" --email "$EMAIL"
else
  bin/console skybook:tenant:claim "$SLUG" "$NAME"
fi

# Smoke-test : d'abord via le PROXY caddy (equivalent localhost/{slug}/),
# sinon directement nginx (si caddy n'est pas lance).
php -r '
$slug = $argv[1];
$try = function (string $base, array $headers) use ($slug): bool {
    $ctx = stream_context_create(["http" => ["header" => implode("\r\n", $headers), "timeout" => 15]]);
    return @file_get_contents($base, false, $ctx) !== false;
};
if ($try("http://caddy/$slug/api/v2/shop/products?itemsPerPage=1", ["Accept: application/ld+json"])) {
    echo "Smoke-test OK via le proxy : localhost/$slug/api repond.\n";
    exit(0);
}
if ($try("http://nginx/api/v2/shop/products?itemsPerPage=1", ["X-Skybook-Tenant: $slug", "Accept: application/ld+json"])) {
    echo "Smoke-test OK (nginx direct — caddy injoignable, verifie docker compose up -d caddy).\n";
    exit(0);
}
fwrite(STDERR, "SMOKE-TEST EN ECHEC pour $slug\n");
exit(1);
' "$SLUG"
