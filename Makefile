.PHONY: build deploy deploy-backend test

build:
	@./scripts/deploy.sh build

deploy:
	@./scripts/deploy.sh deploy

deploy-backend:
	@./scripts/deploy.sh deploy-backend

test:
	@npm --prefix frontend run test:unit
	@npm --prefix frontend run test:production
