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
		printf '%s\n' "$out" | grep -E '^FAIL' | head -20
		fail=1
	else
		echo "PASS  $f ($summary)"
	fi
done
# Stand-aside mode (WooCommerce for Claude stub constant loaded before plugins).
for f in tests/Integration/test-bundled.php tests/Integration/test-workflows.php; do
	out=$(npx wp-env run cli env BLZMCP_SKIP_HTTP=1 wp --require=wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/stub-wcfc.php eval-file "wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/$f" 2>&1)
	status=$?
	summary=$(printf '%s\n' "$out" | grep -E '^[0-9]+ passed, [0-9]+ failed' | tail -1)
	if [ "$status" -ne 0 ] || ! printf '%s' "$summary" | grep -q ', 0 failed'; then
		echo "FAIL  $f [stand-aside] (${summary:-no summary})"
		printf '%s\n' "$out" | grep -E '^FAIL' | head -20
		fail=1
	else
		echo "PASS  $f [stand-aside] ($summary)"
	fi
done
if bash tests/Integration/frontend-curl.sh; then :; else fail=1; fi
[ "$fail" -eq 0 ] && echo "ALL INTEGRATION CHECKS PASSED" || echo "INTEGRATION CHECKS FAILED"
exit $fail
