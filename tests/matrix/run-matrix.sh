#!/usr/bin/env bash
# Real-plugin matrix: the release gate. Called by `scripts/run-integration.sh --matrix ZIP`.
# Besides the suites and the HTTP suite per state, it runs the payment-health storage loop
# (test-payment-orders and test-payment-health in every authority/sync combination, every state
# except none) and, in recommended, the cold performance budget (tests/matrix/perf-suite.sh; set
# BLZMCP_MATRIX_PERF=0 to skip it, which makes the evidence record fail as incomplete).
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
# The HTTP suite prints one TRANSPORT_ROW per route x order authority x principal x case (1.2.1
# item 5); they go into the summary as TRANSPORT= lines and record.sh fails the evidence when a
# required row is missing, duplicated, unknown or FAILED (BLZMCP_TRANSPORT_OUT names the separate
# transport receipt it writes).
#
# Every state runs inside one site-local reporting date (tests/matrix/.window). If a state seeds
# on a different date than the first one, the whole matrix is reseeded once from the start.
#
# Env: BLZMCP_MATRIX_STATES  space-separated subset (default: all six, in order; record.sh fails a
#                            subset as incomplete evidence)
#      BLZMCP_MATRIX_KEEP=1  leave the environments running afterwards (default: wp-env stop)
#      BLZMCP_MATRIX_TZ      site timezone for every state (default Australia/Melbourne)
#
# Host admission (ruling Q8, REVIEW-pass4e; tests/matrix/host-admission.sh), always on, no switch:
# the matrix refuses to start while a wp-env WordPress container runs on a host port outside the
# states' own 8890-8901; a background sampler appends `<UTC> host= ncpu= load1= load5=` every 60 s
# to $LOG_DIR/load-samples.txt for the whole run; before the first state and again immediately
# before the performance phase it waits, sampling every 60 s inside this same run, until
# load5 < 0.25 x N and load1 < 0.33 x N (N logical CPUs). It gives up after 12 hours with
# HOST ADMISSION NOT REACHED and MATRIX FAILED. The ADMISSION lines, host identity and sample
# statistics go into the summary; record.sh refuses the evidence without both admission lines.
set -uo pipefail

