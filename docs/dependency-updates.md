# 依存関係更新の運用

## 1. 目的と対象

この文書は、Issue #222 に基づき、Dependabotによる依存関係更新の運用方針を定める。

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

patchグループとminorグループを分離し、majorをどちらとも混在させないことで、後述のauto-mergeワークフローが「そのPRがpatchのみで構成されているか」を安全に判定できるようにしている。

## 4. patch / minor / majorの扱い

| 区分 | PRの単位 | 自動マージ |
| --- | --- | --- |
| patch | グループ化（`composer-patch` / `actions-patch`） | する |
| minor | グループ化（`composer-minor` / `actions-minor`） | しない（手動確認） |
| major | 個別PR | しない（手動確認・個別対応） |

## 5. patch自動マージの条件

`.github/workflows/dependabot-auto-merge.yaml` が、`dependabot/fetch-metadata` を使い、GitHub公式ドキュメント（[Automating Dependabot with GitHub Actions](https://docs.github.com/en/code-security/tutorials/secure-your-dependencies/automate-dependabot-with-actions)）の手順に沿って以下をすべて満たす場合だけ `gh pr merge --auto --merge` でauto-mergeを有効化する。

- PR作成者が `dependabot[bot]`（`github.actor` と `pull_request.user.login` の両方で確認）
- 対象リポジトリが `bvlion/holidays-webhook-server`（フォークからのPRを除外）
- base branchが `main`
- `dependabot/fetch-metadata` の `update-type` 出力が `version-update:semver-patch`
- mainブランチのruleset上で必須の `test` チェックが成功していること（後述）

minor・major、および人間が作成した通常のPRはこの条件に一致しないため自動マージされない。専用のPersonal Access Tokenは追加せず、標準の `GITHUB_TOKEN` のみを使用する。

`gh pr merge --auto` はGitHubの auto-merge 機能を有効化するだけであり、実際のマージ可否は、mainブランチで必須化した `test` ステータスチェックの結果に従う。

- `test` が成功するまで、実際のマージは行われない。
- `test` が失敗している状態ではマージされない。
- その後 `test` を再実行するなどして成功すれば、他の条件（PR作成者、base branch、`update-type` 等）を満たしている限り、有効化されたauto-mergeによって自動的にマージされ得る。auto-merge自体はCI失敗によって解除されるわけではない。

## 6. mainブランチの保護（ruleset）

リポジトリruleset「main protection」（`refs/heads/main` 対象、enforcement: active）で次を必須化した。

- `pull_request` ルール: Pull Request経由のマージのみを許可し、直接pushを禁止する（レビュー承認は必須化していない。`required_approving_review_count: 0`）
- `required_status_checks` ルール: `test` チェックの成功を必須とする
- `non_fast_forward` ルール: force pushを禁止する

既存の手動PR運用（レビュー承認なしでマージできる運用）は変更していない。

## 7. auto-merge有効化のためのリポジトリ設定

- リポジトリ設定 `allow_auto_merge` を `true` に変更した（変更前: `false`）。
- マージ方式は既存運用に合わせ、`merge commit` を使用する（`gh pr merge --auto --merge`）。squash・rebaseのリポジトリ設定自体は変更していない。
- `allow_update_branch` は変更していない（`false` のまま）。mainブランチの `required_status_checks` は `strict_required_status_checks_policy: false` とし、ブランチが最新でなくても、PRのhead commitで `test` が成功していればマージ可能とした。

## 8. Dependabot PRの手動対応方法

- minor・majorのPRは、内容を確認し、CIが成功していることを確認したうえで手動でマージする。
- CIが失敗したPRは、依存先の変更内容を確認し、必要に応じてコード側を修正するか、Dependabotのリベースを待つ。
- 古いDependabot PRでmainの現状と矛盾する、または既に不要になったものがあれば、内容を確認したうえで手動でclose/rebaseする（自動では処理しない）。

## 9. 既知の古いDependabot PR

- PR #206（`phpunit/phpunit` を `9.6.22` から `11.5.2` へ更新）は2024-12-22作成のまま残っているが、現在のmain（`src/composer.json`）は既に `phpunit/phpunit: ^12.0` を要求しており、このPRの内容は既に不要である。Issue #222の範囲ではclose/mergeせず、対応要否の判断を運用者に委ねる。
