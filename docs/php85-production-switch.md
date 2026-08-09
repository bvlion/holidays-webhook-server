# PHP 8.5 本番切り替えrunbook

この文書は、Issue #226「本番環境をPHP 8.5系へ切り替える」専用の手順書である。本番（XServer）Web PHPを8.2.30からPHP 8.5系へ切り替える一連のメンテナンス作業を、実施順序・役割分担・安全確認とともに整理する。

**このrunbookは、Issue #226のリポジトリ側事前準備であるPull Request（PR #256）に含まれる文書である。PR #256の範囲では、実際の本番操作（XServer操作、tag作成、deploy実行、PHPバージョン切り替え、Cron設定変更、Issue close）を一切行っていない。** 以下は、PR #256のマージ後、**Issue #226の実際の本番切り替え作業で人間（運用者）がそのまま使用する手順**である。リポジトリ側の準備（[`deployment.md`](deployment.md) 6.1節の`--no-dev`固定環境化）はPR #256で完了済みであることを前提にする。

前提となる通常のデプロイ手順・バックアップ方針・ロールバック方針・監視方針は、それぞれ [`deployment.md`](deployment.md)・[`deployment-checklist.md`](deployment-checklist.md)・[`rollback.md`](rollback.md)・[`monitoring.md`](monitoring.md)・[`current-operations.md`](current-operations.md) を参照する。本文書はこれらと重複する一般手順を繰り返さず、PHP 8.5切り替え固有の論点だけをまとめる。

## 1. 事前条件（Issue #226より）

切り替えに着手する前に、次がすべて満たされていることを確認する。

- PHP 8.5系とLaravel 13で `.github/workflows/test.yaml`（`make check`）が成功している。
- 主要ユースケースの回帰テストが成功している。
- デプロイ、DBバックアップ、ロールバック手順が整備されている（[`deployment.md`](deployment.md)・[`rollback.md`](rollback.md)、PR #256で`--no-dev`固定環境化を追加済み）。
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

本文書作成時点でのXServer公式マニュアル（「プログラム言語・コマンドパス」「FastCGIについて」「PHPのバージョンについて」各ページ）に基づく前提は次のとおりである。**この前提は運用時に人間が実環境で再確認すること。**

- **Web側の切り替え**: XServerサーバーパネルの「PHP Ver.切替」で、ドメイン単位にPHP 8.5.xへ変更する。
- **Web側の実行方式**: XServer公式マニュアルには「いずれのバージョンもFastCGIとキャッシュモジュール（APC/OPcache）が標準で有効」と明記されている。**PHP 8.5もFastCGIが標準・常時有効であり、ドメインごとにCGI方式かFastCGI方式かを選択する仕組みではない。**
- **CLI**: PHP 8.5 CLIの公式コマンドパスは `/usr/bin/php8.5` である。
- **Web用コマンドパス**: XServer公式の「プログラム言語・コマンドパス」一覧では、Web実行用（「PHP 8.5.x (CGI)」区分）のコマンドパスとして `/usr/bin/php8.5-cgi` または `/usr/bin/php-fcgi8.5` の2種類が案内されている。これは「ドメインがCGI方式かFastCGI方式かを選ぶ」という意味ではなく、常時有効なFastCGI経由のWeb実行環境を指すコマンドパスが2種類案内されている、という位置づけである。**どちらのパスが実サーバーで実際に有効かは、サーバーパネルの「コマンドパス一覧」で人間が確認する。存在しないパスを推測で実行しない。**4節の拡張確認は、実際に有効な方のパスで行う。

**patch version（8.5.5等）を本文書やIssue記録へ固定的に書かない。** 切り替え当日、本番SSH上で次を実行し、実際に有効なpatch versionを確認する。

```shell
/usr/bin/php8.5 -v
```

この出力（patch versionを含む）を、Issue #226の実施結果として人間が記録する。

本番SSH上でPHP 8.5を明示して実行する`artisan`等のコマンドは、bareの`php`ではなく、原則として`/usr/bin/php8.5`を使用する。Web PHPをドメイン単位で切り替えても、SSHログインシェルの`php`コマンドが自動的にPHP 8.5を指すとは限らないため、CLI実行では常にパスを明示する。

## 4. PHP拡張の事前確認

