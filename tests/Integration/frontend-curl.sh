#!/usr/bin/env bash
# Host-side half of test-no-frontend.php (the cli container cannot reach localhost:8888).
# Fetches the home, shop, a product and the cart from the host and fails if any response
# (body or headers) contains "blzmcp" or "blaze-mcp-toolkit". The dev site title is the plugin
# folder name, so the title is changed for the run and restored afterwards; a temporary product is
# created if the store has none.
#   bash tests/Integration/frontend-curl.sh
set -uo pipefail
wp() { npx wp-env run cli wp "$@" 2>/dev/null; }
if ! wp plugin is-active blaze-mcp-toolkit-for-woocommerce; then
	echo "FAIL  blaze-mcp-toolkit-for-woocommerce is not active (or wp-env is not running)"
	exit 1
fi
orig_title=$(wp option get blogname | tail -1)
if [ -z "$orig_title" ]; then
	echo "FAIL  could not read blogname; aborting before changing anything"
	exit 1
fi
pid=""
cleanup() {
	wp option update blogname "$orig_title" >/dev/null
	[ -n "$pid" ] && wp post delete "$pid" --force >/dev/null
	return 0
}
trap cleanup EXIT
wp option update blogname "Footprint Test Store" >/dev/null
existing=$(wp post list --post_type=product --post_status=publish --field=ID --posts_per_page=1 | head -1)
if [ -z "$existing" ]; then
	pid=$(wp post create --post_type=product --post_status=publish --post_title="Footprint product" --porcelain | tail -1)
	existing=$pid
fi
urls=$(wp eval "echo implode(\"\n\", array( home_url('/'), wc_get_page_permalink('shop'), get_permalink( ${existing} ), wc_get_cart_url() ));" | grep '^http')
fail=0
count=0
while IFS= read -r url; do
	out=$(curl -sS -L -D - -w '\n%{http_code}' --max-time 30 "$url" || true)
	code=$(printf '%s' "$out" | tail -1)
	out=$(printf '%s' "$out" | sed '$d')
	len=${#out}
	hits=$(printf '%s' "$out" | grep -ci 'blzmcp\|blaze-mcp-toolkit' || true)
	count=$((count + 1))
	if [ "$code" != "200" ] || [ "$len" -lt 500 ] || [ "$hits" != "0" ]; then echo "FAIL  $url (http=$code, bytes=$len, hits=$hits)"; fail=1; else echo "PASS  $url (http=200, bytes=$len, no trace)"; fi
done <<< "$urls"
[ "$count" -ge 4 ] || { echo "FAIL  only $count URLs"; fail=1; }
exit $fail
