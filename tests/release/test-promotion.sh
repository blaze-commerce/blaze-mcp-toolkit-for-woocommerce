#!/usr/bin/env bash
# Offline tests for the build-once promotion path (FEATURE-PLAN 1.2.1 item 7):
#   1. scripts/check-release-artifact.py against this repository's HEAD: the expected inventory
#      agrees with rsync --exclude-from=.distignore, a faithful package passes with a sorted,
#      complete manifest, and every unsafe or wrong package fails (traversal, absolute and sibling
#      paths, duplicates, symlinks, special entries, .git/.svn, excluded material, missing,
#      unexpected and changed files, encrypted entries, prepended data, empty directories).
#   2. The deploy request, tag annotation and draft-release selection checks, with fixtures.
#   3. scripts/release-candidate.sh in a throwaway fixture repository with a local bare origin,
#      a stub build-zip.sh, a stub gh and a composer that must never run: argument and
#      destination guards, build once, never overwrite, --candidate verification and retry after
#      a failed upload, digest and source changes, published/ambiguous releases, existing tags.
#   4. Static checks on deploy.yml, ci.yml, run-integration.sh and .distignore.
# No network, no GitHub, no wp-env. Writes only to a temporary directory. Runs under bash 3.2.
#
#   bash tests/release/test-promotion.sh
set -uo pipefail

REPO=$(cd "$(dirname "$0")/../.." && pwd)
HERE="$REPO/tests/release"
CHECK="$REPO/scripts/check-release-artifact.py"
PY=${BLZMCP_PYTHON:-python3}
PKG=blaze-mcp-toolkit-for-woocommerce
WORK=$(mktemp -d "${TMPDIR:-/tmp}/blzmcp-promotion.XXXXXX")
WORK=$(cd "$WORK" && pwd -P)
trap 'chmod -R u+w "$WORK" 2>/dev/null; rm -rf "$WORK"' EXIT
pass=0
fail=0
LAST=''

# BLZMCP_VERBOSE=1 lists every passing check (for receipts).
ok() {
	pass=$((pass + 1))
	if [ -n "${BLZMCP_VERBOSE:-}" ]; then echo "PASS  $1"; fi
}
bad() {
	echo "FAIL  $*"
	fail=$((fail + 1))
}
# expect <label> <exit code> <command...>: runs it, keeps its output in $LAST.
expect() {
	local label=$1 want=$2 got
	shift 2
	LAST=$("$@" 2>&1)
	got=$?
	if [ "$got" -eq "$want" ]; then ok "$label"; else bad "$label (exit $got, want $want): $(printf '%s' "$LAST" | tail -3 | tr '\n' ' ')"; fi
}
# has <label> <extended regex>: $LAST matches.
has() {
	if printf '%s\n' "$LAST" | grep -qE -- "$2"; then ok "$1"; else bad "$1: output lacks /$2/: $(printf '%s' "$LAST" | tail -3 | tr '\n' ' ')"; fi
}
truthy() {
	local label=$1
	shift
	if "$@"; then ok "$label"; else bad "$label"; fi
}
sha_of() { shasum -a 256 "$1" | cut -d' ' -f1; }
chk() { "$PY" "$CHECK" "$@"; }

export GIT_CONFIG_NOSYSTEM=1
export GIT_CONFIG_GLOBAL="$WORK/gitconfig"
printf '[user]\n\tname = Promotion test\n\temail = test@example.invalid\n[init]\n\tdefaultBranch = main\n[commit]\n\tgpgsign = false\n[tag]\n\tgpgsign = false\n' >"$GIT_CONFIG_GLOBAL"

# ---------------------------------------------------------------------------------------------
# 1. Artifact checker against this repository's HEAD.
HEAD=$(git -C "$REPO" rev-parse HEAD)
HEAD_VERSION=$(git -C "$REPO" show "$HEAD:$PKG.php" | sed -n 's/^ \* Version:[[:space:]]*//p' | head -1 | tr -d '\r')
mkdir -p "$WORK/arch" "$WORK/rs"
git -C "$REPO" archive "$HEAD" | tar -x -C "$WORK/arch"
mkdir -p "$WORK/arch/vendor/woocommerce"
cp -R "$WORK/arch/vendor-src/commerce-abilities" "$WORK/arch/vendor/woocommerce/commerce-abilities"
rsync -a --exclude-from="$WORK/arch/.distignore" "$WORK/arch/" "$WORK/rs/"
(cd "$WORK/rs" && find . -type f | sed 's|^\./||' | LC_ALL=C sort) >"$WORK/rsync.txt"
chk expected --repo "$REPO" --source-sha "$HEAD" | LC_ALL=C sort >"$WORK/expected.txt"
if [ -s "$WORK/expected.txt" ] && cmp -s "$WORK/rsync.txt" "$WORK/expected.txt"; then ok "expected inventory agrees with rsync --exclude-from=.distignore"; else
	bad "expected inventory differs from rsync --exclude-from=.distignore: $(diff "$WORK/rsync.txt" "$WORK/expected.txt" | head -5 | tr '\n' ' ')"
fi

# A faithful package: the source inventory plus Composer's generated runtime.
GOOD="$WORK/pkg"
mkdir -p "$GOOD/$PKG"
cp -R "$WORK/rs/." "$GOOD/$PKG/"
mkdir -p "$GOOD/$PKG/vendor/composer"
for f in autoload.php composer/autoload_real.php composer/autoload_static.php composer/ClassLoader.php composer/installed.php; do
	printf '<?php // composer %s\n' "$f" >"$GOOD/$PKG/vendor/$f"