切り替え前に、PHP 8.5側で本番稼働に必要な拡張が、**CLIとWeb実行環境（FastCGI）の両方**で揃っているかを人間が確認する。**CLI（`/usr/bin/php8.5 -m`）の確認だけでは、Web実行環境のextension確認を済ませたことにはならない。** アプリケーションへの実リクエストは、3節のとおり標準で常時有効なFastCGI経由のWeb実行環境で処理されるため、CLIとphp.ini相当の設定が異なり得ることを踏まえ、Web側の確認を省略しない。

### 4.1 必要な拡張の特定（根拠はリポジトリ側で用意済み）

必要な拡張は、`src/composer.lock`のplatform要件と、`composer check-platform-reqs --no-dev`の実行結果を根拠とする。ローカル・CIでは、`docker/web/Dockerfile`が導入する拡張（`pdo_mysql`・`intl`・`gd`・`zip`等）と`make check`内の`composer check-platform-reqs --no-dev`（Makefile参照）で、本番向け（`--no-dev`）の拡張要件を機械的に確認している。切り替え前に、直近の`make check`成功結果、または次のコマンドをローカル・CI相当の環境で実行し、要求される拡張一覧を確認する。

```shell
cd src && composer check-platform-reqs --no-dev
```

### 4.2 XServer CLI側での確認

XServer本番SSH上で、PHP 8.5 CLIのバージョンと読み込んでいる拡張を確認する。

```shell
/usr/bin/php8.5 -v
/usr/bin/php8.5 -m
```

4.1で特定した必要拡張と突き合わせ、不足がないか人間が確認する。

### 4.3 XServer Web実行環境（FastCGI）側での確認

3節のとおり、XServerのWeb PHPは常時FastCGIで動作する。XServer公式の「プログラム言語・コマンドパス」一覧では、このWeb実行環境（「PHP 8.5.x (CGI)」区分）のコマンドパスとして `/usr/bin/php8.5-cgi` または `/usr/bin/php-fcgi8.5` の2種類が案内されている。**実サーバーでどちらのパスが実際に有効かを、人間がサーバーパネルの「コマンドパス一覧」で確認する。存在しないパスを推測で実行しない。** 確認できた方のパスで、次を実行する。

```shell
/usr/bin/php8.5-cgi -v
/usr/bin/php8.5-cgi -m
```

または（サーバーパネルで有効と確認できた方）

```shell
/usr/bin/php-fcgi8.5 -v
/usr/bin/php-fcgi8.5 -m
```

4.1で特定した必要拡張と突き合わせる。CLIとWeb実行環境で読み込まれる拡張構成が異なる場合があるため、**4.2節のCLI確認結果をWeb側確認の代わりにしない。**

`phpinfo()`を出力するファイル等を公開Web領域へ一時的に配置する方法は使わない。

### 4.4 不足時の対応

**CLI・Web実行環境（FastCGI）のいずれかで必要な拡張が1つでも不足している場合は、切り替えを中止する。** 拡張追加の可否・方法（XServer側で追加可能か、追加不可でLaravel側の依存を調整する必要があるか）を確認してから、日を改めて再度この手順から実施する。

### 4.5 秘密情報・記録範囲の扱い

`-m`の出力や`php -i`の全文をそのままIssueへ貼り付けない。確認作業の過程で`.env`の内容や接続情報を誤って出力・記録しないこと。Issue #226の実施結果として記録するのは、3節のpatch versionと、「CLI・Web実行環境とも必要拡張の不足なし」（不足があった場合はその内容と対応）という結果だけでよい。拡張名の網羅的な一覧やコマンドの生出力を記録する必要はない。

## 5. Cron / Scheduler

[`current-operations.md`](current-operations.md) 5.5節のとおり、本番でLaravel Schedulerを起動している実際の仕組み（OS側cron、その他のジョブスケジューラ等）は現時点でリポジトリから確認できない。**XServerではCronからPHPを実行する際にPHP CLIパスを明示できるため、Web側PHPを切り替えただけでCronも自動的にPHP 8.5になるとは仮定しない。また、本番の起動機構がXServerのCronであると本文書側で決め打ちもしない。**

### 5.1 切り替え前の人間による確認: Scheduler起動機構の特定

切り替え作業に入る前に、人間がXServer上でLaravel Schedulerの起動機構を確認し、次の分岐に従う。

