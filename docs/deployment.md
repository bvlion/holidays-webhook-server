# デプロイ手順

この文書は、Issue #224に基づき、本番（XServer）へのデプロイを第三者が安全に実施できるように整理したものである。運用の詳細な調査結果は [`current-operations.md`](current-operations.md) の「4. デプロイ」「5. 本番環境」、責務・構成は [`architecture.md`](architecture.md)、ドメインルールは [`domain.md`](domain.md)、秘密情報の扱いは [`secrets-management.md`](secrets-management.md) を参照する。ここでは重複を避け、デプロイ実施に必要な範囲だけをまとめる。

**このIssueの範囲では実際の本番デプロイ・tag作成・本番操作は行わない。** 以下は次回以降デプロイを行う人間（運用者）向けの手順である。

## 1. 人間とAIの役割分担

| 役割 | 実施者 |
| --- | --- |
| リポジトリ側のコード・workflow・文書の変更、`make check`、workflowの静的検証（actionlint等） | AIエージェント（Codex等）でも可 |
| XServer管理画面操作 | 人間 |
| 本番SSH上の操作（ファイル確認、`.env`確認等） | 人間 |
| 本番DBのbackup／restore | 人間 |
| 本番`.env`・秘密情報の確認 | 人間 |
| tag作成／デプロイ実行（`v*` push） | 人間 |
| 本番PHP切り替え（Issue #226） | 人間 |
| 本番疎通確認 | 人間 |

AIエージェントは、本番XServerへのSSH接続、本番DBへの直接接続、GitHub Actions Secretsの実値取得のいずれも行わない。

## 2. 現在のdeploy方式（実装どおりの整理）

`.github/workflows/deploy.yaml` は `v*` にマッチするtagのpushでのみ起動する。手動実行やブランチpushによる起動経路はない。

処理順序は次のとおりである。

1. リポジトリをcheckoutする。
2. `secrets.ID_RSA`・`secrets.KNOWN_HOSTS` からSSH鍵と既知ホストを配置する。
3. `docker/web/Dockerfile` の既定イメージ（PHP 8.5.9 + Composer 2.8.12。Issue #220で `.github/workflows/test.yaml`（`make check`）の標準に昇格済みの環境と同一）をbuildする。
4. そのイメージのコンテナ内で、cacheの有無に関わらず必ず `composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader` を実行し、本番用vendorを生成する（Issue #226。production vendor cacheは持たない。6.1節）。
5. SSHで配備先へ接続し、`cd ${{ secrets.SSH_DIR }} && ls | grep -v -E 'logs' | xargs rm -rf` を実行する。`ls`（`-a`なし）はドットファイルを列挙しないため、`.env` はこの削除対象に現れない。`logs` はパターンで明示的に除外される。それ以外の通常表示されるファイル・ディレクトリはすべて削除される。
6. `rsync -av` で `src/` の内容を配備先へ転送する。`--exclude` は `logs`・`phpunit.xml`・`.env`・`tests` の4つ。
7. `8398a7/action-slack@v3` でSlackへ結果を通知する。

Slack通知ステップの `if: always()`（Issue #224で追加、3.1節参照）と、起動条件・Secrets構成・Actionsバージョンは変更していない。

### 2.1 現在のdeployに含まれないもの

以下は「今後の課題」ではなく、**現在のworkflowに存在しない処理**である。混同しないこと。

- 本番DBバックアップ
- DB復元
- migration／SQL適用
- Laravel cache（config/route/view/application）の明示的な再構築
- PHP-FPM・Webサーバー・Scheduler等の再起動
- デプロイ後のヘルスチェック
- 直前リリースへのロールバック
- 新旧を安全に切り替えるatomicな仕組み

これらが必要な場合は、本文書の「4. deploy前チェック」「6. Composer / Laravel cache」「7. DB schema」「8. DBバックアップ／復元」、および [`rollback.md`](rollback.md) の手順を人間が別途実施する。

### 2.2 削除→rsync方式のリスク

