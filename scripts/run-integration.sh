#!/usr/bin/env bash
# Release gate: runs every integration test file inside wp-env, then the host-side front-end
# check, and fails if any of them fails.
#
#   bash scripts/run-integration.sh
#
# Needs wp-env running (npx wp-env start). The container cannot fetch its own pages, so
# test-no-frontend.php runs with BLZMCP_SKIP_HTTP=1 here and tests/Integration/frontend-curl.sh
# does the HTTP half from the host. Running test-no-frontend.php alone without that variable
# fails with "HTTP CHECK NOT RUN" when the fetches cannot connect.
set -uo pipefail
cd "$(dirname "$0")/.."
fail=0
for f in tests/Integration/test-*.php; do
	out=$(npx wp-env run cli env BLZMCP_SKIP_HTTP=1 wp eval-file "wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/$f" 2>&1)
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
# Stand-aside mode (WooCommerce for Claude stub constant loaded before plugins).
for f in tests/Integration/test-bundled.php tests/Integration/test-workflows.php tests/Integration/test-analytics-naming.php; do
	out=$(npx wp-env run cli env BLZMCP_SKIP_HTTP=1 wp --require=wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/stub-wcfc.php eval-file "wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/$f" 2>&1)
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
for f in tests/Integration/test-connectors.php tests/Integration/test-connector-availability.php tests/Integration/test-workflows.php tests/Integration/test-analytics-naming.php tests/Integration/test-setup-checks.php; do
	out=$(npx wp-env run cli env BLZMCP_SKIP_HTTP=1 wp --require=wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/stub-easymcp.php eval-file "wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/$f" 2>&1)
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
# Stand-aside plus the Easy MCP AI stub: analytics naming must hold whoever supplies wc-analytics/*.
for f in tests/Integration/test-analytics-naming.php; do
	out=$(npx wp-env run cli env BLZMCP_SKIP_HTTP=1 wp --require=wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/stub-wcfc.php --require=wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/stub-easymcp.php eval-file "wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/$f" 2>&1)
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
	out=$(npx wp-env run cli env BLZMCP_SKIP_HTTP=1 wp --require=wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/stub-easymcp.php --require=wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/stub-easymcp-toolstate.php eval-file "wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/$f" 2>&1)
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
		out=$(npx wp-env run cli env BLZMCP_SKIP_HTTP=1 BLZMCP_EWPA_DENYLIST=1 wp $extra eval-file "wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/$f" 2>&1)
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
	out=$(npx wp-env run cli env BLZMCP_SKIP_HTTP=1 BLZMCP_STATE_NONE=1 wp eval-file "wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/$f" 2>&1)
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
if bash tests/Integration/frontend-curl.sh; then :; else fail=1; fi
[ "$fail" -eq 0 ] && echo "ALL INTEGRATION CHECKS PASSED" || echo "INTEGRATION CHECKS FAILED"
exit $fail
