#!/usr/bin/env bash
# Build the release candidate once, from a clean checkout, and (with --upload) stage it as the
# asset of a draft GitHub release. The tag's deploy workflow ships that asset unchanged; nothing
# is rebuilt after the candidate is tested and signed.
#
#   scripts/release-candidate.sh 1.2.1
#       Build ~/.cache/blzmcp-candidates/<slug>-1.2.1-<sha7>.zip (plus a .sha256 beside it), both
#       read-only, and print the candidate, its sha256, the source commit and its file manifest.
#   scripts/release-candidate.sh 1.2.1 --upload
#       ...and stage it on draft release v1.2.1 targeting the source commit.
#   scripts/release-candidate.sh 1.2.1 --candidate <existing-zip> [--sha256 <hex>] [--upload [--replace]]
#       Use a candidate that already exists (the tested zip): never builds and never runs Composer.
#       Verifies the clean checkout, every version identifier, the package against the source
#       inventory and the digest (the .sha256 beside the zip, --sha256, or both), then with
#       --upload stages it. This is the retry after a failed upload: a draft with no asset gets
#       the upload, and a draft already holding exactly these bytes is left as it is.
#   --replace swaps a draft's different zip or target. New candidate bytes or a new source
#   invalidate the matrix, the technical sign-off and Campbell's go: run them again.
#
# Candidates live outside the checkout (BLZMCP_CANDIDATE_DIR, default ~/.cache/blzmcp-candidates).
# A destination equal to or inside the checkout or dist/ (build-zip.sh wipes dist/), symlinks
# resolved, is refused before any build. A candidate is never overwritten, and a failed check or
# a failed upload leaves an existing candidate and its digest as they were.
set -euo pipefail

SLUG="blaze-mcp-toolkit-for-woocommerce"
REPO="blaze-commerce/$SLUG"
ROOT="$(cd "$(dirname "$0")/.." && pwd -P)"
CHECK="$ROOT/scripts/check-release-artifact.py"
PYTHON="${BLZMCP_PYTHON:-python3}"

usage() {
  echo "usage: $0 <x.y.z> [--upload [--replace]]" >&2
  echo "       $0 <x.y.z> --candidate <existing-zip> [--sha256 <hex>] [--upload [--replace]]" >&2
  exit 2
}
die() {
  echo "$*" >&2
  exit 1
}
check() { "$PYTHON" "$CHECK" "$@"; }
kv() { printf '%s\n' "$1" | sed -n "s/^$2=//p" | head -1; }

[ $# -ge 1 ] || usage
VERSION="$1"
shift
UPLOAD=0
REPLACE=0
CANDIDATE=""
WANT_SHA=""
while [ $# -gt 0 ]; do
  case "$1" in
    --upload) UPLOAD=1 ;;
    --replace) REPLACE=1 ;;
    --candidate)
      if [ $# -lt 2 ] || [ -z "$2" ] || [ "${2#-}" != "$2" ]; then
        echo "--candidate needs a zip path" >&2
        usage
      fi
      CANDIDATE="$2"
      shift
      ;;
    --sha256)
      [ $# -ge 2 ] || { echo "--sha256 needs a value" >&2; usage; }
      WANT_SHA="$2"
      shift
      ;;
    *)
      echo "unknown option: $1" >&2
      usage
      ;;
  esac
  shift
done
[[ "$VERSION" =~ ^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$ ]] || usage
[ "$REPLACE" = 0 ] || [ "$UPLOAD" = 1 ] || { echo "--replace needs --upload" >&2; usage; }
if [ -n "$WANT_SHA" ]; then
  [ -n "$CANDIDATE" ] || { echo "--sha256 needs --candidate" >&2; usage; }
  [[ "$WANT_SHA" =~ ^[0-9a-f]{64}$ ]] || { echo "--sha256 must be 64 lowercase hex characters" >&2; usage; }
fi

cd "$ROOT"
if [ -n "$(git status --porcelain)" ]; then
  echo "Working tree is not clean; commit or stash first:" >&2
  git status --short >&2
  exit 1
