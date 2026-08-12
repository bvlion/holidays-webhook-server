# AGENTS.md

Codex等のAIエージェントがこのリポジトリで作業する際に守るべきルールを簡潔にまとめる。設計の詳細は末尾の参照先ドキュメントを見ること。ここに書かれていない判断が必要な場合は、推測で進めず一旦止まって報告する。

## サービスの目的

`holidays-webhook-server` は、ユーザーまたはグループに紐づく外部HTTPリクエスト定義（コマンド）を、APIから手動実行、または時刻・曜日・祝日条件に基づく定期実行（時間トリガー）で呼び出すLaravelアプリケーションである。Google認証でユーザー・グループを登録し、Google Calendar由来の祝日情報と個別カレンダー上書きを使って実行可否を判定する。

## 主要な責務と対象外

責務・対象外の一覧は [`docs/current-architecture.md`](docs/current-architecture.md) の「2. アプリケーションの責務」「3. 対象外または未実装の機能」に詳しい。要点だけ書く。

- 対応する: Google認証、コマンド（外部HTTPリクエスト定義）のCRUDと実行、時間トリガーによる定期実行、祝日判定、個別カレンダー上書き、ワンタイムスキップ、実行結果の保存。
- 対応していない: SSID関連の実処理、FCM通知の送信、端末モード変更、キュー非同期処理、画面UI（ReDoc以外）。関連テーブル・コードの一部は存在するが、完結した経路がない。これらを「実装済み」として扱わないこと。

## 主要ディレクトリと責務

| パス | 責務 |
| --- | --- |
| `src/app/Http/Controllers/Api/` | 認証必須API（コマンド、実行、休日判定、ワンタイムスキップ） |
| `src/app/Http/Controllers/Web/` | Google認証コールバック、ReDoc表示 |
| `src/app/Console/Commands/` | `holidays:update`・`time:trigger`・`results:delete`（Schedulerから起動） |
| `src/app/Models/` | Eloquentモデル。`SoftDeletes`は未使用、`deleted_at`は各処理が個別に扱う |
| `src/app/Libs/HolidayList.php` | Google Calendar APIからの祝日取得とファイルキャッシュ |
| `docker/db/sql/` | ローカルMySQL初回起動時のテーブル定義（Laravelマイグレーションは存在しない） |
| `docker/` | ローカル・CI用のWeb/DB/checkerイメージ定義 |
| `.github/workflows/` | test・deploy・auto-merge（Dependabot/通常PR共通） |
| `docs/` | 本リポジトリの調査・運用資料一式 |

## 実装時に参照すべきドキュメント

- 現行実装の詳細な棚卸し: [`docs/current-architecture.md`](docs/current-architecture.md)
- 継続運用向けの整理版アーキテクチャ: [`docs/architecture.md`](docs/architecture.md)
- ドメインルール: [`docs/domain.md`](docs/domain.md)
- ローカル・CI・本番の運用詳細: [`docs/current-operations.md`](docs/current-operations.md)
- Pull Requestレビュー運用: [`docs/pull-request-review.md`](docs/pull-request-review.md)
- 秘密情報の扱い: [`docs/secrets-management.md`](docs/secrets-management.md)
- 依存関係更新（Dependabot）の運用: [`docs/dependency-updates.md`](docs/dependency-updates.md)
- セットアップ・日常コマンド: [`README.md`](README.md)

`docs/current-architecture.md`・`docs/current-operations.md`は特定時点の詳細調査ログであり、細部が現状とずれている場合がある。実装に踏み込む前に、対象箇所だけ最新mainのコードで裏取りすること。

## コーディング・フォーマット・静的解析の方針

- コードは `src/` 配下のLaravelアプリケーションに置く。既存の薄いController・素のEloquentモデルというスタイルに合わせ、不要な抽象化やレイヤーを追加しない。
- フォーマットはLaravel Pint（`composer format` / `composer format:check`）、静的解析はPHPStan/Larastan（`composer analyse`）に従う。どちらも `make check` に含まれる。
- 新しい依存や設計パターンを持ち込む前に、同種の既存コード（Controller・Model・Console Command）の書き方を踏襲する。

## テスト追加・変更時の方針

- テストは `src/tests/Feature`・`src/tests/Unit` に配置し、`php artisan test`（`make test`）または `make check` で実行する。
- 挙動を変更した場合は対応するテストを追加・更新する。テスト件数を文書やコミットメッセージに固定値として書かない（件数は変動する）。
- 一部のFeature TestはMySQL接続を必要とする。テストは `make check` の検証専用DB（後述）で実行し、開発用DBに依存させない。

## 依存関係追加・更新時の方針

- 新しい依存関係は、対象Issueで必要性がある場合のみ追加する。対象Issueに不要な依存追加を先回りして行わない。既存依存の更新やDependabot運用は [`docs/dependency-updates.md`](docs/dependency-updates.md) を参照する。
- 依存を変更した場合は `composer:validate`・`composer:audit`・`composer:prod-check` を含む `make check` を通すこと。
- `composer:audit` は `scripts/audit-guard.php` 経由の監査ラッパーを使う。素の `composer audit` を直接呼ばない。

## DB schema / migrationに関する現在の制約

