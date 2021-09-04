# ShareBookmarksApi

holidays-webhook のサーバーサイド

## FW

- [Laravel](http://laravel.jp/)

## 環境構築

### インストール

- VSCode
- Docker for Mac

### 実行

基本的に Docker の PHP を使うためローカルの PHP のバージョン変更は不要

```
docker compose up --build -d web db
```

### テスト

```
docker compose exec -T web php artisan test
```

### ローカルで Google 認証

- `lib` 配下に `google_client_secret` を配置
  - `google_client_secret` は管理者が必要に応じて渡す
- `php lib/google_client_export.php` を実行
- `./cache_clear.sh` を実行（必要に応じて）

## EndPoint

### web

### api

## テスト

master にプッシュすると GitHub Actions によって[ GitHub Pages ](https://bvlion.github.io/holidays-webhook-server/index.html)にテスト結果がアップされる  