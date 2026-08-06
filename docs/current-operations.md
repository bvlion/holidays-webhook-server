# 現行運用

## 1. この文書の目的と確認基準

この文書は、Issue #208 の判断材料として、2026-08-03 時点のローカル環境、継続的インテグレーション、デプロイ、本番で観測できる範囲を整理したものである。

本番情報は次の区分で記載する。

- **運用者確認済み**: 運用者から提供された現在の本番情報
- **公開経路から確認済み**: 公開エンドポイントから独立に確認できた事実
- **リポジトリ上の構成**: リポジトリ内の設定から確認できた事実
- **未確認**: 上記の情報からは確定できない事項

公開確認では `https://holidays-webhook.ambitious-i.net/` の応答だけを使用している。認証が必要なAPI、サーバーへのSSH接続、データベースへの直接接続は実施していない。

## 2. ローカル環境

### 2.1 構成

| 項目 | 現在の設定 | 根拠 |
| --- | --- | --- |
| PHP | 8.2 | `docker/web/Dockerfile` の `php:8.2-apache` |
| Webサーバー | Apache | PHPのApacheイメージと `docker/web/httpd-base.conf` |
| ドキュメントルート | `/var/www/html/public` | Apache VirtualHost設定 |
| データベース | MySQL Server 5.7.35 | `docker/db/Dockerfile` |
| PHPタイムゾーン | `Asia/Tokyo` | `docker/web/php-base.ini` |
| Laravelタイムゾーン | `Asia/Tokyo` | `src/config/app.php` |
| MySQLコンテナのタイムゾーン | `Asia/Tokyo` | `docker-compose.yml` の `TZ` |
| Web公開ポート | ホスト8000番からコンテナ80番 | `docker-compose.yml` |
| MySQL公開ポート | ホスト3346番からコンテナ3306番 | `docker-compose.yml` |

WebコンテナはXdebugを常時有効化し、`host.docker.internal:9003` を接続先としている。MySQLのgeneral logは、SQLに含まれる認証情報等を記録しないよう無効化している。

### 2.2 ファイルのマウント

- `src/` をWebコンテナの `/var/www/html` にマウントする。
- `docker/web/php.ini` を追加のPHP設定としてマウントする。
- `src/logs/` をApacheの `/var/log/apache2` とMySQLの `/var/log/mysql` の両方へマウントする。
- `docker/db/sql/` をMySQL初期化SQLの配置先へマウントする。

祝日キャッシュ `src/logs/holidays.json`、Apacheログ、MySQLログが同じホスト側ディレクトリを共有する構成である。

### 2.3 初期構築

READMEに記載された初回構築は `make setup` の1コマンドで、処理の流れは次のとおりである。

1. `src/.env` が存在しない場合だけ、ルートの `.env.example` から作成する。
2. ローカル固定値だけを許可する接続先ガードを実行し、通過後にLaravelの設定キャッシュを削除する。
3. Docker Composeでローカル用イメージをビルドする。
4. Webイメージ内のPHP 8.2.30とComposer 2.8.12で `composer install` を実行する。
5. `web` と `db` を起動し、`db-check` でMySQLの応答を待つ。
6. 初回のMySQLコンテナ作成時にSQLを適用し、空のDatabaseSeederを実行する。

通常起動、停止、Webイメージ再構築、テスト、総合検証は、それぞれ `make up`、`make stop`、`make rebuild`、`make test`、`make check` で実行する。停止はコンテナを削除せず、再構築はデータベースコンテナを対象にしない。

接続先ガードは、起動またはデータベースアクセスを行う各Make targetの前に、`APP_ENV`、`DB_CONNECTION`、`DB_HOST`、`DB_PORT`、`DB_DATABASE`、`DB_USERNAME`をローカル固定値と照合し、`DATABASE_URL`が存在しないことを確認する。値が一致しない場合は設定値を出力せずに停止する。