done
"$PY" "$HERE/make_zip.py" "$WORK/good.zip" "$GOOD"
GOOD_SHA=$(sha_of "$WORK/good.zip")
expect "faithful package passes" 0 chk check "$WORK/good.zip" --version "$HEAD_VERSION" --repo "$REPO" --source-sha "$HEAD" --sha256 "$GOOD_SHA" --manifest-out "$WORK/good.manifest" --extract-to "$WORK/good.x"
has "check prints the digest" "^SHA256=$GOOD_SHA$"
nfiles=$(cd "$GOOD/$PKG" && find . -type f | wc -l | tr -d ' ')
has "check counts every file" "^FILES=$nfiles$"
truthy "manifest is complete and sorted" test "$(wc -l <"$WORK/good.manifest" | tr -d ' ')" = "$nfiles"
truthy "manifest is in byte order" cmp -s "$WORK/good.manifest" <(LC_ALL=C sort -k2 "$WORK/good.manifest")
truthy "manifest lines are <sha256>  <path>" test -z "$(grep -vE '^[0-9a-f]{64}  [^ ]+$' "$WORK/good.manifest")"
truthy "manifest includes generated Composer PHP" grep -qE '  vendor/composer/autoload_static\.php$' "$WORK/good.manifest"
want_line="$(sha_of "$GOOD/$PKG/readme.txt")  readme.txt"
truthy "manifest hash matches the file" grep -qxF "$want_line" "$WORK/good.manifest"
expect "manifest-dir of the extraction matches" 0 chk manifest-dir "$WORK/good.x" --expect "$WORK/good.manifest"
expect "manifest-out never overwrites" 1 chk check "$WORK/good.zip" --version "$HEAD_VERSION" --repo "$REPO" --source-sha "$HEAD" --manifest-out "$WORK/good.manifest"
expect "extract-to refuses an existing directory" 1 chk check "$WORK/good.zip" --version "$HEAD_VERSION" --repo "$REPO" --source-sha "$HEAD" --extract-to "$WORK/good.x"
expect "wrong digest fails" 1 chk check "$WORK/good.zip" --version "$HEAD_VERSION" --repo "$REPO" --source-sha "$HEAD" --sha256 "$(printf '0%.0s' $(seq 1 64))"
has "wrong digest names it" "sha256 is $GOOD_SHA"
expect "malformed digest fails" 1 chk check "$WORK/good.zip" --version "$HEAD_VERSION" --repo "$REPO" --source-sha "$HEAD" --sha256 "${GOOD_SHA}0"
expect "wrong version fails" 1 chk check "$WORK/good.zip" --version 99.0.0 --repo "$REPO" --source-sha "$HEAD"
has "wrong version names the identifier" "plugin header Version is"
expect "short source sha fails" 1 chk check "$WORK/good.zip" --version "$HEAD_VERSION" --repo "$REPO" --source-sha "${HEAD:0:12}"
expect "unknown commit fails" 1 chk check "$WORK/good.zip" --version "$HEAD_VERSION" --repo "$REPO" --source-sha "$(printf 'a%.0s' $(seq 1 40))"
printf 'not a zip' >"$WORK/not.zip"
expect "non-zip fails" 1 chk check "$WORK/not.zip" --version "$HEAD_VERSION" --repo "$REPO" --source-sha "$HEAD"

first_src=$(grep -E '^src/' "$WORK/expected.txt" | head -1)
first_vendor=$(grep -E '^vendor/woocommerce/' "$WORK/expected.txt" | head -1)
n=0
while IFS='|' read -r label regex ops; do
	[ -n "$label" ] || continue
	n=$((n + 1))
	"$PY" "$HERE/make_zip.py" "$WORK/bad$n.zip" "$GOOD" "$ops"
	expect "rejects: $label" 1 chk check "$WORK/bad$n.zip" --version "$HEAD_VERSION" --repo "$REPO" --source-sha "$HEAD" --extract-to "$WORK/bad$n.x"
	has "rejects: $label (reason)" "$regex"
	truthy "rejects: $label (nothing extracted)" test ! -e "$WORK/bad$n.x"
