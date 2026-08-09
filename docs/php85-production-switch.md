# PHP 8.5 本番切り替えrunbook

この文書は、Issue #226「本番環境をPHP 8.5系へ切り替える」専用の手順書である。本番（XServer）Web PHPを8.2.30からPHP 8.5系へ切り替える一連のメンテナンス作業を、実施順序・役割分担・安全確認とともに整理する。

**このIssueの範囲では、実際の本番操作（XServer操作、tag作成、deploy実行、PHPバージョン切り替え、Issue close）は行わない。** 以下は次回以降この切り替えを実施する人間（運用者）向けの手順である。リポジトリ側の準備（[`deployment.md`](deployment.md) 6.1節の`--no-dev`固定環境化）は本Issueで完了済みであることを前提にする。

前提となる通常のデプロイ手順・バックアップ方針・ロールバック方針・監視方針は、それぞれ [`deployment.md`](deployment.md)・[`deployment-checklist.md`](deployment-checklist.md)・[`rollback.md`](rollback.md)・[`monitoring.md`](monitoring.md)・[`current-operations.md`](current-operations.md) を参照する。本文書はこれらと重複する一般手順を繰り返さず、PHP 8.5切り替え固有の論点だけをまとめる。

## 1. 事前条件（Issue #226より）

切り替えに着手する前に、次がすべて満たされていることを確認する。

- PHP 8.5系とLaravel 13で `.github/workflows/test.yaml`（`make check`）が成功している。
- 主要ユースケースの回帰テストが成功している。
- デプロイ、DBバックアップ、ロールバック手順が整備されている（[`deployment.md`](deployment.md)・[`rollback.md`](rollback.md)、本Issueで`--no-dev`固定環境化を追加済み）。
- 本番環境で必要なPHP拡張が確認されている（4節）。

これらが未確認・未整備のまま切り替えへ進まないこと。

## 2. 人間とAIの役割

| 作業 | 実施者 |
| --- | --- |
| リポジトリ側のコード・workflow・文書の変更、`make check`、workflowの静的検証（actionlint等） | AIエージェントでも可 |
| XServerへのSSH | 人間のみ |
| XServerサーバーパネル操作（PHP Ver.切替を含む） | 人間のみ |
| 本番DB操作（backup／restoreを含む） | 人間のみ |
| 本番`.env`操作・確認 | 人間のみ |
| tag作成・push | 人間のみ |
| deploy実行 | 人間のみ |
| Cron設定変更 | 人間のみ |
| Issue close | 人間のみ |

AIエージェントは、上記のいずれも実施しない。本文書はAIエージェントが作成する手順書・チェックリストであり、実施は人間が行う。役割分担の基本方針は [`deployment.md`](deployment.md) 1節と同じである。

## 3. XServer PHP 8.5の仕様（運用時に実環境で確認すること）

本文書作成時点でのXServer公式仕様に基づく前提は次のとおりである。**この前提は運用時に人間が実環境で再確認すること。**

- **Web側**: XServerサーバーパネルの「PHP Ver.切替」で、ドメイン単位にPHP 8.5.xへ変更する。
- **CLI側**: PHP 8.5 CLIの公式コマンドパスは `/usr/bin/php8.5` である。

**patch version（8.5.5等）を本文書やIssue記録へ固定的に書かない。** 切り替え当日、本番SSH上で次を実行し、実際に有効なpatch versionを確認する。

```shell
/usr/bin/php8.5 -v
```

この出力（patch versionを含む）を、Issue #226の実施結果として人間が記録する。

本番SSH上でPHP 8.5を明示して実行する`artisan`等のコマンドは、bareの`php`ではなく、原則として`/usr/bin/php8.5`を使用する。Web PHPをドメイン単位で切り替えても、SSHログインシェルの`php`コマンドが自動的にPHP 8.5を指すとは限らないため、CLI実行では常にパスを明示する。

## 4. PHP拡張の事前確認

