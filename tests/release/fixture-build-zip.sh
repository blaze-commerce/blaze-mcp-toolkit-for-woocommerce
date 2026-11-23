#!/usr/bin/env bash
# Stand-in for scripts/build-zip.sh inside the fixture repository tests/release/test-promotion.sh
# creates. No Composer: it stages the tracked tree with .distignore exactly as build-zip.sh does,
# maps vendor-src/commerce-abilities into vendor/, and writes fake Composer runtime files that
# change on every build (as real Composer output can). Every run is logged to
# $BLZMCP_TEST_BUILD_LOG. BLZMCP_TEST_BUILD_MODE=excluded|differ|dirty builds a broken package.
set -euo pipefail

SLUG="blaze-mcp-toolkit-for-woocommerce"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
STAGE="$ROOT/dist/$SLUG"
echo "build $(date +%s)" >>"${BLZMCP_TEST_BUILD_LOG:?}"
rm -rf "$ROOT/dist"
mkdir -p "$STAGE"
rsync -a --delete --exclude-from="$ROOT/.distignore" "$ROOT/" "$STAGE/"
mkdir -p "$STAGE/vendor/woocommerce/commerce-abilities" "$STAGE/vendor/composer"
rsync -a --exclude-from="$ROOT/.distignore" "$ROOT/vendor-src/commerce-abilities/" "$STAGE/vendor/woocommerce/commerce-abilities/"
stamp="$$-$RANDOM"
for f in autoload.php composer/autoload_real.php composer/autoload_static.php composer/ClassLoader.php composer/installed.php; do
  printf '<?php // composer %s %s\n' "$f" "$stamp" >"$STAGE/vendor/$f"
done
case "${BLZMCP_TEST_BUILD_MODE:-good}" in
  excluded) echo leak >"$STAGE/debug.log" ;;
  differ) echo '// changed' >>"$STAGE/src/Plugin.php" ;;
  dirty) echo '// changed' >>"$ROOT/src/Plugin.php" ;;
  good) ;;
esac
(cd "$ROOT/dist" && zip -qrX "$SLUG.zip" "$SLUG")
echo "Built dist/$SLUG.zip"
