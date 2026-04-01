#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"

if ! command -v php >/dev/null 2>&1; then
  echo "php が見つかりません。"
  echo "macOSなら: brew install php"
  exit 1
fi

mkdir -p "$ROOT_DIR/data"

echo "Starting local server: http://127.0.0.1:8000"
php -S 127.0.0.1:8000 -t "$ROOT_DIR"
