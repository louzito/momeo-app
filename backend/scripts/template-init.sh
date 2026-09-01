#!/bin/sh
# =============================================================================
# SkyBook — creation de la BDD TEMPLATE des centres blancs (LENT, une fois).
# A lancer via template-init.cmd (qui enchaine avec le provisionnement node).
# Partie conteneur php : BDD + registre + migrations + fixtures minimales.
# =============================================================================
set -e
cd "$(dirname "$0")/.."
DB=skybook_template

echo "== template-init : BDD $DB =="
bin/console dbal:run-sql "CREATE DATABASE IF NOT EXISTS $DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" >/dev/null
bin/console skybook:tenant:register template "$DB" --name "Template SkyBook" --status template
echo "== migrations =="
SKYBOOK_TENANT=template bin/console doctrine:migrations:migrate -n --allow-no-migration -q
echo "== fixtures minimales (channel, virement, admin) =="
SKYBOOK_TENANT=template bin/console sylius:fixtures:load skybook_minimal -n
echo "Template OK. Reste : provisionnement SkyBook (node skybook-provision.mjs, fait par template-init.cmd)."
