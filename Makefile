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
# mysqldumpの出力はいったん $(BACKUP_DIR) 内の一時ファイルへ書き出し、
# 成功かつ0バイトでないことを確認できた場合だけ日時付きの最終ファイル名へ
# mv する。一時ファイルは trap で確実に削除する対象にし、最終ファイルへの
# mv が成功した場合だけ trap を解除する。mv 自体の成否も明示的に確認し、
# 失敗時・最終ファイルの存在/非空を確認できない場合は成功メッセージを
# 表示せず終了コード0以外で終了する。
db-backup: local-environment-guard
	@mkdir -p $(BACKUP_DIR)
	@tmp="$$(mktemp "$(BACKUP_DIR)/.tmp-XXXXXX")"; \
	trap 'rm -f "$$tmp"' EXIT; \
	if ! $(DOCKER_COMPOSE) exec -T db sh -c 'exec mysqldump -u root -p"$$MYSQL_ROOT_PASSWORD" hw' > "$$tmp"; then \
		echo "[中止] mysqldumpに失敗したためバックアップを中止しました。" >&2; \
		exit 1; \
	fi; \
	if [ ! -s "$$tmp" ]; then \
		echo "[中止] バックアップの出力が空だったため中止しました。" >&2; \
		exit 1; \
	fi; \
	if ! mv "$$tmp" "$(BACKUP_FILE)"; then \
		echo "[中止] バックアップファイルの保存(mv)に失敗しました。" >&2; \
		exit 1; \
	fi; \
	trap - EXIT; \
	if [ -f "$(BACKUP_FILE)" ] && [ -s "$(BACKUP_FILE)" ]; then \
		echo "開発用DBを $(BACKUP_FILE) へバックアップしました。"; \
	else \
		echo "[中止] バックアップファイル $(BACKUP_FILE) の保存を確認できませんでした。" >&2; \
		exit 1; \
	fi

# 開発用DBを指定したバックアップファイルから復元する（既存データを上書きする）。
# 使い方: make db-restore FILE=backups/hw-20260101120000.sql
# 指定ファイルが通常ファイルとして存在し、かつ0バイトでないことを確認してから
# 投入する。MySQLへの投入が失敗した場合は成功メッセージを表示しない。
db-restore: local-environment-guard
	@if [ -z "$(FILE)" ]; then \
		echo "使い方: make db-restore FILE=backups/hw-xxxxxxxxxxxxxx.sql" >&2; \
		exit 1; \
	fi
	@if [ ! -f "$(FILE)" ]; then \
		echo "指定されたバックアップファイルが見つかりません（通常ファイルではありません）: $(FILE)" >&2; \
		exit 1; \
	fi
	@if [ ! -s "$(FILE)" ]; then \
		echo "指定されたバックアップファイルが空です: $(FILE)" >&2; \
		exit 1; \
	fi
	@if $(DOCKER_COMPOSE) exec -T db sh -c 'exec mysql -u root -p"$$MYSQL_ROOT_PASSWORD" hw' < "$(FILE)"; then \
		echo "$(FILE) から開発用DBを復元しました。"; \
	else \
		echo "$(FILE) からの復元に失敗しました。" >&2; \
		exit 1; \
	fi

# 開発用DBを完全に初期化する（既存データを完全に削除し、docker/db/sql の定義
# から作り直す）破壊的な操作。誤実行防止のため CONFIRM=yes を必須にする。
#
# 開発用Composeプロジェクトに対する `down --volumes` は使わない（webコンテナ
# を含む全サービスに影響しうるため）。代わりに、Composeが自動で付与する
# `com.docker.compose.project` / `com.docker.compose.volume` ラベルで
# 開発用DBのvolumeを1件だけ特定し、そのvolumeだけを明示的に削除する。
# 該当が0件または複数件の場合は、削除を行わずエラーで停止する。
# volumeの検索自体もCONFIRM=yesの確認後（このtargetの実行時）にだけ行い、
# 他のtargetの実行や `make` の読み込み時には `docker volume ls` を呼ばない。
# 各段階（停止・削除・volume削除・削除確認・再作成・db-check）の成否を
# 明示的に確認し、途中で失敗した場合はその時点で中止して成功メッセージを
# 表示しない。dbサービスのコンテナだけを対象とし、webコンテナや検証用
# Composeプロジェクトには一切触れない。
db-wipe: local-environment-guard
	@if [ "$(CONFIRM)" != "yes" ]; then \
		echo "この操作は開発用データベース（$(DEV_PROJECT)）のデータを完全に削除します。" >&2; \
		echo "実行する場合は次のように明示的に指定してください: make db-wipe CONFIRM=yes" >&2; \
		echo "事前に make db-backup でバックアップを取ることを推奨します。" >&2; \
		exit 1; \
	fi
	@volume="$$(docker volume ls \
		--filter "label=com.docker.compose.project=$(DEV_PROJECT)" \
		--filter "label=com.docker.compose.volume=db_data" \
		--format '{{.Name}}')"; \
	count="$$(printf '%s\n' "$$volume" | grep -c .)"; \
	if [ "$$count" -ne 1 ]; then \
		echo "[中止] 開発用DB(project=$(DEV_PROJECT))のvolumeを1件だけ特定できませんでした（該当: $$count 件）。" >&2; \
		exit 1; \
	fi; \
	echo "削除対象volume: $$volume"; \
	if ! $(DOCKER_COMPOSE) stop db; then \
		echo "[中止] 開発用dbサービスの停止に失敗しました。" >&2; \
		exit 1; \
	fi; \
	if ! $(DOCKER_COMPOSE) rm --force db; then \
		echo "[中止] 開発用dbコンテナの削除に失敗しました。" >&2; \
		exit 1; \
	fi; \
	if ! docker volume rm "$$volume"; then \
		echo "[中止] volume $$volume の削除に失敗しました。DBは初期化されていません。" >&2; \
		exit 1; \
	fi; \
	if docker volume inspect "$$volume" >/dev/null 2>&1; then \
		echo "[中止] volume $$volume の削除を確認できませんでした。DBは初期化されていません。" >&2; \
		exit 1; \
	fi; \
	if ! $(DOCKER_COMPOSE) up --detach db; then \
		echo "[中止] 開発用dbコンテナの再作成に失敗しました。" >&2; \
		exit 1; \
	fi; \
	if ! $(DOCKER_COMPOSE) run --rm --no-deps db-check; then \
		echo "[中止] 初期化後の開発用DBの起動確認(db-check)に失敗しました。" >&2; \
		exit 1; \
	fi; \
	echo "開発用DBを初期化しました（docker/db/sql の定義から作り直しました）。"
