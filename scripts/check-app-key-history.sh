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

# Laravelの APP_KEY (base64:に32byteをbase64化した43文字+パディング1文字) の実値形式。
# .env.example の公開プレースホルダーもこの形式に合致するため、別途値そのもので除外する。
APP_KEY_FORMAT_RE='^base64:[A-Za-z0-9+/]{43}=$'
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

# 1コミット・1ファイルあたり、実値形式に合致する APP_KEY= 行をすべて対象にする(最後の
# 1件だけを暗黙に採用しない)。ファイル全体をシェル変数へ保持せず、`git show` の出力を
# `grep` へ直接ストリームし、1行ずつだけメモリに保持する。
for path in "${TARGET_PATHS[@]}"; do
  while IFS= read -r commit; do
    [ -z "$commit" ] && continue
    while IFS= read -r line; do
      [ -z "$line" ] && continue
      value="${line#APP_KEY=}"
      value="${value%$'\r'}"
      # 前後が同じ種類のquoteで対になっている場合だけ1ペアを除去する。
      # 片側だけquoteがある、または左右で種類が異なる場合は不正値として空にする。
      vlen=${#value}
      if [ "$vlen" -ge 2 ] && [ "${value:0:1}" = '"' ] && [ "${value: -1}" = '"' ]; then
        value="${value:1:vlen-2}"
      elif [ "$vlen" -ge 2 ] && [ "${value:0:1}" = "'" ] && [ "${value: -1}" = "'" ]; then
        value="${value:1:vlen-2}"
      elif [ "${value:0:1}" != '"' ] && [ "${value: -1}" != '"' ] && [ "${value:0:1}" != "'" ] && [ "${value: -1}" != "'" ]; then
        : # quoteなし。そのまま扱う
      else
        value="" # 片側だけ、または左右で異なるquote。不正値として扱う
      fi
      if [ -z "$value" ] || [ "$value" = "$PLACEHOLDER_APP_KEY" ]; then
        value=""
        continue
      fi
      if ! printf '%s' "$value" | grep -Eq "$APP_KEY_FORMAT_RE"; then
        value=""
        continue
      fi
      value_hash="$(printf '%s' "$value" | sha256_of)"
      value=""
      printf '%s\n' "$value_hash" >> "$HASH_FILE"
    done < <(git show "${commit}:${path}" 2>/dev/null | grep -E '^APP_KEY=' || true)
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
