# ドメインルール

この文書は、現在の実装コードから読み取れる主要なドメインルールを整理したものである。一般的なLaravel設計論ではなく、**このリポジトリで実際に成立しているルール**だけを記載する。実装の根拠は主に `src/app/Models/`・`src/app/Http/Controllers/`・`src/app/Console/Commands/` である。詳細な実装棚卸しは [`current-architecture.md`](current-architecture.md) を参照する。

## 1. User / Groupとコマンド所有権

- `groups` はGoogleアカウント（メールアドレス）を起点とする単位。`users` は `groups_id` で所属グループを持ち、`owner_flag` を持つ（`src/app/Http/Controllers/Web/GoogleLoginController.php`）。
- グループごとに `owner_flag = true` のユーザーは1人だけ作成される（Google認証コールバック時に既存の所有者ユーザーを検索し、いなければ作成）。
- APIトークン（`users.api_token`、60文字のランダム文字列）が唯一のAPI認証手段。`config/auth.php` は `guard: token` を使い、Sanctum等のセッション認証は使わない。
- `commands`・`time_triggers`・`calenders`・`onetime_skips`等は、`target_type`（`user`または`group`）と`target_id`の組でユーザーまたはグループへのポリモーフィックな所有権を表現する。外部キー制約はない。
- 所有権確認は `BaseApiController::checkExecutableUser()` が一括して行う。`target_type == 'user' && target_id == $user->id`、または `target_type == 'group' && target_id == $user->groups_id` の場合だけ許可し、それ以外は403を返す。

## 2. Command / SummarizeCommand

- `Command` は1件の外部HTTPリクエスト定義（HTTPメソッド、URL、`body_type`、`headers`、`parameters`を文字列として保持）。
- `SummarizeCommand` は `commands` カラムに複数の `Command` IDをJSON配列として保持する「まとめ実行」定義。
- 単体実行（`POST /api/exec/command/{id}`）は対象コマンドの所有権を確認する。まとめ実行（`POST /api/exec/summary/{id}`）は `SummarizeCommand` 自体の所有権のみを確認し、配列内の各 `Command` の所有権・削除状態は個別に確認しない（`CommandExecController::summary()`）。
- コマンド一覧（`GET /api/commands`）はユーザー対象とグループ対象のOR条件のあとに `whereNull('deleted_at')` を連結しており、生成条件上、削除除外がグループ側の条件にしか及ばない。ユーザー対象コマンドは削除済みが一覧に含まれ得る（`CommandsController::index()`）。
- コマンド登録（`store`）は `CommandRequest` で入力検証するが、更新（`update`）は素の `Request` を使い、同等の検証をしない。
- 削除（`destroy`）は `deleted_at` に現在日時を設定するだけの論理削除。Eloquentの `SoftDeletes` は使っていないため、クエリ側が明示的に `whereNull('deleted_at')` 等で除外する必要がある。

## 3. TimeTrigger

- `time_triggers` は、対象コマンド・対象（user/group）・タイムゾーン（`+09:00`のような6文字のUTCオフセット）・実行時間帯（`time_from`〜`time_to`）・実行間隔（`exec_interval`分）・対象曜日（`target_week`のJSON配列）・`holiday_decision`・`exec_flag`・`exec_notify`を持つ。
- `time:trigger`コマンド（毎分実行）が、生SQL（`DB::select`）で対象トリガーを一括抽出する。抽出条件は次のすべてを満たすこと。
  - `CONVERT_TZ(NOW(), '+09:00', tt.timezone)` の時刻が `time_from`〜`time_to` の範囲内（`BETWEEN`、両端含む）。
  - `(現在分 - time_fromの分) % exec_interval == 0`。
  - `exec_flag = 1` かつ `deleted_at IS NULL`。