- **XServerのCron機能で起動している場合**: 5.2節・5.3節の手順に進む。
- **XServer Cron以外の方式（別のジョブスケジューラ、常駐プロセス等）で起動している場合**: その実際の起動機構に応じた停止・再開方法を人間が特定し、5.3節に相当する停止・再開作業をその機構に対して行う。本文書では方式を先取りしない。
- **起動機構を特定できない場合**: **切り替えを中止する。** Schedulerを安全に停止できる保証がない状態で9節のrelease deployへ進まない。

確認するのは少なくとも次の3点である。

- 現在の起動機構の種別（XServer Cronかどうか）と実行間隔
- PHP CLIパスが特定バージョン（例: PHP 8.2系相当）へ明示的に固定されているかどうか
- Laravel Scheduler（`php artisan schedule:run`相当）を何が・どの間隔で起動しているか

**確認した内容の記録方法は5.4節に従う。実際のcommand内容そのものは、本文書にも、GitHub Issue／PR／docsのどこにも生の形では記録しない。**

### 5.2 PHP CLIパスが固定されている場合の対応

5.1節の確認の結果、Scheduler起動機構のcommandがPHP 8.2系相当のパスへ明示的に固定されている場合、本番切り替え時にそのcommandを`/usr/bin/php8.5`を使うよう更新する必要がある。Web PHPの切り替えとは別作業として、Scheduler起動機構側の設定変更が必要になる（9節の切り替え順序を参照）。

### 5.3 停止・再開の基本方針

deploy中は、新旧ファイルが混在した状態（[`deployment.md`](deployment.md) 2.2節の削除→rsync方式のリスク）になり得る。この間にSchedulerが起動すると、不完全な状態のコードを実行してしまう可能性がある。安全のため、5.1節で特定した起動機構を対象に、次を基本方針とする。具体的にどのタイミングで停止・再開するかは9節の切り替え順序に従う。

- release deploy（9節手順5）に入る前に、5.1節で特定した起動機構でのLaravel Scheduler起動を停止する。
- スモークテスト（12.1節、9節手順8）が成功するまで、Schedulerは停止したままにする。**主要機能確認（12.2節）の完了を待たず、スモークテスト成功の時点でいったん再開判断を行う点に注意する。**
- スモークテスト成功後、起動機構の設定を（必要ならPHP 8.5のパスへ更新したうえで）再度有効化し、Schedulerを再開する（9節手順9）。
- 再開後は、直近の`time:trigger`実行と[`monitoring.md`](monitoring.md) 3節のscheduler heartbeat（`logs/scheduler-heartbeat.json`）が更新されることを確認する（12.2節、9節手順10）。

### 5.4 記録方法（公開Issueへ書いてよい範囲）

Cron／Scheduler起動機構のcommandには、XServerアカウント由来のパス、ユーザー名、URL、引数、環境変数、token等の秘密情報が含まれ得る。**実際のcommand内容そのものは、GitHub Issue・PR・リポジトリ内のdocsのいずれにも生の形で記録しない。**

Issue #226へ記録してよいのは、次のような安全な要約だけとする。

- Scheduler起動方式: XServer Cron ／ その他
- 実行間隔
- PHP CLIパスが特定バージョンへ固定されていたか: yes / no
- 切り替え後にPHP 8.5へ更新したか: yes / no
- Scheduler再開確認: OK / NG

正確なraw commandを作業メモとして保持する必要がある場合でも、公開GitHubではなく、運用者が管理する安全な非公開領域でのみ扱う。

## 6. deploy前backup

Issue #226では、対象リリースにschema変更が含まれるかどうかに関係なく、切り替え直前に次の両方のバックアップを取得する。**これは通常デプロイの判定フロー（[`deployment.md`](deployment.md) 7.1節、[`deployment-checklist.md`](deployment-checklist.md)「backup完了」）にある「schema変更がなければDBバックアップは通常運用サイクルでよい」という判定を、Issue #226ではrunbook側の方針で上書きするものである。**

- **本番アプリケーションファイル**: [`deployment.md`](deployment.md) 5節の手順に従う。
- **本番DB**: [`deployment.md`](deployment.md) 8節の手順に従う。

schema変更がない場合でも省略しない。PHP・フレームワークのメジャーバージョンが変わる切り替えであり、通常のコードリリースより影響範囲の見積もりが難しいため、DBバックアップも毎回取得する運用とする。

`.env`・バックアップ内の秘密情報の取り扱いは、既存の [`secrets-management.md`](secrets-management.md) の方針（3.3節・5節）にそのまま従う。バックアップ自体にも本番データと同じアクセス制御を適用する。

