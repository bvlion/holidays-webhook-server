# 監視（health check / scheduler heartbeat）

この文書は、Issue #225に基づき、`GET /health` とscheduler heartbeatの仕組み、および外部連携（Google認証・Google Calendar・コマンド実行先）失敗時の確認方法を整理したものである。デプロイ手順は[`deployment.md`](deployment.md)、ロールバック手順は[`rollback.md`](rollback.md)、秘密情報の扱いは[`secrets-management.md`](secrets-management.md)を参照する。

## 1. `GET /health` の意味

`/health`は、`/`（サーバー時刻とDB時刻を返す簡易確認用エンドポイント、Issue #225では仕様変更していない）とは別に用意した、外形監視向けの専用エンドポイントである。次の4点を判定する。

| component | 判定内容 |
| --- | --- |
| `app` | Laravelアプリケーションがリクエストに応答できている（このレスポンスが返ること自体が確認） |
| `database` | `DB::select('SELECT 1')` がDBへ接続して成功する |
| `config` | 稼働に必須な設定（`APP_KEY`、DB接続情報、Google認証・Google Calendar関連設定）が空でない |
| `scheduler` | `time:trigger` の最終成功が、後述の閾値以内に記録されている |

認証は不要で、公開経路から到達できることを前提にしている（外形監視ツールから叩く想定）。

## 2. HTTP 200 / 503 の判定

4つのcomponentすべてが`ok`であればHTTP 200、いずれか1つでも`ng`であればHTTP 503を返す。レスポンス例は次のとおり。

```json
{
  "status": "ok",
  "components": {
    "app": "ok",
    "database": "ok",
    "config": "ok",
    "scheduler": "ok"
  }
}
```

```json
{
  "status": "ng",
  "components": {
    "app": "ok",
    "database": "ng",
    "config": "ok",
    "scheduler": "ok"
  }
}
```

**公開レスポンスにはcomponent名とok/ngしか含めない。** 次の情報は一切含めない（[`secrets-management.md`](secrets-management.md)の方針と同じ）。

- DB接続情報（ホスト・DB名・ユーザー名・パスワード）
- ホスト名、filesystemの絶対パス
- `APP_KEY`、Google Client ID / Secret、Google Calendar APIキー、webhook URL、外部HTTP URL、token
- 例外メッセージ、stack trace

どのcomponentが`ng`だったかという事実（component名）だけがレスポンスに現れる。**具体的な原因（どの設定が欠けているか、DBの何が失敗したか等）はレスポンスへ含めず、ログ（3節）でのみ確認できるようにしている。**

## 3. scheduler heartbeatの仕組み

DB schemaやLaravel migrationを新設せず、本番deployで`logs/`が保持される現在の構成（[`deployment.md`](deployment.md) 2節）を使う。

- `time:trigger`・`results:delete`・`holidays:update`の各Artisanコマンドは、`handle()`内で処理全体を`try/catch`し、成功時・失敗時に`App\Libs\SchedulerHeartbeat`経由で`logs/scheduler-heartbeat.json`へ最終成功・失敗時刻を書き込む。
- 時刻はUTCのISO8601形式（例: `2026-08-08T03:00:00+00:00`）で保存し、タイムゾーンに依存した比較ミスを避ける。読み取り側は保存された時刻とオフセットからCarbonで比較する。
- 書き込みは一時ファイルへ書いてから`rename()`する atomic write とし、読み込み途中で壊れた内容を見ることを避ける。同時書き込みは`logs/scheduler-heartbeat.json.lock`に対する`flock()`で排他する。
- `time:trigger`の成功は「その回のscheduler実行が完了したこと」を表す。個々のトリガーの外部HTTP呼び出しが失敗しても（4節）、`time:trigger`コマンド自体は例外を投げずに完了するため、heartbeatは更新され続ける。heartbeatはあくまで「schedulerが動いているか」を見るものであり、「すべての外部連携が成功しているか」は見ていない。
- `results:delete`・`holidays:update`についても同じ仕組みで最終成功・失敗時刻を記録する。ただし`/health`が異常と判定する対象は`time:trigger`だけである（次節）。

