#!/usr/bin/env bash
# Integration checks.
#
#   bash scripts/run-integration.sh                       fast checks (stubs, default wp-env)
#   bash scripts/run-integration.sh --matrix CANDIDATE.zip the release gate: the real-plugin
#                                                         matrix (tests/matrix/run-matrix.sh)
#
# Fast mode runs every integration test file inside the default wp-env (npx wp-env start), the
# stub modes, then the host-side front-end check, and fails if any of them fails. It is NOT the
# release gate: stubs are not compatibility evidence. The container cannot fetch its own pages,
# so test-no-frontend.php runs with BLZMCP_SKIP_HTTP=1 here and tests/Integration/frontend-curl.sh
# does the HTTP half from the host. Running test-no-frontend.php alone without that variable
# fails with "HTTP CHECK NOT RUN" when the fetches cannot connect.
set -uo pipefail
cd "$(dirname "$0")/.."
if [ "${1:-}" == --matrix ]; then
	[ -n "${2:-}" ] || { echo "usage: bash scripts/run-integration.sh --matrix CANDIDATE.zip"; exit 2; }
	exec "$BASH" tests/matrix/run-matrix.sh "$2"
fi
echo "FAST CHECKS ONLY — NOT THE RELEASE GATE (the gate is: bash scripts/run-integration.sh --matrix CANDIDATE.zip)"
fail=0
# test-payment-orders.php and test-payment-health.php run in the storage loop below instead.
# test-payment-methods.php and test-payment-errors.php run here, in the default storage mode.
for f in tests/Integration/test-*.php; do
	case "$f" in
		tests/Integration/test-payment-orders.php | tests/Integration/test-payment-health.php) continue ;;
	esac
	out=$(npx wp-env run cli env BLZMCP_SKIP_HTTP=1 BLZMCP_FIXTURE_ENV="${BLZMCP_FIXTURE_ENV:-}" wp eval-file "wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/$f" 2>&1)
	status=$?
	summary=$(printf '%s\n' "$out" | grep -E '^[0-9]+ passed, [0-9]+ failed' | tail -1)
	if [ "$status" -ne 0 ] || ! printf '%s' "$summary" | grep -q ', 0 failed'; then
		echo "FAIL  $f (${summary:-no summary})"
		printf '%s\n' "$out" | awk '/^FAIL/ && n++ < 20'
		fail=1
	else
		echo "PASS  $f ($summary)"
	fi
