#!/usr/bin/env bash
# Real-plugin matrix: the 1.1 release gate. Called by `scripts/run-integration.sh --matrix ZIP`.
#
#   bash tests/matrix/run-matrix.sh dist/candidate-<commit>.zip
#
# For each state (tests/matrix/<state>.wp-env.json) it builds a work directory under
# $BLZMCP_MATRIX_DIR (default ~/.blzmcp-matrix/<state>; it must be somewhere Docker can mount,
# which on macOS rules out $TMPDIR) holding the config, the candidate zip unpacked as the plugin
# (not the checkout) and a copy of tests/. Then: wp-env start, pretty permalinks and the site
# timezone, preflight (initialize on each transport), the fixture, the state's suite list, the
# HTTP suite, fixture cleanup, wp-env stop. Prints MATRIX PASSED or MATRIX FAILED and writes the
# evidence record (tests/matrix/record.sh).
#
# Every state runs inside one site-local reporting date (tests/matrix/.window). If a state seeds
# on a different date than the first one, the whole matrix is reseeded once from the start.
#
# Env: BLZMCP_MATRIX_STATES  space-separated subset (default: all five, in order)
#      BLZMCP_MATRIX_KEEP=1  leave the environments running afterwards (default: wp-env stop)
#      BLZMCP_MATRIX_TZ      site timezone for every state (default Australia/Melbourne)
set -uo pipefail

