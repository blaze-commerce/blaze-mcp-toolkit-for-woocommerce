#!/usr/bin/env bash
# Self-test for tests/matrix/record.sh's zip inventory and byte-identity check. Builds small zips
# from the files at HEAD (no wp-env, no network) and checks that record.sh accepts a faithful one
# and refuses each broken one with exit 3. Writes only to a temporary directory.
#
#   bash tests/matrix/test-record.sh
set -uo pipefail

REPO=$(cd "$(dirname "$0")/../.." && pwd)
PKG=blaze-mcp-toolkit-for-woocommerce
COMMIT=$(git -C "$REPO" rev-parse HEAD)
WORK=$(mktemp -d "${TMPDIR:-/tmp}/blzmcp-test-record.XXXXXX")
trap 'rm -rf "$WORK"' EXIT
pass=0
fail=0

# A faithful package at HEAD: tracked src/ and top-level files, vendor-src mapped into vendor/.
stage_good() {
	local dir=$1
	rm -rf "$dir"
	mkdir -p "$dir/$PKG"
	git -C "$REPO" ls-tree -r --name-only "$COMMIT" | while IFS= read -r f; do
		local dest=''
		case "$f" in
		src/* | "$PKG.php" | uninstall.php | readme.txt | composer.json | LICENSE.txt) dest=$f ;;
		vendor-src/commerce-abilities/*/UPSTREAM | vendor-src/commerce-abilities/UPSTREAM | vendor-src/commerce-abilities/composer.lock | vendor-src/commerce-abilities/package.json) ;;
		vendor-src/commerce-abilities/*) dest="vendor/woocommerce/commerce-abilities/${f#vendor-src/commerce-abilities/}" ;;
		esac
		[ -n "$dest" ] || continue
		mkdir -p "$dir/$PKG/$(dirname "$dest")"
		git -C "$REPO" show "$COMMIT:$f" >"$dir/$PKG/$dest"
	done
	mkdir -p "$dir/$PKG/vendor/composer"
	echo '<?php // composer' >"$dir/$PKG/vendor/autoload.php"
	echo '<?php // composer' >"$dir/$PKG/vendor/composer/autoload_real.php"
}

# check <label> <expected exit> <stage dir>
check() {
	local label=$1 want=$2 dir=$3 zip="$WORK/candidate-test.zip" out="$WORK/out-$RANDOM.md"
	rm -f "$zip"
	(cd "$dir" && zip -qr "$zip" .)
	printf 'ZIP=%s\nCOMMIT=%s\nWINDOW_FILE=\n' "$zip" "$COMMIT" >"$WORK/summary.txt"
	BLZMCP_MATRIX_OUT=$out BLZMCP_REPORTS_DIR=$WORK bash "$REPO/tests/matrix/record.sh" "$WORK/summary.txt" PASSED >"$WORK/log" 2>&1
	local got=$?
	if [ "$got" -eq "$want" ]; then
		echo "PASS  $label (exit $got)"
		pass=$((pass + 1))
	else
		echo "FAIL  $label (expected exit $want, got $got)"
		sed 's/^/      /' "$WORK/log" | head -5
		fail=$((fail + 1))
	fi
}

stage_good "$WORK/good"
check 'faithful package is accepted' 0 "$WORK/good"
grep -q 'byte-identical' "$WORK"/out-*.md 2>/dev/null && grep -q '^- Inventory: ' "$WORK"/out-*.md && {
	echo "PASS  evidence names byte identity and the inventory"
	pass=$((pass + 1))
} || {
	echo "FAIL  evidence names byte identity and the inventory"
	fail=$((fail + 1))
}

stage_good "$WORK/vend"
v=$(find "$WORK/vend/$PKG/vendor/woocommerce/commerce-abilities" -name '*.php' -type f | sort | head -1)
echo '// changed' >>"$v"
check 'changed vendored commerce file is refused' 3 "$WORK/vend"

stage_good "$WORK/src"
echo '// changed' >>"$WORK/src/$PKG/src/Connectors/EasyMcpConnector.php"
check 'changed src file is refused' 3 "$WORK/src"

stage_good "$WORK/miss"
rm "$WORK/miss/$PKG/src/Connectors/EasyMcpConnector.php"
check 'missing src file is refused' 3 "$WORK/miss"

stage_good "$WORK/extra"
echo 'x' >"$WORK/extra/$PKG/vendor/other.php"
echo 'x' >"$WORK/extra/$PKG/not-in-git.php"
check 'unexpected vendor/ and untracked files are refused' 3 "$WORK/extra"

mkdir -p "$WORK/empty/$PKG"
mkdir -p "$WORK/empty/$PKG/vendor/composer" && echo '<?php' >"$WORK/empty/$PKG/vendor/autoload.php"
check 'zero matched files is refused' 3 "$WORK/empty"

mkdir -p "$WORK/prefix/other"
cp -R "$WORK/good/$PKG/." "$WORK/prefix/other/"
check 'wrong top-level folder is refused' 3 "$WORK/prefix"

echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