配備先の既存ファイルを削除してから新しいファイルを転送するため、rsyncが途中で失敗すると、削除済みで新ファイルも一部しかない状態がサーバーに残り得る。この方式自体は本Issueの範囲では変更しない（`v*` トリガー・SSH+rsync方式そのものを作り直すことはIssue #224のスコープ外）。安全に運用するための最小限の対策は次の2点とする。

- deploy前に、後述の「5. アプリケーションファイルのバックアップ」を必ず行う。
- deploy失敗（delete/rsyncステップの失敗を含む）が確実に人間へ通知されるようにする。3.1節のSlack通知の`if: always()`修正はこのための最小修正である。

## 3. GitHub Actions deploy workflowについて確認した安全性

Issue #224で、当時のdeploy workflowの安全性を確認した結果、**文書化だけでは回避できない問題を1件確認し、最小修正した（3.1節）。** その後Issue #226で、本番向けComposer install環境の固定化・`--no-dev`化・production vendor cacheの廃止を行った（6.1節、3.3節）。

### 3.1 Slack通知が失敗時に送られない問題

修正前の `Slack Notification` ステップには `if:` 条件がなく、それより前のステップ（SSH鍵配置、`composer install`、`delete server files`、`rsync`のいずれか）が失敗すると、GitHub Actionsの既定動作としてこのステップ自体がスキップされ、**失敗の通知が一切飛ばない。**

前述のとおり本deployは「削除してから転送する」方式であり、失敗時にサーバー側が壊れた状態になり得る。それにもかかわらず失敗が通知されないと、人間が気づかないまま本番が壊れた状態で放置される恐れがある。実際、同リポジトリの `.github/workflows/test.yaml` の `notify` ジョブは `if: ${{ always() && ... }}` を使っており、失敗時にも通知する設計になっている。

**対応:** `Slack Notification` ステップに `if: always()` を追加した。これにより、`job.status` が `failure` の場合もSlackへ通知される。

- `v*` タグによる起動条件は変更していない。
- 本番PHPは変更していない。
- 新しいSecretは追加していない。
- Actionsのバージョン更新は目的としない。`actions/checkout`・`actions/cache`等のバージョンはDependabot運用（[`dependency-updates.md`](dependency-updates.md)）に委ね、本文書では特定のバージョン番号を前提にしない。
- 実際のdeployは起動していない。
- `actionlint` で静的検証済み。Issue #224時点（2026-08-08、`agent/issue-224-deployment-docs`を最新mainへ追従させた状態）では、`composer install` ステップの `shellcheck SC2086`（`$PWD` 未クォート）指摘が残っていたが、Issue #226でComposer installステップ自体を書き換えた際に変数をクォートしたため、この指摘は解消した（3.3節）。`actions/checkout`・`actions/cache`のバージョンに関する指摘はない。Dependabotが今後Actionsのバージョンを更新した場合、この結果は再度変わり得るため、実施時点の最新mainを基準に読み替えること。

### 3.2 その他確認したが変更しなかった点（Issue #224時点）

- Actionsのバージョンが古い場合: Dependabot運用に委ねる方針のため、Issue #224では更新しない（バージョンは実施時点のmainの状態に従う）。
- 削除→rsync方式そのもの、atomicな切り替えがないこと: ワークフロー全体の作り直しが必要でありIssue #224の範囲を超えるため、2.2節の運用対策と[`rollback.md`](rollback.md)で対応する。

### 3.3 Issue #226での変更: Composer install環境の固定化・`--no-dev`化・cache廃止

6.1節のとおり、Issue #226で次を変更した。

