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

#
# Evidence completeness (exit 5 after writing the record, verdict FAILED): every release state
# (REQUIRED_STATES) has a STATE line that ran; every state except none has a STORAGE line for each
# authority/sync combination and storage suite; the performance receipt (PERF_FILE) exists, is
# bound to this zip's SHA-256 and has 5 complete trials for each storage/window/logger partition,
# each under the 1500 ms budget, every one of them valid (seeded workload observed, all 4 readers
# at their expected counts), a detected forced orders/logs/jobs reader failure per storage, and
# jobs timed on ActionScheduler_DBStore only, and a clean volume-fixture cleanup record (each
# storage's before, diag-file, diag-db and after cleanups and a final one, nothing left behind,
# every seeded order removed, the original order storage restored). The run's ZIP_SHA256 and VERSION must match the zip (exit 3), and
# the zip's header Version, BLZMCP_VERSION and readme Stable tag must agree (exit 3). The record is
# titled and named after the candidate's version, never a fixed one.
#
# Privacy: absolute local paths in copied log lines are replaced with <path>; the finished record
# is then scanned for home directories, host paths, auth headers, secrets and e-mail addresses
# (other than the fixtures' example.invalid ones). Any hit refuses the record (exit 5, nothing
# written).

SUMMARY=${1:?summary file}
VERDICT=${2:?PASSED or FAILED}
REPO=$(cd "$(dirname "$0")/../.." && pwd)
OUT_DIR=${BLZMCP_REPORTS_DIR:-$REPO/../reports}
REQUIRED_STATES=${BLZMCP_REQUIRED_STATES:-recommended easy-2.3.1 easy-2.4.0 both none}
STORAGE_COMBOS="hpos:no hpos:yes legacy:yes legacy:no"
STORAGE_SUITES="test-payment-orders.php test-payment-health.php"
PKG=blaze-mcp-toolkit-for-woocommerce
mkdir -p "$OUT_DIR"

zip=$(sed -n 's/^ZIP=//p' "$SUMMARY")
commit=$(sed -n 's/^COMMIT=//p' "$SUMMARY")
window_file=$(sed -n 's/^WINDOW_FILE=//p' "$SUMMARY")
run_sha=$(sed -n 's/^ZIP_SHA256=//p' "$SUMMARY")
run_version=$(sed -n 's/^VERSION=//p' "$SUMMARY")
perf_file=$(sed -n 's/^PERF_FILE=//p' "$SUMMARY")
zip_sha=$(shasum -a 256 "$zip" 2>/dev/null | awk '{print $1}')
zip_version=$(unzip -p "$zip" "$PKG/$PKG.php" 2>/dev/null | sed -n 's/^ \* Version:[[:space:]]*//p' | head -1 | tr -d '\r')
zip_constant=$(unzip -p "$zip" "$PKG/$PKG.php" 2>/dev/null | sed -n "s/.*define( *'BLZMCP_VERSION', *'\([^']*\)'.*/\1/p" | head -1)
zip_stable=$(unzip -p "$zip" "$PKG/readme.txt" 2>/dev/null | sed -n 's/^Stable tag:[[:space:]]*//p' | head -1 | tr -d '\r')
label=${zip_version:-unknown}
if [ -n "${BLZMCP_MATRIX_OUT:-}" ]; then
	OUT=$BLZMCP_MATRIX_OUT
	[ ! -e "$OUT" ] || { echo "MATRIX RECORD REFUSED: $OUT already exists"; exit 4; }
else
	base="$OUT_DIR/v$label-matrix-$(date -u +%Y-%m-%d)"
	OUT="$base.md"
	n=2
	while [ -e "$OUT" ]; do
		OUT="$base-r$n.md"
		n=$((n + 1))
	done