Google認証をローカルで使用する場合は、管理者から受け取った `lib/google_client_secret` を `lib/google_client_export.php` で `src/.env` へ反映する手順になっている。

### 2.4 データベース初期化と永続化

MySQLコンテナの初回初期化時に `docker/db/sql/*.sql` が実行され、11テーブルを作成する。Laravelマイグレーションは存在しないため、既存データベースに対する差分適用経路はリポジトリ内にない。

`DatabaseSeeder` は空である。継続的インテグレーションでは `php artisan db:seed` を実行するが、現在はデータを投入しない。

MySQLのデータディレクトリ `/var/lib/mysql` は、`docker-compose.yml` で `db_data` というnamed volumeへ保存している（Issue #233）。固定名(`name:`)を指定していないため、Composeのproject名ごとに別のvolumeとして扱われる。`make stop`／`make up`、`db` コンテナの再作成、`--volumes` を付けない `docker compose down` ではデータが保持され、`--volumes` 付きの `docker compose down`・`docker volume rm`・`make db-wipe`（要 `CONFIRM=yes`）を実行した場合だけ削除される。バックアップ・復元・完全初期化の手順はREADMEに記載している。

- `make db-backup` は `mysqldump` の出力を `backups/` 内の一時ファイルへ書き出し、成功かつ0バイトでないことを確認できた場合だけ日時付きの最終ファイル名へ `mv` する。失敗時は一時ファイルを削除し、成功メッセージは表示しない。
- `make db-restore FILE=...` は、指定ファイルが通常ファイルとして存在し0バイトでないことを確認してから投入する。MySQLへの投入に失敗した場合は成功メッセージを表示しない。
- `make db-wipe CONFIRM=yes` は、開発用Composeプロジェクトに対する `docker compose down --volumes` を使わない。Composeが自動で付与する `com.docker.compose.project`／`com.docker.compose.volume` ラベルで開発用DBのvolumeを1件だけ特定し、該当が0件または複数件の場合は削除せずエラーで停止する。特定できた場合だけ `db` サービスのコンテナとそのvolumeを削除し、`db` だけを作り直す。`web` コンテナには一切触れない。

Issue #213の作業中、一時的な `docker-compose.override.yml` を使って `make check` を既存の開発環境から分離しようとしたが、同一のCompose projectとして扱われたため既存の `hw_web`／`hw_db` が再作成され、その後の `docker compose down --volumes --remove-orphans` で削除された（データディレクトリのnamed volumeが無かったため、DB内のデータも失われた）。Issue #233でこの事故を踏まえ、named volumeによる永続化と、`make check` 専用のCompose project（`docker-compose.check.yml`、2.7節）への分離を行った。

### 2.5 テスト

ローカルの標準コマンドはDocker内の `php artisan test` である。Feature Test・Unit Testはいずれも `tests/Feature`・`tests/Unit` 配下に追加されており、`make test`（`php artisan test` のみ）または `make check`／`composer check`（Pintのフォーマット確認・PHPStan/Larastanの静的解析に続けて実行）でまとめて実行される。テスト件数は追加・削除により変動するため、個々の件数や一覧はこの文書では管理せず、`src/tests/` 配下を実装の正とする。

一部のFeature Testはデータベースへ接続するため、実行時にMySQL接続を必要とする。

### 2.6 キャッシュクリア

`cache_clear.sh` は、Laravelのアプリケーション、設定、ルート、ビュー等のキャッシュを削除・再生成し、Composerのオートローダーを再生成する。最後に `src/bootstrap/cache/config.php` を削除する。

このスクリプトはローカルDocker Composeのコンテナ名とマウント構成を前提としており、本番デプロイワークフローからは呼ばれない。

### 2.7 検証環境の分離（`make check`）

`make check` は `docker-compose.check.yml` と、開発用（既定で `holidays-webhook-server`）とは異なるCompose project名（既定で `holidays-webhook-server-check`）を使う（Issue #233）。開発用の `docker-compose.yml` との主な違いは次のとおりである。

