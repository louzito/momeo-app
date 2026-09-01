@echo off
REM =====================================================================
REM SkyBook - equivalent Windows de "make init" pour Sylius (Docker)
REM A lancer depuis C:\wamp64\www\AFIFLY\back
REM Docker Desktop doit etre demarre.
REM =====================================================================
setlocal
set DOCKER_USER=1000:1000
set ENV=dev

echo.
echo == 1/7 composer install (dans le conteneur php) ==
docker compose run --rm php composer install --no-interaction --no-scripts || goto :err

echo.
echo == 2/7 build des assets (conteneur nodejs) ==
docker compose run --rm nodejs || goto :err

echo.
echo == 3/7 permissions des volumes Docker (var/ et public/media) ==
REM Les volumes nommes sont crees "root" : on les rend accessibles a l'uid 1000 (sylius).
docker compose run --rm --entrypoint sh -u 0 php -c "mkdir -p var public/media vendor && chown -R 1000:1000 var public/media vendor" || goto :err

echo.
echo == 4/7 sylius:install  (cree la base + charge les donnees de demo) ==
docker compose run --rm php bin/console sylius:install -s default -n || goto :err

echo.
echo == 5/7 cles JWT (authentification de l'API admin) ==
docker compose run --rm php bin/console lexik:jwt:generate-keypair --skip-if-exists || goto :err

echo.
echo == 6/7 demarrage des conteneurs ==
docker compose up -d || goto :err

echo.
echo == 7/7 provisionnement SkyBook (types d'association, attributs custom) ==
REM Applique les personnalisations Sylius listees dans skybook-provision.mjs.
REM Non bloquant : si node est absent ou l'API pas prete, relancer a la main.
node skybook-provision.mjs || echo   (!) provisionnement non applique - relance "node skybook-provision.mjs" une fois Sylius demarre

echo.
echo ============================================================
echo  OK !
echo  Boutique : http://localhost:8080
echo  Admin    : http://localhost:8080/admin   (sylius@example.com / sylius)
echo  API      : http://localhost:8080/api/v2/shop/products
echo  Mails    : http://localhost:8025
echo ============================================================
goto :eof

:err
echo.
echo *** ERREUR a l'etape ci-dessus. Copie-moi le message d'erreur complet. ***
exit /b 1
