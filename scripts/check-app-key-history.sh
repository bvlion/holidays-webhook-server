#!/usr/bin/env bash
#
# Issue #209: 本番 APP_KEY と Git履歴上の実値形式 APP_KEY を、値を露出せずに照合する。
# ローカルの信頼できるcloneでのみ実行する。XServer(本番)では実行しない。
#
# 使い方:
#   1. XServer上で本番 .env の APP_KEY を SHA-256 化する(docs/secrets-management.md 参照)。
#   2. このスクリプトをローカルcloneで実行し、そのSHA-256を非表示入力として渡す。
#   3. 標準出力へ MATCH または NO_MATCH のみが出力される。

# 継承された SHELLOPTS=xtrace 等で秘密値やハッシュ値が意図せず出力されるのを防ぐため、
# 最初に明示的にトレースを無効化する。
set +o xtrace
set +x

set -euo pipefail

TARGET_PATHS=(".env" "src/.env" "src/.env.testing")
PLACEHOLDER_APP_KEY="base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA="

if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  echo "エラー: このスクリプトはGitリポジトリ内でのみ実行できます。" >&2
  exit 1
fi

if command -v shasum >/dev/null 2>&1; then
  sha256_of() { shasum -a 256 | awk '{print $1}'; }
elif command -v sha256sum >/dev/null 2>&1; then
  sha256_of() { sha256sum | awk '{print $1}'; }
else
  echo "エラー: shasumまたはsha256sumが見つかりません。" >&2
  exit 1
fi

REPO_ROOT="$(git rev-parse --show-toplevel)"
cd "$REPO_ROOT"

TMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/check-app-key-history.XXXXXX")"
chmod 700 "$TMP_DIR"
HASH_FILE="$TMP_DIR/hashes"
: > "$HASH_FILE"
chmod 600 "$HASH_FILE"
trap 'rm -rf "$TMP_DIR"' EXIT INT TERM

echo "本番 APP_KEY の SHA-256 (64桁16進)を入力してください:" >&2
IFS= read -r -s PROD_HASH
echo >&2
PROD_HASH="$(printf '%s' "$PROD_HASH" | tr '[:upper:]' '[:lower:]')"

if ! printf '%s' "$PROD_HASH" | grep -Eq '^[0-9a-f]{64}$'; then
  echo "エラー: 入力値が64桁のSHA-256形式ではありません。" >&2
  exit 1
fi

for path in "${TARGET_PATHS[@]}"; do
  while IFS= read -r commit; do
    [ -z "$commit" ] && continue
    content="$(git show "${commit}:${path}" 2>/dev/null || true)"
    [ -z "$content" ] && continue
    value="$(printf '%s\n' "$content" \
      | grep -E '^APP_KEY=' \
      | tail -n1 \
      | sed -e 's/^APP_KEY=//' -e 's/\r$//' -e 's/^"//' -e 's/"$//')"
    content=""
    if [ -z "$value" ] || [ "$value" = "$PLACEHOLDER_APP_KEY" ]; then
      value=""
      continue
    fi
    value_hash="$(printf '%s' "$value" | sha256_of)"
    value=""
    printf '%s\n' "$value_hash" >> "$HASH_FILE"
  done < <(git log --all --format='%H' -- "$path")
done

UNIQUE_COUNT="$(sort -u "$HASH_FILE" | wc -l | tr -d ' ')"

if [ "$UNIQUE_COUNT" -ne 2 ]; then
  echo "エラー: Git履歴上のユニークな実値形式APP_KEYが2種類ではありません(検出: ${UNIQUE_COUNT}種類)。" >&2
  exit 1
fi

if grep -qxF "$PROD_HASH" "$HASH_FILE"; then
  echo "MATCH"
else
  echo "NO_MATCH"
fi