| 項目 | 開発用 | 検証用(`make check`) |
| --- | --- | --- |
| Compose project名 | `holidays-webhook-server` | `holidays-webhook-server-check` |
| `container_name` | 指定しない（project名で一意になる） | 指定しない（project名で一意になる） |
| host port公開 | web: 8000、db: 3346 | なし |
| DBデータ | named volume `db_data` で永続化 | volumeなし（コンテナと共に消える使い捨て） |
| 通常のcleanup対象 | なし（`make stop`はコンテナを残す） | `make check`終了時に検証用projectだけを `down --volumes --remove-orphans` |

`web`・`db`・`db-check` のbuild対象・環境変数・`src`／`docker/db/sql`等のマウント内容は開発用と同じにしてあり、検証結果が開発環境と乖離しないようにしている。

### 2.8 PHP 8.5互換確認環境（`make check-php85`）

本番・開発用のPHPは引き続き8.2.30のままだが、将来のPHP 8.5移行に向けた互換確認環境を追加した（Issue #215）。`docker/web/Dockerfile` はビルド引数 `PHP_IMAGE`（既定値 `php:8.2.30-apache`、ファイル自体は変更しない）で切り替え可能にし、`docker-compose.check.yml` に `docker-compose.check-php85.yml` を重ねて `PHP_IMAGE=php:8.5.9-apache` を上書きすることで、`db`・`db-check`（MySQL 5.7.35）は共通のまま `web` だけPHP 8.5系にした専用環境（Compose project `holidays-webhook-server-check-php85`）を構築する。手順は `make check` と同一（`check-php85`）で、開発用・`make check` 用のどちらの環境にも一切触れない。

Issue #215時点では、Laravel 8・依存関係（`nette/schema v1.3.2`・`nette/utils v4.0.5`。いずれもPHP上限が8.4）がPHP 8.5を正式サポートしておらず、`composer install`が失敗していた。この既知の非互換だけを検出した場合に後続を未実施のまま成功扱いにする一時的な判定処理（`src/scripts/php85-compat-check.php`）を経由していたが、Issue #216のLaravel 9更新でこれらの間接依存がPHP 8.5対応版（`nette/schema v1.3.5`・`nette/utils v4.1.5`）へ更新され、`composer install`がPHP 8.5.9でも成功するようになったため、一時的な処理は撤去した。

隔離環境で実際に確認した結果、PHP 8.5.9で`composer install`から`composer check`（`composer:validate`/`composer:audit`/`composer:prod-check`/Laravel Pint/PHPStan(Larastan)/PHPUnit）まで、`make check`と同じ内容がすべて成功することを確認済みである（Issue #216のPull Request参照）。

## 3. 継続的インテグレーション

`.github/workflows/test.yaml` は次のイベントを対象とする。

- `main` へのpush
- `main` 向けpull request

Dependabotを含めて通常のpull requestを使い、Pull RequestのコードをSecretへアクセスできる `pull_request_target` では実行しない。処理内容は次のとおりである。