done <<EOF
path traversal|'\\.\\.' path component|[["add", "$PKG/../evil.php", "x"]]
absolute path|absolute or non-portable|[["add", "/$PKG/evil.php", "x"]]
backslash path|absolute or non-portable|[["add", "$PKG\\\\evil.php", "x"]]
drive-letter path|absolute or non-portable|[["add", "C:/$PKG/evil.php", "x"]]
sibling root|outside the $PKG/ root|[["add", "other/evil.php", "x"]]
file beside the root|outside the $PKG/ root|[["add", "evil.php", "x"]]
duplicate entry|duplicate entry|[["dup", "$PKG/readme.txt"]]
case-only duplicate|differ only by case|[["add", "$PKG/README.txt", "x"]]
symlink entry|is a symlink|[["symlink", "$PKG/link.php", "/etc/passwd"]]
FIFO entry|special entry|[["fifo", "$PKG/pipe"]]
.git metadata|version-control metadata|[["add", "$PKG/.git/config", "x"]]
.svn metadata|version-control metadata|[["add", "$PKG/src/.svn/entries", "x"]]
non-ASCII name|unsupported characters|[["add", "$PKG/caf\u00e9.php", "x"]]
excluded file|excluded by .distignore|[["add", "$PKG/debug.log", "x"]]
excluded directory|excluded by .distignore|[["add", "$PKG/tests/x.php", "x"]]
unexpected vendor file|no source at|[["add", "$PKG/vendor/evil/x.php", "x"]]
unexpected top-level file|no source at|[["add", "$PKG/extra.php", "x"]]
missing required file|uninstall.php: missing|[["drop", "$PKG/uninstall.php"]]
missing source file|missing from the package|[["drop", "$PKG/$first_src"]]
same-size byte change|bytes differ|[["flip", "$PKG/LICENSE.txt"]]
changed vendored file|bytes differ|[["flip", "$PKG/$first_vendor"]]
missing Composer runtime|Composer runtime file missing|[["drop", "$PKG/vendor/composer/autoload_real.php"]]
encrypted entry|encrypted|[["encrypt", "$PKG/readme.txt"]]
data before the archive|data before its first entry|[["prepend", "junk"]]
empty directory|empty directory|[["dir", "$PKG/empty/"]]
file and directory clash|both a file and a directory|[["add", "$PKG/readme.txt/x", "x"]]
EOF

# The extracted tree is re-checked right before publishing: any change after extraction fails.
cp -R "$WORK/good.x" "$WORK/mut1"
printf 'x' >>"$WORK/mut1/readme.txt"
expect "manifest-dir: changed file" 1 chk manifest-dir "$WORK/mut1" --expect "$WORK/good.manifest"
has "manifest-dir: changed file (reason)" "changed: readme.txt"
cp -R "$WORK/good.x" "$WORK/mut2"
printf 'x' >"$WORK/mut2/extra.php"
expect "manifest-dir: extra file" 1 chk manifest-dir "$WORK/mut2" --expect "$WORK/good.manifest"
has "manifest-dir: extra file (reason)" "extra: extra.php"
cp -R "$WORK/good.x" "$WORK/mut3"
rm -f "$WORK/mut3/vendor/composer/autoload_static.php"
expect "manifest-dir: missing Composer file" 1 chk manifest-dir "$WORK/mut3" --expect "$WORK/good.manifest"
has "manifest-dir: missing Composer file (reason)" "missing: vendor/composer/autoload_static.php"
cp -R "$WORK/good.x" "$WORK/mut4"
ln -s /etc/hosts "$WORK/mut4/link.php"
expect "manifest-dir: symlink" 1 chk manifest-dir "$WORK/mut4" --expect "$WORK/good.manifest"
cp -R "$WORK/good.x" "$WORK/mut5"
mkdir "$WORK/mut5/.svn"
expect "manifest-dir: empty directory" 1 chk manifest-dir "$WORK/mut5" --expect "$WORK/good.manifest"

# ---------------------------------------------------------------------------------------------
# 2. Deploy request, tag annotation and draft release selection.
H64=$(printf 'ab%.0s' $(seq 1 32))
C40=$(printf 'c%.0s' $(seq 1 40))
expect "push of a final tag deploys" 0 chk tag-info --event push --tag v1.2.1
has "push is not a dry run" "^DRY_RUN=false$"
has "push version" "^VERSION=1\.2\.1$"
expect "push of an rc tag never deploys" 1 chk tag-info --event push --tag v1.2.1-rc1
has "push of an rc tag (reason)" "pre-release tag pushes never deploy"
expect "push refuses a digest input" 1 chk tag-info --event push --tag v1.2.1 --sha256 "$H64"
expect "dispatch of an rc tag is a dry run" 0 chk tag-info --event workflow_dispatch --tag v1.2.1-rc1 --sha256 "$H64" --dry-run-input true
has "dispatch rc dry run" "^DRY_RUN=true$"
has "dispatch rc rehearses the final release" "^RELEASE_TAG=v1\.2\.1$"
expect "dispatch of a final tag is a dry run" 0 chk tag-info --event workflow_dispatch --tag v1.2.1 --sha256 "$H64" --dry-run-input true
has "dispatch final dry run" "^DRY_RUN=true$"
expect "dispatch with dry_run=false is refused" 1 chk tag-info --event workflow_dispatch --tag v1.2.1 --sha256 "$H64" --dry-run-input false
has "dispatch with dry_run=false (reason)" "never publishes"
expect "unknown event refused" 1 chk tag-info --event schedule --tag v1.2.1
# shellcheck disable=SC2016 # literal $(id): the tag must be refused as data, never run
for t in 'v1.2' 'v01.2.1' 'v1.2.1-rc0' 'v1.2.1-beta1' 'v1.2.1-rc1-x' '1.2.1' 'v1.2.1 ' 'v1.2.1$(id)' 'v1.2.1;id' "$(printf 'v1.2.1\n')x" "$(printf 'v1.2.1\nv1.2.2')"; do
	expect "tag grammar refuses '$t'" 1 chk tag-info --event workflow_dispatch --tag "$t" --sha256 "$H64" --dry-run-input true
done
expect "tag grammar refuses a trailing newline" 1 chk tag-info --event push --tag "v1.2.1
"
for s in '' "${H64}0" "${H64:1}" "$(printf 'AB%.0s' $(seq 1 32))" "${H64:2};echo" "$(printf '%s\n' "$H64")x"; do
	expect "digest grammar refuses '$s'" 1 chk tag-info --event workflow_dispatch --tag v1.2.1 --sha256 "$s" --dry-run-input true