ZIP=${1:?usage: run-matrix.sh CANDIDATE.zip}
REPO=$(cd "$(dirname "$0")/../.." && pwd)
case "$ZIP" in /*) ;; *) ZIP="$REPO/$ZIP" ;; esac
[ -f "$ZIP" ] || { echo "MATRIX FAILED: no candidate zip at $ZIP"; exit 1; }
WPE="$REPO/node_modules/.bin/wp-env"
[ -x "$WPE" ] || { echo "MATRIX FAILED: $WPE missing (npm install)"; exit 1; }
MATRIX_DIR=${BLZMCP_MATRIX_DIR:-$HOME/.blzmcp-matrix}
STATES=${BLZMCP_MATRIX_STATES:-recommended easy-2.3.1 easy-2.4.0 easy-2.5.0 both none}
TZ_NAME=${BLZMCP_MATRIX_TZ:-Australia/Melbourne}
SLUG=blaze-mcp-toolkit-for-woocommerce
T=wp-content/blzmcp-tests
LOG_DIR=$(mktemp -d "${TMPDIR:-/tmp}/blzmcp-matrix-logs.XXXXXX")
export PATH="$REPO/node_modules/.bin:$PATH" # so frontend-curl.sh's `npx wp-env` is the local one

# Suite lists (round 5). Recommended: the full existing suite. Easy-only and both: the
# shared-invariant suites plus the connector-aware ones. None: lifecycle plus the none setup checks.
# The storage suites run in the order-storage loop (every authority/sync combination, each switched
# in one process and asserted in a fresh one), not in the plain list.
STORAGE_SUITES="test-payment-orders.php test-payment-health.php"
STORAGE_COMBOS="hpos:no hpos:yes legacy:yes legacy:no"
ALL_SUITES=$(cd "$REPO/tests/Integration" && ls test-*.php | grep -vxF -e test-payment-orders.php -e test-payment-health.php | tr '\n' ' ')
SHARED_SUITES="test-permission-matrix.php test-health-summary.php test-update-history.php test-access-check.php test-no-frontend.php test-uninstall.php test-bundled.php test-payment-methods.php test-payment-errors.php"
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

# Per-state values (RESULT, PASSES, FAILS, VERSIONS, PHPV, WPV). macOS /bin/bash is 3.2, which has
# no associative arrays, so each value is its own variable: M_<name>_<state with [^A-Za-z0-9] as _>.
sset() { local k=$2; printf -v "M_$1_${k//[^A-Za-z0-9]/_}" '%s' "$3"; }
sget() { # name state [default when unset]
	local k=$2 v
	v="M_$1_${k//[^A-Za-z0-9]/_}"
	if [ -n "${!v+x}" ]; then printf '%s' "${!v}"; else printf '%s' "${3-}"; fi
}
sadd() { sset "$1" "$2" $(($(sget "$1" "$2" 0) + $3)); }
STORAGE_LINES=()
TRANSPORT_LINES=()
PERF_FILE=''
ZIP_SHA256=$(shasum -a 256 "$ZIP" | awk '{print $1}')
CAND_VERSION=$(unzip -p "$ZIP" "$SLUG/$SLUG.php" | sed -n 's/^ \* Version:[[:space:]]*//p' | head -1 | tr -d '\r')
for f in $STORAGE_SUITES; do
	[ -f "$REPO/tests/Integration/$f" ] || { echo "MATRIX FAILED: required storage suite $f does not exist"; exit 1; }
done
fail=0
FIRST_DATE=''
# Host admission (ruling Q8). No other wp-env environment may run beside the matrix, then the
# sampler starts and the host must be admitted before the first state.
# shellcheck source=tests/matrix/host-admission.sh
. "$REPO/tests/matrix/host-admission.sh"
LOAD_SAMPLES_FILE="$LOG_DIR/load-samples.txt"
ADMISSION_LINES=()
HA_SAMPLER_PID=''
trap 'ha_sampler_stop' EXIT
trap 'ha_sampler_stop; exit 130' INT
trap 'ha_sampler_stop; exit 143' TERM
# Prints the refusal and returns 1 when another wp-env WordPress environment is running.
foreign_wpenv_check() {
	local found rc
	found=$(ha_foreign_wpenv)
	rc=$?
	[ "$rc" -eq 0 ] && return 0
	if [ "$rc" -eq 2 ]; then
		echo "HOST ADMISSION REFUSED ($1): $found; the matrix cannot prove that no other wp-env environment is running"
	else
		echo "HOST ADMISSION REFUSED ($1): another wp-env WordPress environment is running: $found (container:host port)."
		echo "  Ruling Q8: no other wp-env or matrix run of this repository may be active, including the owner's port-8888 environment; a separate checkout or port is not an exemption. Stop it (npx wp-env stop in its directory) and run the matrix again. Only the matrix states' own ports $HA_MATRIX_PORT_MIN-$HA_MATRIX_PORT_MAX are allowed."
	fi
	return 1
}
HA_N=$(ha_ncpu) || { echo "HOST ADMISSION NOT REACHED (start): the logical CPU count is unavailable"; echo "MATRIX FAILED"; exit 1; }
HA_HOST=$(ha_identity "$HA_N")
HA_BOUNDS=$(ha_bounds "$HA_N")
echo "Host admission (ruling Q8): host|N|CPU|load source = $HA_HOST; admitted when load5 < ${HA_BOUNDS%% *} and load1 < ${HA_BOUNDS##* } (strict); samples every ${HA_INTERVAL_DEFAULT}s to $LOAD_SAMPLES_FILE"
if ! foreign_wpenv_check start; then
	echo "Logs: $LOG_DIR"
	echo "MATRIX FAILED"
	exit 1
