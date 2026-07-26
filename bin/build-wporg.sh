#!/usr/bin/env bash
#
# build-wporg.sh — stage the WordPress.org SVN layout OFFLINE and produce the
# release zip. This does NOT talk to SVN or WordPress.org; the actual `svn`
# import/commit is parked and handled separately (the dormant
# .github/workflows/wporg-deploy.yml runs the 10up deploy action on a
# `wporg-v*` tag). This script lets you build and inspect the exact artifact
# locally with no network and no credentials.
#
# What it produces under build/ (git-ignored, rebuilt each run):
#   build/svn/trunk/   — the shipped plugin files (everything NOT in .distignore)
#   build/svn/assets/  — the wp.org listing assets copied from .wordpress-org/
#                        (icons / banners / screenshots); empty if none exist yet
#   build/svn/tags/    — empty (wp.org tags are cut from trunk at release time)
#   build/cinatra.zip  — a ready-to-inspect zip whose top-level dir is `cinatra/`
#                        (the wp.org install layout), containing the trunk tree
#
# Usage:
#   bin/build-wporg.sh            # build into ./build
#   OUT_DIR=/tmp/wp bin/build-wporg.sh
#
# Exit non-zero on any error so CI / a caller can trust the result.

set -euo pipefail

# Resolve the repo root from this script's location (works from any CWD).
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." >/dev/null 2>&1 && pwd)"

SLUG="cinatra"
PLUGIN_FILE="cinatra.php"
DISTIGNORE="$REPO_ROOT/.distignore"
WPORG_ASSETS_DIR="$REPO_ROOT/.wordpress-org"

OUT_DIR="${OUT_DIR:-$REPO_ROOT/build}"
SVN_DIR="$OUT_DIR/svn"
TRUNK_DIR="$SVN_DIR/trunk"
ASSETS_DIR="$SVN_DIR/assets"
TAGS_DIR="$SVN_DIR/tags"
ZIP_PATH="$OUT_DIR/${SLUG}.zip"

log() { printf '==> %s\n' "$*"; }
die() { printf 'error: %s\n' "$*" >&2; exit 1; }

[ -f "$REPO_ROOT/$PLUGIN_FILE" ] || die "main plugin file not found: $PLUGIN_FILE"
[ -f "$DISTIGNORE" ] || die ".distignore not found at $DISTIGNORE"

# Derive the shipped version from the canonical source of truth (the plugin
# header Version), and assert readme.txt's Stable tag matches it — the wp.org
# release MUST point Stable tag at a real released version, never at trunk.
VERSION="$(grep -E '^\s*\*\s*Version:' "$REPO_ROOT/$PLUGIN_FILE" | head -1 | sed -E 's/.*Version:[[:space:]]*//')"
[ -n "$VERSION" ] || die "could not read Version from $PLUGIN_FILE header"
STABLE="$(grep -E '^Stable tag:' "$REPO_ROOT/readme.txt" | head -1 | sed -E 's/^Stable tag:[[:space:]]*//')"
[ -n "$STABLE" ] || die "could not read 'Stable tag' from readme.txt"
[ "$STABLE" = "$VERSION" ] || die "readme.txt Stable tag ($STABLE) != plugin Version ($VERSION); fix before building"
[ "$STABLE" != "trunk" ] || die "Stable tag must be a real version, not 'trunk'"
log "Building $SLUG version $VERSION"

# Fresh build dir each run.
rm -rf "$OUT_DIR"
mkdir -p "$TRUNK_DIR" "$ASSETS_DIR" "$TAGS_DIR"

# Build the rsync exclude list from .distignore. Each non-blank, non-comment
# line is one exclude pattern matched against the rsync transfer-relative path
# (so a bare name like `tests` or `vendor` matches at any depth — same effect
# as svn export honouring .distignore). This keeps a SINGLE source of truth
# for the shipped tree shared with the CI plugin-check rsync and the deploy
# action.
RSYNC_EXCLUDES=()
while IFS= read -r line; do
  # Strip trailing CR (in case of CRLF), trim surrounding whitespace.
  line="${line%$'\r'}"
  line="$(printf '%s' "$line" | sed -E 's/^[[:space:]]+//; s/[[:space:]]+$//')"
  [ -z "$line" ] && continue
  case "$line" in
    \#*) continue ;;
  esac
  RSYNC_EXCLUDES+=( "--exclude=$line" )
