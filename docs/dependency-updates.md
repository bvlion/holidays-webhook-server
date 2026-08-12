# 依存関係更新の運用

## 1. 目的と対象

この文書は、Dependabotによる依存関係更新の運用方針を定める。Issue #222で導入したpatch限定の自動マージ運用は、Issue #258で「`main`向けの全PRに共通のCI成功+人間Approveによるauto-merge」へ統一された。ここでいう「全PR」は、`bvlion/holidays-webhook-server`リポジトリ内のブランチから作成された`main`向けPRを指す。フォークからのPRは対象外である（5節）。Dependabotは同一リポジトリ内にブランチを作成するため、この対象に含まれる。

対象は次のとおりである。

- Composer（`/src`）
- GitHub Actions（`.github/workflows`）

## 2. 更新頻度とPR上限

- 両エコシステムとも週次（毎週月曜、Asia/Tokyo）で確認する。
- 過剰なPRを避けるため、`open-pull-requests-limit` をComposer・GitHub Actionsとも10件とする。

## 3. Grouping方針

`.github/dependabot.yml` では、エコシステムごとに次のグループを定義する。

- `composer-patch` / `actions-patch`: `update-types: ["patch"]` のみを含むグループ
- `composer-minor` / `actions-minor`: `update-types: ["minor"]` のみを含むグループ
- major更新はグループに含めない（個別PRのまま作成される）

このグルーピングは、関連する更新をまとめてレビューしやすくするためのものであり、後述のマージ条件（4節）には一切影響しない。patch/minor/majorのいずれも同じ条件でマージされる。

## 4. patch / minor / majorの扱い

Issue #258により、patch / minor / major、およびDependabot以外の通常PRを区別しない運用へ統一した。`main`向けの全PR（同一リポジトリ内のブランチから作成されたもの。1節参照）は次の条件に従う。

| 区分 | PRの単位 | マージ条件 |
| --- | --- | --- |
| patch | グループ化（`composer-patch` / `actions-patch`） | `test`成功 + 人間Approve1件（[`docs/pull-request-review.md`](pull-request-review.md)） |
| minor | グループ化（`composer-minor` / `actions-minor`） | 同上 |
| major | 個別PR | 同上 |
| 通常PR（人間・AIエージェント作成） | 個別PR | 同上 |

patchだけを自動マージし、minor/majorを手動対応とする区分はIssue #258で廃止した。

## 5. auto-mergeの条件

`.github/workflows/auto-merge.yaml` が、`main`向けに開かれた全PR（同一リポジトリ内のブランチから作成されたもの。Draft PRを除く）に対して、作成者やDependabotのupdate-typeを判定せず一律に `gh pr merge --auto --merge` を実行し、GitHubのauto-merge機能を有効化する。

- 対象リポジトリが `bvlion/holidays-webhook-server`（フォークからのPRを除外）
- base branchが `main`
- PRがDraftでない

以前使用していた `dependabot/fetch-metadata` によるDependabot判定・semver update-type判定は、patch限定の自動マージを廃止したことに伴い削除した。専用のPersonal Access Tokenは追加せず、標準の `GITHUB_TOKEN` のみを使用する。

`gh pr merge --auto` はGitHubの auto-merge 機能を有効化するだけであり、実際のマージ可否はmainブランチのrulesetが必須化した条件に従う（6節）。

- `test` の成功と人間による1件以上のApproveが両方揃うまで、実際のマージは行われない。
- 一方だけが成立した状態ではマージされない。
- auto-merge自体はCI失敗やレビュー未完了によって解除されるわけではなく、条件が揃った時点で自動的にマージされる。

## 6. mainブランチの保護（ruleset）

Issue #258で、`refs/heads/main` を対象とするrulesetを2つに分離した。1つのrulesetに`required_approving_review_count`とbypass許可を同居させると、bypass対象者が`required_status_checks`等の必須条件までまとめて回避できてしまうため、bypass範囲を最小化する目的で分離している。

- **`main protection`**（bypass設定なし。誰も回避できない）
  - `pull_request` ルール: Pull Request経由のマージのみを許可し、直接pushを禁止する。マージ方式は `merge`（merge commit）のみに制限する。
  - `required_status_checks` ルール: `test` チェックの成功を必須とする。
  - `non_fast_forward` ルール: force pushを禁止する。
- **`main protection - human approval`**（`required_approving_review_count: 1` のみを扱う）
  - `pull_request` ルール: 人間による1件以上のApproveを必須とする。新しいコミットがpushされた場合は既存のApproveを無効化する（`dismiss_stale_reviews_on_push: true`）。
  - bypass_actors: リポジトリ管理者 `bvlion`（GitHub User ID: 24517539）に対し、`bypass_mode: pull_request`（Pull Request経由のマージ時のみ有効。直接pushの許可ではない）でこのrulesetのみのbypassを許可する。

GitHubはPR作成者自身によるApproveを許可しないため、`bvlion`が作成したPRは誰もApproveできない。このため、`bvlion`自身が作成したPRに限り、`test`成功を人間が確認したうえで、GitHubの bypass merge（Approve要件のみ回避可能。`required_status_checks`は前述のとおり別rulesetにあり、`bvlion`を含め誰もbypassできないため回避されない）で手動マージする。`bvlion`以外が作成したPRは、`test`成功 + 人間のApprove1件が揃うとauto-mergeで自動的にmerge commitされる。

## 7. auto-merge有効化のためのリポジトリ設定

- リポジトリ設定 `allow_auto_merge` は `true`（Issue #222で変更済み、変更なし）。
- マージ方式は既存運用に合わせ、`merge commit` を使用する（`gh pr merge --auto --merge`、およびrulesetの `allowed_merge_methods: ["merge"]`）。squash・rebaseのリポジトリ設定自体は変更していない。
- `allow_update_branch` は変更していない（`false` のまま）。mainブランチの `required_status_checks` は `strict_required_status_checks_policy: false` とし、ブランチが最新でなくても、PRのhead commitで `test` が成功していればマージ可能とした。

## 8. Dependabot PRの手動対応方法

- CIが失敗したPRは、依存先の変更内容を確認し、必要に応じてコード側を修正するか、Dependabotのリベースを待つ。
- 古いDependabot PRでmainの現状と矛盾する、または既に不要になったものがあれば、内容を確認したうえで手動でclose/rebaseする（自動では処理しない）。
- patch / minor / major問わず、`bvlion`以外がApproveすればauto-mergeでマージされる。`bvlion`自身がApprove代わりにbypass mergeする対応は、Dependabot PRか通常PRかを問わない（6節）。

## 9. 既知の古いDependabot PR

- PR #206（`phpunit/phpunit` を `9.6.22` から `11.5.2` へ更新）は2024-12-22作成のまま残っているが、現在のmain（`src/composer.json`）は既に `phpunit/phpunit: ^12.0` を要求しており、このPRの内容は既に不要である。Issue #222の範囲ではclose/mergeせず、対応要否の判断を運用者に委ねる。
