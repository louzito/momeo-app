#!/bin/sh
# =============================================================================
# SkyBook — creation de la BDD TEMPLATE des centres blancs (LENT, une fois).
# A lancer via template-init.cmd (qui enchaine avec le provisionnement node).
# Partie conteneur php : BDD + registre + migrations + socle Sylius minimal.
# Le claim rejoue ensuite ce socle avec l'identite definitive du tenant.
# =============================================================================
set -e
cd "$(dirname "$0")/.."
DB=skybook_template

echo "== template-init : BDD $DB =="
bin/console dbal:run-sql "CREATE DATABASE IF NOT EXISTS $DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" >/dev/null
bin/console todatempo:tenant:register template "$DB" --name "Template TodaTempo" --status template
echo "== migrations =="
TODATEMPO_TENANT=template bin/console doctrine:migrations:migrate -n --allow-no-migration -q
echo "== donnees Sylius minimales =="
TODATEMPO_TENANT=template bin/console todatempo:tenant:initialize "Template TodaTempo" -n
echo "Template OK. Reste : provisionnement TodaTempo (node skybook-provision.mjs, fait par template-init.cmd)."
