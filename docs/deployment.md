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
3. `src/composer.lock` のハッシュで `src/vendor` のキャッシュを復元する。
4. キャッシュがヒットしない場合だけ、Composerコンテナで `composer install`（dev依存を含む既定の `install`。`--no-dev` は指定していない）を実行する。
5. SSHで配備先へ接続し、`cd ${{ secrets.SSH_DIR }} && ls | grep -v -E 'logs' | xargs rm -rf` を実行する。`ls`（`-a`なし）はドットファイルを列挙しないため、`.env` はこの削除対象に現れない。`logs` はパターンで明示的に除外される。それ以外の通常表示されるファイル・ディレクトリはすべて削除される。
6. `rsync -av` で `src/` の内容を配備先へ転送する。`--exclude` は `logs`・`phpunit.xml`・`.env`・`tests` の4つ。
7. `8398a7/action-slack@v3` でSlackへ結果を通知する。

この文書整備の一環として、Slack通知ステップに `if: always()` を追加した（3.5節参照）。それ以外の起動条件・Secrets構成・Actionsバージョンは変更していない。

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

このIssueで、現在のdeploy workflowの安全性を確認した結果、**文書化だけでは回避できない問題を1件確認し、最小修正した。**

### 3.1 Slack通知が失敗時に送られない問題

修正前の `Slack Notification` ステップには `if:` 条件がなく、それより前のステップ（SSH鍵配置、`composer install`、`delete server files`、`rsync`のいずれか）が失敗すると、GitHub Actionsの既定動作としてこのステップ自体がスキップされ、**失敗の通知が一切飛ばない。**

前述のとおり本deployは「削除してから転送する」方式であり、失敗時にサーバー側が壊れた状態になり得る。それにもかかわらず失敗が通知されないと、人間が気づかないまま本番が壊れた状態で放置される恐れがある。実際、同リポジトリの `.github/workflows/test.yaml` の `notify` ジョブは `if: ${{ always() && ... }}` を使っており、失敗時にも通知する設計になっている。

**対応:** `Slack Notification` ステップに `if: always()` を追加した。これにより、`job.status` が `failure` の場合もSlackへ通知される。

- `v*` タグによる起動条件は変更していない。
- 本番PHPは変更していない。
- 新しいSecretは追加していない。
- Actionsのバージョン更新は目的としない。`actions/checkout`・`actions/cache`等のバージョンはDependabot運用（[`dependency-updates.md`](dependency-updates.md)）に委ね、本文書では特定のバージョン番号を前提にしない。
- 実際のdeployは起動していない。
- `actionlint` で静的検証済み。本文書の更新時点（2026-08-08、`agent/issue-224-deployment-docs`を最新mainへ追従させた状態）では、`composer install` ステップの `shellcheck SC2086` 指摘（既存の挙動）のみが残っており、`actions/checkout`・`actions/cache`のバージョンに関する指摘はない。今回の`if: always()`追加によって新たに増えた指摘はない。Dependabotが今後Actionsのバージョンを更新した場合、この結果は再度変わり得るため、実施時点の最新mainを基準に読み替えること。

### 3.2 その他確認したが変更しなかった点

- Actionsのバージョンが古い場合: Dependabot運用に委ねる方針のため、本Issueでは更新しない（バージョンは実施時点のmainの状態に従う）。
- `composer install` ステップの `$PWD` 未クォート（shellcheck指摘）: 既存の挙動であり、動作を変える修正は本Issueのスコープ外と判断し変更しない。
- 削除→rsync方式そのもの、atomicな切り替えがないこと: ワークフロー全体の作り直しが必要でありIssue #224の範囲を超えるため、2.2節の運用対策と[`rollback.md`](rollback.md)で対応する。

## 4. deploy前チェック（人間が実施）

タグをpushする前に、少なくとも次を確認する。

1. **対象commit / tagの確認**: デプロイしたいcommitがどれか特定し、そのcommitに対して意図したtag名（`v*`）を付与する。既存tagと衝突していないか確認する。
2. **main CI成功確認**: 対象commitに対応する `.github/workflows/test.yaml`（`make check`）がGitHub Actions上で成功していることを確認する。
3. **PHP / Laravel / Composer platform要件の確認**: `src/composer.json` の `require.php`（現在 `^8.3`）・`laravel/framework`（現在 `^13.0`）を確認する。
4. **本番PHPが対象リリースを実行可能か**: 本番PHPの実バージョンを人間が確認し、3で確認したComposer platform要件を満たすか判定する。**現時点（Issue #226完了前）は本番PHPが8.2.30であり `^8.3` を満たさないため、mainの現在のリリースを本番へデプロイしてはならない。** この判定を飛ばして安易にdeployしないこと。
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