- Laravelマイグレーションは存在しない。テーブル定義は `docker/db/sql/*.sql` がローカルMySQLコンテナの初回起動時にのみ適用する。
- 本番スキーマへの適用手順・実スキーマとの差分はリポジトリから確認できない（[`docs/current-operations.md`](docs/current-operations.md) 参照）。
- DB schema / migrationの変更も、対象Issueで明示的に必要な場合のみ行う。対象Issueに不要なschema変更を先回りして行わない。テーブル定義を変更する場合は、ローカルSQLと本番適用手順の両方に影響することを踏まえ、事前に方針を確認する。

## 外部HTTP通信を伴う実装・テストの方針

- アプリケーションはGoogle認証（Socialite）、Google Calendar API、コマンドに登録された任意の外部HTTPエンドポイントへ実際にリクエストする。
- テストでは、これらアプリケーションが依存する外部サービス（Google認証・Google Calendar・コマンドの実行先等）への実通信を行わない。mock / stub等で置き換えること。
- これは開発・CI基盤自身が必要とする通信（`composer install`によるパッケージ取得、`composer audit`によるアドバイザリ取得、Dockerイメージのpull等）まで禁止するものではない。`make check`はこれらの通信を前提に動作する。
- 新しい外部HTTP呼び出しを追加する場合は、失敗時（接続エラー・非2xx応答）の挙動を明示し、秘密情報をログ・保存データに残さない（[`docs/secrets-management.md`](docs/secrets-management.md) 参照）。

## 作業後の検証

作業後は原則 `make check` で検証する。

```shell
make check
```

- `make check` はローカル開発環境から分離された検証専用のCompose project（`docker-compose.check.yml`）を使う。開発用のcontainer・network・volume・host portには一切触れない。
- 依存関係インストール、PHP/Composerバージョン確認、Pintフォーマット確認、PHPStan/Larastan静的解析、`php artisan test` までを一括で実行する。
- 詳細は [`README.md`](README.md) と [`docs/current-operations.md`](docs/current-operations.md) の該当節を参照する。

## PR作成時の完了条件

- `make check` が成功していること（失敗した場合は原因を修正するか、修正できない場合はPR本文に明記する）。
- 変更内容・検証結果（`make check` の結果）を日本語でPR本文に簡潔に記載すること。
- スコープ外の変更（無関係なリファクタリング、ドキュメント整備タスクでのアプリケーションコード変更など）を含めないこと。
- Codex Code Reviewの運用は [`docs/pull-request-review.md`](docs/pull-request-review.md) に従うこと。

## Code Review Rules

Codex Code Reviewでは、フォーマットや一般論よりも、対象Issueの意図に対する正しさと本リポジトリ固有の安全性を優先して確認する。

### Issue・仕様との整合

- PRが対象Issueの目的・完了条件を満たしているか確認する。
- Issueにない無関係なリファクタリング、依存追加、schema変更、本番運用変更を混入させていないか確認する。
- 既存の設計・運用ドキュメントが正として指定されている場合、一般的な実装へ勝手に置き換えていないか確認する。

### セキュリティ・外部連携

- 認証・認可の抜け、ユーザー/グループ境界の崩れ、秘密情報や個人情報のレスポンス・ログへの漏洩を優先して指摘する。
- Google認証、Google Calendar、任意の外部HTTP実行では、失敗時の挙動と秘密情報の扱いに回帰がないか確認する。
- 外部HTTPのテストが実サービスへ通信していないか確認する。

### Scheduler・DB・本番運用

- `time:trigger`等のScheduler処理について、実行条件・失敗処理・heartbeat・既存の定期実行挙動を壊していないか確認する。
- DB schema変更や既存データへ影響する処理が、対象Issueで明示されないまま追加されていないか確認する。
- deploy workflow、tag、Cron、本番PHP、backup/rollbackに関わる変更では、既存runbookや安全条件を弱めていないか確認する。

### レビューの優先度

- 重大な正しさ・セキュリティ・データ損失・本番障害につながる問題を優先する。
- Laravel PintやPHPStan/Larastan、既存テストで機械的に検出できる軽微なスタイル指摘を重複して列挙しない。
- 好みだけの命名変更や、対象Issueと無関係なアーキテクチャ変更を要求しない。

## 安全上の必須ルール

- **mainへ直接コミットしない。** Issueごとに専用のbranch / worktreeを使うこと。
- **通常のPull Requestを作成し、明示的な指示なしにmergeしない。**
- **明示的な指示なしにIssueをcloseしない。**
- **本番デプロイ、tag作成（`v*`タグのpushでdeployが起動する）、本番環境操作を行わない。**
- **秘密情報（`.env`の実値、APIキー、トークン、SSH鍵等）をコード・ログ・PR・ドキュメントへ書かない。** 詳細は [`docs/secrets-management.md`](docs/secrets-management.md)。
- **`src/.env`を不要に上書き・再生成しない。** `make setup` は既存の `src/.env` を上書きしない挙動に依存している。
- **開発用DBを破壊する操作を無断で行わない。**
- **`make db-wipe`、`docker compose down --volumes`、`docker volume rm` など、開発用のcontainer・volumeを削除しうる操作は、利用者から明示的な許可を得た場合を除き実行しない。** 誤って実行してしまった場合、または実行してよいか判断がつかない場合は、その時点で作業を止めて日本語で具体的に報告する。
- **通常の検証には、開発環境から分離済みの `make check` を使う。** 開発用の `make up` / `make stop` 等で動作確認する場合も、既存の開発用container・network・volume・DBを削除・初期化しない。