**バックアップの取得先を実施結果へ記録する際は、XServerアカウント名等を含む絶対パス（サーバー内の実ディレクトリパス等）をそのまま公開Issueへ記載しない。** 取得日時・取得方法の種別（例: サーバー内コピー／tar化／ダウンロード）程度の記録にとどめる。パス自体を作業メモとして残す必要がある場合は、運用者が管理する安全な非公開領域でのみ扱う（14節）。

## 7. schema / migration

最新mainを確認した結果、次を再確認している（[`deployment.md`](deployment.md) 7節・8.1節、[`current-operations.md`](current-operations.md) 2.4節）。

- Laravel migrationは存在しない。
- 本番DBの実スキーマは運用者が確認済みで、意味のある差分は現在未使用の`ssids.ssid`カラムのDEFAULTのみである（[`deployment.md`](deployment.md) 8.2節）。この差分はSSID機能が未実装であるため現在の本番利用経路に影響しない。

したがって、**このリリースでは migration適用なし、DB ALTERなし** とする。`php artisan migrate --force`を「念のため」実行する手順は設けない。DB側の変更が必要になった場合は、[`deployment.md`](deployment.md) 7.2節の手順（対象Issue/PRでの個別明示）に従い、Issue #226とは別に扱う。

## 8. 通常deployガードとIssue #226初回切り替えの例外条件

[`deployment.md`](deployment.md) 4節と[`deployment-checklist.md`](deployment-checklist.md)「deploy前」は、本番PHPが対象リリースのPHP要件を満たさない場合、通常運用のdeployを中止するガードを定めている。現時点（Issue #226着手前）は本番PHPが8.2.30であり、`composer.json`の`require.php`（`^8.3`）を満たさないため、mainの現在のリリース（Laravel 13）を通常のtag pushでdeployしてはならない。**このガードは維持する。**

一方、9節の切り替え順序では、手順5（release deploy）を手順6（XServer Web PHP 8.5切り替え）より先に実施する（順序を採用した理由は9.3節）。つまり、Issue #226の初回切り替えでは、tag pushを開始する時点（手順5）で本番Web PHPはまだ8.2.30のままである。これは文字どおりには4節のガードが防ごうとする状態に該当する。

**Issue #226の初回切り替えに限り、次の条件をすべて満たす場合だけ、この状態でのtag pushを例外的に許容する。**

**判定するタイミングは2段階に分かれる。** [`deployment-checklist.md`](deployment-checklist.md)「deploy前」を最初に通読する段階では、通常判定どおり「本番PHPがまだ8.2.30で要件を満たさない」ことだけを確認すればよく、下記8項目はまだすべて満たせていないことが通常である（Scheduler停止・両方のバックアップはこれより後の9節の手順で行うため）。**下記8項目すべての最終確認は、実際にtag pushを実行する直前（9節手順5の直前）に改めて行う。**

- [ ] PHP 8.5 CLIおよびWeb実行環境（FastCGI）の両方で事前確認が完了している（3節・4節）
- [ ] 本番稼働に必要なPHP拡張が、CLI・Web実行環境の両方で確認済みであり、不足がない（4節）
- [ ] Scheduler停止方法（実際の起動機構とその停止・再開手段）が確認済みである（5.1節）
- [ ] Schedulerが実際に停止済みである（5.3節、9節手順3）
- [ ] 本番アプリケーションファイルのバックアップを取得済みである（6節）
- [ ] 本番DBのバックアップを取得済みである（6節）
- [ ] XServerサーバーパネルで、追加の承認・待ち時間なしにPHP 8.5へ直ちに切り替えられる状態になっている（3節）
- [ ] release deploy（手順5）とXServer Web PHP切り替え（手順6）を、間を置かず連続した1回のメンテナンス作業として、同じ人間（運用者）が実施する体制が整っている

**上記のいずれか1つでも満たさない場合は、tag pushを開始しない。** すべて満たしていることを確認してから9節手順5へ進む。

Issue #226のメンテナンス作業が完了し（9節手順13の実施結果記録まで完了し、本番PHPが8.5系になった状態）、以降はこの例外を適用しない。**それ以降の通常運用のdeployは、[`deployment.md`](deployment.md) 4節・[`deployment-checklist.md`](deployment-checklist.md)のガードどおり、本番PHPが対象リリースのPHP要件を満たしていることを確認してから行う。**

## 9. 切り替え順序

