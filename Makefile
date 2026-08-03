DOCKER_COMPOSE = docker compose

.PHONY: setup environment up stop rebuild test check

setup: environment
	$(DOCKER_COMPOSE) build web db db-check
	$(DOCKER_COMPOSE) run --rm --no-deps --user "$$(id -u):$$(id -g)" --env COMPOSER_HOME=/tmp/composer --env XDEBUG_MODE=off web composer install --no-interaction --prefer-dist
	$(DOCKER_COMPOSE) up --detach web db
	$(DOCKER_COMPOSE) run --rm --no-deps db-check
	$(DOCKER_COMPOSE) exec -T --env XDEBUG_MODE=off web php artisan db:seed --force

environment:
	@if [ ! -f src/.env ]; then cp .env.example src/.env; fi

up: environment
	$(DOCKER_COMPOSE) up --detach web db
	$(DOCKER_COMPOSE) run --rm --no-deps db-check

stop:
	$(DOCKER_COMPOSE) stop web db

rebuild: environment
	$(DOCKER_COMPOSE) build --no-cache web
	$(DOCKER_COMPOSE) up --detach web db
	$(DOCKER_COMPOSE) run --rm --no-deps db-check

test:
	$(DOCKER_COMPOSE) exec -T --env XDEBUG_MODE=off web php artisan test

check: setup
	$(DOCKER_COMPOSE) config --quiet
	$(DOCKER_COMPOSE) exec -T --env XDEBUG_MODE=off web php --version
	$(DOCKER_COMPOSE) exec -T --env XDEBUG_MODE=off web composer --version
	$(DOCKER_COMPOSE) exec -T --env XDEBUG_MODE=off web php -r 'exit(array_diff(["pdo_mysql", "intl", "gd", "zip"], get_loaded_extensions()) === [] ? 0 : 1);'
	$(DOCKER_COMPOSE) exec -T --env XDEBUG_MODE=off web composer check-platform-reqs --no-dev
	$(DOCKER_COMPOSE) exec -T --env XDEBUG_MODE=off web php artisan test