done

TOBJ=$(printf 'd%.0s' $(seq 1 40))
ref_json() { printf '{"ref":"refs/tags/%s","object":{"type":"%s","sha":"%s"}}' "$1" "$2" "$TOBJ" >"$WORK/ref.json"; }
tag_json() { "$PY" -c 'import json,sys; print(json.dumps({"sha":sys.argv[1],"tag":sys.argv[2],"object":{"type":"commit","sha":sys.argv[3]},"message":sys.argv[4]}))' "$TOBJ" "$1" "$2" "$3" >"$WORK/tag.json"; }
annot() { chk tag-annotation --tag v1.2.1 --commit "$C40" --ref-json "$WORK/ref.json" --tag-json "$WORK/tag.json"; }
ref_json v1.2.1 tag
tag_json v1.2.1 "$C40" "$(printf 'Release 1.2.1\n\ncandidate-sha256: %s\nsource-sha: %s\n' "$H64" "$C40")"
expect "annotated tag with one digest and source passes" 0 annot
has "annotation digest" "^WANT_SHA=$H64$"
ref_json v1.2.1 commit
expect "lightweight tag refused" 1 annot
has "lightweight tag (reason)" "lightweight"
ref_json v1.2.1 tag
tag_json v1.2.1 "$C40" "$(printf 'candidate-sha256: %s\ncandidate-sha256: %s\nsource-sha: %s\n' "$H64" "$H64" "$C40")"
expect "two digest lines refused" 1 annot
tag_json v1.2.1 "$C40" "$(printf 'candidate-sha256: %s\nsource-sha: %s\nsource-sha: %s\n' "$H64" "$C40" "$C40")"
expect "two source lines refused" 1 annot
tag_json v1.2.1 "$C40" "$(printf 'source-sha: %s\n' "$C40")"
expect "missing digest line refused" 1 annot
tag_json v1.2.1 "$C40" "$(printf 'candidate-sha256: %s\n' "$H64")"
expect "missing source line refused" 1 annot
tag_json v1.2.1 "$C40" "$(printf 'candidate-sha256: %s\nsource-sha: %s\n' "$H64" "$TOBJ")"
expect "source-sha other than the commit refused" 1 annot
tag_json v1.2.1 "$C40" "$(printf 'candidate-sha256: %s\nsource-sha: %s\n' "$(printf 'AB%.0s' $(seq 1 32))" "$C40")"
expect "uppercase digest refused" 1 annot
tag_json v1.2.1 "$C40" "$(printf 'candidate-sha256: %s extra\nsource-sha: %s\n' "$H64" "$C40")"
expect "trailing text on the digest line refused" 1 annot
tag_json v1.2.1 "$TOBJ" "$(printf 'candidate-sha256: %s\nsource-sha: %s\n' "$H64" "$C40")"
expect "tag pointing at another commit refused" 1 annot
tag_json v1.2.2 "$C40" "$(printf 'candidate-sha256: %s\nsource-sha: %s\n' "$H64" "$C40")"
expect "tag object of another name refused" 1 annot

ASSET="$PKG-1.2.1.zip"
rel() { # rel <file> <python list literal of releases>
	"$PY" -c 'import json,sys; print(json.dumps(eval(sys.argv[1])))' "$2" >"$1"
}
state() { chk release-state --releases-json "$WORK/r.json" --version 1.2.1 --source-sha "$C40" --sha256 "$H64" "$@"; }
A_OK="{'id': 7, 'name': '$ASSET', 'state': 'uploaded', 'digest': 'sha256:$H64'}"
rel "$WORK/r.json" "[{'id': 5, 'tag_name': 'v1.2.1', 'draft': True, 'target_commitish': '$C40', 'assets': [$A_OK]}]"
expect "staged draft selected" 0 state --require-staged
has "staged state" "^STATE=staged$"
has "release id" "^RELEASE_ID=5$"
has "asset id" "^ASSET_ID=7$"
# Two pages (gh api --paginate prints one array per page).
printf '[{"id": 4, "tag_name": "v1.2.0", "draft": false, "target_commitish": "%s", "assets": []}]\n' "$C40" >"$WORK/r2.json"
cat "$WORK/r.json" >>"$WORK/r2.json"
expect "paginated release list" 0 chk release-state --releases-json "$WORK/r2.json" --version 1.2.1 --source-sha "$C40" --sha256 "$H64" --require-staged
while IFS='|' read -r label want releases; do
	[ -n "$label" ] || continue
	rel "$WORK/r.json" "$releases"
	expect "release selection: $label" 1 state --require-staged
	has "release selection: $label (state)" "$want"
