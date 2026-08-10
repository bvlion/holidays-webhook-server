# デプロイ作業チェックリスト

この文書は、Issue #224に基づき、本番デプロイ作業時に上から順に確認できる簡潔なチェックリストである。各項目の詳細な考え方は [`deployment.md`](deployment.md)・[`rollback.md`](rollback.md) を参照する。ここでは重複を避け、チェック項目の列挙に留める。

このチェックリストは実施者（人間）が使うものであり、AIエージェントが本番操作・tag作成・deploy実行を代行することはない（役割分担は[`deployment.md`](deployment.md) 1節）。

Issue #226でPHP 8.5への本番切り替えを行う際も、このチェックリストの流れ（deploy前〜作業記録）はそのまま使える想定である。**PHP 8.5切り替え固有の手順（XServer PHP切り替え、Cron/Scheduler停止・再開、PHP拡張確認等）は [`php85-production-switch.md`](php85-production-switch.md) にまとめており、本チェックリストには含めない。** ただし、下記「deploy前」のPHP要件判定と「backup完了」のDBバックアップ要否判定の2項目は、Issue #226のPHP 8.5初回切り替え作業に限り[`php85-production-switch.md`](php85-production-switch.md)側の条件が優先される（各項目の注記を参照）。

## deploy前

- [ ] 対象commit / tagを特定した（[`deployment.md`](deployment.md) 4-1）
- [ ] 対象commitに対応する`test.yaml`（`make check`）がGitHub Actions上で成功している（4-2）
- [ ] `src/composer.json`のPHP / Laravel / Composer要件を確認した（4-3）
- [ ] 本番PHPの実バージョンを確認し、上記要件を満たすことを確認した（4-4）。**満たさない場合はここで中止する。** Issue #226のPHP 8.5初回切り替え作業に限り、[`php85-production-switch.md`](php85-production-switch.md) 8節の例外条件をすべて満たす場合だけ、本番PHPが要件を満たさない状態でのtag pushが許容される。**このチェックリストを上から読んでいる現時点では、8節の例外条件（Scheduler停止済み・アプリ/DBバックアップ取得済み等）をまだ満たせていないことが通常である。ここでは通常判定どおり「本番PHPが要件を満たさない」ことだけを確認し、その後は`php85-production-switch.md` 9節の切り替え順序（Scheduler停止→backup→…）に沿って進める。8節の例外条件8項目すべての最終確認は、tag pushを実行する直前（9節手順5の直前）に行う。**
- [ ] 必要なPHP拡張が本番に入っていることを確認した（4-8）
- [ ] `docker/db/sql/`の差分有無を確認し、schema変更の有無を判定した（4-9、[`deployment.md`](deployment.md) 7節）
- [ ] schema変更がある場合、対象Issue/PRに適用手順・ロールバック手順が明示されている（[`deployment.md`](deployment.md) 7.2節）
- [ ] ロールバック可能な状態（直前tag、アプリファイルの控え、必要ならDBバックアップ）が揃っていることを確認した（4-10）
- [ ] `.env`を変更・削除する予定がないことを確認した（変更が必要な場合は理由・内容を記録した）（4-7）

## backup完了

- [ ] 本番アプリケーションファイルの控えを取得した（[`deployment.md`](deployment.md) 5節）
- [ ] 控えに対応するtag名・commit SHAを記録した
- [ ] schema変更がある場合、DBバックアップを取得した（[`deployment.md`](deployment.md) 7.2節・8節）
- [ ] schema変更がない場合、通常運用のDBバックアップサイクルで問題ないことを確認した（[`deployment.md`](deployment.md) 7.1節）。**ただしIssue #226のPHP 8.5切り替え作業は、schema変更の有無にかかわらずDBバックアップを取得する**（[`php85-production-switch.md`](php85-production-switch.md) 6節。上記2項目によるDBバックアップ要否判定は通常運用時のみ適用し、Issue #226では適用しない）

## deploy

- [ ] 対象commitに意図したtag（`v*`）をpushした
- [ ] GitHub Actionsの`deploy` workflowが起動したことを確認した
- [ ] `composer install`・`delete server files`・`rsync`の各ステップが成功したことを確認した

## deploy直後

- [ ] Slack通知（成功／失敗いずれも通知される。[`deployment.md`](deployment.md) 3.1節）を確認した
- [ ] deploy workflowが失敗していた場合、[`rollback.md`](rollback.md)の該当条件に沿って切り戻しを判断した
- [ ] 必要な場合のみ、Laravel cacheを安全な順序で再構築した（[`deployment.md`](deployment.md) 6.2節）

## 主要機能確認

- [ ] 公開URLのルートが200で応答する
- [ ] `GET /health`が200で応答する（`ng`のcomponentがあれば[`monitoring.md`](monitoring.md) 7節に沿って切り分ける）
- [ ] 主要APIエンドポイント（コマンド一覧・実行系）が想定どおり応答する
- [ ] Google認証フロー（`/auth/redirect` → コールバック）が成功する
- [ ] 休日判定・個別カレンダー上書きが想定どおり動作する
- [ ] 外部HTTP実行（手動実行・時間トリガー対象コマンド）が想定どおり動作する

## scheduler / cron確認

- [ ] `holidays:update`・`time:trigger`・`results:delete`を起動するOS側cron／systemd timer等が、デプロイ後も引き続き設定されている（本番の起動方法自体は未確認事項。[`current-operations.md`](current-operations.md) 5.5節）
- [ ] `time:trigger`が直近で実行され、対象の時間トリガーが処理されていることをログまたは`exec_results`で確認した
- [ ] `logs/scheduler-heartbeat.json`の`time:trigger`が直近で更新されている（[`monitoring.md`](monitoring.md) 3節・6節）
- [ ] Schedulerの多重起動が発生していないことを確認した

## ログ確認

- [ ] 配備先の`logs`（deployで保持される）にdeploy時刻付近の異常なエラー・warningがないか確認した
- [ ] Webサーバー（本番はnginx経由、[`current-operations.md`](current-operations.md) 5.1節）のアクセスログ・エラーログを必要に応じて確認した
- [ ] Slack通知以外に見落としているエラー通知がないか確認した

## rollback判断

- [ ] 「主要機能確認」「scheduler / cron確認」「ログ確認」のいずれかで問題を検知した場合、[`rollback.md`](rollback.md)の開始条件に照らして切り戻し要否を判断した
- [ ] 切り戻す場合、アプリケーションファイルのみで足りるか、DB restoreまで必要かを判定した（[`rollback.md`](rollback.md) 3節）
- [ ] DB restoreを実施する場合、実施前に現状のDBを新たにバックアップした（[`rollback.md`](rollback.md) 5節）

## 作業記録

- [ ] deployしたtag／commit、実施日時、実施者を記録した
- [ ] deploy前チェックの結果（特にPHP要件・schema変更有無の判定）を記録した
- [ ] backupの取得方法・保管場所の安全な要約（XServerアカウント名・ユーザー名・絶対パス・秘密情報等は含めない）と取得日時を記録した
- [ ] 主要機能確認・scheduler確認・ログ確認の結果を記録した
- [ ] ロールバックを実施した場合、判断理由・実施内容・結果を記録した
- [ ] 未解決の問題・後続Issue候補があれば記録した
