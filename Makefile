.PHONY: build deploy test

build:
	@./scripts/deploy.sh build

deploy:
	@./scripts/deploy.sh deploy

test:
	@npm --prefix frontend run test:unit
	@npm --prefix frontend run test:production
