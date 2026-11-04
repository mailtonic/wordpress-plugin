#!/usr/bin/env bash
# Fails unless the plugin header version, readme.txt's stable tag and (when given) the git tag agree.
# Usage: bin/check-version.sh [v1.2.3]
set -euo pipefail
cd "$(dirname "$0")/.."
header=$(sed -n 's/^ \* Version: *//p' mailtonic.php | tr -d '[:space:]')
constant=$(sed -n "s/.*const VERSION *= *'\([^']*\)'.*/\1/p" includes/class-plugin.php)
stable=$(sed -n 's/^Stable tag: *//p' readme.txt | tr -d '[:space:]')
tag="${1:-}"; tag="${tag#v}"
echo "header=$header constant=$constant readme=$stable tag=${tag:-none}"
[ -n "$header" ] && [ "$header" = "$stable" ] && [ "$header" = "$constant" ] || { echo "version mismatch" >&2; exit 1; }
[ -z "$tag" ] || [ "$tag" = "$header" ] || { echo "tag does not match the plugin version" >&2; exit 1; }