1. 対象コミットをチェックアウトする。
2. `src/composer.lock` のハッシュを鍵として `src/vendor` をキャッシュする。
3. `make check` を実行する。ローカルの `make check` と同じ手順で、開発環境とは別のCompose project（`docker-compose.check.yml`、Issue #233）上で、`.env.example` から `src/.env` を作成し、Webイメージ内の固定PHP 8.2.30・Composer 2.8.12で依存関係を導入し、`db` と `db-check` でMySQL 5.7.35の起動を待ち、データベースシーダーを実行し、PHP・Composerのバージョンと必要拡張を確認し、最後に `composer check`（`composer:validate` によるcomposer.json/lockの整合性検証 → `composer:audit`（`scripts/audit-guard.php`監査ラッパー経由）による既知の脆弱性監査 → `composer:prod-check` による本番向け依存関係のdry-run確認 → Laravel Pintによるフォーマット確認 → PHPStan/Larastanによる静的解析 → `--log-junit result.xml` 付きのPHPUnit）を実行する。Composer設定の不整合、新規の脆弱性アドバイザリ、フォーマット違反、新規の静的解析違反があれば失敗する（Issue #214）。`composer:audit` は素の`composer audit`を直接呼ばず、終了コード(0/1/2/3以外は異常)と`--format=json`出力の構造（`advisories`/`abandoned`フィールドの有無・JSONとして解析可能か）の両方を検証する監査ラッパーを経由する。これにより、Packagistへのアドバイザリ取得自体が失敗した場合（Composer 2.8.12で確認済みの挙動：例外を捕捉せず終了コード100・標準出力は空で異常終了）を「監査に成功しbaseline外の脆弱性がなかった」場合と区別し、取得失敗を成功として扱わない。`composer:latest` などの固定外イメージは使用しない。CIランナー自体が使い捨てのため既存環境との衝突は元々起きないが、ローカルでも同じ `make check` が安全に使えるよう検証専用のCompose projectを使っている。
4. `composer check` が出力した `src/result.xml`（bind mount経由でRunner側に残る）を使って、mainへのpushでは外部URLからのXSLTダウンロードとHTMLレポートへの変換を行う。追加のPHPUnit実行はしない。
5. `make check` は、成功・失敗にかかわらず検証専用のCompose projectだけを対象に `docker compose down --volumes --remove-orphans` を実行して後処理する（Makefile内の`trap`によるcleanupで、workflow側からは呼ばない）。開発用の構成は元々このworkflow上に存在しないため影響しない。
6. mainへのpushでは、テストレポートをartifactで公開jobへ渡し、`gh-pages` ブランチへ配置する。
7. mainへのpushでは、テストとレポート公開の結果をSlackへ通知する。
8. 上記1〜7とは別に、`php85-compat` ジョブが専用のCompose project（`holidays-webhook-server-check-php85`）で `make check-php85`（2.8節）を実行する（Issue #215）。`test`・テストレポート公開・Slack通知（`notify`）のいずれの `needs` にも含まれておらず、PHP 8.2.30側の検証結果とは独立に確認できる。Issue #216のLaravel 9更新以降は`make check`と同じ通常のフローで実行しており、一時的な既知非互換判定処理は使用していない。

Pull Requestで実行するテストjobはリポジトリ内容の読み取り権限だけを持ち、Secretを使用しない。`gh-pages` への書き込み権限はmainへのpushでだけ実行する公開jobに限定する。

READMEには「masterにプッシュ」と記載されているが、実際のワークフロー対象は `main` である。

## 4. デプロイ

### 4.1 起動条件

`.github/workflows/deploy.yaml` は、`v` で始まるタグのpushで起動する。手動実行やブランチpushによるデプロイ定義はない。

2026-08-03 のGitHub上ではワークフロー自体は有効だが、保持されている実行履歴を取得できなかった。ローカルに存在する最新タグは `v0.2.2` で、対象コミット日時は2022-12-30である。本番がこのタグと一致するかは未確認である。

### 4.2 デプロイ処理

ワークフローは次の順に処理する。

1. リポジトリをチェックアウトする。
2. GitHub SecretsのSSH秘密鍵とknown hostsをRunnerへ配置する。
3. `src/vendor` のキャッシュを復元する。
4. キャッシュがない場合はComposerコンテナで依存関係をインストールする。
5. SSHで配備先へ接続し、`logs` 以外の通常表示されるファイルとディレクトリを削除する。
6. `src/` の内容をrsyncで配備先へ転送する。
7. Slackへ結果を通知する。

rsyncでは次を転送対象から除外する。

- `logs`
- `phpunit.xml`
- `.env`
- `tests`

配備先の `.env` は、削除処理の `ls` に通常表示されず、rsyncでも除外されるため、現在のワークフロー上はサーバー側の既存ファイルを使い続ける。`logs` も削除とrsyncの両方から除外される。