fi
SOURCE_SHA="$(git rev-parse HEAD)"
grep -qE "^Stable tag: ${VERSION}$" readme.txt || die "readme.txt Stable tag is not $VERSION"
grep -qE "^ \* Version:[[:space:]]+${VERSION}$" "$SLUG.php" || die "$SLUG.php Version is not $VERSION"
grep -qF "define( 'BLZMCP_VERSION', '${VERSION}' );" "$SLUG.php" || die "$SLUG.php BLZMCP_VERSION is not $VERSION"

WORK="$(mktemp -d "${TMPDIR:-/tmp}/blzmcp-candidate.XXXXXX")"
PARTIAL=""
cleanup() {
  if [ -n "$PARTIAL" ]; then rm -f "$PARTIAL"; fi
  rm -rf "$WORK"
}
trap cleanup EXIT

if [ -z "$CANDIDATE" ]; then
  # Build once. The destination is checked before anything is built.
  out="$(check guard-dest --root "$ROOT" --path "${BLZMCP_CANDIDATE_DIR:-$HOME/.cache/blzmcp-candidates}" --kind dir)" || exit 1
  CANDIDATES="$(kv "$out" PATH)"
  ZIP="$CANDIDATES/$SLUG-$VERSION-${SOURCE_SHA:0:7}.zip"
  if [ -e "$ZIP" ] || [ -L "$ZIP" ] || [ -e "$ZIP.sha256" ] || [ -L "$ZIP.sha256" ]; then
    echo "$ZIP (or its .sha256) already exists; not rebuilding over a candidate." >&2
    echo "To re-check or stage it: $0 $VERSION --candidate $ZIP [--upload]" >&2
    exit 1
  fi
  mkdir -p "$CANDIDATES"
  bash scripts/build-zip.sh >/dev/null
  if [ -n "$(git status --porcelain)" ]; then
    echo "The build changed tracked files; not a clean candidate:" >&2
    git status --short >&2
    exit 1
  fi
  PARTIAL="$CANDIDATES/.$SLUG-$VERSION-${SOURCE_SHA:0:7}.zip.partial.$$"
  cp "dist/$SLUG.zip" "$PARTIAL"
  out="$(check check "$PARTIAL" --version "$VERSION" --repo "$ROOT" --source-sha "$SOURCE_SHA")" \
    || die "The built zip does not match the source; no candidate was frozen."
  ZIP_SHA="$(kv "$out" SHA256)"
  # ln refuses an existing name: the candidate is created once and never overwritten.
  ln "$PARTIAL" "$ZIP" || die "Could not freeze $ZIP"
  rm -f "$PARTIAL"
  chmod a-w "$ZIP"
  PARTIAL="$ZIP.sha256.partial.$$"
  printf '%s  %s\n' "$ZIP_SHA" "$(basename "$ZIP")" >"$PARTIAL"
  ln "$PARTIAL" "$ZIP.sha256" || die "Could not write $ZIP.sha256"
  rm -f "$PARTIAL"
  PARTIAL=""
  chmod a-w "$ZIP.sha256"
else
  # An existing candidate: verify, never build.
  out="$(check guard-dest --root "$ROOT" --path "$CANDIDATE" --kind file)" || exit 1
  ZIP="$(kv "$out" PATH)"
  [ -f "$ZIP" ] || die "$CANDIDATE is not a file"
  REF=""
  if [ -e "$ZIP.sha256" ]; then
    REF="$(head -1 "$ZIP.sha256" | cut -d' ' -f1)"
    [[ "$REF" =~ ^[0-9a-f]{64}$ ]] || die "$ZIP.sha256 does not hold a sha256"
  fi
  if [ -n "$WANT_SHA" ] && [ -n "$REF" ] && [ "$WANT_SHA" != "$REF" ]; then
    die "--sha256 $WANT_SHA disagrees with $ZIP.sha256 ($REF)"
  fi
  [ -n "$REF" ] || REF="$WANT_SHA"
  [ -n "$REF" ] || die "No reference digest for $ZIP: pass --sha256 <the signed sha256>"
  out="$(check check "$ZIP" --version "$VERSION" --repo "$ROOT" --source-sha "$SOURCE_SHA" --sha256 "$REF")" \
    || die "$ZIP does not verify against $SOURCE_SHA; not staged."
  ZIP_SHA="$(kv "$out" SHA256)"
fi
FILES="$(kv "$out" FILES)"
MANIFEST_SHA="$(kv "$out" MANIFEST_SHA256)"
if [ -n "$(git status --porcelain)" ]; then
  echo "The checkout changed during the checks:" >&2
  git status --short >&2
  exit 1