fi
: >"$LOAD_SAMPLES_FILE"
ha_sampler_start "$LOAD_SAMPLES_FILE" || { echo "HOST ADMISSION NOT REACHED (start): the load sampler could not start"; echo "MATRIX FAILED"; exit 1; }
if ! ha_wait_admitted start "$LOAD_SAMPLES_FILE"; then
	echo "Logs: $LOG_DIR"
	echo "MATRIX FAILED"
	exit 1
fi
ADMISSION_LINES+=("$HA_ADMISSION")
# The HTTP suite's fail-closed safety checks prove themselves first, offline (REVIEW-pass4a R4):
# injected HTTP 000/5xx/malformed replies, helper failures and leaked diagnostics must each fail.
# Its summary goes into the evidence (HTTP_CHECKS); record.sh refuses a run without a clean one.
HTTP_CHECKS_LOG="$LOG_DIR/test-http-checks.log"
"$BASH" "$REPO/tests/matrix/test-http-checks.sh" >"$HTTP_CHECKS_LOG" 2>&1
HTTP_CHECKS=$(grep -E '^[0-9]+ passed, [0-9]+ failed$' "$HTTP_CHECKS_LOG" | tail -1)
if [[ "$HTTP_CHECKS" =~ ^[1-9][0-9]*\ passed,\ 0\ failed$ ]]; then
	echo "PASS  test-http-checks.sh ($HTTP_CHECKS)"
else
	echo "FAIL  test-http-checks.sh (${HTTP_CHECKS:-no summary})"
	grep '^FAIL' "$HTTP_CHECKS_LOG" | head -10
	fail=1
fi

