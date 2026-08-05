# 開発用Composeプロジェクト。ディレクトリ名から決まる既定のプロジェクト名と
# 同じ値を明示しているため、setup/up/stop/rebuild/test/db-* の挙動は
# これまでと変わらない。
DEV_PROJECT = holidays-webhook-server
# 検証用（make check）専用のCompose project名。開発用とは常に異なる名前を
# 使うことで、container・network・volumeが開発環境と混ざらないようにする。
CHECK_PROJECT = holidays-webhook-server-check

DOCKER_COMPOSE = docker compose -p $(DEV_PROJECT)
# 検証専用のCompose構成（docker-compose.check.yml）を、検証専用のproject名で
# 実行する。開発用のcontainer・network・volume・host portには一切触れない。
CHECK_COMPOSE = docker compose -f docker-compose.check.yml -p $(CHECK_PROJECT)

BACKUP_DIR = backups
BACKUP_FILE = $(BACKUP_DIR)/hw-$(shell date +%Y%m%d%H%M%S).sql

.PHONY: setup environment local-environment-guard up stop rebuild test check check-clean db-backup db-restore db-wipe

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

# 開発用web/dbコンテナ（起動中でも停止中でもよい）には一切触れず、
# docker-compose.check.yml を検証専用のCompose project（$(CHECK_PROJECT)）で
# 実行する。成功・失敗にかかわらず、最後に検証専用projectだけをcleanupする。
check: local-environment-guard
	trap '$(CHECK_COMPOSE) down --volumes --remove-orphans' EXIT; \
	$(CHECK_COMPOSE) build web db db-check && \
	$(CHECK_COMPOSE) run --rm --no-deps --user "$$(id -u):$$(id -g)" --env COMPOSER_HOME=/tmp/composer --env XDEBUG_MODE=off web composer install --no-interaction --prefer-dist && \
	$(CHECK_COMPOSE) up --detach web db && \
	$(CHECK_COMPOSE) run --rm --no-deps db-check && \
	$(CHECK_COMPOSE) exec -T --env XDEBUG_MODE=off web php artisan db:seed --force && \
	$(CHECK_COMPOSE) config --quiet && \
	$(CHECK_COMPOSE) exec -T --env XDEBUG_MODE=off web php --version && \
	$(CHECK_COMPOSE) exec -T --env XDEBUG_MODE=off web composer --version && \
	$(CHECK_COMPOSE) exec -T --env XDEBUG_MODE=off web php -r 'exit(array_diff(["pdo_mysql", "intl", "gd", "zip"], get_loaded_extensions()) === [] ? 0 : 1);' && \
	$(CHECK_COMPOSE) exec -T --env XDEBUG_MODE=off web composer check-platform-reqs --no-dev && \
	$(CHECK_COMPOSE) exec -T --env XDEBUG_MODE=off web composer check

# make check が失敗などで異常終了した場合の手動cleanup用。検証専用project
# だけを対象とし、開発用のcontainer・network・volumeには触れない。
check-clean:
	$(CHECK_COMPOSE) down --volumes --remove-orphans

# 開発用DBを mysqldump でバックアップする。$(BACKUP_DIR) はGit管理対象外。
db-backup: local-environment-guard
	@mkdir -p $(BACKUP_DIR)
	$(DOCKER_COMPOSE) exec -T db sh -c 'exec mysqldump -u root -p"$$MYSQL_ROOT_PASSWORD" hw' > $(BACKUP_FILE)
	@echo "開発用DBを $(BACKUP_FILE) へバックアップしました。"

# 開発用DBを指定したバックアップファイルから復元する（既存データを上書きする）。
# 使い方: make db-restore FILE=backups/hw-20260101120000.sql
db-restore: local-environment-guard
	@if [ -z "$(FILE)" ]; then \
		echo "使い方: make db-restore FILE=backups/hw-xxxxxxxxxxxxxx.sql" >&2; \
		exit 1; \
	fi
	@if [ ! -f "$(FILE)" ]; then \
		echo "指定されたバックアップファイルが見つかりません: $(FILE)" >&2; \
		exit 1; \
	fi
	$(DOCKER_COMPOSE) exec -T db sh -c 'exec mysql -u root -p"$$MYSQL_ROOT_PASSWORD" hw' < $(FILE)
	@echo "$(FILE) から開発用DBを復元しました。"

# 開発用DBを完全に初期化する（既存データを完全に削除し、docker/db/sql の定義
# から作り直す）破壊的な操作。誤実行防止のため CONFIRM=yes を必須にする。
# 対象は開発用DBのvolumeだけであり、検証専用project（$(CHECK_PROJECT)）や
# 開発用webコンテナには影響しない。
db-wipe: local-environment-guard
	@if [ "$(CONFIRM)" != "yes" ]; then \
		echo "この操作は開発用データベース（$(DEV_PROJECT)）のデータを完全に削除します。" >&2; \
		echo "実行する場合は次のように明示的に指定してください: make db-wipe CONFIRM=yes" >&2; \
		echo "事前に make db-backup でバックアップを取ることを推奨します。" >&2; \
		exit 1; \
	fi
	$(DOCKER_COMPOSE) down --volumes db
	$(DOCKER_COMPOSE) up --detach db
	$(DOCKER_COMPOSE) run --rm --no-deps db-check
	@echo "開発用DBを初期化しました（docker/db/sql の定義から作り直しました）。"