done <<EOF
absent|\(none\)|[]
published|\(published\)|[{'id': 5, 'tag_name': 'v1.2.1', 'draft': False, 'target_commitish': '$C40', 'assets': [$A_OK]}]
ambiguous|\(ambiguous\)|[{'id': 5, 'tag_name': 'v1.2.1', 'draft': True, 'target_commitish': '$C40', 'assets': [$A_OK]}, {'id': 6, 'tag_name': 'v1.2.1', 'draft': True, 'target_commitish': '$C40', 'assets': []}]
wrong target|\(draft-mismatch\)|[{'id': 5, 'tag_name': 'v1.2.1', 'draft': True, 'target_commitish': 'main', 'assets': [$A_OK]}]
no asset|\(draft-empty\)|[{'id': 5, 'tag_name': 'v1.2.1', 'draft': True, 'target_commitish': '$C40', 'assets': []}]
wrong asset name|\(draft-mismatch\)|[{'id': 5, 'tag_name': 'v1.2.1', 'draft': True, 'target_commitish': '$C40', 'assets': [{'id': 7, 'name': 'candidate.zip', 'state': 'uploaded', 'digest': 'sha256:$H64'}]}]
two assets|\(draft-mismatch\)|[{'id': 5, 'tag_name': 'v1.2.1', 'draft': True, 'target_commitish': '$C40', 'assets': [$A_OK, {'id': 8, 'name': 'notes.txt', 'state': 'uploaded'}]}]
changed bytes|\(draft-mismatch\)|[{'id': 5, 'tag_name': 'v1.2.1', 'draft': True, 'target_commitish': '$C40', 'assets': [{'id': 7, 'name': '$ASSET', 'state': 'uploaded', 'digest': 'sha256:$(printf 'e%.0s' $(seq 1 64))'}]}]
incomplete upload|\(draft-empty\)|[{'id': 5, 'tag_name': 'v1.2.1', 'draft': True, 'target_commitish': '$C40', 'assets': [{'id': 7, 'name': '$ASSET', 'state': 'starter'}]}]
EOF
rel "$WORK/r.json" "[{'id': 5, 'tag_name': 'v1.2.1', 'draft': True, 'target_commitish': '$C40', 'assets': [{'id': 7, 'name': '$ASSET', 'state': 'uploaded'}]}]"
expect "no API digest: deploy must download and hash" 0 state --require-staged
has "no API digest state" "^STATE=staged-unverified$"

expect "guard: the checkout is refused" 1 chk guard-dest --root "$REPO" --path "$REPO" --kind dir
expect "guard: dist is refused" 1 chk guard-dest --root "$REPO" --path "$REPO/dist" --kind dir
expect "guard: a path inside the checkout is refused" 1 chk guard-dest --root "$REPO" --path "$REPO/src/x/y" --kind dir
ln -s "$REPO/dist" "$WORK/to-dist"
expect "guard: a symlink into dist is refused" 1 chk guard-dest --root "$REPO" --path "$WORK/to-dist/candidates" --kind dir
expect "guard: a sibling with the same prefix is allowed" 0 chk guard-dest --root "$REPO" --path "$REPO-candidates" --kind dir
expect "guard: outside the checkout is allowed" 0 chk guard-dest --root "$REPO" --path "$WORK/candidates" --kind dir

# ---------------------------------------------------------------------------------------------
# 3. release-candidate.sh in a fixture repository (local bare origin, stub gh/build/composer).
FX="$WORK/fx"
V=9.8.7
mkdir -p "$FX/src" "$FX/scripts" "$FX/vendor-src/commerce-abilities/src"
printf '<?php\n/**\n * Plugin Name:       Fixture\n * Version:           %s\n */\n\ndefined( '"'ABSPATH'"' ) || exit;\n\ndefine( '"'BLZMCP_VERSION'"', '"'%s'"' );\n' "$V" "$V" >"$FX/$PKG.php"
printf '=== Fixture ===\nStable tag: %s\n' "$V" >"$FX/readme.txt"
printf '<?php // uninstall\n' >"$FX/uninstall.php"
printf '{"name": "fixture/fixture"}\n' >"$FX/composer.json"
printf 'GPL\n' >"$FX/LICENSE.txt"
printf '<?php // plugin\n' >"$FX/src/Plugin.php"
printf '<?php // vendored\n' >"$FX/vendor-src/commerce-abilities/src/A.php"
for f in UPSTREAM composer.lock package.json composer.json LICENSE; do printf '%s\n' "$f" >"$FX/vendor-src/commerce-abilities/$f"; done
cp "$REPO/.distignore" "$REPO/.gitignore" "$FX/"
cp "$REPO/scripts/release-candidate.sh" "$REPO/scripts/check-release-artifact.py" "$FX/scripts/"
cp "$HERE/fixture-build-zip.sh" "$FX/scripts/build-zip.sh"
git -C "$FX" init -q
git -C "$FX" add -A
git -C "$FX" commit -qm fixture
git init -q --bare "$WORK/origin.git"
git -C "$FX" remote add origin "$WORK/origin.git"
git -C "$FX" push -q origin main
mkdir -p "$WORK/bin"
printf '#!/bin/sh\nexec "%s" "%s" "$@"\n' "$PY" "$HERE/gh_stub.py" >"$WORK/bin/gh"
printf '#!/bin/sh\necho "composer $*" >>"%s"\nexit 1\n' "$WORK/composer.log" >"$WORK/bin/composer"
chmod +x "$WORK/bin/gh" "$WORK/bin/composer"
export PATH="$WORK/bin:$PATH"
export BLZMCP_TEST_BUILD_LOG="$WORK/build.log"
: >"$BLZMCP_TEST_BUILD_LOG"
GH_STUB_STATE="$WORK/gh"
export GH_STUB_STATE
mkdir -p "$GH_STUB_STATE"
CANDS="$WORK/candidates"
rc() { BLZMCP_CANDIDATE_DIR="${CANDS_OVERRIDE:-$CANDS}" "$BASH" "$FX/scripts/release-candidate.sh" "$@"; }
builds() { wc -l <"$BLZMCP_TEST_BUILD_LOG" | tr -d ' '; }
gh_calls() { if [ -f "$GH_STUB_STATE/calls.log" ]; then grep -c "$1" "$GH_STUB_STATE/calls.log"; else echo 0; fi; }
asset_sha() { "$PY" -c 'import json,sys; r=[x for x in json.load(open(sys.argv[1])) if x["tag_name"]==sys.argv[2]]; print(r[0]["assets"][0]["digest"][7:] if r and r[0]["assets"] else "none")' "$GH_STUB_STATE/releases.json" "v$V"; }
draft_target() { "$PY" -c 'import json,sys; r=[x for x in json.load(open(sys.argv[1])) if x["tag_name"]==sys.argv[2]]; print(r[0]["target_commitish"])' "$GH_STUB_STATE/releases.json" "v$V"; }

