.PHONY: run test-business

DOCKER_COMPOSE ?= docker compose
# Avoid using root (0:0) as DOCKER_USER, especially in WSL2 environments
# If id -u returns 0, fall back to 1000:1000 to prevent fixuid/PHP-FPM issues
DOCKER_USER ?= $(shell if [ "$$(id -u)" = "0" ]; then echo "1000:1000"; else echo "$$(id -u):$$(id -g)"; fi)
ENV ?= "dev"

init:
	@make -s docker-compose-check
	@if [ "$$(id -u)" = "0" ]; then \
		echo "Running as root (WSL2/Linux) - preparing permissions for Docker containers..."; \
		chmod -R 777 var/ public/ 2>/dev/null || true; \
		chown -R 1000:1000 vendor/ node_modules/ 2>/dev/null || true; \
	fi
	@if [ ! -e compose.override.yml ]; then \
		cp compose.override.dist.yml compose.override.yml; \
	fi
	@ENV=$(ENV) DOCKER_USER=$(DOCKER_USER) $(DOCKER_COMPOSE) run --rm php composer install --no-interaction --no-scripts
	@ENV=$(ENV) DOCKER_USER=$(DOCKER_USER) $(DOCKER_COMPOSE) run --rm nodejs
	@make -s install
	@ENV=$(ENV) DOCKER_USER=$(DOCKER_USER) $(DOCKER_COMPOSE) up -d

run:
	@make -s up

# Suite métier isolée : MySQL éphémère/dédié, transports synchrones et aucun
# appel à un service de production. Une erreur PHPUnit conserve son contexte.
test-business:
	@ENV=test DOCKER_USER=$(DOCKER_USER) $(DOCKER_COMPOSE) -f compose.yml -f compose.override.yml -f compose.test.yml run --rm php composer install --no-interaction --no-scripts
	@ENV=test DOCKER_USER=$(DOCKER_USER) $(DOCKER_COMPOSE) -f compose.yml -f compose.override.yml -f compose.test.yml run --rm php bin/console lexik:jwt:generate-keypair --skip-if-exists --no-interaction
	@ENV=test DOCKER_USER=$(DOCKER_USER) $(DOCKER_COMPOSE) -f compose.yml -f compose.override.yml -f compose.test.yml run --rm php sh -c 'test -s config/encryption/test.key || openssl rand -hex 32 -out config/encryption/test.key'
	@ENV=test DOCKER_USER=$(DOCKER_USER) $(DOCKER_COMPOSE) -f compose.yml -f compose.override.yml -f compose.test.yml run --rm php bin/console doctrine:schema:drop --full-database --force
	@ENV=test DOCKER_USER=$(DOCKER_USER) $(DOCKER_COMPOSE) -f compose.yml -f compose.override.yml -f compose.test.yml run --rm php bin/console sylius:install --no-interaction
	@ENV=test DOCKER_USER=$(DOCKER_USER) $(DOCKER_COMPOSE) -f compose.yml -f compose.override.yml -f compose.test.yml run --rm php vendor/bin/phpunit --configuration phpunit.business.xml --testdox

debug:
	@ENV=$(ENV) DOCKER_USER=$(DOCKER_USER) $(DOCKER_COMPOSE) -f compose.yml -f compose.override.yml -f compose.debug.yml up -d

up:
	@ENV=$(ENV) DOCKER_USER=$(DOCKER_USER) $(DOCKER_COMPOSE) up -d

down:
	@ENV=$(ENV) DOCKER_USER=$(DOCKER_USER) $(DOCKER_COMPOSE) down

install:
	@ENV=$(ENV) DOCKER_USER=$(DOCKER_USER) $(DOCKER_COMPOSE) run --rm php bin/console sylius:install -s default -n

clean:
	@ENV=$(ENV) DOCKER_USER=$(DOCKER_USER) $(DOCKER_COMPOSE) down -v

php-shell:
	@ENV=$(ENV) DOCKER_USER=$(DOCKER_USER) $(DOCKER_COMPOSE) exec php sh

node-shell:
	@ENV=$(ENV) DOCKER_USER=$(DOCKER_USER) $(DOCKER_COMPOSE) run --rm -i nodejs sh

node-watch:
	@ENV=$(ENV) DOCKER_USER=$(DOCKER_USER) $(DOCKER_COMPOSE) run --rm -i nodejs "npm run watch"

docker-compose-check:
	@$(DOCKER_COMPOSE) version >/dev/null 2>&1 || (echo "Please install docker compose binary or set DOCKER_COMPOSE=\"docker-compose\" for legacy binary" && exit 1)
	@echo "You are using \"$(DOCKER_COMPOSE)\" binary"
	@echo "Current version is \"$$($(DOCKER_COMPOSE) version)\""