- Composer install手順を「`composer`固定外イメージでのdev依存込みinstall」から、「`docker/web/Dockerfile`の既定イメージ（PHP 8.5.9 + Composer 2.8.12、CIと同一）での`--no-dev`install」へ置き換えた。
- production用vendor cache（`actions/cache@v6`ステップ）を廃止し、cacheの有無に関わらず必ずComposer installを実行するようにした。
- 書き換えに伴い、3.1節で挙げていた`$PWD`未クォートのshellcheck指摘は解消した。
- `v*` タグによる起動条件、SSH鍵配置・削除・rsync・Slack通知の各ステップの処理内容は変更していない。
- 削除→rsync方式そのもの、atomicな切り替えがないことは、Issue #226でも変更しない（引き続き2.2節の運用対策と[`rollback.md`](rollback.md)で対応する）。

## 4. deploy前チェック（人間が実施）

タグをpushする前に、少なくとも次を確認する。

1. **対象commit / tagの確認**: デプロイしたいcommitがどれか特定し、そのcommitに対して意図したtag名（`v*`）を付与する。既存tagと衝突していないか確認する。
2. **main CI成功確認**: 対象commitに対応する `.github/workflows/test.yaml`（`make check`）がGitHub Actions上で成功していることを確認する。
3. **PHP / Laravel / Composer platform要件の確認**: `src/composer.json` の `require.php`（現在 `^8.3`）・`laravel/framework`（現在 `^13.0`）を確認する。
4. **本番PHPが対象リリースを実行可能か**: 本番PHPの実バージョンを人間が確認し、3で確認したComposer platform要件を満たすか判定する。**現時点（Issue #226完了前）は本番PHPが8.2.30であり `^8.3` を満たさないため、mainの現在のリリースを本番へデプロイしてはならない。** この判定を飛ばして安易にdeployしないこと。**唯一の例外はIssue #226のPHP 8.5初回切り替え作業であり、[`php85-production-switch.md`](php85-production-switch.md) 8節に定める条件をすべて満たす場合に限り、本番PHPが8.2.30のままtag pushを開始することを認める。** それ以外の通常のdeployにこの例外は適用しない。Issue #226完了後（本番PHPが8.5系になった後）は、この例外自体が不要になり、通常どおり本判定に従う。
5. **アプリケーションファイルのバックアップ**: 5節を参照。
6. **DBバックアップ**: 8節を参照。
7. **`.env`を変更・削除しないこと**: deployは`.env`を削除・上書きしない設計（2節参照）だが、人間が手動でSSH操作をする場合も`.env`を書き換えない。変更が必要な場合は本Issueの手順とは別に、変更内容・理由・実施者を記録する。
8. **必要なPHP拡張の確認**: 本番PHPに、`src/composer.json`が要求する拡張（Laravel 13・Guzzle・Socialite等が要求するもの）が入っているか、切り替え前に人間が確認する。拡張一覧はComposerの`platform`要件とLaravelのドキュメントを基準にする。本Issueでは拡張の実地確認は行わない。
9. **schema変更の有無**: 対象リリースに `docker/db/sql/*.sql` の変更が含まれるか `git diff` で確認する（7節）。
10. **rollback可能性**: [`rollback.md`](rollback.md)に沿って、今回のリリースを戻せる状態（直前のtag、アプリファイルの控え、必要ならDBバックアップ）が揃っているか確認する。

## 5. アプリケーションファイルのバックアップ（deploy前・人間が実施）

現在のdeploy workflowは、配備先の既存ファイルを削除してから新しいファイルを転送する（2.2節）。ロールバックの土台として、deploy直前に本番サーバー上のアプリケーションディレクトリ（`${SSH_DIR}`相当）を、SSH経由でサーバー内または安全な別経路へ退避しておく。

- 具体的なバックアップ手段（サーバー内コピー、tar化して別ディレクトリへ、ダウンロード等）はXServerの契約プラン・利用可能なディスク容量によって異なるため、本文書では方式を固定しない。**利用可能な手段の確認は人間が行う。**
- バックアップには `.env` を含めてよい（`.env`自体は削除されないが、バックアップの完全性のため含める）。バックアップの保管場所には本番と同等のアクセス制御を適用する（[`secrets-management.md`](secrets-management.md) 3.3節）。
- 直前のリリースに対応するtag名・commit SHAを記録し、バックアップとひも付けておく（[`deployment-checklist.md`](deployment-checklist.md)参照）。