for args in "" "1.2" "01.2.1" "9.8.7;id" "$V --bogus" "$V --candidate" "$V --candidate --upload" "$V --candidate=x" "$V --replace" "$V --sha256 $H64" "$V --candidate x --sha256 abc"; do
	# shellcheck disable=SC2086 # each case is a word list
	expect "usage refused: '$args'" 2 rc $args
done
truthy "usage errors build nothing" test "$(builds)" = 0
expect "version mismatch refused" 1 rc 9.8.8
has "version mismatch (reason)" "Stable tag is not 9.8.8"
sed -e "s/'BLZMCP_VERSION', '$V'/'BLZMCP_VERSION', '9.8.6'/" "$FX/$PKG.php" >"$WORK/main.php"
cp "$WORK/main.php" "$FX/$PKG.php"
git -C "$FX" commit -qam "constant mismatch"
expect "BLZMCP_VERSION mismatch refused" 1 rc "$V"
has "BLZMCP_VERSION mismatch (reason)" "BLZMCP_VERSION is not $V"
git -C "$FX" reset -q --hard HEAD~1
printf 'x' >"$FX/src/untracked.php"
expect "dirty tree refused" 1 rc "$V"
rm -f "$FX/src/untracked.php"
mkdir -p "$WORK/elsewhere"
ln -s "$FX/dist" "$WORK/elsewhere/dist-link"
ln -s "$FX" "$WORK/elsewhere/root-link"
for d in "$FX" "$FX/dist" "$FX/src/candidates" "$WORK/elsewhere/dist-link" "$WORK/elsewhere/root-link/x" "dist"; do
	CANDS_OVERRIDE="$d" expect "candidate directory refused: $d" 1 rc "$V"
done
truthy "refused destinations build nothing" test "$(builds)" = 0

FXHEAD=$(git -C "$FX" rev-parse HEAD)
ZIP="$CANDS/$PKG-$V-${FXHEAD:0:7}.zip"
expect "build once" 0 rc "$V"
ZSHA=$(sha_of "$ZIP")
has "build prints the digest" "^sha256: +$ZSHA$"
has "build prints the source" "^source-sha: +$FXHEAD$"
truthy "build ran once" test "$(builds)" = 1
truthy "candidate is read-only" test ! -w "$ZIP"
truthy "digest file is read-only" test ! -w "$ZIP.sha256"
truthy "digest file holds the digest" test "$(cut -d' ' -f1 "$ZIP.sha256")" = "$ZSHA"
truthy "build without --upload calls no gh" test "$(gh_calls .)" = 0
truthy "no stray partial files" test -z "$(find "$CANDS" -name '*.partial.*')"
expect "rebuild over a candidate refused" 1 rc "$V"
has "rebuild refusal points at --candidate" "--candidate"
truthy "rebuild refusal built nothing" test "$(builds)" = 1
truthy "candidate unchanged after refused rebuild" test "$(sha_of "$ZIP")" = "$ZSHA"

for mode in excluded differ dirty; do
	CANDS_OVERRIDE="$WORK/cand-$mode" BLZMCP_TEST_BUILD_MODE=$mode expect "broken build refused: $mode" 1 rc "$V"
	truthy "broken build left no candidate: $mode" test -z "$(find "$WORK/cand-$mode" -name '*.zip*' 2>/dev/null)"
done
git -C "$FX" checkout -q -- src/Plugin.php

