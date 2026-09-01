@echo off
REM SkyBook - applique les migrations Doctrine en attente sur TOUTES les BDD
REM du registre (y compris template et le pool). Voir scripts/migrate-all.sh.
cd /d %~dp0
docker compose exec -T php sh scripts/migrate-all.sh