切り替え前に、PHP 8.5側で本番稼働に必要な拡張が揃っているかを人間が確認する。

### 4.1 必要な拡張の特定（根拠はリポジトリ側で用意済み）

必要な拡張は、`src/composer.lock`のplatform要件と、`composer check-platform-reqs --no-dev`の実行結果を根拠とする。ローカル・CIでは、`docker/web/Dockerfile`が導入する拡張（`pdo_mysql`・`intl`・`gd`・`zip`等）と`make check`内の`composer check-platform-reqs --no-dev`（Makefile参照）で、本番向け（`--no-dev`）の拡張要件を機械的に確認している。切り替え前に、直近の`make check`成功結果、または次のコマンドをローカル・CI相当の環境で実行し、要求される拡張一覧を確認する。

```shell
cd src && composer check-platform-reqs --no-dev
```

### 4.2 XServer側での確認

XServer本番SSH上で、PHP 8.5 CLIが読み込んでいる拡張を確認する。

```shell
/usr/bin/php8.5 -m
```

4.1で特定した必要拡張と突き合わせ、不足がないか人間が確認する。

### 4.3 不足時の対応

**必要な拡張が1つでも不足している場合は、切り替えを中止する。** 拡張追加の可否・方法（XServer側で追加可能か、追加不可でLaravel側の依存を調整する必要があるか）を確認してから、日を改めて再度この手順から実施する。

### 4.4 秘密情報の扱い

`-m`の出力は拡張名の一覧であり、通常は秘密情報を含まないが、確認作業の過程で`.env`の内容や接続情報を誤って出力・記録しないこと。拡張確認の結果（拡張名の一覧・不足の有無）以外は出力・記録しない。

## 5. Cron / Scheduler

[`current-operations.md`](current-operations.md) 5.5節のとおり、本番のOS側cron設定（Laravel Schedulerを起動する仕組み）は現時点でリポジトリから確認できない。**XServerではCronからPHPを実行する際にPHP CLIパスを明示できるため、Web側PHPを切り替えただけでCronも自動的にPHP 8.5になるとは仮定しない。**

### 5.1 切り替え前の人間による確認

切り替え作業に入る前に、人間がXServerのCron設定を確認し、少なくとも次を記録する。

- 現在登録されているcommandの内容（PHP実行部分を含む）
- PHP 8.2系のパス（例: `/usr/bin/php8.2`相当）へ固定されているかどうか
- Laravel Scheduler（`php artisan schedule:run`相当）を何が・どの間隔で起動しているか

**実際のCron設定内容は本文書では推測しない。** 上記は人間が実環境を見て確認し、Issue #226の実施結果として記録する項目である。

### 5.2 PHP 8.2固定の場合の対応

確認の結果、CronのcommandがPHP 8.2系のパスへ明示的に固定されている場合、本番切り替え時にそのcommandを`/usr/bin/php8.5`を使うよう更新する必要がある。Web PHPの切り替えとは別作業として、Cron設定自体の変更が必要になる（9節の切り替え順序を参照）。

### 5.3 切り替え中の停止・再開

deploy中は、新旧ファイルが混在した状態（[`deployment.md`](deployment.md) 2.2節の削除→rsync方式のリスク）になり得る。この間に毎分Schedulerが起動すると、不完全な状態のコードを実行してしまう可能性がある。安全のため、次の順序で人間が対応する。

1. 切り替え作業（9節のバックアップ以降）に入る前に、XServerのCron設定でLaravel Schedulerの起動を停止する（cron自体をコメントアウトする、無効化する等、XServerで可能な方法を人間が選択する）。
2. deploy・PHP切り替え・Laravel cache再構築・主要機能確認（9節）が完了するまで、Schedulerは停止したままにする。
3. 主要機能確認が完了してから、Cron設定を（必要ならPHP 8.5のパスへ更新したうえで）再度有効化し、Schedulerを再開する。
4. 再開後は、直近の`time:trigger`実行と[`monitoring.md`](monitoring.md) 3節のscheduler heartbeat（`logs/scheduler-heartbeat.json`）が更新されることを確認する（9節・10節）。