done
# Order storage loop (payment health, FEATURE-PLAN items 3 and 7): each authority/sync combination is
# switched in one process and asserted in a fresh one, so the reader sees a fully initialised store.
# The store's original storage mode is restored on every exit path and checked at the end.
pf_dir=wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration
pf_opt() {
	npx wp-env run cli wp option get "$1" 2>/dev/null | tr -d '\r' | grep -E '^(yes|no)$' | tail -1
}
pf_orig_hpos=$(pf_opt woocommerce_custom_orders_table_enabled)
pf_orig_sync=$(pf_opt woocommerce_custom_orders_table_data_sync_enabled)
[ "$pf_orig_hpos" == yes ] && pf_orig_set=hpos || pf_orig_set=legacy
[ -n "$pf_orig_sync" ] || pf_orig_sync=no
pf_restore() {
	npx wp-env run cli env BLZMCP_PF_SET="$pf_orig_set" BLZMCP_PF_SYNC="$pf_orig_sync" wp eval-file "$pf_dir/test-payment-orders.php" >/dev/null 2>&1
}
trap 'pf_restore; exit 130' INT TERM
trap pf_restore EXIT
for combo in hpos:no hpos:yes legacy:yes legacy:no; do
	pf_set=${combo%%:*}
	pf_sync=${combo##*:}
	label="storage=$pf_set sync=$pf_sync"
	switch_out=$(npx wp-env run cli env BLZMCP_PF_SET="$pf_set" BLZMCP_PF_SYNC="$pf_sync" wp eval-file "$pf_dir/test-payment-orders.php" 2>&1)
	if ! printf '%s\n' "$switch_out" | grep -qE '^[0-9]+ passed, 0 failed'; then
		echo "FAIL  order storage switch [$label]"
		printf '%s\n' "$switch_out" | awk '/^FAIL/ && n++ < 20'
		fail=1
		continue
	fi
	for f in tests/Integration/test-payment-orders.php tests/Integration/test-payment-health.php; do
		out=$(npx wp-env run cli env BLZMCP_SKIP_HTTP=1 BLZMCP_FIXTURE_ENV="${BLZMCP_FIXTURE_ENV:-}" BLZMCP_EXPECT_AUTHORITY="$pf_set" BLZMCP_EXPECT_SYNC="$pf_sync" wp eval-file "wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/$f" 2>&1)
		status=$?
		summary=$(printf '%s\n' "$out" | grep -E '^[0-9]+ passed, [0-9]+ failed' | tail -1)
		seen=$(printf '%s\n' "$out" | grep -E '^STORAGE ' | tail -1)
		if [ "$status" -ne 0 ] || ! printf '%s' "$summary" | grep -q ', 0 failed' || ! printf '%s' "$seen" | grep -q "authority=$pf_set sync=$pf_sync"; then
			echo "FAIL  $f [$label] (${summary:-no summary}; ${seen:-no STORAGE line})"
			printf '%s\n' "$out" | awk '/^FAIL/ && n++ < 20'
			fail=1
		else
			echo "PASS  $f [$label] ($summary)"
		fi
	done
	# Gateway registry genuinely unbuilt: seed, check (no rest_api_init, so nothing builds it), clean.
	for entry in "tests/Integration/test-payment-methods.php|BLZMCP_PM_STEP" "tests/Integration/test-payment-health.php|BLZMCP_PH_STEP"; do
		IFS='|' read -r f step_var <<<"$entry"
		for step in seed check clean; do
			extra=''
			[ "$step" == check ] && extra="--require=$pf_dir/payment-no-rest-init.php"
			# shellcheck disable=SC2086 # $extra is one wp argument or empty.
			out=$(npx wp-env run cli env BLZMCP_SKIP_HTTP=1 "$step_var=$step" wp $extra eval-file "wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/$f" 2>&1)
			status=$?
			summary=$(printf '%s\n' "$out" | grep -E '^[0-9]+ passed, [0-9]+ failed' | tail -1)
			if [ "$status" -ne 0 ] || ! printf '%s' "$summary" | grep -q ', 0 failed'; then
				echo "FAIL  $f [$label, registry unbuilt: $step] (${summary:-no summary})"
				printf '%s\n' "$out" | awk '/^FAIL/ && n++ < 20'
				fail=1
			else
				echo "PASS  $f [$label, registry unbuilt: $step] ($summary)"
			fi
		done
	done
done
pf_restore
trap - EXIT INT TERM
pf_now_hpos=$(pf_opt woocommerce_custom_orders_table_enabled)
pf_now_sync=$(pf_opt woocommerce_custom_orders_table_data_sync_enabled)
if [ "${pf_now_hpos:-no}" != "${pf_orig_hpos:-no}" ] || [ "${pf_now_sync:-no}" != "$pf_orig_sync" ]; then
	echo "FAIL  order storage not restored (was hpos=${pf_orig_hpos:-no} sync=$pf_orig_sync, now hpos=${pf_now_hpos:-no} sync=${pf_now_sync:-no})"
	fail=1
else
	echo "PASS  order storage restored to $pf_orig_set (sync=$pf_orig_sync)"
fi
# Stand-aside mode (WooCommerce for Claude stub constant loaded before plugins).
for f in tests/Integration/test-bundled.php tests/Integration/test-workflows.php tests/Integration/test-analytics-naming.php; do
	out=$(npx wp-env run cli env BLZMCP_SKIP_HTTP=1 BLZMCP_FIXTURE_ENV="${BLZMCP_FIXTURE_ENV:-}" wp --require=wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/stub-wcfc.php eval-file "wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/$f" 2>&1)
	status=$?
	summary=$(printf '%s\n' "$out" | grep -E '^[0-9]+ passed, [0-9]+ failed' | tail -1)
	if [ "$status" -ne 0 ] || ! printf '%s' "$summary" | grep -q ', 0 failed'; then
		echo "FAIL  $f [stand-aside] (${summary:-no summary})"
		printf '%s\n' "$out" | awk '/^FAIL/ && n++ < 20'
		fail=1
	else
		echo "PASS  $f [stand-aside] ($summary)"
	fi
done
# Easy MCP AI stub (EASY_MCP_AI_VERSION defined while Enable Abilities stays active: the `both` state).
for f in tests/Integration/test-connectors.php tests/Integration/test-connector-availability.php tests/Integration/test-workflows.php tests/Integration/test-analytics-naming.php tests/Integration/test-setup-checks.php tests/Integration/test-bundled.php; do
	out=$(npx wp-env run cli env BLZMCP_SKIP_HTTP=1 BLZMCP_FIXTURE_ENV="${BLZMCP_FIXTURE_ENV:-}" wp --require=wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/stub-easymcp.php eval-file "wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/$f" 2>&1)
	status=$?
	summary=$(printf '%s\n' "$out" | grep -E '^[0-9]+ passed, [0-9]+ failed' | tail -1)
	if [ "$status" -ne 0 ] || ! printf '%s' "$summary" | grep -q ', 0 failed'; then
		echo "FAIL  $f [easy-stub] (${summary:-no summary})"
		printf '%s\n' "$out" | awk '/^FAIL/ && n++ < 20'
		fail=1
	else
		echo "PASS  $f [easy-stub] ($summary)"
	fi
done
# Stand-aside plus the Easy MCP AI stub: analytics naming must hold whoever supplies wc-analytics/*,
# and the workflows still lead with payment health under its Easy MCP name.
for f in tests/Integration/test-analytics-naming.php tests/Integration/test-workflows.php; do
	out=$(npx wp-env run cli env BLZMCP_SKIP_HTTP=1 BLZMCP_FIXTURE_ENV="${BLZMCP_FIXTURE_ENV:-}" wp --require=wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/stub-wcfc.php --require=wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/stub-easymcp.php eval-file "wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/$f" 2>&1)
	status=$?
	summary=$(printf '%s\n' "$out" | grep -E '^[0-9]+ passed, [0-9]+ failed' | tail -1)
	if [ "$status" -ne 0 ] || ! printf '%s' "$summary" | grep -q ', 0 failed'; then
		echo "FAIL  $f [stand-aside+easy-stub] (${summary:-no summary})"
		printf '%s\n' "$out" | awk '/^FAIL/ && n++ < 20'
		fail=1
	else
		echo "PASS  $f [stand-aside+easy-stub] ($summary)"
	fi
done
# Easy MCP AI stub plus a stub Tool_State whose resolve() answers from a test global.
for f in tests/Integration/test-connector-availability.php tests/Integration/test-setup-checks.php; do
	out=$(npx wp-env run cli env BLZMCP_SKIP_HTTP=1 BLZMCP_FIXTURE_ENV="${BLZMCP_FIXTURE_ENV:-}" wp --require=wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/stub-easymcp.php --require=wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/stub-easymcp-toolstate.php eval-file "wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/$f" 2>&1)
	status=$?
	summary=$(printf '%s\n' "$out" | grep -E '^[0-9]+ passed, [0-9]+ failed' | tail -1)
	if [ "$status" -ne 0 ] || ! printf '%s' "$summary" | grep -q ', 0 failed'; then
		echo "FAIL  $f [easy-toolstate] (${summary:-no summary})"
		printf '%s\n' "$out" | awk '/^FAIL/ && n++ < 20'
		fail=1
	else
		echo "PASS  $f [easy-toolstate] ($summary)"
	fi
done
# Enable Abilities denylist: put health-summary in ewpa_thirdparty_disabled in this step, so the
# test's fresh process sees Enable Abilities unregister it at wp_abilities_api_init. The option is
# restored (or deleted, if it did not exist) on every exit path.
denylist_slug='blaze-mcp-toolkit/health-summary'
denylist_had=0
denylist_prev=''
denylist_ok=1
if denylist_get=$(npx wp-env run cli wp option get ewpa_thirdparty_disabled --format=json 2>&1); then
	denylist_had=1
	denylist_prev=$(printf '%s\n' "$denylist_get" | grep -E '^[[{"]' | tail -1)
	[ -n "$denylist_prev" ] || denylist_ok=0
elif ! printf '%s' "$denylist_get" | grep -q 'Does it exist'; then
	denylist_ok=0
fi
denylist_restore() {
	[ "$denylist_ok" -eq 1 ] || return 0
	if [ "$denylist_had" -eq 1 ]; then
		npx wp-env run cli wp option update ewpa_thirdparty_disabled "$denylist_prev" --format=json >/dev/null 2>&1
	else
		npx wp-env run cli wp option delete ewpa_thirdparty_disabled >/dev/null 2>&1
	fi
	return $?
}
trap 'denylist_restore; exit 130' INT TERM
trap denylist_restore EXIT
denylist_new=$(DL_PREV="$denylist_prev" DL_SLUG="$denylist_slug" python3 -c 'import json,os; p=os.environ["DL_PREV"]; v=json.loads(p) if p else []; v=list(v.values()) if isinstance(v,dict) else (v if isinstance(v,list) else []); v=v+[os.environ["DL_SLUG"]] if os.environ["DL_SLUG"] not in v else v; print(json.dumps(v))')
stub_dir=wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration
easy_requires="--require=$stub_dir/stub-easymcp.php --require=$stub_dir/stub-easymcp-toolstate.php"
denylist_set=0
if [ "$denylist_ok" -eq 1 ] && npx wp-env run cli wp option update ewpa_thirdparty_disabled "$denylist_new" --format=json >/dev/null 2>&1; then
	denylist_set=1
fi
# Each entry: test file|mode label|extra wp arguments (the Easy MCP AI stubs, or none).
for entry in \
	"tests/Integration/test-connector-availability.php|ewpa-denylist|" \
	"tests/Integration/test-setup-checks.php|ewpa-denylist|" \
	"tests/Integration/test-setup-checks.php|ewpa-denylist+easy-toolstate|$easy_requires"; do
	IFS='|' read -r f mode extra <<<"$entry"
	if [ "$denylist_set" -eq 1 ]; then
		# shellcheck disable=SC2086 # $extra is a list of wp arguments.
		out=$(npx wp-env run cli env BLZMCP_SKIP_HTTP=1 BLZMCP_FIXTURE_ENV="${BLZMCP_FIXTURE_ENV:-}" BLZMCP_EWPA_DENYLIST=1 wp $extra eval-file "wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/$f" 2>&1)
		status=$?
	else
		out='FAIL  could not read or set ewpa_thirdparty_disabled'
		status=1
	fi
	summary=$(printf '%s\n' "$out" | grep -E '^[0-9]+ passed, [0-9]+ failed' | tail -1)
	if [ "$status" -ne 0 ] || ! printf '%s' "$summary" | grep -q ', 0 failed'; then
		echo "FAIL  $f [$mode] (${summary:-no summary})"
		printf '%s\n' "$out" | awk '/^FAIL/ && n++ < 20'
		fail=1
	else
		echo "PASS  $f [$mode] ($summary)"
	fi
done
if ! denylist_restore; then
	echo "FAIL  ewpa_thirdparty_disabled not restored"
	fail=1
fi
trap - EXIT INT TERM
# No connection plugin: deactivate Enable Abilities for MCP in this step and reactivate it on every
# exit path, so the test's fresh process sees the `none` state.
none_active=0
if npx wp-env run cli wp plugin is-active enable-abilities-for-mcp >/dev/null 2>&1; then
	none_active=1
fi
none_restore() {
	[ "$none_active" -eq 1 ] || return 0
	npx wp-env run cli wp plugin activate enable-abilities-for-mcp >/dev/null 2>&1 \
		&& npx wp-env run cli wp plugin is-active enable-abilities-for-mcp >/dev/null 2>&1
}
trap 'none_restore; exit 130' INT TERM
trap none_restore EXIT
f=tests/Integration/test-setup-checks.php
if [ "$none_active" -eq 1 ] && npx wp-env run cli wp plugin deactivate enable-abilities-for-mcp >/dev/null 2>&1; then
	out=$(npx wp-env run cli env BLZMCP_SKIP_HTTP=1 BLZMCP_FIXTURE_ENV="${BLZMCP_FIXTURE_ENV:-}" BLZMCP_STATE_NONE=1 wp eval-file "wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/$f" 2>&1)
	status=$?
else
	out='FAIL  Enable Abilities for MCP was not active, or could not be deactivated'
	status=1
fi
if ! none_restore; then
	echo "FAIL  enable-abilities-for-mcp not reactivated"
	fail=1
fi
trap - EXIT INT TERM
summary=$(printf '%s\n' "$out" | grep -E '^[0-9]+ passed, [0-9]+ failed' | tail -1)
if [ "$status" -ne 0 ] || ! printf '%s' "$summary" | grep -q ', 0 failed' || printf '%s' "$out" | grep -q 'Fatal error'; then
	echo "FAIL  $f [none] (${summary:-no summary})"
	printf '%s\n' "$out" | awk '/^FAIL|Fatal error/ && n++ < 20'
	fail=1
else
	echo "PASS  $f [none] ($summary)"
fi
# Host-side scripts run under /bin/bash when it exists: on macOS that is bash 3.2, the shell swamp's
# `bash -lc` gate resolves to, so a bash-4-only construct fails here first. The self-tests run their
# scripts under the same bash ($BASH).
host_bash=/bin/bash
[ -x "$host_bash" ] || host_bash=$BASH
if "$host_bash" tests/Integration/frontend-curl.sh; then :; else fail=1; fi
# The matrix's own host scripts and in-container helpers parse (1.2.1 item 5): the matrix is the
# only place they run, so a syntax error must fail here first. http-suite.sh re-runs itself under
# bash 4+, the others must parse under the host bash (3.2 on macOS).
parse_fail=''
for f in tests/matrix/run-matrix.sh tests/matrix/record.sh tests/matrix/perf-suite.sh tests/matrix/fake-wp-env.sh; do
	"$host_bash" -n "$f" 2>/dev/null || parse_fail="$parse_fail $f"
done
modern_bash=''
for b in "$BASH" /opt/homebrew/bin/bash /usr/local/bin/bash; do
	[ -x "$b" ] && [ "$("$b" -c 'echo "${BASH_VERSINFO[0]}"' 2>/dev/null)" -ge 4 ] 2>/dev/null && { modern_bash=$b; break; }
done
if [ -n "$modern_bash" ]; then
	"$modern_bash" -n tests/matrix/http-suite.sh 2>/dev/null || parse_fail="$parse_fail tests/matrix/http-suite.sh"
else
	parse_fail="$parse_fail tests/matrix/http-suite.sh(no-bash-4)"
fi
for f in tests/matrix/*.php; do
	php -l "$f" >/dev/null 2>&1 || parse_fail="$parse_fail $f"
done
if [ -z "$parse_fail" ]; then
	echo "PASS  matrix scripts and helpers parse ($host_bash${modern_bash:+, $modern_bash}, php -l)"
else
	echo "FAIL  matrix scripts and helpers parse:$parse_fail"
	fail=1
fi
# The matrix recorder's zip inventory and byte-identity check (host-side, no wp-env).
if rec_out=$("$host_bash" tests/matrix/test-record.sh 2>&1); then
	echo "PASS  $host_bash tests/matrix/test-record.sh ($(printf '%s\n' "$rec_out" | tail -1))"
else
	echo "FAIL  $host_bash tests/matrix/test-record.sh"
	printf '%s\n' "$rec_out" | awk '/^FAIL|unbound variable|invalid option|syntax error/ && n++ < 20'
	fail=1
fi
# The performance gate's own self-tests, the release-zip exclusion canaries and the build-once
# promotion tooling (artifact checker, release-candidate.sh, deploy.yml checks; offline stubs).
for t in "php tests/matrix/test-perf-validate.php" "$host_bash tests/matrix/test-perf-suite.sh" "$host_bash tests/matrix/test-distignore.sh" "$host_bash tests/matrix/test-http-checks.sh" "$host_bash tests/release/test-promotion.sh"; do
	if t_out=$($t 2>&1); then
		echo "PASS  $t ($(printf '%s\n' "$t_out" | tail -1))"
	else
		echo "FAIL  $t"
		printf '%s\n' "$t_out" | awk '/^FAIL/ && n++ < 20'
		fail=1
	fi
done
[ "$fail" -eq 0 ] && echo "ALL INTEGRATION CHECKS PASSED" || echo "INTEGRATION CHECKS FAILED"
echo "FAST CHECKS ONLY — NOT THE RELEASE GATE"
exit $fail
