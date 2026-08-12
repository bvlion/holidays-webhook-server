# Pull Requestレビュー運用

この文書は、`holidays-webhook-server` のPull RequestでCodex Code Reviewと既存のGitHub CIをどう使うかを定義する。

## 1. Codex Code Review

このリポジトリでは、GitHubに接続したCodex Code Reviewを追加のレビュー手段として利用する。

Codexの公式仕様では、対象リポジトリをCodex cloudへ接続し、Codex settingsでリポジトリの **Code review** を有効にする。すべての新規Pull Requestを自動レビューする場合は、同じ設定画面で **Automatic reviews** を有効にする。

自動レビューを有効にした場合、Pull Requestが新たにreview対象としてopenされたときにCodexレビューが起動する。

再レビューが必要な場合は、対象Pull Requestへ次のコメントを投稿する。

```text
@codex review
```

Codexは通常のGitHub Reviewとして結果を投稿する。レビュー時にはリポジトリ内の`AGENTS.md`を参照するため、リポジトリ固有のレビュー観点は同ファイルの`Code Review Rules`に記載する。

## 2. Codexレビューの位置づけ

Codexレビューは、CIや人間による最終判断を置き換えるものではなく、コード上の重大な問題・意図との不一致を早期に検出するための追加レビューとして扱う。

Codexから指摘があった場合は内容を確認し、妥当なら修正する。誤検出または意図した挙動である場合は、必要に応じてPull Request上で理由を明記する。

Codexレビュー自体をmainへのマージ必須status checkにはしない。Codexの応答遅延・外部サービス障害によって既存のCIやリポジトリ運用を停止させないためである。

## 3. 既存CI・main rulesetとの関係

Issue #258対応後のmainブランチは、`refs/heads/main`を対象とする2つのrulesetで保護されている。

- **`main protection`**（bypassなし。誰も回避できない）
  - mainへの変更はPull Request経由とする（直接pushは禁止）。マージ方式は`merge`（merge commit）のみ。
  - required status checkは`test`（`make check`を実行）。
  - force pushは禁止する。
- **`main protection - human approval`**
  - 人間による1件以上のApproveを必須とする（`required_approving_review_count: 1`）。
  - 新しいコミットがpushされると既存のApproveは無効化される（`dismiss_stale_reviews_on_push: true`）。
  - リポジトリ管理者`bvlion`のみ、Pull Request経由のマージ時に限り（`bypass_mode: pull_request`）このrulesetのApprove要件をbypassできる。`main protection`側は誰もbypassできないため、`bvlion`のPRでも`test`は必ず成功が必須。

GitHubの仕様上、PR作成者は自分自身のPRをApproveできない。このリポジトリのcollaboratorは`bvlion`のみであるため、`bvlion`が作成したPR（AIエージェント経由のPRを含む）は、`test`成功を人間が確認したうえで`bvlion`がGitHubのbypass merge機能で手動マージする。`bvlion`以外が作成したPRは、`test`成功 + 人間のApprove1件が揃うと、`.github/workflows/auto-merge.yaml`が有効化したauto-mergeによって自動的にmerge commitでマージされる。

Codexレビューはrequired status checkではなく、上記いずれのrulesetのマージ条件にも含まれない。Codexレビューが成功していても`test`が失敗している、または人間のApprove（`bvlion`のPRの場合はbypass merge）がなければマージされない。

## 4. 通常のレビュー手順

1. Pull Requestを作成する。
2. GitHub Actionsの`test`結果を確認する。
3. Automatic reviewsが有効であればCodexレビューを確認する。
4. 修正push後など、明示的な再レビューが必要なら`@codex review`をコメントする。
5. Codexの指摘と人間の確認結果を踏まえてマージ可否を判断する。
6. `bvlion`以外が作成したPRは、人間のApproveを行う（`test`成功と揃うとauto-mergeで自動的にマージされる）。
7. `bvlion`自身が作成したPRは、`test`成功を確認したうえで`bvlion`がGitHubのbypass merge機能で手動マージする（自分自身はApproveできないため）。

## 5. Codexのレビュー観点

Codexが参照する具体的なレビュー観点は`AGENTS.md`の`## Code Review Rules`を正とする。特に、次を優先する。

- 対象Issueの意図・スコープと実装の不一致
- 認証・認可、秘密情報、外部HTTP通信に関する問題
- Scheduler・定期実行・本番deployに影響する回帰
- DB schemaや既存データへ意図しない影響を与える変更
- 正常系だけでなく失敗時の挙動に関する重大な欠落

フォーマットや静的解析で機械的に検出できる問題は`make check`を優先し、Codexレビューでは重大な正しさ・安全性の問題を重視する。

## 6. 設定変更時の注意

- Codexレビュー導入のために既存の`test` required checkを外さない。
- Codexレビューをrequired checkへ追加する場合は、本Issueの範囲では行わず、運用上の必要性を別途判断する。
- GitHub AppやCodexの権限を変更する場合は、必要最小限の範囲を維持する。
- CodexのGitHub連携仕様が変わった場合は、OpenAI公式ドキュメントを確認して本書を更新する。
