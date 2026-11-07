#!/usr/bin/env bash
# Writes the matrix evidence record: ../reports/v1.1-matrix-<UTC date>.md (outside the repo).
# An existing record is never overwritten: the next free -r2, -r3 … name is used instead, or
# BLZMCP_MATRIX_OUT names the file (it must not exist yet).
#
#   bash tests/matrix/record.sh <summary.txt from run-matrix.sh> <PASSED|FAILED>
#
# Zip inventory and byte identity (fails the record, exit 3, on any problem): every file in the
# zip must be byte-identical to its source at the evidence commit. vendor/woocommerce/
# commerce-abilities/* is compared with vendor-src/commerce-abilities/*; the only other files
# allowed without a git source are composer's vendor/autoload.php and vendor/composer/*. Every
# tracked src/ file, the plugin's top-level files and every vendored source file must be in the
# zip, and both matched counts must be above zero.
#
# Contents: the git commit, the candidate zip's SHA-256, the fixture window and timezone, and per
# state the WP, WooCommerce, PHP and plugin versions (wp plugin list) with pass/fail counts.
# No credentials and no customer data (the fixture's customers are synthetic) go in it.
set -uo pipefail

SUMMARY=${1:?summary file}
VERDICT=${2:?PASSED or FAILED}
REPO=$(cd "$(dirname "$0")/../.." && pwd)
OUT_DIR=${BLZMCP_REPORTS_DIR:-$REPO/../reports}
mkdir -p "$OUT_DIR"
if [ -n "${BLZMCP_MATRIX_OUT:-}" ]; then
	OUT=$BLZMCP_MATRIX_OUT
	[ ! -e "$OUT" ] || { echo "MATRIX RECORD REFUSED: $OUT already exists"; exit 4; }
else
	base="$OUT_DIR/v1.1-matrix-$(date -u +%Y-%m-%d)"
	OUT="$base.md"
	n=2
	while [ -e "$OUT" ]; do
		OUT="$base-r$n.md"
		n=$((n + 1))
	done
fi

zip=$(sed -n 's/^ZIP=//p' "$SUMMARY")
commit=$(sed -n 's/^COMMIT=//p' "$SUMMARY")
window_file=$(sed -n 's/^WINDOW_FILE=//p' "$SUMMARY")
# Tie the zip to the evidence commit (see the header): byte identity for every shipped file,
# vendored commerce files against vendor-src/, and an inventory of what must be present.
PKG=blaze-mcp-toolkit-for-woocommerce
VPFX=vendor/woocommerce/commerce-abilities/
SPFX=vendor-src/commerce-abilities/
check_dir=$(mktemp -d "${TMPDIR:-/tmp}/blzmcp-record.XXXXXX")
differ=()
same=0
vendored=0
built=0
if ! unzip -Z1 "$zip" >"$check_dir/names" 2>/dev/null || ! unzip -q "$zip" -d "$check_dir/x"; then
	differ+=("(zip unreadable)")
fi
if grep -qvE "^$PKG/" "$check_dir/names" 2>/dev/null || grep -qE '(^|/)\.\.(/|$)' "$check_dir/names" 2>/dev/null; then
	differ+=("(zip has entries outside $PKG/)")