## 4. heartbeatが古いと異常と判断する基準

`/health`の`scheduler`componentは、`time:trigger`の最終成功時刻が現在時刻から`HEALTH_SCHEDULER_HEARTBEAT_THRESHOLD`秒（既定300秒 = 5分、`.env.example`参照）以内でなければ`ng`とする。

- `time:trigger`は毎分実行される想定だが、本番でLaravel Schedulerを起動するOS側cronの実際の間隔はリポジトリから確認できない（[`current-operations.md`](current-operations.md) 5.5節）。想定間隔(1分)より十分余裕を持たせた既定値とし、実際の運用間隔に応じて`.env`の`HEALTH_SCHEDULER_HEARTBEAT_THRESHOLD`を調整すること。
- `results:delete`（毎時）・`holidays:update`（毎月）は`/health`の判定対象に含めない。月次ジョブが毎日実行されていないことを異常として扱うと誤検知になるため、これらの最終成功・失敗はheartbeatファイルを直接確認する運用とする（5節）。
- heartbeatファイルが存在しない場合（初回deploy直後、`logs/`が新しい環境等）も`ng`として扱う。

## 5. 外部連携失敗の確認方法

`/health`はGoogleへ実通信して確認しない（設定の存在確認と実際の利用時のエラー記録を分離している）。外部連携（Google認証・Google Calendar・コマンド実行先への手動/時間トリガー実行）の失敗は、すべてLaravelの通常ログ（`logs/laravel.log`）へ記録される。

| 失敗箇所 | ログのイベント名 | 記録される内容 |
| --- | --- | --- |
| 手動コマンド実行（`CommandExecController`）でレスポンスを伴わない失敗 | `external_http.no_response` | `integration=command_exec`、`command_id`、例外クラス名 |
| `time:trigger`でレスポンスを伴わない失敗 | `external_http.no_response` | `integration=time_trigger`、`trigger_id`、例外クラス名 |
| `time:trigger`内のGoogle Calendar祝日判定失敗 | `time_trigger.holiday_lookup_failed` | `trigger_id`、例外クラス名（該当トリガーだけスキップし、他のトリガーの実行は継続する） |
| Google Calendar API取得失敗（`HolidayList`） | `external_service.failure` | `integration=google_calendar`、例外クラス名、（分かる場合のみ）HTTPステータス |
| Google認証失敗（`GoogleLoginController`） | `external_service.failure` | `integration=google_auth`、`operation`（`callback` / `api_login`）、例外クラス名 |
| `/health`のDB接続失敗 | `health_check.database_failure` | 例外クラス名 |
| `/health`の必須設定欠落 | `health_check.config_missing` | 欠落した設定「名」の一覧（実値は含まない） |
| `/health`のscheduler停止 | `health_check.scheduler_stale` | 対象タスク名、閾値秒数 |

**ログにはaccess token・authorization code・client secret・callback query・外部URL・Authorizationヘッダー・リクエストbody・例外の生メッセージを出力しない。** 例外の生メッセージ（`getMessage()`）は、Google Calendar APIのようにURLへAPIキーを含める実装ではリクエストURL全体を含み得るため、意図的にログへも例外オブジェクトの`$previous`へも含めていない。安全に取得できる情報（例外クラス名、必要ならHTTPステータスコード）だけを記録する。

## 6. ログ確認方法

ローカル・本番とも`logs/laravel.log`を確認する（[`current-operations.md`](current-operations.md) 5.6節）。本番は`.github/workflows/deploy.yaml`のdeploy処理が`logs`ディレクトリを配備先で保持するため、deployをまたいでログが残る（[`deployment.md`](deployment.md) 2節）。

scheduler heartbeatの生データは`logs/scheduler-heartbeat.json`を直接確認する。例:

```json
{
  "time:trigger": {"status": "success", "last_success_at": "2026-08-08T03:00:00+00:00"},
  "results:delete": {"status": "success", "last_success_at": "2026-08-08T02:00:00+00:00"},
  "holidays:update": {"status": "success", "last_success_at": "2026-08-01T00:00:00+00:00"}
}
```

`status`が`failure`の場合は`last_failure_at`が最終失敗時刻を表す。失敗は`last_success_at`を更新しない。

