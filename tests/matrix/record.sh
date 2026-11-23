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
# within its budget rule (ruling Q6, REVIEW-pass4c; decided here on the raw trials, never on the
# receipt's own pass flags, maxima or rule labels: legacy/24h/db and legacy/168h/db need all 5
# trials valid, at least 4 strictly below 1500 ms and none above 2000 ms; every other partition
# needs all 5 valid and strictly below 1500 ms; no median, no pooling), the receipt's own verdict
# all_pass true, every one of them valid (seeded workload observed, all 4 readers
# at their expected counts), a detected forced orders/logs/jobs reader failure per storage, and
# jobs timed on ActionScheduler_DBStore only, and a clean volume-fixture cleanup record (each
# storage's before, diag-file, diag-db and after cleanups and a final one, nothing left behind,
# every seeded order removed, the original order storage restored). The run's ZIP_SHA256 and VERSION must match the zip (exit 3), and
# the zip's header Version, BLZMCP_VERSION and readme Stable tag must agree (exit 3). The record is
# titled and named after the candidate's version, never a fixed one.
#
# 1.2.1 (FEATURE-PLAN item 5), also exit 5 / FAILED: every STATE and STORAGE line appears exactly
# once (a duplicate is incomplete evidence, never a second pass); the HTTP suite's TRANSPORT rows
# cover exactly the required partitions (per state and route: discovery, workflows, analytics off,
# tool off and manager roles; per state, route and authority hpos/legacy: the administrator
# disclosure, administrator and manager diagnostics off/on/off, customer, subscriber and anonymous
# denial and the manager's warm downgrade; none: no tool on either route), each exactly once and
# PASSED (a missing, duplicated or unknown row is incomplete; a FAILED row fails the verdict); the
# performance receipt is schema 2 with 50,000 recent and 10,000 older checkout orders plus at least
# 1,000 separately counted excluded-origin orders across 10 methods, verified per storage, every
# valid trial carries the outside reader, query count, rows examined and selected, peak memory, warm
# timing and a query plan, and the 1,000 and 1,001 distinct-ID cap cases passed per storage. The
# transport receipt (BLZMCP_TRANSPORT_OUT, default <record>-transport.md) lists every row.
#
# Wave 3 (REVIEW-pass4a R4, ruling Q3), also exit 5 / FAILED: the release matrix is 6 states
# (Easy MCP AI 2.5.0 added); the required set must come to exactly 40 storage rows (5 connected
# states x 4 authority/sync combinations x 2 suites) and 164 transport rows (27 per connected route
# x 6 routes + 2 for none), so narrowing BLZMCP_REQUIRED_STATES can never shrink the gate into a
# pass; and the HTTP suite's offline fail-closed self-test (tests/matrix/test-http-checks.sh) must
# have run in this matrix with 0 failures (HTTP_CHECKS line).
#
# Privacy: absolute local paths in copied log lines are replaced with <path>; the finished record
# is then scanned for home directories, host paths, auth headers, secrets and e-mail addresses
# (other than the fixtures' example.invalid ones). Any hit refuses the record (exit 5, nothing
# written).