b=$(builds)
expect "--candidate verifies without building" 0 rc "$V" --candidate "$ZIP"
has "--candidate prints the same digest" "^sha256: +$ZSHA$"
truthy "--candidate built nothing" test "$(builds)" = "$b"
expect "--candidate with a different --sha256 refused" 1 rc "$V" --candidate "$ZIP" --sha256 "$H64"
mkdir -p "$WORK/copies"
cp "$ZIP" "$WORK/copies/nosidecar.zip"
expect "--candidate without a reference digest refused" 1 rc "$V" --candidate "$WORK/copies/nosidecar.zip"
has "no reference digest (reason)" "No reference digest"
expect "--candidate with the right --sha256" 0 rc "$V" --candidate "$WORK/copies/nosidecar.zip" --sha256 "$ZSHA"
expect "--candidate with a wrong --sha256 refused" 1 rc "$V" --candidate "$WORK/copies/nosidecar.zip" --sha256 "$H64"
cp "$ZIP" "$WORK/copies/tampered.zip"
printf '%s  tampered.zip\n' "$H64" >"$WORK/copies/tampered.zip.sha256"
expect "--candidate whose digest file disagrees refused" 1 rc "$V" --candidate "$WORK/copies/tampered.zip"
mkdir -p "$FX/dist"
cp "$ZIP" "$FX/dist/inside.zip"
expect "--candidate inside dist refused" 1 rc "$V" --candidate "$FX/dist/inside.zip" --sha256 "$ZSHA"
ln -s "$FX/dist/inside.zip" "$WORK/copies/link.zip"
expect "--candidate symlinked into dist refused" 1 rc "$V" --candidate "$WORK/copies/link.zip" --sha256 "$ZSHA"
rm -f "$FX/dist/inside.zip"
mkdir -p "$WORK/fxpkg"
(cd "$WORK/fxpkg" && unzip -q "$ZIP")
"$PY" "$HERE/make_zip.py" "$WORK/copies/changed.zip" "$WORK/fxpkg" "[[\"flip\", \"$PKG/src/Plugin.php\"]]"
expect "--candidate that differs from the source refused" 1 rc "$V" --candidate "$WORK/copies/changed.zip" --sha256 "$(sha_of "$WORK/copies/changed.zip")"
"$PY" "$HERE/make_zip.py" "$WORK/copies/symlink.zip" "$WORK/fxpkg" "[[\"symlink\", \"$PKG/src/link.php\", \"/etc/passwd\"]]"
expect "--candidate with a symlink entry refused" 1 rc "$V" --candidate "$WORK/copies/symlink.zip" --sha256 "$(sha_of "$WORK/copies/symlink.zip")"
truthy "--candidate never ran composer" test ! -e "$WORK/composer.log"
truthy "--candidate checks built nothing" test "$(builds)" = "$b"

# Staging: an interrupted upload, then the retry with the same tested zip.
GH_STUB_FAIL=upload expect "failed upload reported" 1 rc "$V" --candidate "$ZIP" --upload
has "failed upload points at the retry" "retry with"
truthy "failed upload left a draft without an asset" test "$(asset_sha)" = none
truthy "failed upload left the candidate intact" test "$(sha_of "$ZIP")" = "$ZSHA"
truthy "failed upload left the digest file intact" test "$(cut -d' ' -f1 "$ZIP.sha256")" = "$ZSHA"
truthy "failed upload left the candidate read-only" test ! -w "$ZIP"
expect "retry uploads the same candidate" 0 rc "$V" --candidate "$ZIP" --upload
has "retry says it is the same candidate" "uploading the same candidate"
truthy "draft asset is the candidate" test "$(asset_sha)" = "$ZSHA"
truthy "draft targets the source" test "$(draft_target)" = "$FXHEAD"
ups=$(gh_calls 'release upload')
expect "staging again is a no-op" 0 rc "$V" --candidate "$ZIP" --upload
has "no-op says so" "already holds this candidate"
truthy "no-op uploaded nothing" test "$(gh_calls 'release upload')" = "$ups"
GH_STUB_NO_DIGEST=1 expect "no API digest: download and hash" 0 rc "$V" --candidate "$ZIP" --upload
has "download verified" "downloaded .* matches"
"$PY" -c 'import json,sys; p=sys.argv[1]; r=json.load(open(p)); r.insert(0, {"id": 50, "tag_name": "v0.0.1", "draft": False, "target_commitish": "x", "assets": []}); json.dump(r, open(p, "w"))' "$GH_STUB_STATE/releases.json"
GH_STUB_PAGES=2 expect "paginated release list while staging" 0 rc "$V" --candidate "$ZIP" --upload
truthy "staging built nothing" test "$(builds)" = "$b"
truthy "staging never ran composer" test ! -e "$WORK/composer.log"

# New source means a new candidate: the draft is not swapped without --replace, which invalidates.
printf '<?php // plugin v2\n' >"$FX/src/Plugin.php"
git -C "$FX" commit -qam "source change"
FXHEAD2=$(git -C "$FX" rev-parse HEAD)
ZIP2="$CANDS/$PKG-$V-${FXHEAD2:0:7}.zip"
expect "new source builds a new candidate" 0 rc "$V"
ZSHA2=$(sha_of "$ZIP2")
truthy "new candidate has new bytes" test "$ZSHA2" != "$ZSHA"
expect "old candidate no longer verifies against the new source" 1 rc "$V" --candidate "$ZIP"
expect "new candidate does not silently replace the draft" 1 rc "$V" --candidate "$ZIP2" --upload
has "replacement needs --replace" "Use --replace"
truthy "draft still holds the old candidate" test "$(asset_sha)" = "$ZSHA"
GH_STUB_FAIL=upload expect "failed replacement reported" 1 rc "$V" --candidate "$ZIP2" --upload --replace
truthy "failed replacement left the new candidate intact" test "$(sha_of "$ZIP2")" = "$ZSHA2"
expect "replacement with --replace" 0 rc "$V" --candidate "$ZIP2" --upload --replace
has "replacement invalidates prior evidence" "INVALIDATED"
truthy "draft holds the new candidate" test "$(asset_sha)" = "$ZSHA2"
truthy "draft targets the new source" test "$(draft_target)" = "$FXHEAD2"
truthy "old candidate kept" test "$(sha_of "$ZIP")" = "$ZSHA"