## 7. health異常時の一次切り分け

1. `/health`のレスポンスで、どのcomponentが`ng`かを確認する。
2. `database`が`ng`の場合: `logs/laravel.log`の`health_check.database_failure`を確認し、DB自体の疎通（[`current-operations.md`](current-operations.md) 5.4節相当の確認）を行う。DBスキーマ不一致が疑われる場合は[`rollback.md`](rollback.md) 6節を参照する。
3. `config`が`ng`の場合: `logs/laravel.log`の`health_check.config_missing`で欠落した設定「名」を確認し、本番`.env`の該当項目を人間が確認する（実値はログに出ないため、AIエージェントは実値を扱わない）。
4. `scheduler`が`ng`の場合は8節を参照する。
5. 直近のdeploy（tag push）が原因と疑われる場合は[`rollback.md`](rollback.md)の判断フローに従う。

## 8. scheduler停止時に人間が確認する内容

`/health`の`scheduler`が`ng`、または`logs/scheduler-heartbeat.json`の`time:trigger`が更新されていない場合、原因はアプリケーションコードではなく実行基盤側にあることが多い。次を人間が確認する（[`current-operations.md`](current-operations.md) 8節の未確認事項に対応）。

1. 本番でLaravel Schedulerを起動しているcron／systemd timer等の定義が存在し、有効になっているか。
2. 直近の`time:trigger`実行がcron側のログ・OSのジョブ履歴に残っているか。
3. `php artisan schedule:run`実行時のPHPエラー（拡張不足、`.env`不備等）がないか。
4. DB接続失敗など、`time:trigger`自体が例外で終了する原因がないか（`logs/laravel.log`を確認）。
5. 同一時刻の多重起動やサーバー間の時刻ずれがないか。

## 9. Google / 外部HTTP障害時の切り分け

- Google認証（`/auth/redirect`、`/login/callback`）が失敗する場合は、[`rollback.md`](rollback.md)の「Google連携が失敗する」行の観点（Google側の一時障害か、コード・設定変更起因か）に加え、`logs/laravel.log`の`external_service.failure`（`integration=google_auth`）でoperationと例外クラス名を確認する。
- Google Calendar取得（休日判定）が失敗する場合は、同ログの`integration=google_calendar`を確認する。`time:trigger`経由の場合は個別トリガーだけがスキップされるため、`time_trigger.holiday_lookup_failed`で対象トリガーを特定できる。
- コマンド実行先（手動実行・`time:trigger`）でレスポンスを伴わない失敗（DNS失敗・connect timeout・connection refused等）は、`external_http.no_response`で確認する。この場合、`exec_results`（`time:trigger`経由のみ）には`response_code=0`、`response_header`・`response_body`に`{"error":"no_response",...}`という固定表現で保存され、実際のHTTPレスポンスと区別できる。手動実行のAPIレスポンスも同じ表現を返す。
- HTTP 4xx/5xxのようにレスポンスを伴う失敗は、従来どおり実際のステータスコードが`exec_results`・APIレスポンスへ反映される（変更なし）。

## 10. Slack通知について

このIssueではSlack通知を**採用していない**。理由は次のとおり。

- 本番でアプリケーションruntime用のSlack webhook（`LOG_SLACK_WEBHOOK_URL`）が設定済みかどうかはリポジトリから確認できない。
- GitHub Actions用の`SLACK_WEBHOOK_URL`（[`secrets-management.md`](secrets-management.md) 4節）はテスト・デプロイ結果通知専用であり、アプリケーションruntimeとは別物のため流用しない。
- 外部HTTP失敗1件ごとに通知する実装は、障害時に大量通知を発生させるリスクがある。

現状は、本文書の3節〜9節のとおり、`/health`とLaravelの通常ログで異常を検知・追跡できる状態を最低限の要件として満たしている。将来的にSlack通知を追加する場合は、`LOG_SLACK_WEBHOOK_URL`が設定されている場合だけ有効になるoptionalな仕組みとし、同一障害の連続通知を避ける仕組み（例: 一定時間内の重複抑制）を必ず設けること。