### 9.1 現在のdeploy方式の確認

[`deployment.md`](deployment.md) 2節のとおり、現在の`.github/workflows/deploy.yaml`は次の方式である。

- GitHub Actions runner上で、PR #256で固定した`docker/web/Dockerfile`の環境（PHP 8.5.9 + Composer 2.8.12）を使って本番用vendor（`--no-dev`）を生成する。
- SSHで配備先の既存の通常ファイルを削除する。
- rsyncで新しいコードを配備先へ転送する。

**XServer側のPHPは、deploy workflowの実行中には一切使用されない。** deploy自体はXServer上のPHPバージョンに依存せず成功し得る。PHPバージョンの依存が表面化するのは、配備されたコードが実際にリクエスト・Schedulerで実行されるタイミングである。

### 9.2 順序を決める際の制約

Issue #226のメンテナンス作業には、次の制約がある。

- Laravel 13版はPHP 8.2では起動できない（`composer.json`の`require.php`が`^8.3`）。
- 現在本番にある旧版が、PHP 8.5で確実に起動する保証もない（未検証）。
- deployは「削除→rsync」方式でありatomicではない（[`deployment.md`](deployment.md) 2.2節）。
- Schedulerが動く可能性がある（5節）。

### 9.3 採用する順序と理由

以上を踏まえ、次の順序を採用する。

1. **事前確認**（1節の事前条件、4節のPHP拡張確認[CLIとWeb実行環境の両方]を含む）
2. **Scheduler起動機構の特定**（5.1節）
3. **Scheduler停止**（5.1節で特定した起動機構を、5.3節の方法で停止する）
4. **アプリ・DBのbackup**（6節）
5. **release deploy**（8節の例外条件をすべて満たしていることを確認したうえで、tag push → GitHub Actions deploy workflow。PHP 8.5.9/Composer 2.8.12固定環境で生成した`--no-dev`vendorを配備。10節）
6. **XServer Web PHPをPHP 8.5系へ切り替え**（3節、サーバーパネル「PHP Ver.切替」）
7. **PHP 8.5 CLI（`/usr/bin/php8.5`）でLaravel cacheを再構築**（11節）
8. **Schedulerを止めたまま、副作用のない最低限のsmoke testを行う**（12.1節: `/`・`/health`・DB接続を含むread-only確認）
9. **smoke test成功後、Schedulerを再開する**（必要ならPHP CLIパスを8.5へ更新したうえで、5.1節で特定した起動機構を5.2節・5.3節の方法で再開する）
10. **scheduler heartbeatと`time:trigger`を確認する**（12.2節、[`monitoring.md`](monitoring.md) 3節）
11. **Google連携・外部HTTP実行を含む残りの主要機能確認を行う**（12.2節・12.3節）
12. **warning / deprecation / exceptionログを確認する**（12.4節）
13. **実施結果を記録する**（14節）

**手順5（release deploy）を手順6（Web PHP切り替え）より先に置く理由**は次のとおりである。

- 「先にPHP切り替え→後でdeploy」の順序にすると、PHP切り替え直後から新コード配備までの間、**動作検証していない「旧アプリ×PHP8.5」の組み合わせ**が公開状態になる。この組み合わせが動くかどうかは事前に分からない未知のリスクである。
- 「先にdeploy→後でPHP切り替え」の順序では、rsync完了からPHP切り替え完了までの間、**「新アプリ（Laravel 13）×PHP 8.2」は起動できない**ことが事前に判明している。これは未知のリスクではなく、既知の・想定内の失敗モードである。
- rsync自体がatomicでないため、通常のdeployでも配備直後に短時間アプリが不安定になるリスクは元々ある（[`deployment.md`](deployment.md) 2.2節）。この既存のリスクに、「PHP切り替え完了までの間、新コードが正しく起動しない」という既知の状態が一時的に重なるだけであり、影響の性質が変わるわけではない。
- Scheduler停止（手順3）により、この一時的な期間中にバッチ処理が壊れたコードを実行することは防げる。残るリスクは公開HTTPアクセスに対する一時的なエラー応答であり、影響範囲を限定できる。

このため、**手順5と手順6の間隔をできる限り短くすること**を運用上の要件とする（8節の例外条件にも含まれる）。事前にリハーサル・手順の読み合わせを行い、tag pushからPHP切り替え完了までを人間が間を置かず連続して実施する。この間の公開アクセスでエラー応答が発生すること自体は想定内であり、単独では13節のロールバック判断のトリガーにしない。手順6が完了しても回復しない場合、または想定より大幅に手順6が遅延する場合は、[`rollback.md`](rollback.md)に沿ったロールバックを検討する。

