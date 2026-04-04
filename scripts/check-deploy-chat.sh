#!/usr/bin/env bash
set -euo pipefail

# Quick deploy verification for Chat.app production.
# Usage:
#   bash scripts/check-deploy-chat.sh
#   bash scripts/check-deploy-chat.sh https://drsp.cc/chat

BASE_URL="${1:-https://drsp.cc/chat}"
NOW="$(date +%s)"

INDEX_URL="${BASE_URL%/}/index.html?t=${NOW}"
HEALTH_URL="${BASE_URL%/}/api.php?action=health&t=${NOW}"
POLL_URL="${BASE_URL%/}/api.php?action=getPoll&session=webinar-demo&voterToken=deploy-check-${NOW}"

echo "[1/3] index headers: ${INDEX_URL}"
INDEX_HEADERS="$(curl -sSI -H 'Cache-Control: no-cache' -H 'Pragma: no-cache' "$INDEX_URL")"
echo "$INDEX_HEADERS" | awk 'BEGIN{IGNORECASE=1} /HTTP\/|last-modified:|etag:|content-length:|date:/ {print}'

STATUS_CODE="$(echo "$INDEX_HEADERS" | awk 'BEGIN{IGNORECASE=1} /^HTTP\// {print $2; exit}')"
if [ "${STATUS_CODE}" != "200" ]; then
  echo "ERROR: index status is ${STATUS_CODE:-unknown} (expected 200)" >&2
  exit 1
fi

echo
echo "[2/3] health: ${HEALTH_URL}"
HEALTH_JSON="$(curl -sS "$HEALTH_URL")"
echo "$HEALTH_JSON" | jq -c '{ok,version,time}'

HEALTH_OK="$(echo "$HEALTH_JSON" | jq -r '.ok')"
if [ "$HEALTH_OK" != "true" ]; then
  echo "ERROR: health endpoint returned ok=false" >&2
  exit 1
fi

echo
echo "[3/3] getPoll contract: ${POLL_URL}"
POLL_JSON="$(curl -sS "$POLL_URL")"
echo "$POLL_JSON" | jq -c '{ok,hasPollList:(has("pollList")),pollListType:(.pollList|type),keys:(keys|sort)}'

POLL_OK="$(echo "$POLL_JSON" | jq -r '.ok')"
HAS_POLL_LIST="$(echo "$POLL_JSON" | jq -r 'has("pollList")')"
if [ "$POLL_OK" != "true" ] || [ "$HAS_POLL_LIST" != "true" ]; then
  echo "ERROR: getPoll contract check failed (ok=${POLL_OK}, hasPollList=${HAS_POLL_LIST})" >&2
  exit 1
fi

echo
echo "Deploy check: OK"
