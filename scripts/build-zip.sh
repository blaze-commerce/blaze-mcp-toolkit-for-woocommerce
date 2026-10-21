#!/usr/bin/env bash
set -euo pipefail

SLUG="blaze-mcp-toolkit-for-woocommerce"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
STAGE="$ROOT/dist/$SLUG"
trap '( cd "$ROOT" && composer install --prefer-dist --no-interaction --quiet )' EXIT

rm -rf "$ROOT/dist"
mkdir -p "$STAGE"

( cd "$ROOT" && composer install --no-dev --prefer-dist --no-interaction --classmap-authoritative --quiet )

rsync -a --delete --exclude-from="$ROOT/.distignore" "$ROOT/" "$STAGE/"

( cd "$ROOT/dist" && zip -qr "$SLUG.zip" "$SLUG" )
echo "Built dist/$SLUG.zip"
unzip -l "$ROOT/dist/$SLUG.zip" | grep -E "vendor/autoload.php|readme.txt"