**手順8（スモークテスト）を手順9（Scheduler再開）より先に置く理由**は次のとおりである。

- Schedulerを再開する前に、副作用のない最小限の確認（`/`・`/health`・DB接続のread-only確認）で新環境が最低限動作していることを確かめることで、万一新環境に重大な問題があった場合に、Scheduler経由の外部HTTP実行やDB更新処理が壊れた環境のまま走り続けるリスクを避けられる。
- スモークテストが失敗した場合はSchedulerを再開せず、13節のロールバック手順を検討する。主要機能確認（手順11）の完了を待ってからSchedulerを再開すると、その間Schedulerが停止したままになり定期実行の欠落が長引くため、スモークテストという最小限の確認だけを再開の条件とする。

### 9.4 通常運用との関係

8節の例外条件を満たす場合に限り、手順5開始時点で本番PHPが8.2.30のままであることを認める。メンテナンス作業完了（手順13）後は、本番PHPは8.5系であり、以降のdeployは[`deployment.md`](deployment.md) 4節・[`deployment-checklist.md`](deployment-checklist.md)の通常ガードに従う。

## 10. Composer / 本番依存関係

PR #256のリポジトリ側変更により、`.github/workflows/deploy.yaml`は次の内容へ変更済みである（詳細は[`deployment.md`](deployment.md) 6.1節）。

- 依存関係の生成環境を、`docker/web/Dockerfile`のPHP 8.5.9 + Composer 2.8.12（CIと同一）へ固定した。
- `composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader`を、cacheの有無に関わらず必ず実行する。
- production用vendor cacheは廃止した（test用cacheとは元々分離されていた設計ではなく、production側のcache自体を持たない）。

したがって、Issue #226のリリースでは、deploy workflowが生成するvendorは常に`--no-dev`かつCI検証済み環境由来のものであり、本手順で追加の対応は不要である（9節手順5）。

## 11. Laravel cache

9節の手順6（XServer Web PHP切り替え）が完了したら、PHP 8.5 CLI（`/usr/bin/php8.5`）を明示して、[`deployment.md`](deployment.md) 6.2節の安全な順序でLaravel cacheを再構築する（9節手順7）。**bareの`php artisan`によるコピペ手順にはしない。**

1. ファイル配備（rsync、9節手順5）が完了していることを確認する。
2. `/usr/bin/php8.5 artisan config:clear` で既存のconfig cacheを破棄する。
3. `/usr/bin/php8.5 artisan route:clear`・`/usr/bin/php8.5 artisan view:clear` で古いルート・ビューキャッシュを破棄する。
4. `/usr/bin/php8.5 artisan cache:clear` でアプリケーションキャッシュを破棄する（祝日キャッシュ`logs/holidays.json`はこの操作では消えない）。
5. 必要な場合のみ `/usr/bin/php8.5 artisan config:cache`・`/usr/bin/php8.5 artisan route:cache`・`/usr/bin/php8.5 artisan view:cache` で再生成する。
6. Composerのオートローダーが新しいコードに追随していることを確認する（deploy workflowが`--no-dev`で生成したvendorがrsyncで配備済みであれば、オートローダーはその時点で更新されている）。

## 12. 本番確認

9節の切り替え順序のうち、確認作業は2段階に分かれる。

- **12.1 スモークテスト**（9節手順8）: Schedulerを止めたまま、副作用がなく、Schedulerの状態にも依存しない最低限の項目だけを確認する。
- **12.2 主要機能確認**（9節手順10・11）: Scheduler再開後に、[`deployment-checklist.md`](deployment-checklist.md)「主要機能確認」「scheduler / cron確認」、および[`monitoring.md`](monitoring.md)の仕組みを再利用して確認する。

### 12.1 スモークテスト（Scheduler停止中、9節手順8）

