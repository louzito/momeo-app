@echo off
REM SkyBook - creation de la BDD template du pool (LENT, une seule fois),
REM puis provisionnement SkyBook via l'API (node, avec l'en-tete tenant).
cd /d %~dp0
docker compose exec -T php sh scripts/template-init.sh || exit /b 1
set SKYBOOK_TENANT=template
node skybook-provision.mjs || exit /b 1
set SKYBOOK_TENANT=
echo Template prete et provisionnee. Lance pool-refill.cmd [N] pour remplir le pool.
