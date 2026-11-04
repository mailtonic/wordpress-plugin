#!/usr/bin/env bash
# Builds build/mailtonic.zip: runtime files only (no tests, dev configuration or source maps).
set -euo pipefail
cd "$(dirname "$0")/.."
rm -rf build && mkdir -p build/mailtonic
cp -R mailtonic.php uninstall.php readme.txt LICENSE includes blocks assets build/mailtonic/
find build/mailtonic -name '*.map' -delete
(cd build && zip -qr mailtonic.zip mailtonic)
if unzip -l build/mailtonic.zip | grep -E 'tests/|phpunit|phpcs|composer\.|\.map$|docker-compose|\.github' ; then
  echo "build: dev files in the zip" >&2; exit 1
fi
echo "build/mailtonic.zip"