done < "$DISTIGNORE"

log "Staging trunk/ (honouring .distignore: ${#RSYNC_EXCLUDES[@]} patterns)"
# --delete keeps the destination an exact mirror across rebuilds; the source is
# the repo root. The build/ dir is itself excluded via .distignore so we never
# copy the in-progress output into trunk.
rsync -a --delete "${RSYNC_EXCLUDES[@]}" "$REPO_ROOT/" "$TRUNK_DIR/"

# Hard guard: no hidden files may survive into trunk/ — Plugin Check rejects
# them and the deploy would be dirty. Fail loudly if .distignore drifted.
if find "$TRUNK_DIR" -name '.*' -not -name '.' -not -name '..' -print -quit | grep -q .; then
  find "$TRUNK_DIR" -name '.*' -not -name '.' -not -name '..' >&2
  die "hidden files leaked into trunk/ — fix .distignore"
fi

# Stage the wp.org listing assets (icons/banners/screenshots) into svn assets/.
# These live in .wordpress-org/ and are NOT part of the shipped plugin (they
# never go into trunk/). If the directory does not exist yet, assets/ stays
# empty — that is fine for this prep step; the listing graphics are added later.
if [ -d "$WPORG_ASSETS_DIR" ]; then
  log "Copying wp.org listing assets from .wordpress-org/ into svn assets/"
  rsync -a --exclude='.*' "$WPORG_ASSETS_DIR/" "$ASSETS_DIR/"
else
  log "No .wordpress-org/ directory yet — svn assets/ left empty (listing graphics added later)"
fi

# tags/ is intentionally empty: wp.org release tags are cut from trunk by the
# deploy step at release time, not staged here.
log "svn tags/ left empty (cut from trunk at release time)"

# Produce the install-layout zip: top-level dir is the plugin slug so it
# unzips to wp-content/plugins/cinatra/ exactly as wp.org serves it.
log "Creating release zip: $ZIP_PATH"
ZIP_STAGE="$OUT_DIR/zip-stage"
rm -rf "$ZIP_STAGE"
mkdir -p "$ZIP_STAGE/$SLUG"
rsync -a "$TRUNK_DIR/" "$ZIP_STAGE/$SLUG/"
( cd "$ZIP_STAGE" && \
  if command -v zip >/dev/null 2>&1; then
    zip -rq "$ZIP_PATH" "$SLUG"
  else
    # Portable fallback: no `zip` binary present.
    command -v php >/dev/null 2>&1 || die "neither 'zip' nor 'php' available to build the zip"
    php -r '
      $zip = new ZipArchive();
      if ($zip->open($argv[1], ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { fwrite(STDERR, "cannot open zip\n"); exit(1); }
      $base = $argv[2];
      $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
      foreach ($it as $f) { $zip->addFile($f->getPathname(), substr($f->getPathname(), strlen($base) + 1)); }
      $zip->close();
    ' "$ZIP_PATH" "$ZIP_STAGE"
  fi )
rm -rf "$ZIP_STAGE"

log "Done."
echo
echo "SVN layout staged at: $SVN_DIR"
echo "  trunk/  ($(find "$TRUNK_DIR" -type f | wc -l | tr -d ' ') files)"
echo "  assets/ ($(find "$ASSETS_DIR" -type f | wc -l | tr -d ' ') files)"
echo "  tags/   (empty)"
echo "Release zip: $ZIP_PATH"
echo
echo "This build is OFFLINE only — no svn import/commit is performed."
echo "The actual wp.org publish runs from .github/workflows/wporg-deploy.yml"
echo "on a pushed 'wporg-v*' tag (parked until SVN_USERNAME/SVN_PASSWORD exist)."