## 6. deploy前backup

Issue #226では、対象リリースにschema変更が含まれるかどうかに関係なく、切り替え直前に次の両方のバックアップを取得する。

- **本番アプリケーションファイル**: [`deployment.md`](deployment.md) 5節の手順に従う。
- **本番DB**: [`deployment.md`](deployment.md) 8節の手順に従う。

schema変更がない場合でも省略しない。PHP・フレームワークのメジャーバージョンが変わる切り替えであり、通常のコードリリースより影響範囲の見積もりが難しいため、DBバックアップも毎回取得する運用とする。

`.env`・バックアップ内の秘密情報の取り扱いは、既存の [`secrets-management.md`](secrets-management.md) の方針（3.3節・5節）にそのまま従う。バックアップ自体にも本番データと同じアクセス制御を適用する。

## 7. schema / migration

最新mainを確認した結果、次を再確認している（[`deployment.md`](deployment.md) 7節・8.1節、[`current-operations.md`](current-operations.md) 2.4節）。

- Laravel migrationは存在しない。
- 本番DBの実スキーマは運用者が確認済みで、意味のある差分は現在未使用の`ssids.ssid`カラムのDEFAULTのみである（[`deployment.md`](deployment.md) 8.2節）。この差分はSSID機能が未実装であるため現在の本番利用経路に影響しない。

したがって、**このリリースでは migration適用なし、DB ALTERなし** とする。`php artisan migrate --force`を「念のため」実行する手順は設けない。DB側の変更が必要になった場合は、[`deployment.md`](deployment.md) 7.2節の手順（対象Issue/PRでの個別明示）に従い、Issue #226とは別に扱う。

## 8. 切り替え順序

### 8.1 現在のdeploy方式の確認

[`deployment.md`](deployment.md) 2節のとおり、現在の`.github/workflows/deploy.yaml`は次の方式である。

- GitHub Actions runner上で、本Issueで固定した`docker/web/Dockerfile`の環境（PHP 8.5.9 + Composer 2.8.12）を使って本番用vendor（`--no-dev`）を生成する。
- SSHで配備先の既存の通常ファイルを削除する。
- rsyncで新しいコードを配備先へ転送する。

**XServer側のPHPは、deploy workflowの実行中には一切使用されない。** deploy自体はXServer上のPHPバージョンに依存せず成功し得る。PHPバージョンの依存が表面化するのは、配備されたコードが実際にリクエスト・Schedulerで実行されるタイミングである。

### 8.2 順序を決める際の制約

Issue #226のメンテナンス作業には、次の制約がある。

- Laravel 13版はPHP 8.2では起動できない（`composer.json`の`require.php`が`^8.3`）。
- 現在本番にある旧版が、PHP 8.5で確実に起動する保証もない（未検証）。
- deployは「削除→rsync」方式でありatomicではない（[`deployment.md`](deployment.md) 2.2節）。
- Schedulerが毎分動く可能性がある（5節）。

### 8.3 採用する順序と理由

以上を踏まえ、次の順序を採用する。

1. **事前確認**（1節の事前条件、4節のPHP拡張確認、5.1節のCron確認を含む）
2. **Cron / Schedulerの停止**（5.3節）
3. **アプリ・DBのbackup**（6節）
4. **release deploy**（tag push → GitHub Actions deploy workflow。PHP 8.5.9/Composer 2.8.12固定環境で生成した`--no-dev`vendorを配備）
5. **XServer Web PHPをPHP 8.5系へ切り替え**（3節、サーバーパネル「PHP Ver.切替」）
6. **PHP 8.5 CLI（`/usr/bin/php8.5`）でLaravel cacheを再構築**（10節）
7. **CronをPHP 8.5で再設定・再開**（5.3節）
8. **主要機能確認**（11節）
9. **Scheduler heartbeat確認**（11節、[`monitoring.md`](monitoring.md) 3節）
10. **warning / deprecation / exceptionログの確認**（11節）
11. **実施結果の記録**（13節）