cp "$GH_STUB_STATE/releases.json" "$WORK/releases.keep"
"$PY" -c 'import json,sys; p=sys.argv[1]; r=json.load(open(p)); [x.update(draft=False) for x in r if x["tag_name"]==sys.argv[2]]; json.dump(r, open(p, "w"))' "$GH_STUB_STATE/releases.json" "v$V"
expect "published release never replaced" 1 rc "$V" --candidate "$ZIP2" --upload --replace
has "published release (reason)" "never replaced"
"$PY" -c 'import json,sys; p=sys.argv[1]; r=json.load(open(p)); r.append({"id": 900, "tag_name": sys.argv[2], "draft": True, "target_commitish": "x", "assets": []}); [x.update(draft=True) for x in r]; json.dump(r, open(p, "w"))' "$GH_STUB_STATE/releases.json" "v$V"
expect "ambiguous releases refused" 1 rc "$V" --candidate "$ZIP2" --upload --replace
has "ambiguous releases (reason)" "ambiguous"
cp "$WORK/releases.keep" "$GH_STUB_STATE/releases.json"
git -C "$FX" tag -a "v$V" -m "release"
git -C "$FX" push -q origin "v$V"
expect "existing tag on origin refused" 1 rc "$V" --candidate "$ZIP2" --upload
has "existing tag (reason)" "already exists on origin"
git -C "$FX" push -q origin ":refs/tags/v$V"
git -C "$FX" tag -d "v$V" >/dev/null
git -C "$FX" remote set-url origin "$WORK/missing.git"
expect "unreachable origin refused" 1 rc "$V" --candidate "$ZIP2" --upload
has "unreachable origin (reason)" "Could not check origin"
git -C "$FX" remote set-url origin "$WORK/origin.git"
truthy "gh was never asked to delete or publish" test "$(gh_calls 'delete')" = 0
truthy "no build after the second candidate" test "$(builds)" = "$((b + 1))"

# ---------------------------------------------------------------------------------------------
# 4. Static checks on the workflow and the gates.
DEPLOY="$REPO/.github/workflows/deploy.yml"
truthy "10up pinned to the reviewed commit" grep -qE '^ +uses: 10up/action-wordpress-plugin-deploy@54bd289b8525fd23a5c365ec369185f2966529c2 # 2\.3\.0$' "$DEPLOY"
truthy "no floating action refs for 10up" test -z "$(grep -E '10up/action-wordpress-plugin-deploy@' "$DEPLOY" | grep -v '@54bd289b8525fd23a5c365ec369185f2966529c2')"
truthy "pre-release tag pushes excluded" grep -qE "^ +- '!v\*-\*'" "$DEPLOY"
truthy "publish step only on a tag push" grep -qE "^ +if: github\.event_name == 'push' && env\.DRY_RUN == 'false'$" "$DEPLOY"
truthy "10up dry run unless a final tag push" grep -qE "^ +dry-run: \\$\\{\\{ github\.event_name != 'push' \|\| env\.DRY_RUN != 'false' \\}\\}$" "$DEPLOY"
expect "request values reach scripts only through env" 0 "$PY" "$HERE/scan_workflow.py" "$DEPLOY"
has "request values reach scripts only through env (scan)" "^clean$"
# The scanner itself catches an interpolated input (the 1.2.0 deploy's WANT_SHA="${{ inputs.sha256 }}").
# shellcheck disable=SC2016 # literal workflow text
printf 'jobs:\n  d:\n    steps:\n      - run: |\n          WANT_SHA="${{ inputs.sha256 }}"\n' >"$WORK/bad-workflow.yml"
expect "scanner flags an interpolated input" 1 "$PY" "$HERE/scan_workflow.py" "$WORK/bad-workflow.yml"
git -C "$REPO" show 02bd09f6ab62981d0b7fa969d67d226022e03276:.github/workflows/deploy.yml >"$WORK/old-deploy.yml" 2>/dev/null \
	&& expect "scanner flags the 1.2.0 deploy.yml" 1 "$PY" "$HERE/scan_workflow.py" "$WORK/old-deploy.yml"
d_line() { grep -nE "$1" "$DEPLOY" | head -1 | cut -d: -f1; }
truthy "Composer source checks run before the candidate is downloaded" test "$(d_line 'composer install')" -lt "$(d_line 'name: Download the staged candidate')"
truthy "manifest re-checked right before the 10up step" test "$(d_line 'name: Check the extracted candidate is unchanged')" -lt "$(d_line 'uses: 10up/')"
truthy "SVN copy checked against the manifest before publishing" test "$(d_line 'name: Check the staged SVN files')" -lt "$(d_line 'name: Publish the GitHub release')"
truthy "deploy passes the version to 10up" grep -qE '^ +VERSION: \$\{\{ env\.VERSION \}\}$' "$DEPLOY"
# shellcheck disable=SC2016 # literal workflow text
truthy "deploy uses the shared artifact checker" grep -qF '"${CHECK[@]}" check "$P/$ASSET"' "$DEPLOY"
truthy "deploy never builds the plugin" test -z "$(grep -nE 'build-zip|composer install --no-dev' "$DEPLOY")"
truthy "CI runs this test" grep -q 'tests/release/test-promotion.sh' "$REPO/.github/workflows/ci.yml"
truthy "the fast gate runs this test" grep -q 'tests/release/test-promotion.sh' "$REPO/scripts/run-integration.sh"
truthy ".distignore excludes tests/ (helpers here never ship)" grep -qx 'tests' "$REPO/.distignore"
truthy ".distignore excludes scripts/ (the checker never ships)" grep -qx 'scripts' "$REPO/.distignore"
if command -v actionlint >/dev/null 2>&1; then
	expect "actionlint deploy.yml and ci.yml" 0 actionlint "$DEPLOY" "$REPO/.github/workflows/ci.yml"
fi

echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
