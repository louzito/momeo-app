#!/bin/sh
# =============================================================================
# SkyBook — maintient N centres blancs prets dans le pool (defaut N=10).
# Idempotent et relancable (cron ou apres chaque attribution). Chaque centre
# blanc = clone de la BDD template (rapide) enregistre en status=pool sous un
# slug provisoire pool-NNN. L'installation LENTE n'a lieu qu'une fois, dans
# template-init.
# =============================================================================
set -e
cd "$(dirname "$0")/.."
N=${1:-10}

TPL=$(bin/console skybook:tenant:list --status=template --count)
if [ "$TPL" -lt 1 ]; then
  echo "ERREUR : aucune BDD template. Lance d'abord template-init.cmd." >&2
  exit 1
fi
CUR=$(bin/console skybook:tenant:list --status=pool --count)
echo "Pool actuel : $CUR / $N"
while [ "$CUR" -lt "$N" ]; do
  bin/console skybook:tenant:pool-add
  CUR=$((CUR + 1))
done
echo "Pool complet : $CUR centre(s) pret(s)."