fi
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
# Candidate identity: the run's hash and version label must be this zip's.
[ -n "$run_sha" ] && [ "$run_sha" == "$zip_sha" ] || differ+=("(candidate SHA-256 differs from the run's: run ${run_sha:-none}, zip ${zip_sha:-unreadable})")
[ -n "$run_version" ] && [ "$run_version" == "$zip_version" ] || differ+=("(run labelled version ${run_version:-none}, candidate header is ${zip_version:-none})")
[ -n "$zip_version" ] && [ "$zip_version" == "$zip_constant" ] && [ "$zip_version" == "$zip_stable" ] || differ+=("(version mismatch in the zip: header ${zip_version:-none}, BLZMCP_VERSION ${zip_constant:-none}, readme Stable tag ${zip_stable:-none})")
[ "${#differ[@]}" -eq 0 ] || VERDICT=FAILED

# Evidence completeness: required states ran, storage combinations ran, performance receipt bound.
incomplete=()
for s in $REQUIRED_STATES; do
	r=$(grep "^STATE=$s|" "$SUMMARY" | head -1 | cut -d'|' -f2)
	case "$r" in
	PASSED | FAILED) ;;
	*) incomplete+=("state $s did not run (${r:-no STATE line})") ;;
	esac
	[ "$s" == none ] && continue
	for c in $STORAGE_COMBOS; do
		for f in $STORAGE_SUITES; do
			sr=$(grep -F "STORAGE=$s|$c|$f|" "$SUMMARY" | head -1 | cut -d'|' -f4)
			case "$sr" in
			PASSED) ;;
			FAILED) VERDICT=FAILED ;;
			*) incomplete+=("storage $s $c $f did not run (${sr:-no STORAGE line})") ;;
			esac
		done
	done
done
perf_ok=0
if [ -z "$perf_file" ] || [ ! -f "$perf_file" ] || ! jq -e '.schema == "blzmcp-payment-performance/1"' "$perf_file" >/dev/null 2>&1; then
	incomplete+=("no performance receipt (PERF_FILE)")
elif [ "$(jq -r '.candidate_sha256 // ""' "$perf_file")" != "$zip_sha" ]; then
	incomplete+=("performance receipt is not bound to this zip (candidate_sha256 $(jq -r '.candidate_sha256 // "none"' "$perf_file"))")