### 4.3 デプロイに含まれない処理

現在のワークフローには次の処理がない。

- データベースマイグレーションまたはSQL適用
- Laravelの設定、ルート、ビューキャッシュの明示的な再生成
- PHP-FPM、Webサーバー、ワーカー、Schedulerの再起動または再読み込み
- デプロイ後のヘルスチェック
- 直前リリースへ戻すロールバック処理
- 配備中のリクエストを旧版または新版のどちらかへ固定する切り替え処理

これは改善案ではなく、現在のワークフローに処理が存在しないことを示している。代表的に問題化するのは、コードとキャッシュまたはデータベーススキーマを同時に更新するリリースである。現在の最小ガードは、その種の変更を行うIssueで必要な運用手順を個別に明示することである。

## 5. 本番環境

### 5.1 公開経路から確認できた事項

2026-08-03 に本番URLのルートへHTTPSでアクセスし、次を確認した。

| 項目 | 確認結果 |
| --- | --- |
| URL | `https://holidays-webhook.ambitious-i.net/` |
| HTTP状態 | 200 |
| 公開Webサーバーの応答ヘッダー | `server: nginx` |
| Content-Type | `application/json` |
| アプリケーション時刻 | `2026-08-03 11:06:50 JST` |
| データベース時刻 | `2026-08-03 11:06:50` |

この観測により、公開経路の前面がnginxであることと、アプリケーションおよびデータベースが同じ日本時間を返していることは確認できる。ただし、nginxが直接PHPを実行しているか、別のWebサーバーやコンテナへ中継しているかは確認できない。

### 5.2 PHP

- **運用者確認済み**: XServer上でPHP 8.2.30を使用している。この情報はIssue #207およびIssue #226に記録されている。
- **公開経路から確認済み**: 公開応答にPHPバージョンは含まれず、PHP 8.2.30であることを独立には確認できない。
- **未確認**: PHPのServer API、主要拡張、実際に読み込まれている設定ファイル。

リポジトリ上の条件は次のとおりである。

- `composer.json`: PHP `^8.0.2`（Issue #216のLaravel 9更新に伴い`^7.3|^8.0`から変更）
- ローカルDocker: PHP 8.2.30 + Apache
- `composer.lock`: Laravel Framework 9.52.21（Issue #216でLaravel 8.83.29から更新）

リポジトリ上の条件も、公開経路から本番のPHPバージョンと実行方式を証明するものではない。

### 5.3 Webサーバー

- **確認済み**: 公開応答はnginxヘッダーを返す。
- **未確認**: nginxのバージョン、VirtualHost、TLS終端、PHPへの接続方式、プロキシ構成、ドキュメントルート。

ローカルはApacheであり、本番公開経路と異なる。

### 5.4 データベース

- **確認済み**: 本番ルートから `SELECT NOW()` が成功し、日本時間の値を返す。
- **リポジトリ上の構成**: Laravelの既定接続はMySQLで、時間トリガーSQLはMySQL固有関数を使用する。
- **未確認**: 本番の製品名、バージョン、接続方式、タイムゾーン設定、文字コード、SQLモード、バックアップ、レプリケーション、実スキーマ。

ローカルはMySQL Server 5.7.35であるが、本番も同じとは判断できない。

### 5.5 cronとScheduler

Laravel側には、毎分、毎時、毎月のスケジュール定義がある。一方、リポジトリにはLaravel Schedulerを定期起動するOS側のcron、systemd timer、コンテナ設定がない。

本番での起動方法、実行ユーザー、作業ディレクトリ、ログ出力先、多重起動の有無、失敗監視は未確認である。

### 5.6 ログ、監視、バックアップ

- アプリケーションの既定ログ設定はLaravel標準構成で、`.env` の `LOG_CHANNEL` と `LOG_LEVEL` に依存する。
- ローカルでは `src/logs` にApache、MySQL、祝日キャッシュを集約する。
- デプロイは配備先の `logs` を保持する。
- GitHub Actionsはテストとデプロイの結果をSlackへ通知する。
- 本番のログローテーション、外部ログ集約、死活監視、エラー通知、データベースバックアップは未確認である。