SUMMARY=${1:?summary file}
VERDICT=${2:?PASSED or FAILED}
REPO=$(cd "$(dirname "$0")/../.." && pwd)
OUT_DIR=${BLZMCP_REPORTS_DIR:-$REPO/../reports}
REQUIRED_STATES=${BLZMCP_REQUIRED_STATES:-recommended easy-2.3.1 easy-2.4.0 easy-2.5.0 both none}
# The release gate's partition counts (wave 3, ruling Q3). Not overridable.
GATE_STORAGE_ROWS=40
GATE_TRANSPORT_ROWS=164
STORAGE_COMBOS="hpos:no hpos:yes legacy:yes legacy:no"
STORAGE_SUITES="test-payment-orders.php test-payment-health.php"
# Ruling Q6 (REVIEW-pass4c): the recorder's own budget decision, from each partition's raw trials
# only (never the receipt's pass, max_ms, rule or trials_ms fields). A trial is valid when it ran
# ok with all 5 readers true and a numeric elapsed_ms. legacy/24h/db and legacy/168h/db: exactly 5
# trials, all valid, at least 4 strictly below 1500 ms, none above 2000 ms. Every other partition:
# exactly 5 trials, all valid and strictly below 1500 ms. No median, no pooling. Not overridable.
Q6_JQ='def q6_rule($s; $l): if $s == "legacy" and $l == "db" then "4of5<1500,max<=2000" else "5of5<1500" end;
	def q6_valid: .ok == true and ((.validation.readers // {}) | (keys == ["inventory", "jobs", "logs", "orders", "outside"]) and all(.[]; . == true)) and ((.elapsed_ms | type) == "number");
	def q6_decide($r; $s; $w; $l):
		(($r.trials // []) | map(select(.storage == $s and .window == $w and .logger == $l))) as $t
		| ($t | map(select(q6_valid))) as $v
		| ($v | map(select(.elapsed_ms < 1500)) | length) as $under
		| ($v | map(.elapsed_ms) | max) as $vmax
		| q6_rule($s; $l) as $rule
		| {storage:$s, window:$w, logger:$l, rule:$rule, trials:($t | length), valid:($v | length), under:$under,
		   max_ms:($t | map(.elapsed_ms | numbers) | max), trials_ms:($t | map(.elapsed_ms)),
		   pass:(($t | length) == 5 and ($v | length) == 5
			and (if $rule == "4of5<1500,max<=2000" then $under >= 4 and $vmax <= 2000 else $under == 5 end))};
	def q6_all: . as $r | [("legacy", "hpos") as $s | (24, 168) as $w | ("file", "db") as $l | q6_decide($r; $s; $w; $l)];'
PKG=blaze-mcp-toolkit-for-woocommerce
mkdir -p "$OUT_DIR"

zip=$(sed -n 's/^ZIP=//p' "$SUMMARY")
commit=$(sed -n 's/^COMMIT=//p' "$SUMMARY")
window_file=$(sed -n 's/^WINDOW_FILE=//p' "$SUMMARY")
run_sha=$(sed -n 's/^ZIP_SHA256=//p' "$SUMMARY")
run_version=$(sed -n 's/^VERSION=//p' "$SUMMARY")
perf_file=$(sed -n 's/^PERF_FILE=//p' "$SUMMARY")
http_checks=$(sed -n 's/^HTTP_CHECKS=//p' "$SUMMARY" | head -1)
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
if [ -n "${BLZMCP_TRANSPORT_OUT:-}" ]; then
	TRANSPORT_OUT=$BLZMCP_TRANSPORT_OUT
else
	TRANSPORT_OUT="${OUT%.md}-transport.md"
fi
[ ! -e "$TRANSPORT_OUT" ] || { echo "MATRIX RECORD REFUSED: $TRANSPORT_OUT already exists"; exit 4; }
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
# ${arr[@]+...}: an empty array is "unbound" under set -u before bash 4.4 (macOS /bin/bash is 3.2).
for m in ${missing[@]+"${missing[@]}"}; do differ+=("$m (missing from zip)"); done
# Candidate identity: the run's hash and version label must be this zip's.
[ -n "$run_sha" ] && [ "$run_sha" == "$zip_sha" ] || differ+=("(candidate SHA-256 differs from the run's: run ${run_sha:-none}, zip ${zip_sha:-unreadable})")
[ -n "$run_version" ] && [ "$run_version" == "$zip_version" ] || differ+=("(run labelled version ${run_version:-none}, candidate header is ${zip_version:-none})")
[ -n "$zip_version" ] && [ "$zip_version" == "$zip_constant" ] && [ "$zip_version" == "$zip_stable" ] || differ+=("(version mismatch in the zip: header ${zip_version:-none}, BLZMCP_VERSION ${zip_constant:-none}, readme Stable tag ${zip_stable:-none})")
[ "${#differ[@]}" -eq 0 ] || VERDICT=FAILED

# Evidence completeness: required states ran, storage combinations ran, performance receipt bound.
incomplete=()
# Each STATE and STORAGE partition exactly once.
dup_lines=$(
	{
		grep '^STATE=' "$SUMMARY" | cut -d'|' -f1
		grep '^STORAGE=' "$SUMMARY" | cut -d'|' -f1-3
	} | sort | uniq -d | sed 's/^/duplicated /' | paste -sd, - | cut -c1-300
)
[ -z "$dup_lines" ] || incomplete+=("summary partitions duplicated: $dup_lines")
# Transport receipt rows: route x authority x principal x case, each required one exactly once.
TRANSPORT_ANY="admin|discovery admin|workflows admin|analytics_off admin|tool_off manager|roles"
TRANSPORT_AUTH="admin|disclosure admin|diag_off admin|diag_on admin|diag_off_again manager|diag_off manager|diag_on manager|diag_off_again customer|denied subscriber|denied anonymous|denied manager|warm_downgrade"
TRANSPORT_AUTHORITIES="hpos legacy"
routes_for() {
	case "$1" in
	recommended) echo rec ;;
	easy-*) echo easy ;;
	both | none) echo "rec easy" ;;
	esac
}
tdir=$(mktemp -d "${TMPDIR:-/tmp}/blzmcp-record-t.XXXXXX")
for s in $REQUIRED_STATES; do
	for r in $(routes_for "$s"); do
		if [ "$s" == none ]; then
			echo "$s|$r|any|admin|no_tool"
			continue
		fi
		for c in $TRANSPORT_ANY; do echo "$s|$r|any|$c"; done
		for a in $TRANSPORT_AUTHORITIES; do
			for c in $TRANSPORT_AUTH; do echo "$s|$r|$a|$c"; done
		done
	done
done | sort >"$tdir/required"
grep '^TRANSPORT=' "$SUMMARY" | sed 's/^TRANSPORT=//' >"$tdir/rows"
cut -d'|' -f1-5 "$tdir/rows" | sort >"$tdir/keys"
t_missing=$(sort -u "$tdir/keys" | comm -23 "$tdir/required" - | wc -l | tr -d ' ')
t_missing_list=$(sort -u "$tdir/keys" | comm -23 "$tdir/required" - | head -8 | paste -sd, -)
t_unknown=$(sort -u "$tdir/keys" | comm -13 "$tdir/required" - | head -8 | paste -sd, -)
t_dup=$(uniq -d "$tdir/keys" | head -8 | paste -sd, -)
t_failed=$(awk -F'|' '$6 != "PASSED"' "$tdir/rows" | cut -d'|' -f1-5 | head -8 | paste -sd, -)
t_required=$(wc -l <"$tdir/required" | tr -d ' ')
t_rows=$(wc -l <"$tdir/rows" | tr -d ' ')
t_passed=$(awk -F'|' '$6 == "PASSED"' "$tdir/rows" | wc -l | tr -d ' ')
rm -rf "$tdir"
[ "$t_missing" == 0 ] || incomplete+=("transport rows missing: $t_missing of $t_required (first: $t_missing_list)")
[ -z "$t_dup" ] || incomplete+=("transport rows duplicated: $t_dup")
[ -z "$t_unknown" ] || incomplete+=("transport rows not in the required set: $t_unknown")
[ -z "$t_failed" ] && [ -n "$(grep -m1 '^TRANSPORT=' "$SUMMARY")" ] || VERDICT=FAILED
[ "$t_required" == "$GATE_TRANSPORT_ROWS" ] || incomplete+=("required transport partitions come to $t_required, the release matrix needs $GATE_TRANSPORT_ROWS (states: $REQUIRED_STATES)")
# Storage rows: every connected state x combination x suite, and that set must be the gate's 40.
s_required=0
s_rows=0
for s in $REQUIRED_STATES; do
	[ "$s" == none ] && continue
	for c in $STORAGE_COMBOS; do
		for f in $STORAGE_SUITES; do
			s_required=$((s_required + 1))
			case "$(grep -F "STORAGE=$s|$c|$f|" "$SUMMARY" | head -1 | cut -d'|' -f4)" in PASSED | FAILED) s_rows=$((s_rows + 1)) ;; esac
		done
	done
