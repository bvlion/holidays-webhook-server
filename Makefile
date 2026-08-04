DOCKER_COMPOSE = docker compose

.PHONY: setup environment local-environment-guard up stop rebuild test check

setup: local-environment-guard
	$(DOCKER_COMPOSE) build web db db-check
	$(DOCKER_COMPOSE) run --rm --no-deps --user "$$(id -u):$$(id -g)" --env COMPOSER_HOME=/tmp/composer --env XDEBUG_MODE=off web composer install --no-interaction --prefer-dist
	$(DOCKER_COMPOSE) up --detach web db
	$(DOCKER_COMPOSE) run --rm --no-deps db-check
	$(DOCKER_COMPOSE) exec -T --env XDEBUG_MODE=off web php artisan db:seed --force

environment:
	@if [ ! -f src/.env ]; then cp .env.example src/.env; fi

local-environment-guard: environment
	@if { [ -n "$${APP_ENV+x}" ] && [ "$${APP_ENV}" != "local" ]; } \
		|| { [ -n "$${DB_CONNECTION+x}" ] && [ "$${DB_CONNECTION}" != "mysql" ]; } \
		|| { [ -n "$${DB_HOST+x}" ] && [ "$${DB_HOST}" != "db" ]; } \
		|| { [ -n "$${DB_PORT+x}" ] && [ "$${DB_PORT}" != "3306" ]; } \
		|| { [ -n "$${DB_DATABASE+x}" ] && [ "$${DB_DATABASE}" != "hw" ]; } \
		|| { [ -n "$${DB_USERNAME+x}" ] && [ "$${DB_USERNAME}" != "user" ]; } \
		|| [ -n "$${DATABASE_URL+x}" ]; then \
		echo "ローカル環境ガードに失敗したため処理を中止しました。" >&2; \
		exit 1; \
	fi
	@awk '\
		BEGIN { \
			expected["APP_ENV"] = "local"; \
			expected["DB_CONNECTION"] = "mysql"; \
			expected["DB_HOST"] = "db"; \
			expected["DB_PORT"] = "3306"; \
			expected["DB_DATABASE"] = "hw"; \
			expected["DB_USERNAME"] = "user"; \
		} \
		{ \
			line = $$0; \
			sub(/\r$$/, "", line); \
			if (line ~ /^[[:space:]]*(#|$$)/) next; \
			separator = index(line, "="); \
			if (separator == 0) next; \
			key = substr(line, 1, separator - 1); \
			value = substr(line, separator + 1); \
			gsub(/^[[:space:]]+|[[:space:]]+$$/, "", key); \
			gsub(/^[[:space:]]+|[[:space:]]+$$/, "", value); \
			if (key == "DATABASE_URL") invalid = 1; \
			if (key in expected) { \
				seen[key]++; \
				if (value != expected[key]) invalid = 1; \
			} \
		} \
		END { \
			for (key in expected) { \
				if (seen[key] != 1) invalid = 1; \
			} \
			if (invalid) { \
				print "ローカル環境ガードに失敗したため処理を中止しました。" > "/dev/stderr"; \
				exit 1; \
			} \
		}' src/.env
	@rm -f src/bootstrap/cache/config.php

up: local-environment-guard
	$(DOCKER_COMPOSE) up --detach web db
	$(DOCKER_COMPOSE) run --rm --no-deps db-check

stop:
	$(DOCKER_COMPOSE) stop web db

rebuild: local-environment-guard
	$(DOCKER_COMPOSE) build --no-cache web
	$(DOCKER_COMPOSE) up --detach web db
	$(DOCKER_COMPOSE) run --rm --no-deps db-check

test: local-environment-guard
	$(DOCKER_COMPOSE) exec -T --env XDEBUG_MODE=off web php artisan test

check: local-environment-guard setup
	$(DOCKER_COMPOSE) config --quiet
	$(DOCKER_COMPOSE) exec -T --env XDEBUG_MODE=off web php --version
	$(DOCKER_COMPOSE) exec -T --env XDEBUG_MODE=off web composer --version
	$(DOCKER_COMPOSE) exec -T --env XDEBUG_MODE=off web php -r 'exit(array_diff(["pdo_mysql", "intl", "gd", "zip"], get_loaded_extensions()) === [] ? 0 : 1);'
	$(DOCKER_COMPOSE) exec -T --env XDEBUG_MODE=off web composer check-platform-reqs --no-dev
	$(DOCKER_COMPOSE) exec -T --env XDEBUG_MODE=off web composer check
