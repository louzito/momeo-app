@echo off
REM SkyBook - maintient N centres blancs prets dans le pool (defaut 10).
cd /d %~dp0
docker compose exec -T php sh scripts/pool-refill.sh %1
