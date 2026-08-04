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

### 2.4 データベース初期化

MySQLコンテナの初回初期化時に `docker/db/sql/*.sql` が実行され、11テーブルを作成する。Laravelマイグレーションは存在しないため、既存データベースに対する差分適用経路はリポジトリ内にない。

`DatabaseSeeder` は空である。継続的インテグレーションでは `php artisan db:seed` を実行するが、現在はデータを投入しない。

### 2.5 テスト

ローカルの標準コマンドはDocker内の `php artisan test` である。現在のテストは次の2件だけである。

- Feature: `/` が200を返すこと
- Unit: `true` が真であること

Featureテストはデータベース時刻を取得するため、実行時にMySQL接続を必要とする。

### 2.6 キャッシュクリア

`cache_clear.sh` は、Laravelのアプリケーション、設定、ルート、ビュー等のキャッシュを削除・再生成し、Composerのオートローダーを再生成する。最後に `src/bootstrap/cache/config.php` を削除する。

このスクリプトはローカルDocker Composeのコンテナ名とマウント構成を前提としており、本番デプロイワークフローからは呼ばれない。

## 3. 継続的インテグレーション

`.github/workflows/test.yaml` は次のイベントを対象とする。

- `main` へのpush
- `main` 向けpull request

Dependabotを含めて通常のpull requestを使い、Pull RequestのコードをSecretへアクセスできる `pull_request_target` では実行しない。処理内容は次のとおりである。

1. 対象コミットをチェックアウトする。
2. `src/composer.lock` のハッシュを鍵として `src/vendor` をキャッシュする。
3. `make check` を実行する。ローカルの `make check` と同じ手順で、`.env.example` から `src/.env` を作成し、Webイメージ内の固定PHP 8.2.30・Composer 2.8.12で依存関係を導入し、`db` と `db-check` でMySQL 5.7.35の起動を待ち、データベースシーダーを実行し、PHP・Composerのバージョンと必要拡張を確認し、PHPUnitを実行する。`composer:latest` などの固定外イメージは使用しない。
4. mainへのpushでは、追加でJUnit XMLを出力するPHPUnit実行、外部URLからのXSLTダウンロード、HTMLレポートへの変換を行う。
5. 成否にかかわらず、`docker compose down --volumes --remove-orphans` でコンテナ・ネットワーク・ボリュームを後処理する。
6. mainへのpushでは、テストレポートをartifactで公開jobへ渡し、`gh-pages` ブランチへ配置する。
7. mainへのpushでは、テストとレポート公開の結果をSlackへ通知する。

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

- `composer.json`: PHP `^7.3|^8.0`
- ローカルDocker: PHP 8.2.30 + Apache
- `composer.lock`: Laravel Framework 8.83.29

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
