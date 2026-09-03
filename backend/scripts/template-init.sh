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
bin/console skybook:tenant:register template "$DB" --name "Template SkyBook" --status template
echo "== migrations =="
SKYBOOK_TENANT=template bin/console doctrine:migrations:migrate -n --allow-no-migration -q
echo "== donnees Sylius minimales =="
SKYBOOK_TENANT=template bin/console skybook:tenant:initialize "Template SkyBook" -n
echo "Template OK. Reste : provisionnement SkyBook (node skybook-provision.mjs, fait par template-init.cmd)."