## 6. Composer / Laravel

### 6.1 本番向け依存関係

本番向けにインストールする依存関係は、dev依存（`require-dev`）を含めない前提とする。`src/composer.json` の `composer:prod-check`（`composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader --dry-run`）で、dev依存を除いた依存解決がdry-runで通ることを`make check`が既に検証している。

**Issue #226で、`.github/workflows/deploy.yaml`のComposer install手順を次のように変更した。**

- 依存関係を生成する環境を、`docker/web/Dockerfile`の既定イメージ（PHP 8.5.9 + Composer 2.8.12）へ固定した。`.github/workflows/test.yaml`（`make check`）が使う環境と同一であり、CIで検証済みの環境で本番用vendorを生成する。旧手順が使っていた`composer`固定外イメージ（floating tag）は使用しない。
- `composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader` を実行し、dev依存を含めない。
- production用のvendor cacheは廃止した。`.github/workflows/test.yaml`のvendor cache（dev依存込みで生成される）とは完全に独立しており、cache hitによってComposer installがskipされ、dev依存込みvendorが本番へ配備される、という従来の問題（2節）は構造的に発生しない。deployは`v*`タグpush時のみ起動する低頻度の処理であり、cacheによる高速化の価値より、cacheの有無に関わらず常に本番用installを実行できる確実性を優先した。

この変更は、本番PHPをPHP 8.5系へ切り替える[`php85-production-switch.md`](php85-production-switch.md)（Issue #226）のリポジトリ側の準備として実施した。実際の本番向けComposer installの動作確認（`--no-dev`で成功し、dev依存が含まれず、`composer check-platform-reqs --no-dev`が通ること）は、本番秘密情報を使わない一時環境で確認済みである。実際の本番デプロイでの動作確認は、本Issueの範囲では行っていない。

### 6.2 Laravel cacheの扱い

`cache_clear.sh`はローカルDocker Compose（`docker compose exec -T web ...`）のコンテナ構成を前提としており、本番デプロイworkflowからは呼ばれない（[`current-operations.md`](current-operations.md) 2.6節）。**本番のファイル配置・実行ユーザーで動くとは限らないため、本番手順へそのまま流用しない。**

現在のアプリ構成（`config/route/view`キャッシュ＋`APP_KEY`等を含む`.env`を配備先で保持し続ける方式）を踏まえ、本番でキャッシュを再構築する場合は次の順序を安全側の基本方針とする。実行は人間が本番SSH上で行う。

1. ファイル配備（rsync）が完了していることを確認する。
2. `php artisan config:clear` で既存のconfig cacheを破棄する（配備直後に古いcodeパスを参照した設定キャッシュが残っている可能性を消す）。
3. `php artisan route:clear`・`php artisan view:clear` で古いルート・ビューキャッシュを破棄する。
4. `php artisan cache:clear` でアプリケーションキャッシュ（`.env`の`CACHE_DRIVER`に依存）を破棄する。祝日キャッシュ（`logs/holidays.json`）はLaravelのcacheとは別物であり、この操作では消えない。
5. 必要な場合のみ `php artisan config:cache`・`php artisan route:cache`・`php artisan view:cache` で再生成する。Laravel 13の`config:cache`自体、内部で最初に既存のconfig cacheをクリアしてから再生成するため、`config:cache`単体でも古い設定が残ったまま固定されることはない。手順として2番目に明示的な`config:clear`を置くのは、デプロイ手順を分かりやすくし、再生成前に一旦未キャッシュ状態へ戻してから進める運用上の整理のためである。
6. 最後に、Composerのオートローダーが新しいコードに追随しているか確認する（`composer install --no-dev`を配備側で実行済みであれば、オートローダーはその時点で更新されている）。

現在のdeploy workflowはこれらのartisanコマンドを一切実行しない（2.1節）。本番で明示的に再構築したい場合は、上記手順を人間がSSH経由で実施する。