**4（release deploy）を5（Web PHP切り替え）より先に置く理由**は次のとおりである。

- 「先にPHP切り替え→後でdeploy」の順序にすると、PHP切り替え直後から新コード配備までの間、**動作検証していない「旧アプリ×PHP8.5」の組み合わせ**が公開状態になる。この組み合わせが動くかどうかは事前に分からない未知のリスクである。
- 「先にdeploy→後でPHP切り替え」の順序では、rsync完了からPHP切り替え完了までの間、**「新アプリ（Laravel 13）×PHP 8.2」は起動できない**ことが事前に判明している。これは未知のリスクではなく、既知の・想定内の失敗モードである。
- rsync自体がatomicでないため、通常のdeployでも配備直後に短時間アプリが不安定になるリスクは元々ある（[`deployment.md`](deployment.md) 2.2節）。この既存のリスクに、「PHP切り替え完了までの間、新コードが正しく起動しない」という既知の状態が一時的に重なるだけであり、影響の性質が変わるわけではない。
- Scheduler停止（手順2）により、この一時的な期間中に毎分のバッチ処理が壊れたコードを実行することは防げる。残るリスクは公開HTTPアクセスに対する一時的なエラー応答であり、影響範囲を限定できる。

このため、**手順4と手順5の間隔をできる限り短くすること**を運用上の要件とする。事前にリハーサル・手順の読み合わせを行い、tag pushからPHP切り替え完了までを人間が間を置かず連続して実施する。この間の公開アクセスでエラー応答が発生すること自体は想定内であり、単独では11節のロールバック判断のトリガーにしない。手順5が完了しても回復しない場合、または想定より大幅に手順5が遅延する場合は、[`rollback.md`](rollback.md)に沿ったロールバックを検討する。

### 8.4 既存ガードとの整合

[`deployment.md`](deployment.md) 4節は、「本番PHPが8.2.30である間はmainの現在のリリース（Laravel 13、PHP `^8.3`要求）をデプロイしてはならない」という、**通常運用のdeployに対するガード**である。このガードは維持する。

Issue #226の本手順は、通常運用の中で不用意にLaravel 13をdeployするものではなく、**Scheduler停止・バックアップ取得・PHP切り替え・Laravel cache再構築・主要機能確認・実施結果記録までを一体の計画されたメンテナンス作業として、人間が連続して実施するもの**である。8.3節の順序は、この計画されたメンテナンス作業の内部手順であり、通常運用のガードとは矛盾しない。メンテナンス作業完了後（手順11の実施結果記録まで完了した状態）は、本番PHPは8.5系であり、Laravel 13を通常運用でデプロイしてよい状態になる。

## 9. Composer / 本番依存関係

本Issueのリポジトリ側変更により、`.github/workflows/deploy.yaml`は次の内容へ変更済みである（詳細は[`deployment.md`](deployment.md) 6.1節）。

- 依存関係の生成環境を、`docker/web/Dockerfile`のPHP 8.5.9 + Composer 2.8.12（CIと同一）へ固定した。
- `composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader`を、cacheの有無に関わらず必ず実行する。
- production用vendor cacheは廃止した（test用cacheとは元々分離されていた設計ではなく、production側のcache自体を持たない）。

したがって、Issue #226のリリースでは、deploy workflowが生成するvendorは常に`--no-dev`かつCI検証済み環境由来のものであり、本手順で追加の対応は不要である。

## 10. Laravel cache

配備（8.3節の手順4）が完了したら、PHP 8.5 CLI（`/usr/bin/php8.5`）を明示して、[`deployment.md`](deployment.md) 6.2節の安全な順序でLaravel cacheを再構築する。**bareの`php artisan`によるコピペ手順にはしない。**

