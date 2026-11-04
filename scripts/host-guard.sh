#!/usr/bin/env bash
# Fails if a file names an internal project or a private host: the internal project slug,
# *.internal / *.corp / *.lan host names, or RFC 1918 addresses. Patterns are assembled from
# pieces so this script never trips itself.
set -euo pipefail
cd "${1:-$(dirname "$0")/..}"
slug="relyweb""-internal"
hosts='[a-z0-9-]+\.(internal|corp|lan)\b'
rfc1918='\b(10\.[0-9]{1,3}|192\.168|172\.(1[6-9]|2[0-9]|3[01]))\.[0-9]{1,3}\.[0-9]{1,3}\b'
hits=$(grep -rIilE --exclude-dir=.git --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.cache --exclude-dir=wp-content --exclude-dir=build -- "${slug}|${hosts}|${rfc1918}" . || true)
if [ -n "$hits" ]; then
  echo "host guard: internal names or private hosts found in:" >&2
  echo "$hits" >&2
  exit 1
fi
echo "host guard: clean"
