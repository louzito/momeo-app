@echo off
REM SkyBook - attribution instantanee : claim-center.cmd <slug> "<Nom>" [email]
cd /d %~dp0
docker compose exec -T php sh scripts/claim-center.sh %*