- 曜日判定はMySQLの `DAYOFWEEK()` を使うため、日曜日が1、土曜日が7になる（PHPの`date('N')`とは基準が異なる）。
- 開始時刻が終了時刻より後になる「日跨ぎ」の時間帯を特別扱いする処理はない。
- コマンドID（`command_id`）が正の値（`c_id > 0`）でない行はスキップされる。負のコマンドID（`-1`〜`-3`）は「マナー解除」「マナーモード」「サイレント」という端末モード変更を意図した名称変換があるが、対応する端末制御処理は実装されていない。
- 登録・更新・削除用のAPIは存在しない。`time_triggers`の管理経路はリポジトリ内に見当たらない。

## 4. 休日判定

- 休日判定は「Google Calendar由来の祝日」と「`calenders`（個別カレンダー）」の2層で構成される（詳細は5節）。
- `holiday_decision` の値ごとの実行条件は次のとおり（`time:trigger`、`CalendarController::isHoliday()`両方で共通の考え方）。

  | `holiday_decision` | 実行条件 |
  | --- | --- |
  | `exec` | 休日であれば実行する。対象曜日は見ない |
  | `not_exec` | 休日でなく、かつ対象曜日（`target_week`）に含まれる場合に実行する |
  | `not_check` | 休日を判定せず、対象曜日に含まれる場合に実行する |

- 休日参照日の基準がずれる点に注意する。`time:trigger`はトリガーのタイムゾーンへ変換した日付（`date('Y-m-d')`、実際にはPHPサーバー日時ベース）で祝日を引くのに対し、`calenders`の結合条件はトリガーのタイムゾーン変換後の日付を使う。日付境界では両者が異なる日を指し得る。

## 5. Google Calendar由来の休日と個別カレンダー上書きの優先関係

- 優先順位: **`calenders`（個別カレンダー）が常にGoogle Calendar由来の祝日より優先される。**
- `CalendarController::isHoliday()`: まずGoogle Calendarの祝日リストで判定し、対象日・対象（`target_id`/`target_type`）の `calenders` レコードが存在すればその `is_holiday` 値で上書きし、`force: true` を返す。存在しなければ `force: false`。
- `time:trigger`: SQLの `LEFT OUTER JOIN calenders` で当日分の個別カレンダーを取得し、`user_holiday`（`cal.is_holiday`）が `NULL` でなければGoogle Calendar判定より優先して使う。
- `calenders`は `target_id`/`target_type`/`target_date` の組で一意に扱われ（`Calender::updateOrCreate`）、Eloquentの`SoftDeletes`は使わないが `deleted_at` カラムを持つ。

## 6. OneTimeSkip

- `onetime_skips` は「時間トリガー」または「SSIDトリガー」を対象に、1回限りの実行スキップを複数件登録できる（`target_type`が`time`または`ssid`、`target_id`が対象トリガーのID）。
- `time:trigger`実行時、未使用（`deleted_at IS NULL`）のスキップが見つかると、そのレコードの `deleted_at` を現在日時にして「使用済み」にし、当該トリガーの実行そのものをスキップする（外部HTTPリクエストを送らない）。
- 登録（`POST /api/onetime/skip`）は対象トリガーの所有権を確認しない。参照（`GET`）・削除（`DELETE`）は `checkReadableUser()` 経由で対象トリガーの所有権を確認する（`OnetimeSkipsController`）。
- 削除は「対象の最古の未使用レコード」に対して Eloquentの `delete()` を呼ぶ。モデルは `SoftDeletes` を使っていないため、この削除は物理削除になる（`time:trigger`側の「使用済みにする」処理とは異なる）。

## 7. 外部HTTP実行

