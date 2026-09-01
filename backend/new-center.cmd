@echo off
REM SkyBook - nouveau centre : new-center.cmd <slug> "<Nom>" [email]
cd /d %~dp0
docker compose exec -T php sh scripts/new-center.sh %*