1. ファイル配備（rsync）が完了していることを確認する。
2. `/usr/bin/php8.5 artisan config:clear` で既存のconfig cacheを破棄する。
3. `/usr/bin/php8.5 artisan route:clear`・`/usr/bin/php8.5 artisan view:clear` で古いルート・ビューキャッシュを破棄する。
4. `/usr/bin/php8.5 artisan cache:clear` でアプリケーションキャッシュを破棄する（祝日キャッシュ`logs/holidays.json`はこの操作では消えない）。
5. 必要な場合のみ `/usr/bin/php8.5 artisan config:cache`・`/usr/bin/php8.5 artisan route:cache`・`/usr/bin/php8.5 artisan view:cache` で再生成する。
6. Composerのオートローダーが新しいコードに追随していることを確認する（deploy workflowが`--no-dev`で生成したvendorがrsyncで配備済みであれば、オートローダーはその時点で更新されている）。

この手順は8.3節の手順6に対応する。手順5（Web PHP切り替え）の完了後、この手順を実施する。

## 11. 本番確認

配備・PHP切り替え・Cron再開（8.3節の手順1〜7）が完了したら、既存の [`deployment-checklist.md`](deployment-checklist.md)「主要機能確認」「scheduler / cron確認」「ログ確認」、および [`monitoring.md`](monitoring.md) の仕組みを再利用して確認する。

確認対象は少なくとも次のとおりである。

| 確認対象 | 方法 |
| --- | --- |
| `/` | 公開URLのルートが200で応答し、サーバー時刻・DB時刻を返すことを確認する（[`current-operations.md`](current-operations.md) 5.1節相当）。 |
| `/health` | `GET /health`が200で応答することを確認する。`ng`のcomponentがあれば[`monitoring.md`](monitoring.md) 7節に沿って切り分ける。 |
| 主要API | コマンド一覧・実行系等、[`deployment-checklist.md`](deployment-checklist.md)の主要機能確認相当のエンドポイントが想定どおり応答することを確認する。 |
| Google認証 / Calendar | `/auth/redirect` → コールバックが成功することを確認する。休日判定・個別カレンダー上書きが想定どおり動作することを確認する。 |
| 外部HTTP実行 | 9.1節を参照。 |
| cron / scheduler | 5.3節で再設定したCronが有効になっており、Laravel Schedulerが起動していることを確認する。 |
| scheduler heartbeat | `logs/scheduler-heartbeat.json`の`time:trigger`が直近で更新されていることを確認する（[`monitoring.md`](monitoring.md) 3節・6節）。 |
| logs | `logs/laravel.log`にPHP/Laravelのwarning・deprecation・例外がないか、切り替え前後の時刻を突き合わせて確認する（11.2節）。 |

### 11.1 外部副作用のある確認への配慮

「外部HTTP実行」の確認は、登録済みコマンドを実際に手動実行すると、コマンドの実行先（外部サービス）へ実際にHTTPリクエストが飛び、副作用（外部システムの状態変更、重複実行等）が生じ得る。次のいずれかを人間が状況に応じて選択する。

- **安全なコマンドがある場合**: 副作用がない、またはべき等であることが分かっているコマンド（例: 参照系のみのエンドポイントを叩くコマンド）を選んで手動実行し、`exec_results`とAPIレスポンスを確認する。
- **安全なコマンドがない場合**: 実際の手動実行は行わず、直近の`time:trigger`実行結果（`exec_results`、[`monitoring.md`](monitoring.md) 9節の`external_http.no_response`等のログイベント）を確認するにとどめる。切り替え後、次回の定期実行（時間トリガー対象）が成功しているかを事後的に確認する形で代替する。

どちらを選ぶかは、登録されているコマンドの内容を把握している人間が判断する。副作用の有無が不明なコマンドを「確認のため」に実行しない。

### 11.2 ログ確認

