#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "$0")" && pwd)"
chat_root="$(cd "$script_dir/.." && pwd)"
project_root="$(cd "$chat_root/.." && pwd)"

chat_lp="$chat_root/lp/index.html"
mng_lp="${MNG_LP_PATH:-$(find "$project_root" -maxdepth 6 -type f -path "*予実管理*/*/lp/index.html" | head -n1)}"
dic_lp="${DIC_LP_PATH:-$(find "$project_root" -maxdepth 6 -type f -path "*辞書ア*/lp/index.html" | head -n1)}"

if [[ ! -f "$chat_lp" ]]; then
  echo "ERROR: chat LP not found: $chat_lp" >&2
  exit 1
fi

if [[ -z "${mng_lp:-}" || ! -f "$mng_lp" ]]; then
  echo "ERROR: mng LP not found. Set MNG_LP_PATH." >&2
  exit 1
fi

if [[ -z "${dic_lp:-}" || ! -f "$dic_lp" ]]; then
  echo "ERROR: dic LP not found. Set DIC_LP_PATH." >&2
  exit 1
fi

fail() {
  echo "NG: $1" >&2
  exit 1
}

expect_contains() {
  local file="$1"
  local pattern="$2"
  local label="$3"
  rg -q -- "$pattern" "$file" || fail "$label"
}

expect_not_contains() {
  local file="$1"
  local pattern="$2"
  local label="$3"
  if rg -q -- "$pattern" "$file"; then
    fail "$label"
  fi
}

style_blocks="$(rg -c -- '^<style>$' "$chat_lp")"
[[ "$style_blocks" == "2" ]] || fail "chat LP: <style> blocks must be exactly 2 (now: $style_blocks)"

expect_contains "$chat_lp" '<section class="hero">' "chat LP: hero section missing"
expect_contains "$chat_lp" '<section class="cta">' "chat LP: CTA section missing"
expect_not_contains "$chat_lp" 'id="contact"' "chat LP: CTA must not have id=contact"

expect_contains "$chat_lp" 'こんなイベント運営者に向いています' "chat LP: Target section title changed"
expect_contains "$chat_lp" '<div class="grid">' "chat LP: grid class missing"
expect_contains "$chat_lp" '<div class="card">' "chat LP: card class missing"
expect_not_contains "$chat_lp" 'feat-grid|feat-card' "chat LP: old feat-grid/feat-card classes remain"

expect_contains "$chat_lp" '--ac:' "chat LP: --ac variable missing"
expect_contains "$chat_lp" '--ac2:' "chat LP: --ac2 variable missing"
expect_contains "$chat_lp" '--ac3:' "chat LP: --ac3 variable missing"
expect_contains "$chat_lp" '--ac4:' "chat LP: --ac4 variable missing"
expect_not_contains "$chat_lp" '--grn|--grn2|--grn3|--grn4' "chat LP: old --grn variables remain"

expect_contains "$chat_lp" '--fs-s:' "chat LP: --fs-s missing"
expect_contains "$chat_lp" '--fs-m:' "chat LP: --fs-m missing"
expect_contains "$chat_lp" '--fs-l:' "chat LP: --fs-l missing"
expect_not_contains "$chat_lp" '--font-xs|--font-sm|--font-md|--font-lg' "chat LP: old --font-* variables remain"

expect_contains "$chat_lp" '\.hero-free-num,\.beta b\{display:block;' "chat LP: beta line-break style missing"

expect_contains "$mng_lp" '<section class="hero">' "mng LP: hero section missing"
expect_contains "$dic_lp" '<section class="hero">' "dic LP: hero section missing"

echo "OK: LP regression checks passed"
echo "  chat: $chat_lp"
echo "  mng : $mng_lp"
echo "  dic : $dic_lp"