ZIP=${1:?usage: run-matrix.sh CANDIDATE.zip}
REPO=$(cd "$(dirname "$0")/../.." && pwd)
case "$ZIP" in /*) ;; *) ZIP="$REPO/$ZIP" ;; esac
[ -f "$ZIP" ] || { echo "MATRIX FAILED: no candidate zip at $ZIP"; exit 1; }
WPE="$REPO/node_modules/.bin/wp-env"
[ -x "$WPE" ] || { echo "MATRIX FAILED: $WPE missing (npm install)"; exit 1; }
MATRIX_DIR=${BLZMCP_MATRIX_DIR:-$HOME/.blzmcp-matrix}
STATES=${BLZMCP_MATRIX_STATES:-recommended easy-2.3.1 easy-2.4.0 both none}
TZ_NAME=${BLZMCP_MATRIX_TZ:-Australia/Melbourne}
SLUG=blaze-mcp-toolkit-for-woocommerce
T=wp-content/blzmcp-tests
LOG_DIR=$(mktemp -d "${TMPDIR:-/tmp}/blzmcp-matrix-logs.XXXXXX")
export PATH="$REPO/node_modules/.bin:$PATH" # so frontend-curl.sh's `npx wp-env` is the local one

# Suite lists (round 5). Recommended: the full existing suite. Easy-only and both: the
# shared-invariant suites plus the connector-aware ones. None: lifecycle plus the none setup checks.
ALL_SUITES=$(cd "$REPO/tests/Integration" && ls test-*.php | tr '\n' ' ')
SHARED_SUITES="test-permission-matrix.php test-health-summary.php test-update-history.php test-access-check.php test-no-frontend.php test-uninstall.php test-bundled.php"
CONNECTOR_SUITES="test-connectors.php test-connector-availability.php test-workflows.php test-setup-checks.php test-analytics-naming.php"
suites_for() {
	case "$1" in
	recommended) echo "$ALL_SUITES" ;;
	easy-* | both) echo "$SHARED_SUITES $CONNECTOR_SUITES" ;;
	none) echo "test-lifecycle.php test-setup-checks.php" ;;
	esac
}
# Files every state's list must contain; one missing is INCOMPLETE COVERAGE.
required_for() {
	case "$1" in
	recommended) echo "$SHARED_SUITES $CONNECTOR_SUITES test-lifecycle.php" ;;
	easy-* | both) echo "$SHARED_SUITES $CONNECTOR_SUITES" ;;
	none) echo "test-lifecycle.php test-setup-checks.php" ;;
	esac
}
port_for() { jq -r '.port' "$REPO/tests/matrix/$1.wp-env.json"; }

declare -A RESULT PASSES FAILS VERSIONS PHPV WPV
fail=0
FIRST_DATE=''

run_state() {
	local state=$1 w="$MATRIX_DIR/$1" port suites f out status summary p q
	port=$(port_for "$state")
	PASSES[$state]=0
	FAILS[$state]=0
	RESULT[$state]=FAILED
	echo
	echo "=== $state (port $port) ==="
	mkdir -p "$w/$SLUG" "$w/tests"
	# Sync into the existing directories rather than replacing them: a running container's bind
	# mount keeps pointing at a deleted directory, and WordPress would then lose the plugin.
	rm -rf "$LOG_DIR/unzip-$state"
	unzip -q "$ZIP" -d "$LOG_DIR/unzip-$state" || { echo "FAIL  [$state] could not unpack the candidate"; return 1; }
	[ -f "$LOG_DIR/unzip-$state/$SLUG/$SLUG.php" ] || { echo "FAIL  [$state] the candidate has no $SLUG/$SLUG.php"; return 1; }
	rsync -a --delete "$LOG_DIR/unzip-$state/$SLUG/" "$w/$SLUG/"
	rm -rf "$LOG_DIR/unzip-$state"
	rsync -a --delete "$REPO/tests/" "$w/tests/"
	rm -f "$w/tests/matrix/.window"
	cp "$REPO/tests/matrix/$state.wp-env.json" "$w/.wp-env.json"
	cd "$w" || return 1
	if ! "$WPE" start >"$LOG_DIR/$state-start.log" 2>&1; then
		echo "FAIL  [$state] wp-env start (log: $LOG_DIR/$state-start.log)"
		tail -5 "$LOG_DIR/$state-start.log"
		return 1
	fi
	# Fresh database for every run, then the config's plugins activated in order.
	"$WPE" clean all >>"$LOG_DIR/$state-start.log" 2>&1
	wp() { "$WPE" run cli wp "$@" 2>/dev/null; }
	for p in $(jq -r '.plugins[] | sub(".*/"; "") | sub("\\.zip$"; "") | sub("\\.[0-9][0-9.]*$"; "")' "$w/.wp-env.json"); do
		wp plugin activate "$p" >>"$LOG_DIR/$state-start.log"
	done
	wp rewrite structure '/%postname%/' --hard >/dev/null
	wp option update timezone_string "$TZ_NAME" >/dev/null
	if jq -e '.plugins | map(test("easy-mcp-ai")) | any' "$w/.wp-env.json" >/dev/null; then
		# wp-env activates Easy MCP AI before the candidate, so its activation seed misses our
		# tools. Activate it again the way a store adding it later would (the HTTP suite checks
		# this seed, and the pre-1.1 seeded case, explicitly).
		wp plugin deactivate easy-mcp-ai >/dev/null
		wp option delete easy_mcp_ai_enabled_abilities easy_mcp_ai_abilities_seed_pending >/dev/null
		wp plugin activate easy-mcp-ai >/dev/null
		wp eval 'echo 1;' >/dev/null
	fi
	# Action Scheduler stays on its HybridStore while its post-to-table migration is pending, and
	# wp-env never runs WP-Cron. Finish the migration in recommended and both so the health
	# summary's exact database-store path is exercised there; the easy states keep the sampled path.
	# Done last: activating a plugin (here Easy MCP AI) puts Action Scheduler back on HybridStore.
	if [ "$state" == recommended ] || [ "$state" == both ]; then
		wp action-scheduler migrate >>"$LOG_DIR/$state-start.log"
		echo "action scheduler store: $(wp eval 'echo get_class( ActionScheduler::store() );')"
	fi
	VERSIONS[$state]=$(wp plugin list --format=json | jq -c 'map({name, status, version})')
	PHPV[$state]=$(wp eval 'echo PHP_VERSION;')
	WPV[$state]=$(wp core version)
	echo "versions: WP ${WPV[$state]}, PHP ${PHPV[$state]}, plugins $(jq -r 'map(select(.status == "active") | "\(.name) \(.version)") | join(", ")' <<<"${VERSIONS[$state]}")"
	if [ "$(jq -r --arg s "$SLUG" 'map(select(.name == $s))[0].status' <<<"${VERSIONS[$state]}")" != active ]; then
		echo "FAIL  [$state] the candidate plugin is not active"
		return 1
	fi
	# The plugin under test must be the candidate: same version header as the zip.
	cand_version=$(unzip -p "$ZIP" "$SLUG/$SLUG.php" | sed -n 's/^ \* Version:[[:space:]]*//p' | head -1)
	[ "$(jq -r --arg s "$SLUG" 'map(select(.name == $s))[0].version' <<<"${VERSIONS[$state]}")" == "$cand_version" ] || { echo "FAIL  [$state] plugin version is not the candidate's ($cand_version)"; return 1; }

	if [ "$state" != none ]; then
		if ! WPE="$WPE" BLZMCP_PRODUCT_ID=0 BLZMCP_PREFLIGHT=1 bash "$REPO/tests/matrix/http-suite.sh" "$state" "$port" >"$LOG_DIR/$state-preflight.log" 2>&1; then
			grep -E 'TRANSPORT UNREACHABLE|FAIL' "$LOG_DIR/$state-preflight.log" | head -5
			echo "FAIL  [$state] preflight"
			return 1
		fi
		echo "PASS  [$state] preflight ($(grep -c '^PASS' "$LOG_DIR/$state-preflight.log") checks)"
	fi

	out=$(wp eval-file "$T/matrix/fixture.php" seed)
	product_id=$(sed -n 's/^PRODUCT_ID=//p' <<<"$out")
	window_date=$(sed -n 's/^WINDOW_DATE=//p' <<<"$out")
	if [ -z "$product_id" ] || [ -z "$window_date" ]; then
		echo "FAIL  [$state] fixture seed: $(head -3 <<<"$out")"
		return 1
	fi
	echo "fixture: $(grep '^WINDOW_' <<<"$out" | tr '\n' ' ')"
	# The window is fixed; the orders come out again until the HTTP suite (the suites assume they
	# own the order tables, e.g. test-bundled's before/after order-store check).
	wp eval-file "$T/matrix/fixture.php" cleanup >/dev/null
	cp "$w/tests/matrix/.window" "$LOG_DIR/$state.window"
	if [ -z "$FIRST_DATE" ]; then
		FIRST_DATE=$window_date
	elif [ "$window_date" != "$FIRST_DATE" ]; then
		echo "WINDOW CHANGED: $state seeded on $window_date, the first state on $FIRST_DATE"
		return 2
	fi

	suites=$(suites_for "$state")
	echo "suite list [$state]: $suites"
	for f in $(required_for "$state"); do
		if [[ " $suites " != *" $f "* ]] || [ ! -f "$REPO/tests/Integration/$f" ]; then
			echo "INCOMPLETE COVERAGE: [$state] required suite $f is not in the list or does not exist"
			FAILS[$state]=$((FAILS[$state] + 1))
		fi
	done
	local extra_env=''
	[ "$state" == none ] && extra_env='BLZMCP_STATE_NONE=1'
	for f in $suites; do
		# shellcheck disable=SC2086 # $extra_env is a single VAR=value or empty.
		out=$("$WPE" run cli env BLZMCP_SKIP_HTTP=1 $extra_env wp eval-file "$T/Integration/$f" 2>&1)
		status=$?
		summary=$(printf '%s\n' "$out" | grep -E '^[0-9]+ passed, [0-9]+ failed' | tail -1)
		printf '%s\n' "$out" >"$LOG_DIR/$state-$f.log"
		p=$(sed -E 's/^([0-9]+) passed.*/\1/' <<<"${summary:-0 passed}")
		q=$(sed -E 's/.* ([0-9]+) failed.*/\1/' <<<"${summary:-x 1 failed}")
		PASSES[$state]=$((PASSES[$state] + p))
		if [ "$status" -ne 0 ] || [ -z "$summary" ] || [ "$q" != 0 ] || grep -q 'Fatal error' <<<"$out"; then
			echo "FAIL  [$state] $f (${summary:-no summary})"
			printf '%s\n' "$out" | awk '/^FAIL|Fatal error/ && n++ < 20'
			FAILS[$state]=$((FAILS[$state] + (q == 0 ? 1 : q)))
		else
			echo "PASS  [$state] $f ($summary)"
		fi
	done
	# test-no-frontend.php runs with BLZMCP_SKIP_HTTP=1; its HTTP half is frontend-curl.sh from the
	# host, so every state that lists test-no-frontend must run it (else INCOMPLETE COVERAGE).
	if [[ " $suites " == *" test-no-frontend.php "* ]]; then
		if [ "$state" == none ]; then
			echo "INCOMPLETE COVERAGE: [$state] test-no-frontend.php is listed but its HTTP half is not run in none"
			FAILS[$state]=$((FAILS[$state] + 1))
		elif WPE="$WPE" bash "$REPO/tests/Integration/frontend-curl.sh" >"$LOG_DIR/$state-frontend.log" 2>&1; then
			echo "PASS  [$state] frontend-curl.sh ($(grep -c '^PASS' "$LOG_DIR/$state-frontend.log") URLs)"
			PASSES[$state]=$((PASSES[$state] + 1))
		else
			echo "FAIL  [$state] frontend-curl.sh"
			grep '^FAIL' "$LOG_DIR/$state-frontend.log" | head -5
			FAILS[$state]=$((FAILS[$state] + 1))
		fi
	fi
	# The exact (database store) per-hook grace must have been asserted where the store was migrated.
	if { [ "$state" == recommended ] || [ "$state" == both ]; } && ! grep -q '^PASS  database store: pending job 2 hours late' "$LOG_DIR/$state-test-health-summary.php.log" 2>/dev/null; then
		echo "INCOMPLETE COVERAGE: [$state] test-health-summary did not take the database-store path"
		FAILS[$state]=$((FAILS[$state] + 1))
	fi
	if [ "$state" != none ] && [ ! -s "$LOG_DIR/$state-frontend.log" ]; then
		echo "INCOMPLETE COVERAGE: [$state] the front-end HTTP check (frontend-curl.sh) did not run"
		FAILS[$state]=$((FAILS[$state] + 1))
	fi
	# Reseed for the HTTP suite, on this same reporting date.
	out=$(wp eval-file "$T/matrix/fixture.php" seed)
	product_id=$(sed -n 's/^PRODUCT_ID=//p' <<<"$out")
	if [ "$(sed -n 's/^WINDOW_DATE=//p' <<<"$out")" != "$FIRST_DATE" ]; then
		echo "WINDOW CHANGED: $state reseeded on another date"
		return 2
	fi
	WPE="$WPE" BLZMCP_PRODUCT_ID="$product_id" bash "$REPO/tests/matrix/http-suite.sh" "$state" "$port" >"$LOG_DIR/$state-http.log" 2>&1
	status=$?
	summary=$(grep -E '^[0-9]+ passed, [0-9]+ failed' "$LOG_DIR/$state-http.log" | tail -1)
	grep -E '^(FAIL|NOTE|TRANSPORT|CLEANUP)' "$LOG_DIR/$state-http.log"
	p=$(sed -E 's/^([0-9]+) passed.*/\1/' <<<"${summary:-0 passed}")
	q=$(sed -E 's/.* ([0-9]+) failed.*/\1/' <<<"${summary:-x 1 failed}")
	PASSES[$state]=$((PASSES[$state] + p))
	if [ "$status" -ne 0 ] || [ -z "$summary" ]; then
		[ -z "$summary" ] && echo "INCOMPLETE COVERAGE: [$state] http-suite did not finish"
		FAILS[$state]=$((FAILS[$state] + (q == 0 ? 1 : q)))
		echo "FAIL  [$state] http-suite.sh (${summary:-no summary})"
	else
		echo "PASS  [$state] http-suite.sh ($summary)"
	fi
	if grep -q '^CLEANUP ERROR' "$LOG_DIR/$state-http.log"; then
		FAILS[$state]=$((FAILS[$state] + 1))
	fi
	out=$(wp eval-file "$T/matrix/fixture.php" cleanup)
	echo "fixture cleanup: $out"
	[ "${FAILS[$state]}" -eq 0 ] && RESULT[$state]=PASSED
	return 0
}