| 確認対象 | 方法 |
| --- | --- |
| `/` | 公開URLのルートが200で応答し、サーバー時刻・DB時刻を返すことを確認する（[`current-operations.md`](current-operations.md) 5.1節相当）。 |
| `/health` | `GET /health`のレスポンスを次の基準で判定する（[`monitoring.md`](monitoring.md) 2節のとおり、`app`/`database`/`config`/`scheduler`の4componentすべてが`ok`ならHTTP 200、1つでも`ng`ならHTTP 503を返す仕様であり、この文書はその仕様自体を変更しない）。 |
| DB接続（read-only） | `/health`の`database`component、または`/`のDB時刻表示により、読み取り専用のDB接続が成立していることを確認する。書き込みを伴う確認はここでは行わない。 |

`/health`の判定基準（Scheduler停止中）:

- **合格**: 次のいずれかに該当する場合。
  - HTTP 200で、`app`・`database`・`config`・`scheduler`のすべてが`ok`。
  - HTTP 503だが、`app=ok`・`database=ok`・`config=ok`であり、`ng`なのは`scheduler`のみ。**Schedulerを意図的に停止しheartbeatの閾値（既定300秒、[`monitoring.md`](monitoring.md) 4節）を超えれば、正常な想定状態でも`scheduler`は`ng`になり、`/health`全体もHTTP 503になる。この場合の503は異常ではない。**
- **不合格**: 次のいずれかに該当する場合。
  - `app`・`database`・`config`のいずれかが`ng`。
  - 上記2パターン以外のHTTPステータス、またはレスポンス形式自体が期待どおりでない。

**`scheduler=ng`であることだけを理由に、この段階でロールバックを判断しない。** 一方、`app`・`database`・`config`のいずれかが`ng`の場合、または想定外のレスポンスの場合は不合格とし、Schedulerを再開せず、13節のロールバック手順を検討する。問題があるままSchedulerを再開すると、定期実行が壊れた環境で走り続けるリスクがあるため、再開前に必ずこの最小限の確認を行う。`ng`のcomponentがあれば[`monitoring.md`](monitoring.md) 7節に沿って切り分ける。

### 12.2 主要機能確認（Scheduler再開後、9節手順10・11）

スモークテスト成功後、Schedulerを再開してから（5.3節、9節手順9）、残りの項目を確認する。

| 確認対象 | 方法 |
| --- | --- |
| 主要API | コマンド一覧・実行系等、[`deployment-checklist.md`](deployment-checklist.md)の主要機能確認相当のエンドポイントが想定どおり応答することを確認する。 |
| Google認証 / Calendar | `/auth/redirect` → コールバックが成功することを確認する。休日判定・個別カレンダー上書きが想定どおり動作することを確認する。 |
| 外部HTTP実行 | 12.3節を参照。 |
| cron / scheduler | 5.1節で特定した起動機構が有効になっており、Laravel Schedulerが起動していることを確認する。 |
| scheduler heartbeat | `logs/scheduler-heartbeat.json`の`time:trigger`が直近で更新されていることを確認する（[`monitoring.md`](monitoring.md) 3節・6節）。 |
| `/health`（最終確認） | `time:trigger`の実行とscheduler heartbeatの更新を確認した後、`GET /health`が**HTTP 200**で、`app`・`database`・`config`・`scheduler`の**4componentすべてが`ok`**であることを確認する。12.1節のスモークテスト時点では`scheduler=ng`（HTTP 503）を許容したが、Scheduler再開後・heartbeat更新後はこの一時的な許容が終わるため、最終的に通常運用と同じ全component `ok`（HTTP 200）へ戻っていることを確認する。 |
| logs | 12.4節を参照。 |

### 12.3 外部副作用のある確認への配慮

「外部HTTP実行」の確認は、登録済みコマンドを実際に手動実行すると、コマンドの実行先（外部サービス）へ実際にHTTPリクエストが飛び、副作用（外部システムの状態変更、重複実行等）が生じ得る。次のいずれかを人間が状況に応じて選択する。

- **安全なコマンドがある場合**: 副作用がない、またはべき等であることが分かっているコマンド（例: 参照系のみのエンドポイントを叩くコマンド）を選んで手動実行し、`exec_results`とAPIレスポンスを確認する。
- **安全なコマンドがない場合**: 実際の手動実行は行わず、直近の`time:trigger`実行結果（`exec_results`、[`monitoring.md`](monitoring.md) 9節の`external_http.no_response`等のログイベント）を確認するにとどめる。切り替え後、次回の定期実行（時間トリガー対象）が成功しているかを事後的に確認する形で代替する。

どちらを選ぶかは、登録されているコマンドの内容を把握している人間が判断する。副作用の有無が不明なコマンドを「確認のため」に実行しない。