else
	pmissing=$(jq -r '[("legacy", "hpos") as $s | (24, 168) as $w | ("file", "db") as $l | . as $r
		| ($r.partitions | map(select(.storage == $s and .window == $w and .logger == $l and (.trials_ms | length) == 5 and .complete == true)) | length) as $n
		| select($n != 1) | "\($s)/\($w)h/\($l)"] | join(", ")' "$perf_file")
	hmissing=$(jq -r '[("legacy", "hpos") as $s | (24, 168) as $w | ("cold", "warm") as $k | . as $r
		| ((($r.http // []) | map(select(.storage == $s and .window == $w and .kind == $k and .http_code == "200" and .valid_body == true and ((.total_ms | type) == "number")))) | length) as $n
		| select($n < 1) | "\($s)/\($w)h/\($k)"] | join(", ")' "$perf_file")
	# REVIEW-pass4 #10: a timing counts only with the workload observed and every reader valid.
	# Checked on the trials themselves, not on the partition's own complete flag.
	tinvalid=$(jq -r '[("legacy", "hpos") as $s | (24, 168) as $w | ("file", "db") as $l | . as $r
		| (($r.trials // []) | map(select(.storage == $s and .window == $w and .logger == $l))) as $t
		| ($t | map(select(.ok == true and ((.validation.readers // {}) | (keys == ["inventory", "jobs", "logs", "orders"]) and all(.[]; . == true))))) as $v
		| select(($t | length) != 5 or ($v | length) != 5) | "\($s)/\($w)h/\($l) (\($v | length) of \($t | length) trials valid)"] | join(", ")' "$perf_file")
	fmissing=$(jq -r '[("legacy", "hpos") as $s | ({l:"file", f:"logs"}, {l:"db", f:"logs"}, {l:"db", f:"orders"}, {l:"db", f:"jobs"}) as $p | . as $r
		| (($r.forced_failures // []) | map(select(.storage == $s and .logger == $p.l and .force == $p.f))) as $f
		| select(($f | length) != 1 or ($f | all(.ok == false and .detected == true)) != true) | "\($p.f) reader (\($s), \($p.l) logger)"] | join(", ")' "$perf_file")
	# Wave 4 (REVIEW-pass4b gap 2): every fixture cleanup ran and left nothing, every seeded order
	# was removed, and the original order storage was restored. Checked on the cleanup records.
	cmissing=$(jq -r '. as $r | ($r.cleanup // []) as $c
		| def good: .ok == true and (.mode != "orders" or .tagged_remaining == 0) and (if has("expected_removed") then .orders_removed == .expected_removed else true end);
		[ (("legacy", "hpos") as $s | ("before", "diag-file", "diag-db", "after") as $ph
			| ($c | map(select(.storage == $s and .phase == $ph))) as $e
			| select(($e | length) < 1 or ($e | all(good)) != true or ($ph == "after" and ($e | all(has("expected_removed") and .expected_removed > 0)) != true))
			| "\($ph) (\($s))"),
		  (($c | map(select(.phase == "final"))) as $e | select(($e | length) < 1 or ($e | all(good)) != true) | "final"),
		  (select(($c | all(good)) != true) | "a cleanup record that failed"),
		  (select(($r.storage_restored.ok // false) != true) | "order storage restore") ] | unique | join(", ")' "$perf_file")
	if [ -n "$pmissing" ]; then
		incomplete+=("performance partitions incomplete: $pmissing")
	elif [ -n "$tinvalid" ]; then
		incomplete+=("performance trials without the seeded workload and valid readers: $tinvalid")
	elif [ -n "$fmissing" ]; then
		incomplete+=("forced reader failure not shown to fail the gate: $fmissing")
	elif [ -n "$cmissing" ]; then
		incomplete+=("volume fixture cleanup missing or incomplete: $cmissing")
	elif [ "$(jq -c '[.exact_jobs_required, .jobs_store]' "$perf_file")" != '[true,["ActionScheduler_DBStore"]]' ]; then
		incomplete+=("background jobs were not timed on the exact database-store path (jobs_store $(jq -c '.jobs_store' "$perf_file"))")
	elif [ -n "$hmissing" ]; then
		incomplete+=("performance receipt has no HTTP latency figure for: $hmissing")
	elif [ "$(jq -r '.all_pass and ([.partitions[].max_ms] | all(. < 1500))' "$perf_file")" != true ]; then
		incomplete+=("performance budget missed: $(jq -r '[.partitions[] | select(.max_ms >= 1500) | "\(.storage)/\(.window)h/\(.logger) \(.max_ms) ms"] | join(", ")' "$perf_file")")
	else
		perf_ok=1
	fi
fi
[ "${#incomplete[@]}" -eq 0 ] || VERDICT=FAILED
name_commit=$(basename "$zip" .zip | sed -n 's/^candidate-//p')
dirty=$(git -C "$REPO" status --porcelain -- src blaze-mcp-toolkit-for-woocommerce.php uninstall.php readme.txt vendor-src | wc -l | tr -d ' ')
TMP_OUT=$(mktemp "${TMPDIR:-/tmp}/blzmcp-record-out.XXXXXX")
{
	echo "# Blaze MCP Toolkit $label - real-plugin matrix"
	echo
	echo "- Result: **MATRIX $VERDICT**"
	echo "- Candidate version: $label (header, BLZMCP_VERSION and readme Stable tag: ${zip_version:-none} / ${zip_constant:-none} / ${zip_stable:-none})"
	if [ "${#incomplete[@]}" -eq 0 ]; then
		echo "- Evidence: complete (states $REQUIRED_STATES; payment storage loop in every combination; performance receipt bound to this zip)"
	else
		echo "- Evidence: **INCOMPLETE** in ${#incomplete[@]} item(s): ${incomplete[*]:0:20}"
	fi
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
	echo "## Payment health order storage"
	echo
	echo "| State | Authority:sync | Suite | Result | Passed | Failed |"
	echo "|---|---|---|---|---|---|"
	grep '^STORAGE=' "$SUMMARY" | sed 's/^STORAGE=//' | while IFS='|' read -r state combo suite result p f; do
		echo "| $state | $combo | $suite | $result | $p | $f |"
	done
	echo
	echo "## Payment health cold performance"
	echo
	if [ -n "$perf_file" ] && [ -f "$perf_file" ]; then
		echo "- Receipt SHA-256: \`$(shasum -a 256 "$perf_file" | awk '{print $1}')\`; budget $(jq -r '.budget_ms' "$perf_file") ms per partition (maximum of the trials); $(jq -r '.meaning' "$perf_file")"
		echo "- Seed: $(jq -r '"\(.seed.recent_orders) recent checkout orders over \(.seed.horizon_days) days + \(.seed.older_orders) older, methods \(.seed.methods | join(", "))"' "$perf_file")"
		echo
		echo "| Storage | Window | Logger | Max ms | Trials (ms) | Warm max ms | Pass |"
		echo "|---|---|---|---|---|---|---|"
		jq -r '.partitions[] | "| \(.storage) | \(.window)h | \(.logger) | \(.max_ms) | \(.trials_ms | map(tostring) | join(", ")) | \(.warm_ms_max) | \(.pass) |"' "$perf_file"
		echo
		echo "- HTTP latency (abilities REST route, ms): $(jq -r '(.http // []) | map("\(.storage)/\(.window)h/\(.kind) \(.total_ms)") | join(", ") | if . == "" then "none" else . end' "$perf_file")"
		echo "- Background jobs timed on: $(jq -r '.jobs_path // "not recorded"' "$perf_file")"
		echo "- Trial validity: $(jq -r '.validity // "not recorded"' "$perf_file")"
		echo "- Volume fixture cleanup: $(jq -r '((.cleanup // []) | map(select(.phase == "after")) | map("\(.storage) removed \(.orders_removed)/\(.expected_removed) orders, \(.tagged_remaining) tagged left") | join("; ") | if . == "" then "not recorded" else . end)
			+ "; diagnostics cleanups \((.cleanup // []) | map(select(.mode == "diagnostics")) | length), all clean \((.cleanup // []) | map(select(.mode == "diagnostics")) | all(.ok == true))"
			+ "; order storage restored to \(.storage_restored.storage // "?") sync=\(.storage_restored.sync // "?"): \(.storage_restored.ok // false)"' "$perf_file")"
		echo "- Forced reader failures (each must fail the trial): $(jq -r '(.forced_failures // []) | map("\(.force)/\(.storage)/\(.logger) \(if .ok == false and .detected == true then "failed as required" else "NOT DETECTED" end)") | join(", ") | if . == "" then "none" else . end' "$perf_file")"
	else
		echo "- (no performance receipt)"
	fi
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
	done | sed -E 's#(/(Users|home|private|var|tmp|opt|root)/[^ )"'"'"']*)#<path>#g' | sort -u)
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
} >"$TMP_OUT"
# Privacy scan of the finished record: nothing local, no auth material, no real e-mail address.
leaks=$(grep -nE -e "${HOME:-/nonexistent-home}" -e '/(Users|home)/[A-Za-z0-9._-]+' -e 'Authorization:|Bearer [A-Za-z0-9]|SECRET=|password=|app_password|sk_(live|test)_|whsec_' "$TMP_OUT" | cut -c1-80)
emails=$(grep -oE '[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}' "$TMP_OUT" | grep -vE '@example\.invalid$' | head -3)
if [ -n "$leaks" ] || [ -n "$emails" ]; then
	rm -f "$TMP_OUT"
	echo "MATRIX RECORD REFUSED: the record would contain local or private data (lines: $(printf '%s' "$leaks" | cut -d: -f1 | paste -sd, -)${emails:+; e-mail addresses})"
	exit 5
fi
mv "$TMP_OUT" "$OUT"
echo "Evidence: $OUT"
if [ "${#differ[@]}" -ne 0 ]; then
	echo "CANDIDATE DOES NOT MATCH THE EVIDENCE COMMIT: ${#differ[@]} item(s): ${differ[*]:0:20}"
	exit 3
fi
if [ "${#incomplete[@]}" -ne 0 ]; then
	echo "EVIDENCE INCOMPLETE: ${#incomplete[@]} item(s): ${incomplete[*]:0:20}"
	exit 5
fi