done
[ "$s_required" == "$GATE_STORAGE_ROWS" ] || incomplete+=("required storage partitions come to $s_required, the release matrix needs $GATE_STORAGE_ROWS (states: $REQUIRED_STATES)")
# The HTTP suite's fail-closed safety checks proved themselves offline in this run.
if [ -z "$http_checks" ]; then
	incomplete+=("the HTTP safety-check self-test (test-http-checks.sh) did not run (no HTTP_CHECKS line)")
elif ! [[ "$http_checks" =~ ^[1-9][0-9]*\ passed,\ 0\ failed$ ]]; then
	incomplete+=("the HTTP safety-check self-test (test-http-checks.sh) did not pass: $http_checks")
fi
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
if [ -z "$perf_file" ] || [ ! -f "$perf_file" ] || ! jq -e '.schema == "blzmcp-payment-performance/2"' "$perf_file" >/dev/null 2>&1; then
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
		| ($t | map(select(.ok == true and ((.validation.readers // {}) | (keys == ["inventory", "jobs", "logs", "orders", "outside"]) and all(.[]; . == true))))) as $v
		| select(($t | length) != 5 or ($v | length) != 5) | "\($s)/\($w)h/\($l) (\($v | length) of \($t | length) trials valid)"] | join(", ")' "$perf_file")
	# Ruling Q6: the budget, decided on the raw trials (Q6_JQ), not the receipt's own pass or max_ms.
	pbudget=$(jq -r "$Q6_JQ"' [q6_all[] | select(.pass != true)
		| "\(.storage)/\(.window)h/\(.logger) (rule \(.rule): \(.under) of \(.trials) trials valid and under 1500 ms, \(.valid) valid, max \(.max_ms // "none") ms)"] | join(", ")' "$perf_file")
	fmissing=$(jq -r '[("legacy", "hpos") as $s | ({l:"file", f:"logs"}, {l:"db", f:"logs"}, {l:"db", f:"orders"}, {l:"db", f:"jobs"}) as $p | . as $r
		| (($r.forced_failures // []) | map(select(.storage == $s and .logger == $p.l and .force == $p.f))) as $f
		| select(($f | length) != 1 or ($f | all(.ok == false and .detected == true)) != true) | "\($p.f) reader (\($s), \($p.l) logger)"] | join(", ")' "$perf_file")
	# Wave 4 (REVIEW-pass4b gap 2): every fixture cleanup ran and left nothing, every seeded order
	# was removed, and the original order storage was restored. Checked on the cleanup records.
	cmissing=$(jq -r '. as $r | ($r.cleanup // []) as $c
		| def good: .ok == true and (.mode != "orders" or .tagged_remaining == 0) and (if has("expected_removed") then .orders_removed == .expected_removed else true end);
		[ (("legacy", "hpos") as $s | ("before", "diag-file", "diag-db", "after", "cap") as $ph
			| ($c | map(select(.storage == $s and .phase == $ph))) as $e
			| select(($e | length) < 1 or ($e | all(good)) != true or (($ph == "after" or $ph == "cap") and ($e | all(has("expected_removed") and .expected_removed > 0)) != true))
			| "\($ph) (\($s))"),
		  (($c | map(select(.phase == "final"))) as $e | select(($e | length) < 1 or ($e | all(good)) != true) | "final"),
		  (select(($c | all(good)) != true) | "a cleanup record that failed"),
		  (select(($r.storage_restored.ok // false) != true) | "order storage restore") ] | unique | join(", ")' "$perf_file")
	# 1.2.1 item 5: the volume (checkout kept, excluded-origin counted apart), per-trial metrics and caps.
	smissing=$(jq -r '. as $r | [
		(select(($r.seed.recent_orders // 0) < 50000) | "recent checkout orders \($r.seed.recent_orders)"),
		(select(($r.seed.older_orders // 0) < 10000) | "older orders \($r.seed.older_orders)"),
		(select(($r.seed.outside_orders // 0) < 1000) | "excluded-origin orders \($r.seed.outside_orders)"),
		(select((($r.seed.outside_methods // []) | unique | length) < 10) | "excluded-origin methods \(($r.seed.outside_methods // []) | length)"),
		(("legacy", "hpos") as $s | (($r.seed.storages // []) | map(select(.storage == $s))) as $v
			| select(($v | length) != 1 or ($v[0].verify.ok // false) != true or $v[0].verify.outside != $r.seed.outside_orders or $v[0].verify.checkout != ($r.seed.recent_orders + $r.seed.older_orders))
			| "verified checkout/excluded counts (\($s))")
		] | join(", ")' "$perf_file")
	mmissing=$(jq -r '[(.trials // [])[] | select(.ok == true)
		| select(((.queries | type) != "number") or ((.rows_examined | type) != "number") or ((.rows_selected | type) != "number")
			or ((.peak_memory_delta_bytes | type) != "number") or ((.warm_ms | type) != "number")
			or (((.slowest // []) | map(select((.plan | type) == "array" and (.plan | length) > 0)) | length) < 1))
		| "\(.storage)/\(.window)h/\(.logger)#\(.trial)"] | unique | .[0:8] | join(", ")' "$perf_file")
	kmissing=$(jq -r '. as $r | [("legacy", "hpos") as $s | (1000, 1001) as $k
		| (($r.cap_cases // []) | map(select(.storage == $s and .distinct_ids == $k))) as $c
		| select(($c | length) != 1 or ($c[0].ok // false) != true) | "\($k) distinct IDs (\($s))"] | join(", ")' "$perf_file")
	if [ -n "$smissing" ]; then
		incomplete+=("performance volume incomplete: $smissing")
	elif [ -n "$pmissing" ]; then
		incomplete+=("performance partitions incomplete: $pmissing")
	elif [ -n "$tinvalid" ]; then
		incomplete+=("performance trials without the seeded workload and valid readers: $tinvalid")
	elif [ -n "$fmissing" ]; then
		incomplete+=("forced reader failure not shown to fail the gate: $fmissing")
	elif [ -n "$cmissing" ]; then
		incomplete+=("volume fixture cleanup missing or incomplete: $cmissing")
	elif [ "$(jq -c '[.exact_jobs_required, .jobs_store]' "$perf_file")" != '[true,["ActionScheduler_DBStore"]]' ]; then
		incomplete+=("background jobs were not timed on the exact database-store path (jobs_store $(jq -c '.jobs_store' "$perf_file"))")
	elif [ -n "$mmissing" ]; then
		incomplete+=("performance trials without query count, rows examined/selected, peak memory, warm timing or a query plan: $mmissing")
	elif [ -n "$kmissing" ]; then
		incomplete+=("method-cap stress case missing or failed: $kmissing")
	elif [ -n "$hmissing" ]; then
		incomplete+=("performance receipt has no HTTP latency figure for: $hmissing")
	elif [ -n "$pbudget" ]; then
		incomplete+=("performance budget missed: $pbudget")
	elif [ "$(jq -r '.all_pass == true' "$perf_file")" != true ]; then
		incomplete+=("performance receipt verdict is not all_pass (perf-suite.sh failed)")
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
	echo "- Storage rows: required $s_required (gate $GATE_STORAGE_ROWS), received $s_rows"
	echo
	echo "| State | Authority:sync | Suite | Result | Passed | Failed |"
	echo "|---|---|---|---|---|---|"
	grep '^STORAGE=' "$SUMMARY" | sed 's/^STORAGE=//' | while IFS='|' read -r state combo suite result p f; do
		echo "| $state | $combo | $suite | $result | $p | $f |"
	done
	echo
	echo "## Transport receipt rows (route x order authority x principal x case)"
	echo
	echo "- HTTP safety-check self-test (tests/matrix/test-http-checks.sh, offline injected replies): ${http_checks:-not run}"
	echo "- Required $t_required (gate $GATE_TRANSPORT_ROWS), received $t_rows, passed $t_passed; missing $t_missing${t_missing_list:+ (first: $t_missing_list)}; duplicated ${t_dup:-none}; not in the required set ${t_unknown:-none}; not PASSED ${t_failed:-none}"
	echo "- Every row is the HTTP suite's own pass/fail for that case on the real transport (no CLI parity); the full list is in \`$(basename "$TRANSPORT_OUT")\`"
	echo
	echo "| State | Route | Authority | Rows | Passed |"
	echo "|---|---|---|---|---|"
	grep '^TRANSPORT=' "$SUMMARY" | sed 's/^TRANSPORT=//' | awk -F'|' '{k = $1 "|" $2 "|" $3; n[k]++; if ($6 == "PASSED") p[k]++} END {for (k in n) print k "|" n[k] "|" (p[k] + 0)}' | sort | while IFS='|' read -r state route auth n p; do
		echo "| $state | $route | $auth | $n | $p |"
	done
	echo
	echo "## Payment health cold performance"
	echo
	if [ -n "$perf_file" ] && [ -f "$perf_file" ]; then
		echo "- Receipt SHA-256: \`$(shasum -a 256 "$perf_file" | awk '{print $1}')\`; budget $(jq -r '.budget_ms' "$perf_file") ms per trial; $(jq -r '.meaning' "$perf_file")"
		echo "- Budget rule (ruling Q6, REVIEW-pass4c), decided by this recorder on each partition's own raw trials (no median, no pooling, the receipt's pass flags not trusted): legacy/24h/db and legacy/168h/db \`4of5<1500,max<=2000\` (all 5 trials valid, at least 4 strictly below 1500 ms, none above 2000 ms); every other partition \`5of5<1500\` (all 5 valid and strictly below 1500 ms). Receipt verdict all_pass: $(jq -r '.all_pass' "$perf_file")"
		echo "- Seed: $(jq -r '"\(.seed.recent_orders) recent checkout orders over \(.seed.horizon_days) days + \(.seed.older_orders) older, methods \(.seed.methods | join(", ")); plus \(.seed.outside_orders // 0) excluded-origin orders counted separately across \((.seed.outside_methods // []) | length) methods (\((.seed.outside_methods // []) | join(", ")))"' "$perf_file")"
		echo "- Verified per storage: $(jq -r '(.seed.storages // []) | map("\(.storage) checkout \(.verify.checkout // "?") + excluded-origin \(.verify.outside // "?"), ok \(.verify.ok // false)") | join("; ")' "$perf_file")"
		echo
		echo "| Storage | Window | Logger | Max ms | Trials (ms) | Valid, under 1500 ms | Warm max ms | Queries | Rows examined max | Rows selected max | Peak memory max (bytes) | Rule | Pass (recorder) | Pass (receipt) |"
		echo "|---|---|---|---|---|---|---|---|---|---|---|---|---|---|"
		jq -r "$Q6_JQ"' . as $r | q6_all[] as $d
			| ((($r.partitions // []) | map(select(.storage == $d.storage and .window == $d.window and .logger == $d.logger)))[0] // {}) as $p
			| "| \($d.storage) | \($d.window)h | \($d.logger) | \($d.max_ms) | \($d.trials_ms | map(tostring) | join(", ")) | \($d.valid) of \($d.trials), \($d.under) under | \($p.warm_ms_max) | \(($p.queries // []) | map(tostring) | join("/")) | \($p.rows_examined_max) | \($p.rows_selected_max) | \($p.peak_memory_delta_bytes_max) | \($d.rule) | \($d.pass) | \($p.pass) |"' "$perf_file"
		echo
		echo "- Method-cap stress cases (own assertions, not performance trials): $(jq -r '(.cap_cases // []) | map("\(.storage) \(.distinct_ids) IDs: listed \(.listed), methods_truncated \(.methods_truncated), inventory \(.inventory_coverage), \(if .ok then "passed" else "FAILED" end) (\(.elapsed_ms) ms)") | join("; ") | if . == "" then "none" else . end' "$perf_file")"
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
# The transport receipt: every row, with the candidate identity.
TMP_TRANS=$(mktemp "${TMPDIR:-/tmp}/blzmcp-record-trans.XXXXXX")
{
	t_ok=PASSED
	[ "$t_missing" == 0 ] && [ -z "$t_dup" ] && [ -z "$t_unknown" ] && [ -z "$t_failed" ] && [ "$t_rows" -gt 0 ] && [ "$t_required" == "$GATE_TRANSPORT_ROWS" ] && [[ "$http_checks" =~ ^[1-9][0-9]*\ passed,\ 0\ failed$ ]] || t_ok=FAILED
	echo "# Blaze MCP Toolkit $label - origin disclosure transport receipt"
	echo
	echo "- Result: **TRANSPORT $t_ok** (passed only when every required row is present exactly once and PASSED)"
	echo "- Candidate: \`$(basename "$zip")\`, SHA-256 \`${zip_sha:-unreadable}\`, version ${zip_version:-none}"
	echo "- Git commit: \`$commit\`"
	echo "- Matrix record: \`$(basename "$OUT")\`"
	echo "- HTTP safety-check self-test (tests/matrix/test-http-checks.sh): ${http_checks:-not run}"
	echo "- Rows: required $t_required (gate $GATE_TRANSPORT_ROWS), received $t_rows, passed $t_passed; missing $t_missing${t_missing_list:+ (first: $t_missing_list)}; duplicated ${t_dup:-none}; not in the required set ${t_unknown:-none}; not PASSED ${t_failed:-none}"
	echo "- A row is one case of tests/matrix/http-suite.sh over the state's real transport (rec = MCP Adapter with Enable Abilities, easy = Easy MCP AI), under the order authority it names (switched in its own process and read back before the case; any = the case does not depend on the order store), for that principal. PASSED means every check in the case passed. disclosure: the administrator sees schema 2, the mixed, registered-disabled and unregistered inventory-only methods kept apart from checkout counts, and a warm repeat from the cache. diag_off / diag_on / diag_off_again: the shop-manager diagnostics setting off, on, then off after a warm result, for the administrator and the manager (the manager keeps the outside-checkout facts with diagnostics off). denied: a customer, a subscriber or an anonymous caller gets the route's known authentication refusal (HTTP 401/403) or permission refusal and no method facts, with a privileged result warm; no reply, 5xx, a malformed reply, an unknown error and a missing credential fail the row. diag_off / diag_off_again for the manager: every applicable log and job check is not checked, unavailable, with the role reason, null counts, no groups or hooks and no diagnostic finding; diag_on and the administrator: the fixture's failed Stripe payment job is reported with its finding. warm_downgrade: a manager's warm privileged result gets the known permission refusal after the role loses manage_woocommerce. tool_off: the known not-found (rec) or switched-off (easy) answer. no_tool: in none, an absent route answers HTTP 404 rest_no_route, and a route that answers neither lists nor runs payment health."
	echo
	echo "| State | Route | Authority | Principal | Case | Result |"
	echo "|---|---|---|---|---|---|"
	grep '^TRANSPORT=' "$SUMMARY" | sed 's/^TRANSPORT=//' | while IFS='|' read -r state route auth who case result; do
		echo "| $state | $route | $auth | $who | $case | $result |"
	done
} >"$TMP_TRANS"
# Privacy scan of the finished record: nothing local, no auth material, no real e-mail address.
leaks=$(grep -nE -e "${HOME:-/nonexistent-home}" -e '/(Users|home)/[A-Za-z0-9._-]+' -e 'Authorization:|Bearer [A-Za-z0-9]|SECRET=|password=|app_password|sk_(live|test)_|whsec_' "$TMP_OUT" "$TMP_TRANS" | cut -c1-80)
emails=$(grep -ohE '[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}' "$TMP_OUT" "$TMP_TRANS" | grep -vE '@example\.invalid$' | head -3)
if [ -n "$leaks" ] || [ -n "$emails" ]; then
	rm -f "$TMP_OUT" "$TMP_TRANS"
	echo "MATRIX RECORD REFUSED: the record would contain local or private data (lines: $(printf '%s' "$leaks" | cut -d: -f1 | paste -sd, -)${emails:+; e-mail addresses})"
	exit 5
fi
mv "$TMP_OUT" "$OUT"
mv "$TMP_TRANS" "$TRANSPORT_OUT"
echo "Evidence: $OUT"
echo "Transport receipt: $TRANSPORT_OUT"
if [ "${#differ[@]}" -ne 0 ]; then
	echo "CANDIDATE DOES NOT MATCH THE EVIDENCE COMMIT: ${#differ[@]} item(s): ${differ[*]:0:20}"
	exit 3
fi
if [ "${#incomplete[@]}" -ne 0 ]; then
	echo "EVIDENCE INCOMPLETE: ${#incomplete[@]} item(s): ${incomplete[*]:0:20}"
	exit 5
fi