## 6. ローカルと本番の差異

| 項目 | ローカル | 本番で確認できた範囲 |
| --- | --- | --- |
| Webサーバー | Apache | 公開応答はnginx |
| PHP | Dockerの8.2.30 | 運用者確認ではXServerの8.2.30。公開経路からバージョンとServer APIは独立確認できない |
| データベース | MySQL Server 5.7.35 | `SELECT NOW()` の成功のみ確認、製品とバージョンは未確認 |
| アプリケーション配置 | `src` をコンテナへバインドマウント | GitHub ActionsからSSHとrsyncで `src` の内容を転送 |
| `.env` | 公開用 `.env.example` から、Git管理外の `src/.env` を作成 | 配備対象外で、サーバー側の既存ファイルを利用 |
| スキーマ作成 | MySQL初回起動時にSQLを実行 | 適用方法と実スキーマは未確認 |
| Scheduler起動 | READMEに起動手順なし | OS側の登録内容は未確認 |
| ログ | `src/logs` をApacheとMySQLで共有 | 配備時に `logs` を保持、実際の出力先とローテーションは未確認 |
| Xdebug | Webイメージで有効 | 有効状態は未確認 |

## 7. 現状の運用差異と後続Issue候補

Issue #208では変更せず、次の差異を記録する。

- READMEのテスト起動ブランチ表記は `master`、実際のワークフローは `main` である。
- ローカルのWebサーバーはApache、本番公開経路はnginxである。
- ローカルPHPは8.2、本番PHPは運用者確認で8.2.30だが、実行基盤はローカルのApacheと本番のXServerで異なる。本番PHPのバージョンとServer APIは公開経路から独立確認できない。
- ローカルMySQLはDockerfileで固定されるが、本番データベースの製品とバージョンは記録されていない。
- ローカルデータベースは初回起動SQLで作成するが、本番へのスキーマ適用処理はデプロイにない。
- Laravel Schedulerの処理定義はあるが、実行基盤の設定はリポジトリにない。
- デプロイはタグを起点とする一方、稼働中タグまたはコミットを確認する仕組みがない。
- デプロイ前に配備先の通常ファイルを削除してからrsyncするため、転送失敗時の配備状態と復旧手順はワークフローから確認できない。
- テストワークフローは実行中に外部URLからXSLTを取得するため、外部提供元またはネットワーク状態に依存する。

代表的にこれらの差異が問題化するのは、ローカルのApacheでは成功するが本番nginxのルーティングやリクエスト制限では失敗する変更である。現在の最小ガードは、Webサーバー依存の変更時に本番相当のnginx設定を確認対象へ含めることである。

## 8. 本番で追加確認が必要な事項

後続Issueでは、少なくとも次の実測が必要である。

1. 稼働中コミットまたはリリースタグ
2. 本番ホストまたは管理画面でPHP 8.2.30の稼働を再確認し、Server API、主要拡張、設定ファイルを記録すること
3. nginxのバージョン、公開設定、PHPまたは上流サーバーへの接続構成
4. データベースの製品、バージョン、タイムゾーン、SQLモード、実スキーマ
5. Laravel Schedulerを起動するcronまたはサービスの定義、実行ユーザー、ログ
6. 同一時刻にSchedulerが多重起動しない運用上の保証
7. `.env`、Google認証情報、Google Calendar APIキーの配置・更新手順
8. アプリケーションログ、Webアクセスログ、cron失敗の監視と保持期間
9. データベースのバックアップ、復元手順、復元確認の実績
10. デプロイ失敗時の復旧方法と、直前リリースへの切り戻し手順
11. 本番スキーマを変更する際の適用順序と停止要否
12. SSID、まとめコマンド、時間トリガーを管理しているリポジトリ外のシステム