## 7. DB schema

現在Laravel migrationは存在しない。`php artisan migrate --force`を無条件のdeploy手順として追加することはしない。

### 7.1 「適用なし」の判定方法

対象リリースにschema変更が含まれるかどうかは、直前にdeployした commit/tag と、これからdeployする commit/tag の間で `docker/db/sql/` の差分があるかどうかで判定する。

```shell
git diff <直前deployのtag>..<今回deployするtag> -- docker/db/sql/
```

差分が空であれば、このリリースのDB適用は「適用なし」と判定できる。この場合、8節のDBバックアップは通常運用のバックアップサイクルに従えばよく、schema変更に伴う追加のバックアップは不要である。**ただしIssue #226のPHP 8.5切り替えは、この判定に関わらずDBバックアップを必須とする（[`php85-production-switch.md`](php85-production-switch.md) 6節）。**

### 7.2 schema変更がある場合

`docker/db/sql/` に差分がある場合、次を対象リリースのIssue/PRで明示する運用とする。本文書では汎用手順を先取りしない。

- 変更対象のテーブル・カラムと、その変更が後方互換か破壊的か
- 本番の実スキーマに対してどう適用するか（人間が確認済みの本番DB接続手段を使う。8.3節参照）
- 適用前の本番DBバックアップの取得（8節）
- 適用に失敗した場合、または適用後にアプリケーションが期待どおり動かない場合のロールバック手順（[`rollback.md`](rollback.md) の「schema変更を伴うリリースで問題が起きる」節）

## 8. DBバックアップ／復元

### 8.1 運用者による本番確認結果（2026-08-08）

運用者がXServer本番環境上で次を確認した。接続先ホスト名・DB名・ユーザー名・パスワード等の実値は、この文書を含むリポジトリには記載しない。

- **製品・バージョン**: 本番DBはMySQL 5.7.16である。
- **管理アクセス**: XServerのphpMyAdminから対象DB・テーブルを参照できる。また、SSHトンネル経由でSequel Aceから本番MySQLへ接続できる。
- **バックアップ取得**: XServerのサーバーパネルから、対象DBの手動バックアップを取得できる。取得形式は非圧縮SQLとgz形式のいずれかを選択できる。
- **復元経路（2通り）**:
  1. XServerサーバーパネル: XServer側の自動バックアップから復元する操作がある。サーバーパネル上のこの機能は、手動でダウンロードしたSQLファイルを指定して復元する方式ではない。
  2. Sequel Ace: SSHトンネル経由で本番DBへ接続した状態で `File → Import...` が利用できる。したがって、手動取得したSQLについては、この経路で復元できる。gzファイルをSequel Aceへ直接Importできるかどうかは確認していないため、可否を断定しない。
- **実スキーマ**: 運用者がSequel Aceから、データを含めず構造のみで全テーブルをSQL Exportし、最新mainの`docker/db/sql/*.sql`と比較した。本番には `calenders`・`commands`・`exec_results`・`groups`・`onetime_skips`・`ssid_states`・`ssid_triggers`・`ssids`・`summarize_commands`・`time_triggers`・`users` の11テーブルが存在し、テーブル名は最新mainの11定義と一致する。カラム、型、NULL可否、主要default、PRIMARY KEY、UNIQUE制約を比較した結果、意味のある差分は8.2節の1点のみであり、`INT`と`INT(11)`の表記、`DEFAULT NULL`の明記有無、数値defaultのクォート有無、`ENGINE`/`CHARSET`の明記等のSQL dump上の表記差は実質的なスキーマ差として扱っていない。

**重要**: 本番DBへの実際のrestoreは破壊的操作であるため、このIssueでは実施していない。「実際にrestoreして成功を確認した」という実績はない。確認できたのは、バックアップ取得経路が利用可能であること、復元操作・復元経路が存在すること、本番障害時に利用できる手段を特定できたことの範囲である。

以下は今回未確認のままであり、推測で埋めない。

