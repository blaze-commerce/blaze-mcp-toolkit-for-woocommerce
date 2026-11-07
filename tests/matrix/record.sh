#!/usr/bin/env bash
# Writes the matrix evidence record: ../reports/v1.1-matrix-<UTC date>.md (outside the repo).
#
#   bash tests/matrix/record.sh <summary.txt from run-matrix.sh> <PASSED|FAILED>
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
OUT="$OUT_DIR/v1.1-matrix-$(date -u +%Y-%m-%d).md"

zip=$(sed -n 's/^ZIP=//p' "$SUMMARY")
commit=$(sed -n 's/^COMMIT=//p' "$SUMMARY")
window_file=$(sed -n 's/^WINDOW_FILE=//p' "$SUMMARY")
dirty=$(git -C "$REPO" status --porcelain -- src blaze-mcp-toolkit-for-woocommerce.php uninstall.php readme.txt vendor-src | wc -l | tr -d ' ')
{
	echo "# Blaze MCP Toolkit 1.1 — real-plugin matrix"
	echo
	echo "- Result: **MATRIX $VERDICT**"
	echo "- Run (UTC): $(date -u '+%Y-%m-%d %H:%M')"
	echo "- Git commit: \`$commit\`$([ "$dirty" != 0 ] && echo " (WARNING: $dirty uncommitted change(s) under src/ or the plugin files at record time)")"
	echo "- Candidate: \`$(basename "$zip")\`"
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
	echo "- Large-range recovery through Easy MCP AI is checked on what the client receives (Easy MCP AI passes at most 200 bytes of an error message)."
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