[`monitoring.md`](monitoring.md) 6節のとおり`logs/laravel.log`を確認し、切り替え作業前後の時刻に、想定外のPHP/Laravelのwarning・deprecation・例外が発生していないかを確認する。PHP 8.5への切り替えに伴うdeprecation警告は、Laravel側・アプリケーション側どちらのコードに起因するかを切り分け、アプリケーション側のコードに起因する場合は後続Issueとして記録する。

## 12. rollback

今回のリリースはschema変更を伴わない（7節）。したがって、**通常の障害ではDB restoreを最初の選択肢にしない。** 基本のロールバックは、[`rollback.md`](rollback.md) 4節（アプリケーションファイルのみの切り戻し）を、PHP切り替えに合わせて次のように拡張した手順とする。

1. **Scheduler停止**: 5.3節と同じ方法で、Cronを再度停止する。
2. **直前アプリケーションバックアップへ戻す**: 6節で取得した切り替え直前のアプリケーションファイルの控えを配備先へ復元する（[`rollback.md`](rollback.md) 4節の要領）。
3. **`.env` / `logs`を上書きしない**: 復元時に配備先の`.env`・`logs`には触れない（[`rollback.md`](rollback.md) 4節3項）。
4. **XServer Web PHPを8.2系へ戻す**: サーバーパネルの「PHP Ver.切替」で対象ドメインをPHP 8.2系へ戻す。
5. **必要なら旧PHP CLIで旧アプリのcacheを再構築する**: 復元したアプリケーションのバージョンに応じたPHP CLI（8.2系）で、[`deployment.md`](deployment.md) 6.2節の順序に沿ってLaravel cacheを再構築する。
6. **主要機能確認**: [`deployment-checklist.md`](deployment-checklist.md)の主要機能確認相当の項目で復旧を確認する。
7. **Cronを元の状態へ戻す**: 5.1節で記録した切り替え前のCron設定（PHP 8.2系のパスを含む）へ戻し、Schedulerを再開する。再開後、[`monitoring.md`](monitoring.md)のscheduler heartbeatが更新されることを確認する。

DB restoreは、[`rollback.md`](rollback.md) 5節の開始条件（schema変更適用後の不整合、または意図しないデータ破損・不整合が実際に確認された場合）に該当する場合のみ、既存手順へ進む。**今回のリリースはschema変更を伴わないため、通常はこの経路に進む理由がない。** DB破損等の実害が確認された場合に限り、[`rollback.md`](rollback.md) 5節の手順を実施する。

## 13. 実施結果の記録

Issue #226の実施結果として、少なくとも次を記録する。

- 実際に確認したPHP 8.5のpatch version（3節、`/usr/bin/php8.5 -v`の出力）
- PHP拡張確認の結果（4節、不足がなかったこと、または対応内容）
- 切り替え前のCron設定の確認結果（5.1節）と、切り替え後のCron再設定内容（5.3節）
- backupの取得先・取得日時（6節）
- 8.3節の各手順の実施日時
- 11節の主要機能確認・scheduler heartbeat確認・ログ確認の結果
- ロールバックを実施した場合、判断理由・実施内容・結果（12節）
- 未解決の問題・後続Issue候補（deprecation警告の扱い等）

秘密情報（`.env`の実値、DB接続情報、APIキー等）は記録に含めない（[`secrets-management.md`](secrets-management.md)）。

## 14. 関連ドキュメント

- デプロイ手順・deploy前チェック・バックアップ方針: [`deployment.md`](deployment.md)
- デプロイ作業チェックリスト: [`deployment-checklist.md`](deployment-checklist.md)
- ロールバック手順: [`rollback.md`](rollback.md)
- 監視（health check / scheduler heartbeat）: [`monitoring.md`](monitoring.md)
- 運用・本番情報の詳細と未確認事項: [`current-operations.md`](current-operations.md)
- 秘密情報の管理: [`secrets-management.md`](secrets-management.md)