run_state() {
	local state=$1 w="$MATRIX_DIR/$1" port suites f out status summary p q versions phpv wpv
	port=$(port_for "$state")
	sset PASSES "$state" 0
	sset FAILS "$state" 0
	sset RESULT "$state" FAILED
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
	versions=$(wp plugin list --format=json | jq -c 'map({name, status, version})')
	phpv=$(wp eval 'echo PHP_VERSION;')
	wpv=$(wp core version)
	sset VERSIONS "$state" "$versions"
	sset PHPV "$state" "$phpv"
	sset WPV "$state" "$wpv"
	echo "versions: WP $wpv, PHP $phpv, plugins $(jq -r 'map(select(.status == "active") | "\(.name) \(.version)") | join(", ")' <<<"$versions")"
	if [ "$(jq -r --arg s "$SLUG" 'map(select(.name == $s))[0].status' <<<"$versions")" != active ]; then
		echo "FAIL  [$state] the candidate plugin is not active"
		return 1
	fi
	# The plugin under test must be the candidate: same version header as the zip.
	cand_version=$(unzip -p "$ZIP" "$SLUG/$SLUG.php" | sed -n 's/^ \* Version:[[:space:]]*//p' | head -1)
	[ "$(jq -r --arg s "$SLUG" 'map(select(.name == $s))[0].version' <<<"$versions")" == "$cand_version" ] || { echo "FAIL  [$state] plugin version is not the candidate's ($cand_version)"; return 1; }

	if [ "$state" != none ]; then
		if ! WPE="$WPE" BLZMCP_PRODUCT_ID=0 BLZMCP_PREFLIGHT=1 "$BASH" "$REPO/tests/matrix/http-suite.sh" "$state" "$port" >"$LOG_DIR/$state-preflight.log" 2>&1; then
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
			sadd FAILS "$state" 1
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
		sadd PASSES "$state" "$p"
		if [ "$status" -ne 0 ] || [ -z "$summary" ] || [ "$q" != 0 ] || grep -q 'Fatal error' <<<"$out"; then
			echo "FAIL  [$state] $f (${summary:-no summary})"
			printf '%s\n' "$out" | awk '/^FAIL|Fatal error/ && n++ < 20'
			sadd FAILS "$state" $((q == 0 ? 1 : q))
		else
			echo "PASS  [$state] $f ($summary)"
		fi
	done
	# Order storage loop (payment health): every authority/sync combination, switched in one process
	# and asserted in a fresh one. Every combination and suite must report its own STORAGE line, or
	# the state fails with INCOMPLETE COVERAGE. The state's original storage mode is restored after.
	if [ "$state" != none ]; then
		local orig_hpos orig_sync orig_set combo st sy seen
		orig_hpos=$(wp option get woocommerce_custom_orders_table_enabled | tr -d '\r' | tail -1)
		orig_sync=$(wp option get woocommerce_custom_orders_table_data_sync_enabled | tr -d '\r' | tail -1)
		[ "$orig_hpos" == yes ] && orig_set=hpos || orig_set=legacy
		[ "$orig_sync" == yes ] || orig_sync=no
		for combo in $STORAGE_COMBOS; do
			st=${combo%%:*}
			sy=${combo##*:}
			if ! "$WPE" run cli env BLZMCP_PF_SET="$st" BLZMCP_PF_SYNC="$sy" wp eval-file "$T/Integration/test-payment-orders.php" 2>&1 | grep -qE '^[0-9]+ passed, 0 failed'; then
				echo "INCOMPLETE COVERAGE: [$state] could not switch order storage to storage=$st sync=$sy"
				sadd FAILS "$state" 1
				for f in $STORAGE_SUITES; do STORAGE_LINES+=("$state|$combo|$f|NOT RUN|0|1"); done
				continue
			fi
			for f in $STORAGE_SUITES; do
				out=$("$WPE" run cli env BLZMCP_SKIP_HTTP=1 BLZMCP_EXPECT_AUTHORITY="$st" BLZMCP_EXPECT_SYNC="$sy" wp eval-file "$T/Integration/$f" 2>&1)
				status=$?
				summary=$(printf '%s\n' "$out" | grep -E '^[0-9]+ passed, [0-9]+ failed' | tail -1)
				seen=$(printf '%s\n' "$out" | grep -E '^STORAGE ' | tail -1)
				printf '%s\n' "$out" >"$LOG_DIR/$state-$f-$st-$sy.log"
				p=$(sed -E 's/^([0-9]+) passed.*/\1/' <<<"${summary:-0 passed}")
				q=$(sed -E 's/.* ([0-9]+) failed.*/\1/' <<<"${summary:-x 1 failed}")
				sadd PASSES "$state" "$p"
				if [ "$status" -ne 0 ] || [ -z "$summary" ] || [ "$q" != 0 ] || ! grep -q "authority=$st sync=$sy" <<<"$seen" || grep -q 'Fatal error' <<<"$out"; then
					echo "FAIL  [$state] $f [storage=$st sync=$sy] (${summary:-no summary}; ${seen:-no STORAGE line})"
					printf '%s\n' "$out" | awk '/^FAIL|Fatal error/ && n++ < 20'
					sadd FAILS "$state" $((q == 0 ? 1 : q))
					STORAGE_LINES+=("$state|$combo|$f|FAILED|$p|$((q == 0 ? 1 : q))")
				else
					echo "PASS  [$state] $f [storage=$st sync=$sy] ($summary)"
					STORAGE_LINES+=("$state|$combo|$f|PASSED|$p|0")
				fi
			done
		done
		if ! "$WPE" run cli env BLZMCP_PF_SET="$orig_set" BLZMCP_PF_SYNC="$orig_sync" wp eval-file "$T/Integration/test-payment-orders.php" 2>&1 | grep -qE '^[0-9]+ passed, 0 failed'; then
			echo "FAIL  [$state] order storage not restored to storage=$orig_set sync=$orig_sync"
			sadd FAILS "$state" 1
		fi
	fi
	# test-no-frontend.php runs with BLZMCP_SKIP_HTTP=1; its HTTP half is frontend-curl.sh from the
	# host, so every state that lists test-no-frontend must run it (else INCOMPLETE COVERAGE).
	if [[ " $suites " == *" test-no-frontend.php "* ]]; then
		if [ "$state" == none ]; then
			echo "INCOMPLETE COVERAGE: [$state] test-no-frontend.php is listed but its HTTP half is not run in none"
			sadd FAILS "$state" 1
		elif WPE="$WPE" "$BASH" "$REPO/tests/Integration/frontend-curl.sh" >"$LOG_DIR/$state-frontend.log" 2>&1; then
			echo "PASS  [$state] frontend-curl.sh ($(grep -c '^PASS' "$LOG_DIR/$state-frontend.log") URLs)"
			sadd PASSES "$state" 1
		else
			echo "FAIL  [$state] frontend-curl.sh"
			grep '^FAIL' "$LOG_DIR/$state-frontend.log" | head -5
			sadd FAILS "$state" 1
		fi
	fi
	# The exact (database store) per-hook grace must have been asserted where the store was migrated.
	if { [ "$state" == recommended ] || [ "$state" == both ]; } && ! grep -q '^PASS  database store: pending job 2 hours late' "$LOG_DIR/$state-test-health-summary.php.log" 2>/dev/null; then
		echo "INCOMPLETE COVERAGE: [$state] test-health-summary did not take the database-store path"
		sadd FAILS "$state" 1
	fi
	if [ "$state" != none ] && [ ! -s "$LOG_DIR/$state-frontend.log" ]; then
		echo "INCOMPLETE COVERAGE: [$state] the front-end HTTP check (frontend-curl.sh) did not run"
		sadd FAILS "$state" 1
	fi
	# Reseed for the HTTP suite, on this same reporting date.
	out=$(wp eval-file "$T/matrix/fixture.php" seed)
	product_id=$(sed -n 's/^PRODUCT_ID=//p' <<<"$out")
	if [ "$(sed -n 's/^WINDOW_DATE=//p' <<<"$out")" != "$FIRST_DATE" ]; then
		echo "WINDOW CHANGED: $state reseeded on another date"
		return 2
	fi
	WPE="$WPE" BLZMCP_PRODUCT_ID="$product_id" "$BASH" "$REPO/tests/matrix/http-suite.sh" "$state" "$port" >"$LOG_DIR/$state-http.log" 2>&1
	status=$?
	summary=$(grep -E '^[0-9]+ passed, [0-9]+ failed' "$LOG_DIR/$state-http.log" | tail -1)
	grep -E '^(FAIL|NOTE|TRANSPORT UNREACHABLE|CLEANUP)' "$LOG_DIR/$state-http.log"
	# The transport receipt rows (route x authority x principal x case), checked by record.sh.
	while IFS= read -r line; do TRANSPORT_LINES+=("${line#TRANSPORT_ROW=}"); done < <(grep '^TRANSPORT_ROW=' "$LOG_DIR/$state-http.log")
	echo "transport rows [$state]: $(grep -c '^TRANSPORT_ROW=.*|PASSED$' "$LOG_DIR/$state-http.log") passed, $(grep -c '^TRANSPORT_ROW=.*|FAILED$' "$LOG_DIR/$state-http.log") failed"
	p=$(sed -E 's/^([0-9]+) passed.*/\1/' <<<"${summary:-0 passed}")
	q=$(sed -E 's/.* ([0-9]+) failed.*/\1/' <<<"${summary:-x 1 failed}")
	sadd PASSES "$state" "$p"
	if [ "$status" -ne 0 ] || [ -z "$summary" ]; then
		[ -z "$summary" ] && echo "INCOMPLETE COVERAGE: [$state] http-suite did not finish"
		sadd FAILS "$state" $((q == 0 ? 1 : q))
		echo "FAIL  [$state] http-suite.sh (${summary:-no summary})"
	else
		echo "PASS  [$state] http-suite.sh ($summary)"
	fi
	if grep -q '^CLEANUP ERROR' "$LOG_DIR/$state-http.log"; then
		sadd FAILS "$state" 1
	fi
	out=$(wp eval-file "$T/matrix/fixture.php" cleanup)
	echo "fixture cleanup: $out"
	# Cold performance budget (FEATURE-PLAN item 10), recommended state only, on the candidate.
	if [ "$state" == recommended ] && [ "${BLZMCP_MATRIX_PERF:-1}" != 0 ]; then
		PERF_FILE="$LOG_DIR/payment-performance.json"
		# A temporary application password for the HTTP latency figures, from the matrix's own
		# credentials helper. It lives only in shell variables and is revoked straight after.
		local cred perf_id perf_secret
		cred=$(wp eval-file "$T/matrix/credentials.php" app-password create admin)
		perf_id=$(sed -n 's/^ID=//p' <<<"$cred" | head -1)
		perf_secret=$(sed -n 's/^SECRET=//p' <<<"$cred" | head -1)
		unset cred
		[ -n "$perf_id" ] && [ -n "$perf_secret" ] || echo "FAIL  [$state] perf: no application password for the HTTP latency figures"
		# The suites above deactivate plugins (test-lifecycle, test-uninstall, test-update-history),
		# and every deactivation marks the Action Scheduler migration incomplete, so the store is
		# HybridStore again here (seen at 61170fd). Finish the migration before timing; perf-suite
		# repeats it before each logger and fails any trial not run on ActionScheduler_DBStore.
		wp action-scheduler migrate >>"$LOG_DIR/$state-start.log"
		local perf_store
		perf_store=$(wp eval 'echo get_class( ActionScheduler::store() );')
		echo "perf: Action Scheduler store before timing: $perf_store"
		if [ "$perf_store" != ActionScheduler_DBStore ]; then
			echo "FAIL  [$state] perf: Action Scheduler is on ${perf_store:-no store}, not ActionScheduler_DBStore, after the migration"
			sadd FAILS "$state" 1
		fi
		# Ruling Q8: immediately before timing, no other wp-env environment and the host admitted
		# (waiting here, sampling every 60 s, inside this same run). Not admitted within 12 hours:
		# the performance phase is not run and the matrix fails; no completed trial is repeated.
		local perf_admitted=0
		if foreign_wpenv_check perf && ha_wait_admitted perf "$LOAD_SAMPLES_FILE"; then
			perf_admitted=1
			ADMISSION_LINES+=("$HA_ADMISSION")
		else
			echo "FAIL  [$state] perf: host admission (ruling Q8) not reached before timing; perf-suite.sh not run"
			sadd FAILS "$state" 1
		fi
		if [ "$perf_admitted" -eq 1 ]; then
			if WPE="$WPE" BLZMCP_T="$T" BLZMCP_PERF_OUT="$PERF_FILE" BLZMCP_PERF_CANDIDATE="$ZIP" BLZMCP_PERF_REQUIRE_DBSTORE=1 \
				BLZMCP_PERF_PORT="$port" BLZMCP_PERF_LOGIN=admin BLZMCP_PERF_APP_PASSWORD="$perf_secret" \
				"$BASH" "$REPO/tests/matrix/perf-suite.sh" >"$LOG_DIR/$state-perf.log" 2>&1; then
				echo "PASS  [$state] perf-suite.sh ($(grep -c '^partition' "$LOG_DIR/$state-perf.log") partitions under 1500 ms)"
				sadd PASSES "$state" 1
			else
				echo "FAIL  [$state] perf-suite.sh (payment health cold budget; see payment-performance.json)"
				grep -E '^(partition|FAIL|CLEANUP)' "$LOG_DIR/$state-perf.log" | head -10
				sadd FAILS "$state" 1
			fi
		fi
		unset perf_secret
		if [ -n "$perf_id" ] && ! wp eval-file "$T/matrix/credentials.php" app-password revoke admin "$perf_id" >/dev/null; then
			echo "CLEANUP ERROR  [$state] perf application password not revoked"
			sadd FAILS "$state" 1
		fi
	fi
	[ "$(sget FAILS "$state" 1)" -eq 0 ] && sset RESULT "$state" PASSED
	return 0
}

stop_state() {
	[ "${BLZMCP_MATRIX_KEEP:-0}" == 1 ] && return 0
	(cd "$MATRIX_DIR/$1" 2>/dev/null && "$WPE" stop >/dev/null 2>&1)
}

attempt=1
while :; do
	restart=0
	STORAGE_LINES=()
	TRANSPORT_LINES=()
	PERF_FILE=''
	FIRST_DATE=''
	for state in $STATES; do
		[ -f "$REPO/tests/matrix/$state.wp-env.json" ] || { echo "INCOMPLETE COVERAGE: no config for $state"; sset RESULT "$state" FAILED; sset FAILS "$state" 1; continue; }
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
	[ "$(sget RESULT "$state" FAILED)" == PASSED ] || fail=1
	echo "$state: $(sget RESULT "$state" 'NOT RUN') ($(sget PASSES "$state" 0) passed, $(sget FAILS "$state" 1) failed)"
done
# The sampler ran for the whole matrix; stop it before counting (ruling Q8).
ha_sampler_stop
read -r HA_COUNT HA_MAX1 HA_MAX5 HA_OVER <<<"$(ha_samples_stats "$LOAD_SAMPLES_FILE" "$HA_N")"
echo "Host load (ruling Q8): $HA_COUNT samples, max load1 $HA_MAX1, max load5 $HA_MAX5, $HA_OVER above the admission bounds (diagnostic only)"
{
	echo "ZIP=$ZIP"
	echo "ZIP_SHA256=$ZIP_SHA256"
	echo "VERSION=$CAND_VERSION"
	echo "COMMIT=$(git -C "$REPO" rev-parse HEAD)"
	echo "WINDOW_FILE=$(ls "$LOG_DIR"/*.window 2>/dev/null | head -1)"
	echo "PERF_FILE=$PERF_FILE"
	echo "HTTP_CHECKS=$HTTP_CHECKS"
	echo "HOST=$HA_HOST"
	for line in ${ADMISSION_LINES[@]+"${ADMISSION_LINES[@]}"}; do
		echo "ADMISSION=$line"
	done
	echo "LOAD_SAMPLES=$HA_COUNT"
	echo "LOAD_MAX=$HA_MAX1|$HA_MAX5"
	echo "LOAD_OVER=$HA_OVER"
	for state in $STATES; do
		echo "STATE=$state|$(sget RESULT "$state" 'NOT RUN')|$(sget PASSES "$state" 0)|$(sget FAILS "$state" 1)|$(sget WPV "$state")|$(sget PHPV "$state")|$(sget VERSIONS "$state" '[]')"
	done
	for line in ${STORAGE_LINES[@]+"${STORAGE_LINES[@]}"}; do
		echo "STORAGE=$line"
	done
	for line in ${TRANSPORT_LINES[@]+"${TRANSPORT_LINES[@]}"}; do
		echo "TRANSPORT=$line"
	done
} >"$LOG_DIR/summary.txt"
"$BASH" "$REPO/tests/matrix/record.sh" "$LOG_DIR/summary.txt" "$([ "$fail" -eq 0 ] && echo PASSED || echo FAILED)" || fail=1
echo "Logs: $LOG_DIR"
if [ "$fail" -eq 0 ]; then echo "MATRIX PASSED"; else echo "MATRIX FAILED"; fi
exit $fail