fi

echo "candidate:  $ZIP"
echo "sha256:     $ZIP_SHA"
echo "source-sha: $SOURCE_SHA"
echo "manifest:   $FILES files, sha256 $MANIFEST_SHA"

[ "$UPLOAD" = 1 ] || exit 0

TAG="v$VERSION"
ASSET="$SLUG-$VERSION.zip"
set +e
git ls-remote --exit-code --tags origin "refs/tags/$TAG" >/dev/null 2>&1
rc=$?
set -e
case "$rc" in
  0) die "Tag $TAG already exists on origin; a staged candidate must come before the tag." ;;
  2) ;;
  *) die "Could not check origin for tag $TAG (git ls-remote exit $rc); not staging." ;;
esac

STATE=""
REASON=""
release_state() {
  gh api --paginate "repos/$REPO/releases?per_page=100" >"$WORK/releases.json" || die "Could not list releases for $REPO."
  local out
  out="$(check release-state --releases-json "$WORK/releases.json" --version "$VERSION" --source-sha "$SOURCE_SHA" --sha256 "$ZIP_SHA")" || exit 1
  STATE="$(kv "$out" STATE)"
  REASON="$(kv "$out" REASON)"
  if [ "$STATE" = staged-unverified ]; then
    rm -rf "$WORK/download"
    mkdir "$WORK/download"
    gh release download "$TAG" --repo "$REPO" --pattern "$ASSET" --dir "$WORK/download" \
      || die "Could not download $ASSET from draft $TAG to check it."
    local got
    got="$(shasum -a 256 "$WORK/download/$ASSET" | cut -d' ' -f1)"
    if [ "$got" = "$ZIP_SHA" ]; then
      STATE=staged
      REASON="downloaded $ASSET sha256 matches"
    else
      STATE=draft-mismatch
      REASON="$ASSET on the draft has sha256 $got"
    fi
  fi
}

NOTES="Built once from $SOURCE_SHA. $ASSET sha256 $ZIP_SHA. The deploy for tag $TAG ships this file unchanged."
cp "$ZIP" "$WORK/$ASSET" # the release asset must be named <slug>-<version>.zip
release_state
case "$STATE" in
  none)
    gh release create "$TAG" --repo "$REPO" --draft --target "$SOURCE_SHA" --title "$VERSION" --notes "$NOTES" "$WORK/$ASSET" \
      || die "Staging failed. $ZIP is unchanged; retry with: $0 $VERSION --candidate $ZIP --upload"
    ;;
  draft-empty)
    echo "Draft $TAG has no complete asset ($REASON); uploading the same candidate." >&2
    gh release upload "$TAG" --repo "$REPO" --clobber "$WORK/$ASSET" \
      || die "Upload failed. $ZIP is unchanged; retry with: $0 $VERSION --candidate $ZIP --upload"
    ;;
  staged)
    echo "Draft $TAG already holds this candidate ($REASON); nothing to upload." >&2
    ;;
  draft-mismatch)
    [ "$REPLACE" = 1 ] || die "Draft $TAG does not hold this candidate: $REASON. Use --replace to swap it (that invalidates its matrix, sign-off and go)."
    gh release edit "$TAG" --repo "$REPO" --draft --target "$SOURCE_SHA" --notes "$NOTES" \
      || die "Could not retarget draft $TAG. $ZIP is unchanged."
    gh release upload "$TAG" --repo "$REPO" --clobber "$WORK/$ASSET" \
      || die "Upload failed. $ZIP is unchanged; retry with: $0 $VERSION --candidate $ZIP --upload --replace"
    echo "INVALIDATED: draft $TAG now holds sha256 $ZIP_SHA from $SOURCE_SHA. Any matrix, technical sign-off or go for its previous zip or source no longer applies; run them again for this candidate." >&2
    ;;
  *)
    die "Not staging: $REASON ($STATE). A published or ambiguous release is never replaced."
    ;;
esac
release_state
[ "$STATE" = staged ] || die "Draft $TAG does not verify after staging ($STATE: $REASON). $ZIP is unchanged."
echo "Staged draft release $TAG ($REPO): $ASSET sha256 $ZIP_SHA, target $SOURCE_SHA."
