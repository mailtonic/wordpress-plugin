#!/usr/bin/env bash
# CI check 101: each guard passes the real tree and fails a fixture tree holding a forbidden string.
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

copy() {
  rm -rf "$tmp/tree" && mkdir -p "$tmp/tree"
  (cd "$root" && git ls-files -co --exclude-standard | grep -v '^vendor/' | tar -cf - -T -) | tar -xf - -C "$tmp/tree"
  cp -R "$root/scripts" "$tmp/tree/"
}

expect() { # expect pass|fail guard
  if "$tmp/tree/scripts/$2" "$tmp/tree" >/dev/null 2>&1; then got=pass; else got=fail; fi
  [ "$got" = "$1" ] || { echo "test-guards: $2 should $1 ($3)" >&2; exit 1; }
}

copy
expect pass brand-guard.sh "real tree"
expect pass host-guard.sh "real tree"

for needle in "ace""lle" "Ace""lle"; do
  copy; echo "Powered by ${needle}" > "$tmp/tree/fixture.txt"
  expect fail brand-guard.sh "content $needle"
done
copy; touch "$tmp/tree/includes/${needle}-helper.php"
expect fail brand-guard.sh "file name"

for needle in "relyweb""-internal" "api.billing.inter""nal" "db.co""rp" "10.1.2"".3" "192.16""8.0.10" "172.20"".0.5"; do
  copy; echo "host: ${needle}" > "$tmp/tree/fixture.txt"
  expect fail host-guard.sh "$needle"
done
echo "test-guards: ok"