fi
: >"$check_dir/present"
while IFS= read -r f; do
	rel=${f#"$check_dir/x/$PKG"/}
	echo "$rel" >>"$check_dir/present"
	case "$rel" in
	"$VPFX"*) src="$SPFX${rel#"$VPFX"}" ;;
	vendor/autoload.php | vendor/composer/*)
		built=$((built + 1))
		continue
		;;
	vendor/*)
		differ+=("$rel (unexpected vendor file)")
		continue
		;;
	*) src=$rel ;;
	esac
	if ! git -C "$REPO" cat-file -e "$commit:$src" 2>/dev/null; then
		differ+=("$rel (no source at commit)")
	elif ! cmp -s "$f" <(git -C "$REPO" show "$commit:$src"); then
		differ+=("$rel (bytes differ)")
	elif [ "$src" != "$rel" ]; then
		vendored=$((vendored + 1))
	else
		same=$((same + 1))
	fi
done < <(find "$check_dir/x/$PKG" -type f 2>/dev/null | sort)
# Required: tracked src/ and the top-level plugin files; every vendored source except its build metadata.
git -C "$REPO" ls-tree -r --name-only "$commit" >"$check_dir/tracked"
{
	grep -E '^src/' "$check_dir/tracked"
	printf '%s\n' "$PKG.php" uninstall.php readme.txt composer.json LICENSE.txt
	grep -E "^$SPFX" "$check_dir/tracked" | grep -vE '/(UPSTREAM|composer\.lock|package\.json)$' | sed "s|^$SPFX|$VPFX|"
} | sort -u >"$check_dir/required"
missing=()
while IFS= read -r m; do missing+=("$m"); done < <(sort -u "$check_dir/present" | comm -23 "$check_dir/required" -)
rm -rf "$check_dir"
[ "$same" -gt 0 ] || differ+=("(no tracked files matched)")
[ "$vendored" -gt 0 ] || differ+=("(no vendored commerce files matched)")
for m in "${missing[@]}"; do differ+=("$m (missing from zip)"); done
[ "${#differ[@]}" -eq 0 ] || VERDICT=FAILED
name_commit=$(basename "$zip" .zip | sed -n 's/^candidate-//p')
dirty=$(git -C "$REPO" status --porcelain -- src blaze-mcp-toolkit-for-woocommerce.php uninstall.php readme.txt vendor-src | wc -l | tr -d ' ')
{
	echo "# Blaze MCP Toolkit 1.1 — real-plugin matrix"
	echo
	echo "- Result: **MATRIX $VERDICT**"
	echo "- Run (UTC): $(date -u '+%Y-%m-%d %H:%M')"
	echo "- Git commit: \`$commit\`$([ "$dirty" != 0 ] && echo " (WARNING: $dirty uncommitted change(s) under src/ or the plugin files at record time)")"
	echo "- Candidate: \`$(basename "$zip")\` (build commit per its name: \`${name_commit:-unknown}\`)"
	if [ "${#differ[@]}" -eq 0 ]; then
		echo "- Zip vs evidence commit: **byte-identical** — $same tracked shipped files match \`$commit\`; $vendored vendored files under \`$VPFX\` match \`$SPFX\` at that commit; $built composer runtime files (vendor/autoload.php, vendor/composer/*) have no git source"
		echo "- Inventory: no required file missing (tracked src/, $PKG.php, uninstall.php, readme.txt, composer.json, LICENSE.txt, every vendored source file except UPSTREAM/composer.lock/package.json); no other unexpected vendor/ file"
	else
		echo "- Zip vs evidence commit: **DIFFERS / INVENTORY FAILED** in ${#differ[@]} item(s): ${differ[*]:0:20}"
	fi
	echo "- SHA-256: \`$(shasum -a 256 "$zip" | awk '{print $1}')\`"
	echo "- Runner: \`scripts/run-integration.sh --matrix $(basename "$zip")\`"
	echo
	echo "## Fixture window"
	echo
	if [ -n "$window_file" ] && [ -f "$window_file" ]; then
		sed 's/^/- /' "$window_file"
	else
		echo "- (no window recorded)"
	fi
	echo "- Orders at site-local noon: current period −1…−6 (3 a day at 10.00, 25.00, 40.00, processing), previous −8…−13 (2 a day at 10.00, 20.00); 2 failed a day (15.00) on −1…−3; 1 on-hold (7.00) on −2; a 5.00 refund dated −2 noon on one −2 order. Meta \`_blzmcp_fixture=1\`; removed after each state."
	echo "- Asserted (\`wc-analytics/totals\`, date basis date_paid): subject=revenue \`metrics.orders_count\` 18 / \`metrics.net_sales\` 445.00 / \`metrics.refunds\` 5.00; subject=refunds \`metrics.paid_gross_revenue\` 450.00; previous period 12 / 180.00 / 180.00. Cross-checked against WooCommerce › Analytics (\`/wc-analytics/reports/revenue/stats\` \`totals.orders_count\`, \`totals.net_revenue\`) for the same dates."
	echo
	echo "## States"
	echo
	echo "| State | Result | Passed | Failed | WordPress | PHP | Active plugins |"
	echo "|---|---|---|---|---|---|---|"
	grep '^STATE=' "$SUMMARY" | sed 's/^STATE=//' | while IFS='|' read -r state result p f wpv phpv plugins; do
		active=$(jq -r 'map(select(.status == "active" or .status == "active-network") | "\(.name) \(.version)") | join(", ")' <<<"${plugins:-[]}" 2>/dev/null)
		echo "| $state | $result | $p | $f | ${wpv:-?} | ${phpv:-?} | ${active:-?} |"
	done
	echo
	echo "## Coverage notes"
	echo
	echo "- NOT COVERED: Easy MCP AI's auto-tick after an admin visits its Tools screen. On a store seeded before 1.1, Easy MCP AI did not tick the newly annotated wc-analytics tools on its own (its tool policy only records once that screen has been visited). Such stores rely on the setup screen's easy_tools_on row, which tells the owner to tick the analytics tools; the matrix asserts that row warns and names them."
	echo "- Stub-only cases (malformed Tool_State::resolve() results, call counters) run in the fast checks, not here."
	echo "- Large-range recovery and the analytics-unavailable errors through Easy MCP AI are checked on what the client receives (Easy MCP AI passes at most 200 bytes of an error message)."
	echo "- The Enable Abilities denylist pass is not repeated in the matrix recommended state; F02 covers it over HTTP in both (and the fast checks run the denylist in-container)."
	echo
	echo "## Failures, incomplete coverage and notes"
	echo
	echo "Check labels only, from the run's logs (no credentials or customer data are ever printed there)."
	echo
	log_dir=$(dirname "$SUMMARY")
	lines=$(for f in "$log_dir"/*.log; do
		[ -f "$f" ] || continue
		grep -E '^(FAIL|INCOMPLETE COVERAGE|TRANSPORT UNREACHABLE|WINDOW CHANGED|CLEANUP ERROR|NOTE)' "$f" | cut -c1-300 | sed "s|^|$(basename "$f" .log): |"
	done | sort -u)
	if [ -n "$lines" ]; then
		printf '%s\n' "$lines" | sed 's/^/- /'
	else
		echo "- none"
	fi
	echo
	echo "## Plugin lists (wp plugin list --format=json)"
	echo
	grep '^STATE=' "$SUMMARY" | sed 's/^STATE=//' | while IFS='|' read -r state _ _ _ _ _ plugins; do
		echo "- **$state**: \`$(jq -c '.' <<<"${plugins:-[]}" 2>/dev/null)\`"
	done
} >"$OUT"
echo "Evidence: $OUT"
if [ "${#differ[@]}" -ne 0 ]; then
	echo "CANDIDATE DOES NOT MATCH THE EVIDENCE COMMIT: ${#differ[@]} item(s): ${differ[*]:0:20}"
	exit 3
fi
