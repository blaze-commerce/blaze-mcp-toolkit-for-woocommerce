#!/usr/bin/env bash
# Build the release candidate once, from a clean checkout, and (with --upload) stage it as the
# asset of a draft GitHub release. The tag's deploy workflow ships that asset unchanged; nothing
# is rebuilt after the candidate is tested and signed.
#
#   scripts/release-candidate.sh 1.2.1            # build ~/.cache/blzmcp-candidates/<slug>-1.2.1-<sha7>.zip, print its sha256
#   scripts/release-candidate.sh 1.2.1 --upload   # ...and create draft release v1.2.1 with it
#   scripts/release-candidate.sh 1.2.1 --upload --replace   # replace the draft's zip (re-sign!)
set -euo pipefail

SLUG="blaze-mcp-toolkit-for-woocommerce"
REPO="blaze-commerce/$SLUG"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="${1:-}"
UPLOAD=0
REPLACE=0
for arg in "${@:2}"; do
  case "$arg" in
    --upload) UPLOAD=1 ;;
    --replace) REPLACE=1 ;;
    *) echo "unknown option: $arg" >&2; exit 2 ;;
  esac
done
[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "usage: $0 <x.y.z> [--upload [--replace]]" >&2; exit 2; }

cd "$ROOT"
if [ -n "$(git status --porcelain)" ]; then
  echo "Working tree is not clean; commit or stash first:" >&2
  git status --short >&2
  exit 1
fi
SOURCE_SHA="$(git rev-parse HEAD)"
grep -qE "^Stable tag: ${VERSION}$" readme.txt || { echo "readme.txt Stable tag is not $VERSION" >&2; exit 1; }
grep -qE "^ \* Version:[[:space:]]+${VERSION}$" "$SLUG.php" || { echo "$SLUG.php Version is not $VERSION" >&2; exit 1; }

# Candidates live outside dist/ (build-zip.sh wipes dist/) and are never overwritten:
# the signed file must survive later builds.
CANDIDATES="${BLZMCP_CANDIDATE_DIR:-$HOME/.cache/blzmcp-candidates}"
mkdir -p "$CANDIDATES"
ZIP="$CANDIDATES/$SLUG-$VERSION-${SOURCE_SHA:0:7}.zip"
if [ -e "$ZIP" ]; then
  echo "$ZIP already exists (sha256 $(shasum -a 256 "$ZIP" | cut -d' ' -f1)); not rebuilding over a candidate." >&2
  echo "Delete it yourself if it was never signed." >&2
  exit 1
fi
bash scripts/build-zip.sh >/dev/null
cp "dist/$SLUG.zip" "$ZIP"
chmod a-w "$ZIP"
ZIP_SHA="$(shasum -a 256 "$ZIP" | cut -d' ' -f1)"
ASSET="$SLUG-$VERSION.zip"
if [ -n "$(git status --porcelain)" ]; then
  echo "The build changed tracked files; not a clean candidate:" >&2
  git status --short >&2
  exit 1
fi

echo "candidate:  $ZIP"
echo "sha256:     $ZIP_SHA"
echo "source-sha: $SOURCE_SHA"

[ "$UPLOAD" = 1 ] || exit 0

TAG="v$VERSION"
if git ls-remote --exit-code --tags origin "refs/tags/$TAG" >/dev/null 2>&1; then
  echo "Tag $TAG already exists on origin; a staged candidate must come before the tag." >&2
  exit 1
fi
EXISTING="$(gh api "repos/$REPO/releases?per_page=100" --jq "[.[] | select(.tag_name == \"$TAG\")] | map({draft, target_commitish}) | tostring")"
NOTES="Built once from $SOURCE_SHA. $ASSET sha256 $ZIP_SHA. The deploy for tag $TAG ships this file unchanged."
# The release asset must be named <slug>-<version>.zip; upload a copy under that name.
STAGE="$(mktemp -d)"
cp "$ZIP" "$STAGE/$ASSET"
if [ "$EXISTING" = "[]" ]; then
  gh release create "$TAG" --repo "$REPO" --draft --target "$SOURCE_SHA" --title "$VERSION" --notes "$NOTES" "$STAGE/$ASSET"
elif [ "$REPLACE" = 1 ] && echo "$EXISTING" | grep -q '"draft":true' && ! echo "$EXISTING" | grep -q '"draft":false'; then
  gh release edit "$TAG" --repo "$REPO" --draft --target "$SOURCE_SHA" --notes "$NOTES"
  gh release upload "$TAG" --repo "$REPO" --clobber "$STAGE/$ASSET"
  echo "Replaced the draft's zip. Re-run the matrix and re-sign against the new sha256." >&2
else
  echo "A release for $TAG already exists ($EXISTING). Use --replace to swap a draft's zip." >&2
  exit 1
fi
echo "Staged draft release $TAG ($REPO) with $ASSET."
