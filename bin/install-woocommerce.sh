#!/usr/bin/env bash
# Downloads a pinned WooCommerce release from WordPress.org for the test suite.
# Usage: bin/install-woocommerce.sh [version]   (default: the version tests are pinned to)
set -euo pipefail
version="${1:-${MAILTONIC_WC_VERSION:-11.2.1}}"
dir="$(cd "$(dirname "$0")/.." && pwd)/.cache/woocommerce-${version}"
if [ -f "$dir/woocommerce/woocommerce.php" ]; then echo "$dir/woocommerce"; exit 0; fi
mkdir -p "$dir"
curl -fsSL "https://downloads.wordpress.org/plugin/woocommerce.${version}.zip" -o "$dir/wc.zip"
unzip -q "$dir/wc.zip" -d "$dir"
rm "$dir/wc.zip"
echo "$dir/woocommerce"
