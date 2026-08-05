# holidays-webhook-server

holidays-webhook のサーバーサイド

## FW

- [Laravel](http://laravel.jp/)

## 環境構築

### インストール

- Docker DesktopまたはDocker Engine
- Docker Compose v2
- Make

ローカル環境では、Docker内のPHP 8.2.30、Composer 2.8.12、MySQL 5.7.35を使用する。ホスト側にPHPやComposerをインストールする必要はない。

### 初回起動

```shell
make setup
```

このコマンドは、次を順番に実行する。

1. `src/.env` がない場合だけ、`.env.example` から作成する。
2. Dockerイメージをビルドする。
3. WebコンテナのPHPと固定版Composerで依存関係を導入する。
4. Webサーバーとデータベースを起動し、データベースの応答を待つ。
5. 初回のMySQLコンテナ作成時に `docker/db/sql` のテーブル定義を適用し、データベースシーダーを実行する。現在のシーダーはデータを投入しない。

`.env.example` の値はローカル開発・テスト専用であり、本番環境では使用しない。Google連携を使用しない起動とテストに外部の秘密情報は不要である。

`make setup` は再実行できる。既存の `src/.env` を上書きせず、既存のデータベースコンテナを再利用する。

### ローカル接続先ガード

`make setup`、`make up`、`make rebuild`、`make test`、`make check` は、Dockerやアプリケーションを実行する前に `src/.env` と同名の実行環境変数を検査する。次のローカル固定値だけを許可し、条件を満たさない場合は設定値を表示せずに中止する。

- `APP_ENV=local`
- `DB_CONNECTION=mysql`
- `DB_HOST=db`
- `DB_PORT=3306`
- `DB_DATABASE=hw`
- `DB_USERNAME=user`
- `DATABASE_URL`が存在しない

ガード通過後、Laravelが古い接続設定を使用しないよう `src/bootstrap/cache/config.php` を削除してから処理を続ける。本番または外部データベース用の `.env` では、これらのローカル開発用コマンドを実行できない。

### 通常起動

```shell
make up
```

既存のWebサーバーとデータベースを起動し、データベースの応答を待つ。

### 停止

```shell
make stop
```

コンテナを削除せずに停止するため、ローカルデータベースの内容は保持される。MySQLのデータは `/var/lib/mysql` をnamed volumeへ保存しているため（後述）、`docker compose down`（`--volumes` を付けない場合）やコンテナの再作成でもデータは失われない。

### Webイメージの再構築

```shell
make rebuild
```

Webイメージだけをキャッシュなしで再構築する。データベースコンテナは再作成しない。

### 開発用データベースの永続化

MySQLのデータディレクトリ `/var/lib/mysql` は、`docker-compose.yml` で `db_data` というnamed volumeへ保存している（Issue #233）。このvolumeには固定名(`name:`)を指定していないため、Composeのproject名（既定では `holidays-webhook-server`）ごとに別のvolumeとして扱われる。

- `make stop` → `make up`、`make rebuild`、通常の `docker compose down`（`--volumes` なし）、`db` コンテナの再作成では、`db_data` は削除されずデータが保持される。
- データを完全に削除するのは、`--volumes` を明示的に付けた `docker compose down`、`docker volume rm`、または後述の `make db-wipe` を実行した場合だけである。
- `make check`（後述）は開発用とは別のCompose project・別のDB（volumeなし・使い捨て）を使うため、`make check` を実行しても開発用DBのデータには影響しない。

#### バックアップ

```shell
make db-backup
```

`docker compose exec db mysqldump` の出力を `backups/` 内の一時ファイルへ書き出し、成功かつ0バイトでないことを確認できた場合だけ `backups/hw-<日時>.sql` へ`mv`する（`backups/` はGit管理対象外）。`mysqldump`が失敗した場合や出力が空だった場合は一時ファイルを削除し、成功メッセージは表示しない。

#### 復元

```shell
make db-restore FILE=backups/hw-20260101120000.sql
```

指定したバックアップファイルの内容を開発用DBへ流し込む。既存データを上書きするため、`FILE` は必ず明示的に指定する必要がある（省略時はエラーで停止する）。指定ファイルが通常ファイルとして存在し、かつ0バイトでないことを確認してから投入する。MySQLへの投入が失敗した場合は成功メッセージを表示しない。

#### 完全初期化（危険な操作）

```shell
make db-wipe CONFIRM=yes
```

開発用DBのvolumeを削除し、`docker/db/sql` の定義から作り直す。**開発用DBの内容がすべて失われる。** 誤実行を防ぐため `CONFIRM=yes` を明示しない限り実行されない。実行前に `make db-backup` でバックアップを取ることを推奨する。

開発用Composeプロジェクトに対する `docker compose down --volumes` は使わない。Composeが自動で付与する `com.docker.compose.project`／`com.docker.compose.volume` ラベルで開発用DBのvolumeを1件だけ特定し、該当が0件または複数件の場合は削除せずエラーで停止する。特定できた場合だけ `db` サービスのコンテナとそのvolumeを削除して作り直すため、開発用`web`コンテナや、`make check` が使う検証専用environment（Compose project）には一切触れない。

**この操作（および `docker volume rm`、`docker compose down --volumes` など、開発用volumeやコンテナを削除しうるDocker操作全般）は、利用者から明示的な許可を得た場合を除き、AIエージェントや自動化から無断で実行してはならない。** 検証や調査の過程で誤って実行してしまった場合、実行してよいか判断がつかない場合、既存の開発環境と衝突しそうな場合は、その時点で作業を止め、状況を日本語で具体的に報告すること。

### 検証環境の分離（`make check`）

`make check` は、開発用とは異なるCompose project（既定では `holidays-webhook-server-check`）と `docker-compose.check.yml` を使って、依存関係の導入からテストまでを実行する（Issue #233）。

- 開発用のcontainer・network・volume・host portには一切触れない。開発環境を起動したままでも、停止したままでも実行できる。
- 検証用のcontainer・networkは開発用と別名になり、host portは公開しない。
- 検証用DBはnamed volumeを持たない使い捨てで、`make check` の成功・失敗にかかわらず、検証用Compose projectだけを対象にcleanup（`docker compose down --volumes --remove-orphans`）する。
- 一時的な `docker-compose.override.yml` は使用しない。検証用の構成は `docker-compose.check.yml` としてリポジトリに含まれている。

### テスト

```shell
make test
```

環境構築からPHP・Composer・必要なPHP拡張・フォーマット確認・静的解析・テストまでをまとめて確認する場合は、次を実行する。

```shell
make check
```

`make check` は最後に `composer check`（`composer:validate` → `composer:audit` → `composer:prod-check` → `composer format:check` → `composer analyse` → `php artisan test`）を実行する。どれか1つでも失敗すると後続は実行されない。個別に確認・修正する場合は、`src` 配下でそれぞれ次を使う。

```shell
composer composer:validate  # composer.json/composer.lockの整合性をstrictに検証する
composer composer:audit     # 監査ラッパー(scripts/audit-guard.php)経由でcomposer.lockの既知の脆弱性を監査する
composer composer:prod-check # 本番向け(--no-dev)の依存関係が解決できるかをdry-runで確認する（vendorは変更しない）
composer format              # Laravel Pintでコードを整形する
composer format:check        # 整形せず、フォーマット違反があれば失敗する
composer analyse             # PHPStan/Larastanを実行する
```

`composer:audit` は素の`composer audit`を直接呼ばず、`scripts/audit-guard.php`という監査ラッパーを経由する（Issue #214）。Composer 2.8.12はPackagistへのアドバイザリ取得自体に失敗した場合、例外を捕捉せず異常終了（終了コード100・標準出力は空）する挙動を隔離環境で確認済みだが、この挙動に依存せず将来のComposerの変更にも耐えられるよう、ラッパーは`composer audit --locked --format=json`の終了コードとJSON構造の両方を検証し、次のいずれかに該当する場合は「監査取得失敗」として失敗させる。

- 終了コードが正常完了時のビットマスク(0/1/2/3)以外
- 標準出力が空、またはJSONとして解析できない
- `advisories`・`abandoned`フィールドが欠落している

これにより、「監査に成功しbaseline外の脆弱性がなかった」場合と「アドバイザリ取得先に到達できず結果を取得できなかった」場合を区別し、後者を「脆弱性なし」として誤成功させない。

`composer:audit`（ラッパー）は、Issue #214時点で既知だった脆弱性アドバイザリ（直接依存の`guzzlehttp/guzzle`・`laravel/framework`・`phpunit/phpunit`と、いくつかの間接依存）を`composer.json`の`config.audit.ignore`へ理由付きで記録し、新規のアドバイザリが増えた場合だけ失敗する構成にしている。abandoned package（`fruitcake/laravel-cors`、`swiftmailer/swiftmailer`）は結果に表示されるが、依存関係の置き換えを伴うためCIは失敗させない（`config.audit.abandoned=report`）。既知の脆弱性の詳細と対応方針、および44件のbaseline解消を追跡するフォローアップIssueはIssue #214のPull Request（#235）を参照。

### ローカルで Google 認証

- `lib` 配下に `google_client_secret` を配置
  - `google_client_secret` は管理者が必要に応じて渡す
- `php lib/google_client_export.php` を実行
- `./cache_clear.sh` を実行（必要に応じて）

## EndPoint

### web

- [redoc](/src/redoc)
  - Docker を立ち上げ [/doc](http://localhost:8000/doc) にアクセスする
  - 本番も同等

### api

- [calendar](/doc/api/calendar.md)
- [exec](/doc/api/exec.md)

## テスト

Pull Requestの作成・更新、mainへのpush、Dependabotが作成するPull Requestでは、GitHub Actionsがローカルと同じ固定PHP 8.2.30・Composer 2.8.12・MySQL 5.7.35で `make check` を実行する。`make check` はLaravel Pintによるフォーマット確認、PHPStan/Larastanによる静的解析、既存テストを実行するため、フォーマット違反または新規の静的解析違反があるとCIは失敗する。mainにプッシュした場合だけ、テスト結果を[GitHub Pages](https://bvlion.github.io/holidays-webhook-server/index.html)へアップし、Slackへ通知する。