stop_state() {
	[ "${BLZMCP_MATRIX_KEEP:-0}" == 1 ] && return 0
	(cd "$MATRIX_DIR/$1" 2>/dev/null && "$WPE" stop >/dev/null 2>&1)
}

attempt=1
while :; do
	restart=0
	FIRST_DATE=''
	for state in $STATES; do
		[ -f "$REPO/tests/matrix/$state.wp-env.json" ] || { echo "INCOMPLETE COVERAGE: no config for $state"; RESULT[$state]=FAILED; FAILS[$state]=1; continue; }
		run_state "$state"
		rc=$?
		cd "$REPO" || exit 1
		stop_state "$state"
		if [ "$rc" -eq 2 ]; then
			restart=1
			break
		fi
	done
	[ "$restart" -eq 1 ] && [ "$attempt" -lt 2 ] && { attempt=$((attempt + 1)); echo "Reseeding every state on the new date."; continue; }
	[ "$restart" -eq 1 ] && { echo "WINDOW CHANGED twice; giving up"; fail=1; }
	break
done

echo
echo "=== Matrix summary ==="
for state in $STATES; do
	[ "${RESULT[$state]:-FAILED}" == PASSED ] || fail=1
	echo "$state: ${RESULT[$state]:-NOT RUN} (${PASSES[$state]:-0} passed, ${FAILS[$state]:-1} failed)"
done
{
	echo "ZIP=$ZIP"
	echo "COMMIT=$(git -C "$REPO" rev-parse HEAD)"
	echo "WINDOW_FILE=$(ls "$LOG_DIR"/*.window 2>/dev/null | head -1)"
	for state in $STATES; do
		echo "STATE=$state|${RESULT[$state]:-NOT RUN}|${PASSES[$state]:-0}|${FAILS[$state]:-1}|${WPV[$state]:-}|${PHPV[$state]:-}|${VERSIONS[$state]:-[]}"
	done
} >"$LOG_DIR/summary.txt"
bash "$REPO/tests/matrix/record.sh" "$LOG_DIR/summary.txt" "$([ "$fail" -eq 0 ] && echo PASSED || echo FAILED)"
echo "Logs: $LOG_DIR"
if [ "$fail" -eq 0 ]; then echo "MATRIX PASSED"; else echo "MATRIX FAILED"; fi
exit $fail
