#!/usr/bin/env bash
# Canary inventory for .distignore: copies the tracked tree into a temporary directory, adds
# synthetic canary files (run folders, receipts, secrets, local config, dev packages), applies
# .distignore exactly as scripts/build-zip.sh does (rsync --exclude-from) and checks that every
# canary is left out and every runtime file stays. Writes only to a temporary directory.
#
#   bash tests/matrix/test-distignore.sh
set -uo pipefail

REPO=$(cd "$(dirname "$0")/../.." && pwd)
WORK=$(mktemp -d "${TMPDIR:-/tmp}/blzmcp-distignore.XXXXXX")
trap 'rm -rf "$WORK"' EXIT
SRC="$WORK/src"
DST="$WORK/out"
mkdir -p "$SRC" "$DST"
git -C "$REPO" ls-files -z | (cd "$REPO" && xargs -0 tar cf -) | tar xf - -C "$SRC"
cp "$REPO/.distignore" "$SRC/.distignore"
# Composer runtime (built by build-zip.sh) and a dev package that must never ship.
mkdir -p "$SRC/vendor/composer" "$SRC/vendor/woocommerce/commerce-abilities/src" "$SRC/node_modules/x" "$SRC/dist"
echo '<?php' >"$SRC/vendor/autoload.php"
echo '<?php' >"$SRC/vendor/composer/autoload_real.php"
echo '<?php' >"$SRC/vendor/woocommerce/commerce-abilities/src/Canary.php"
CANARIES=(
	reports/tech-lead-run/FIX-LOG.md reports/tech-lead-run/RUN-STATE.json reports/tech-lead-run/receipts/payment-performance.json
	receipts/package.json src/receipts/x.txt attempts/1/log.txt _sentinel run-feature.json commands.jsonl
	.env .env.local site.env auth.json wp-config.php wp-config-local.php key.pem private.key bundle.p12
	dump.sql dump.sql.gz customers.csv debug.log candidate-abc.zip backup.tar.gz
	.codex/config .orca/state .cursor/x .vscode/settings.json .idea/workspace.xml .claude/settings.json
	CLAUDE.local.md NOTES.md phpstan.neon .phpunit.cache/x composer.lock package.json tests/x.php scripts/x.sh docs/x.txt
	node_modules/x/index.js dist/blaze.zip vendor-src/x.php file.php~ file.php.bak .file.swp
)
for c in "${CANARIES[@]}"; do
	mkdir -p "$SRC/$(dirname "$c")"
	echo canary >"$SRC/$c"
done
rsync -a --delete --exclude-from="$SRC/.distignore" "$SRC/" "$DST/"

pass=0
fail=0
for c in "${CANARIES[@]}"; do
	if [ -e "$DST/$c" ]; then echo "FAIL  canary ships: $c"; fail=$((fail + 1)); else pass=$((pass + 1)); fi
done
REQUIRED=(blaze-mcp-toolkit-for-woocommerce.php uninstall.php readme.txt composer.json LICENSE.txt vendor/autoload.php vendor/composer/autoload_real.php vendor/woocommerce/commerce-abilities/src/Canary.php)
while IFS= read -r f; do REQUIRED+=("$f"); done < <(git -C "$REPO" ls-files src)
for r in "${REQUIRED[@]}"; do
	if [ -f "$DST/$r" ]; then pass=$((pass + 1)); else echo "FAIL  runtime file left out: $r"; fail=$((fail + 1)); fi
done
extra=$(cd "$DST" && find . -type f | sed 's|^\./||' | grep -vE '^(src/|vendor/(autoload\.php|composer/|woocommerce/commerce-abilities/))' | grep -vxE 'blaze-mcp-toolkit-for-woocommerce\.php|uninstall\.php|readme\.txt|composer\.json|LICENSE\.txt')
if [ -n "$extra" ]; then echo "FAIL  unexpected files would ship: $(printf '%s' "$extra" | head -10 | tr '\n' ' ')"; fail=$((fail + 1)); else pass=$((pass + 1)); fi
echo "$pass passed, $fail failed (${#CANARIES[@]} canaries)"
[ "$fail" -eq 0 ]