- 手動実行（`CommandExecController`）と時間トリガー実行（`TimeTrigger`コマンド）はいずれもGuzzleを使うが、実行方式・結果の扱いが異なる（[`architecture.md` 6節](architecture.md#6-外部http実行)参照）。
- リクエスト構築の共通ルール:
  - `allow_redirects: true`（リダイレクトを許可する）。
  - `headers`はJSONデコードして空でなければ付与する。
  - `parameters`は空でなければ、文字列中の `##DATETIME##` をPHPサーバーの現在日時（`date('Y-m-d H:i:s')`）へ置換する。
  - `body_type == 'json'` の場合、置換後の文字列をデコードせずそのままGuzzleの `json` オプションへ渡す。それ以外の `body_type` はJSONデコードした値を渡す（Guzzleオプション名として `body_type` の値をそのまま使う）。
- 接続エラー等でHTTPレスポンスを持たない例外が発生した場合、`RequestException::getResponse()` は `null` になり得るが、`saveResult()` 側は常にレスポンスオブジェクトを受け取る前提で実装されている（未処理の失敗パターン）。

## 8. ExecResult

- `exec_results` は時間トリガー実行の結果だけを保存する。手動実行の結果はAPIレスポンスとして返るのみで、`exec_results`には保存されない。
- 保存される値: `command_id`、`trigger_id`、`exec_time`（トリガーのタイムゾーンでの実行時刻）、`response_code`、`response_header`（JSON文字列）、`response_body`。
- 保存前のマスキング処理はない。外部サービスの応答に機密値（再利用可能なトークン等）が含まれる場合、そのまま保存され得る（[`secrets-management.md` 5.4](secrets-management.md)参照）。

## 9. 実行結果の保持

- `results:delete`（毎時実行、`DeleteOldExecResultCommand`）が、**コマンドごとに新しい100件を残し、それより古い `exec_results` を物理削除する。**
- 世代管理はコマンド単位（`command_id`ごと）であり、トリガー単位・グローバル単位ではない。
- 実行結果取得API（`GET /api/exec/result/{id}`）は時間トリガーの実行結果を返す。手動実行の結果はこのAPIの対象ではない。

## 10. 認証・認可境界

- 認証はAPIトークン（`auth:api`、tokenガード）のみ。全APIルートは1分あたり60回のレート制限（`throttle:api`）を通る。
- 認可は「対象（user/group）とリクエストユーザーが一致するか」の一点に単純化されている（`BaseApiController::checkExecutableUser()`）。ロールベースの権限や管理者権限の概念はない。
- 認可の非対称性（2節のまとめ実行、6節のワンタイムスキップ登録）は現状の実装事実として存在する。新しいコードを書く際にこれを「バグとして無断で修正」しない。挙動を変える場合は対象Issueの範囲を確認する。

## 11. タイムゾーン

- Laravelアプリケーションのタイムゾーン（`config/app.php`）・ローカルPHPの`date.timezone`・ローカルMySQLコンテナの`TZ`は、いずれも `Asia/Tokyo` に統一されている。
- `time_triggers.timezone` は `+09:00` のようなUTCオフセット文字列で個別に保持され、`time:trigger`のSQLは `CONVERT_TZ(NOW(), '+09:00', tt.timezone)` でDBの現在時刻（日本時間基準）をトリガーごとのタイムゾーンへ変換する。
- 一方、Google Calendarの祝日参照日はPHPサーバー側の `date('Y-m-d')`（日本時間）を使う。トリガーのタイムゾーンが日本時間と異なる場合、日付境界付近で祝日参照日とトリガーの実行日がずれ得る（4節参照）。
- 休日判定API（`CalendarController`）の入力日は `strtotime()`→`date('Y-m-d')` で正規化される。不正な日付文字列に対する例外捕捉は機能しておらず、意図しない日付へ丸められ得る。

## 関連ドキュメント

- 実装の詳細な棚卸しと現状差異の一覧: [`current-architecture.md`](current-architecture.md)（特に「10. 現状差異と後続Issue候補」）
- システム構成・境界: [`architecture.md`](architecture.md)
- AIエージェント向け作業ルール: [`../AGENTS.md`](../AGENTS.md)
