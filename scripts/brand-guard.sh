#!/usr/bin/env bash
# Fails if the upstream product name appears in any file name or file content.
# The name is assembled from two halves so this script never trips itself.
set -euo pipefail
cd "${1:-$(dirname "$0")/..}"
needle="ace""lle"
hits=$(
  {
    find . \( -name .git -o -name vendor -o -name node_modules \) -prune -o -iname "*${needle}*" -print
    grep -rIil --exclude-dir=.git --exclude-dir=vendor --exclude-dir=node_modules -- "$needle" . || true
  } | sort -u
)
if [ -n "$hits" ]; then
  echo "brand guard: forbidden name found in:" >&2
  echo "$hits" >&2
  exit 1
fi
echo "brand guard: clean"
