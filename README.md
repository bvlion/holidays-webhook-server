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

コンテナを削除せずに停止するため、ローカルデータベースの内容は保持される。現在のデータベースはコンテナ内にデータを保持するため、データを残す必要がある場合は `docker compose down` を使用しない。

### Webイメージの再構築

```shell
make rebuild
```

Webイメージだけをキャッシュなしで再構築する。データベースコンテナは再作成しない。

### テスト

```shell
make test
```

環境構築からPHP・Composer・必要なPHP拡張・フォーマット確認・静的解析・テストまでをまとめて確認する場合は、次を実行する。

```shell
make check
```

`make check` は最後に `composer check`（`composer format:check` → `composer analyse` → `php artisan test`）を実行する。フォーマットと静的解析だけを個別に確認・修正する場合は、`src` 配下でそれぞれ次を使う。

```shell
composer format       # Laravel Pintでコードを整形する
composer format:check # 整形せず、フォーマット違反があれば失敗する
composer analyse      # PHPStan/Larastanを実行する
```

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
