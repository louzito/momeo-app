#!/bin/sh
# =============================================================================
# SkyBook — creation d'un centre : claim si le pool n'est pas vide, sinon
# fabrication d'un centre blanc (clone template, chemin "lent" de secours)
# puis claim. Usage : new-center.sh <slug> "<Nom>" [email-admin]
# =============================================================================
set -e
cd "$(dirname "$0")/.."
SLUG=$1
NAME=$2
EMAIL=$3
if [ -z "$SLUG" ] || [ -z "$NAME" ]; then
  echo "Usage : new-center.sh <slug> \"<Nom>\" [email-admin]" >&2
  exit 1
fi
POOL=$(bin/console skybook:tenant:list --status=pool --count)
if [ "$POOL" -eq 0 ]; then
  echo "Pool vide -> fabrication d'un centre blanc (secours)…"
  sh scripts/pool-refill.sh 1
fi
sh scripts/claim-center.sh "$SLUG" "$NAME" $EMAIL
echo "Pense a relancer pool-refill pour reconstituer le pool."