- 過去にDB restoreを実施した実績
- gzファイルをSequel Aceへ直接Importできるか
- 本番DBのTLS詳細
- 本番DBのSQL mode
- レプリケーション構成

### 8.2 既知のスキーマ差分: `ssids.ssid`のDEFAULT

本番の実スキーマ確認（8.1節）で、`ssids`テーブルの`ssid`カラムに次の差分が見つかった。

| | 定義 |
| --- | --- |
| 本番 | `ssid VARCHAR(1024) NOT NULL DEFAULT ''` |
| 最新mainの[`docker/db/sql/ssids.sql`](../docker/db/sql/ssids.sql) | `ssid VARCHAR(1024) NOT NULL`（DEFAULTなし） |

**このIssueでは、この差分を解消するための本番DB ALTER、および `docker/db/sql/ssids.sql` の変更を行わない。** 理由は次のとおりである。

1. `docker/db/sql/ssids.sql`は初期commit（2021-08-30、`7e34549046675d7eab3b1f926024444c5465845e`）の時点ですでに`ssid VARCHAR(1024) NOT NULL`でDEFAULTを持たない。その後のSSID関連commit（`196a922d90d92dd52b418c701e3413f8d95222d2`）もコメントを「URL」から「SSID」へ変更しただけで、DEFAULTは変更していない。したがって、少なくともリポジトリ上では今回までの保守作業で発生したschema regressionではない。本番DBにいつ`DEFAULT ''`が設定されたかは確認できていない。
2. 現在のアプリケーションではSSID機能が完結していない。[`current-architecture.md`](current-architecture.md)のとおり、SSID関連テーブルは存在するが、SSID自体・SSID状態のモデル、SSIDを受け取るAPI、SSIDを使った実行処理がなく、`src/routes/api.php`にもSSID関連ルートはない。したがって、現在の本番利用経路ではこのDEFAULT差異による挙動差は発生しない。
3. [`AGENTS.md`](../AGENTS.md)の方針により、対象Issueで必要性のないschema変更を先回りして行わない。

将来SSID機能を再実装するIssueで、`DEFAULT ''`を正とするか、DEFAULTなしを正とするかを改めて判断する。

### 8.3 バックアップ・復元コマンドを記載する場合の注意

将来、本番向けのbackup/restore手順をコマンド例つきで文書化する場合は、次を守る。

- 接続情報（ホスト名、ユーザー名、パスワード）をコマンドライン引数へ直接書かない。パスワードは`MYSQL_PWD`環境変数、`--defaults-extra-file`で指定する設定ファイル（権限600、Git管理外）、またはXServer管理画面の対話プロンプトなど、シェル履歴・プロセス一覧（`ps`）に残らない方法を使う。
- 例（実値はプレースホルダーのままとし、本文書へ実値を書かない）:

  ```shell
  # 例: 設定ファイル経由で認証情報を渡す場合（ファイルは事前にpermission 600で用意し、Gitに含めない）
  mysqldump --defaults-extra-file=/path/to/backup-only.cnf --single-transaction <DB名> > backup.sql
  ```

- バックアップファイル自体にも本番データと同じアクセス制御を適用する（[`secrets-management.md`](secrets-management.md) 3.3節）。

## 9. 関連ドキュメント

- PHP 8.5本番切り替えrunbook（Issue #226）: [`php85-production-switch.md`](php85-production-switch.md)
- ロールバック条件・手順: [`rollback.md`](rollback.md)
- deploy作業時のチェックリスト: [`deployment-checklist.md`](deployment-checklist.md)
- 運用・本番情報の詳細と未確認事項: [`current-operations.md`](current-operations.md)
- 秘密情報の管理: [`secrets-management.md`](secrets-management.md)
- 依存関係更新（Dependabot）運用: [`dependency-updates.md`](dependency-updates.md)
- アーキテクチャ: [`architecture.md`](architecture.md)
- ドメインルール: [`domain.md`](domain.md)
