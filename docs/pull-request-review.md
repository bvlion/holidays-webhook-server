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

Issue #257対応時点のmain ruleset `main protection` は次の状態である。

- mainへの変更はPull Request経由とする。
- required status checkは`test`。
- `test`では`make check`を実行する。
- Codexレビューはrequired status checkではない。
- approving reviewの必須数は0。
- force pushは禁止する。

したがって、Codexレビューが成功していても`test`が失敗しているPull Requestはマージ条件を満たさない。一方、Codexレビューの完了そのものはGitHubのruleset上のマージ条件には含めない。

人間Approveを必須化し、条件を満たしたPull Requestを自動マージする運用はIssue #258で別途扱う。#258の実装後は、同Issueでこの文書のマージ条件に関する記述も現行設定へ合わせて更新する。

## 4. 通常のレビュー手順

1. Pull Requestを作成する。
2. GitHub Actionsの`test`結果を確認する。
3. Automatic reviewsが有効であればCodexレビューを確認する。
4. 修正push後など、明示的な再レビューが必要なら`@codex review`をコメントする。
5. Codexの指摘と人間の確認結果を踏まえてマージ可否を判断する。

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