**現在の`.github/workflows/deploy.yaml`は`--no-dev`を指定しない`composer install`を実行しており、dev依存を含めてインストールしている。** これは本文書が定義する「今後安全に実施する手順」とは異なる、現状の実装の挙動である（2節参照）。本Issueでは、本番PHPがLaravel 13を実行できない制約（4節）があるため、実際に本番向けComposer installを実行して確認することはしない。この差分の解消（deploy workflowを`--no-dev`へ寄せるか、別の理由で現状維持とするか）は、Issue #226で実際にLaravel 13を本番へ載せる際に判断する。

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

差分が空であれば、このリリースのDB適用は「適用なし」と判定できる。この場合、8節のDBバックアップは通常運用のバックアップサイクルに従えばよく、schema変更に伴う追加のバックアップは不要である。

### 7.2 schema変更がある場合

`docker/db/sql/` に差分がある場合、次を対象リリースのIssue/PRで明示する運用とする。本文書では汎用手順を先取りしない。

- 変更対象のテーブル・カラムと、その変更が後方互換か破壊的か
- 本番の実スキーマに対してどう適用するか（人間が確認済みの本番DB接続手段を使う。8.3節参照）
- 適用前の本番DBバックアップの取得（8節）
- 適用に失敗した場合、または適用後にアプリケーションが期待どおり動かない場合のロールバック手順（[`rollback.md`](rollback.md) の「schema変更を伴うリリースで問題が起きる」節）

## 8. DBバックアップ／復元

### 8.1 リポジトリから確定できないこと（推測で埋めない）

次の事項は、このリポジトリの内容からは確定できない。**未確認のまま断定しない。** 特に、本番DBがローカルと同じMySQL Server 5.7.35であるとは断定しない（[`current-operations.md`](current-operations.md) 5.4節も参照）。

- 本番DBの製品・バージョン
- 本番DBへの接続方法（ホスト、ポート、認証方式、TLS要否）
- XServer上で利用可能なbackup／restore手段（管理画面のDB機能、`mysqldump`相当のCLIが使えるか、実行できるSSHユーザー権限等）
- 本番の実スキーマ（`docker/db/sql/`との差分の有無を含む）
- 本番DBのバックアップ取得・復元が過去に実施された実績

これらは「人間による本番確認項目」であり、AIエージェントが本番へ接続して確認することはしない。

### 8.2 人間による本番確認項目（deploy運用を始める前に一度確認する）

- [ ] 本番DBの製品・バージョンをXServer管理画面または運用者の記録から確認する
- [ ] 本番DBへの接続方法（XServer管理画面のDB機能／本番アプリサーバーからのローカル接続／その他）を確認する
- [ ] XServer上で利用可能なbackup手段（管理画面のエクスポート機能、SSH経由の`mysqldump`相当コマンド等）を確認する
- [ ] 上記backup手段でのrestore手順（同一手段で戻せるか、別手段が必要か）を確認する
- [ ] 本番の実スキーマが`docker/db/sql/`の定義と一致しているか、差分があれば何かを確認する
- [ ] 確認した内容を、秘密情報（接続文字列の実値、パスワード等）を含めない形で運用者内の記録に残す

**このIssueの時点では、上記いずれも「実行して確認した」わけではない。未確認は未確認のまま記載する。**

### 8.3 バックアップ・復元コマンドを記載する場合の注意

将来、8.2節の確認が完了し、本番向けのbackup/restore手順をコマンド例つきで文書化する場合は、次を守る。

- 接続情報（ホスト名、ユーザー名、パスワード）をコマンドライン引数へ直接書かない。パスワードは`MYSQL_PWD`環境変数、`--defaults-extra-file`で指定する設定ファイル（権限600、Git管理外）、またはXServer管理画面の対話プロンプトなど、シェル履歴・プロセス一覧（`ps`）に残らない方法を使う。
- 例（実値はプレースホルダーのままとし、本文書へ実値を書かない）:

  ```shell
  # 例: 設定ファイル経由で認証情報を渡す場合（ファイルは事前にpermission 600で用意し、Gitに含めない）
  mysqldump --defaults-extra-file=/path/to/backup-only.cnf --single-transaction <DB名> > backup.sql
  ```

- 上記はMySQL系DBを前提にした一般的な例であり、**本番DBがMySQL系であることを含めて8.1節の事項は未確認である。** 実際に使用する製品・手段が確認でき次第、このコマンド例を実物に置き換える。
- バックアップファイル自体にも本番データと同じアクセス制御を適用する（[`secrets-management.md`](secrets-management.md) 3.3節）。

## 9. 関連ドキュメント

- ロールバック条件・手順: [`rollback.md`](rollback.md)
- deploy作業時のチェックリスト: [`deployment-checklist.md`](deployment-checklist.md)
- 運用・本番情報の詳細と未確認事項: [`current-operations.md`](current-operations.md)
- 秘密情報の管理: [`secrets-management.md`](secrets-management.md)
- 依存関係更新（Dependabot）運用: [`dependency-updates.md`](dependency-updates.md)
- アーキテクチャ: [`architecture.md`](architecture.md)
- ドメインルール: [`domain.md`](domain.md)