### 12.4 ログ確認

[`monitoring.md`](monitoring.md) 6節のとおり`logs/laravel.log`を確認し、切り替え作業前後の時刻に、想定外のPHP/Laravelのwarning・deprecation・例外が発生していないかを確認する。PHP 8.5への切り替えに伴うdeprecation警告は、Laravel側・アプリケーション側どちらのコードに起因するかを切り分け、アプリケーション側のコードに起因する場合は後続Issueとして記録する。

## 13. rollback

今回のリリースはschema変更を伴わない（7節）。したがって、**通常の障害ではDB restoreを最初の選択肢にしない。** 基本のロールバックは、[`rollback.md`](rollback.md) 4節（アプリケーションファイルのみの切り戻し）を、PHP切り替えに合わせて次のように拡張した手順とする。

1. **Scheduler停止**: 5.1節で特定した起動機構を、5.3節と同じ方法で再度停止する。
2. **直前アプリケーションバックアップへ戻す**: 6節で取得した切り替え直前のアプリケーションファイルの控えを配備先へ復元する（[`rollback.md`](rollback.md) 4節の要領）。
3. **`.env` / `logs`を上書きしない**: 復元時に配備先の`.env`・`logs`には触れない（[`rollback.md`](rollback.md) 4節3項）。
4. **XServer Web PHPを8.2系へ戻す**: サーバーパネルの「PHP Ver.切替」で対象ドメインをPHP 8.2系へ戻す。
5. **必要なら旧PHP CLIで旧アプリのcacheを再構築する**: 復元したアプリケーションのバージョンに応じたPHP CLI（8.2系）で、[`deployment.md`](deployment.md) 6.2節の順序に沿ってLaravel cacheを再構築する。
6. **主要機能確認**: [`deployment-checklist.md`](deployment-checklist.md)の主要機能確認相当の項目で復旧を確認する。
7. **Cronを元の状態へ戻す**: 5.1節で確認した切り替え前のScheduler起動機構の設定（PHP CLIパスの固定を含む）へ戻し、Schedulerを再開する。再開後、[`monitoring.md`](monitoring.md)のscheduler heartbeatが更新されることを確認する。

DB restoreは、[`rollback.md`](rollback.md) 5節の開始条件（schema変更適用後の不整合、または意図しないデータ破損・不整合が実際に確認された場合）に該当する場合のみ、既存手順へ進む。**今回のリリースはschema変更を伴わないため、通常はこの経路に進む理由がない。** DB破損等の実害が確認された場合に限り、[`rollback.md`](rollback.md) 5節の手順を実施する。

## 14. 実施結果の記録

Issue #226の実施結果として、少なくとも次を記録する。**秘密情報（`.env`の実値、DB接続情報、APIキー等）、Cron／Scheduler起動機構の生commandや実行環境の絶対パス（XServerアカウント名を含むパス等）は、いずれも記録に含めない**（[`secrets-management.md`](secrets-management.md)、5.4節、6節）。

- 実際に確認したPHP 8.5のpatch version（3節、`/usr/bin/php8.5 -v`の出力）
- PHP拡張確認の結果（4節、CLI・Web実行環境とも不足がなかったこと、または対応内容。生の`-m`出力は記録しない）
- 8節の例外条件をすべて満たしていることを確認した記録（チェック済みであること）
- Scheduler起動機構の確認結果（5.4節の安全な要約: 起動方式・実行間隔・PHP CLIパス固定の有無・切り替え後の更新有無・再開確認結果）
- backupの取得日時・取得方法の種別（絶対パスは含めない。6節）
- 9節の各手順の実施日時
- 12節のスモークテスト・主要機能確認・scheduler heartbeat確認・ログ確認の結果
- ロールバックを実施した場合、判断理由・実施内容・結果（13節）
- 未解決の問題・後続Issue候補（deprecation警告の扱い等）

## 15. 関連ドキュメント

- デプロイ手順・deploy前チェック・バックアップ方針: [`deployment.md`](deployment.md)
- デプロイ作業チェックリスト: [`deployment-checklist.md`](deployment-checklist.md)
- ロールバック手順: [`rollback.md`](rollback.md)
- 監視（health check / scheduler heartbeat）: [`monitoring.md`](monitoring.md)
- 運用・本番情報の詳細と未確認事項: [`current-operations.md`](current-operations.md)
- 秘密情報の管理: [`secrets-management.md`](secrets-management.md)
