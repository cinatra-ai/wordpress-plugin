#!/usr/bin/env bash
#
# generate-pot.sh — (re)generate languages/cinatra.pot from the plugin's
# CURRENT translatable strings via `wp i18n make-pot` (WP-CLI's bundled i18n
# command). This is a static PHP/JS source scan — it needs PHP + wp-cli, but
# NO WordPress installation, database, or web server (verified: runs cleanly
# in a bare `php-cli` container with only wp-cli.phar on PATH).
#
# Requires `wp` (WP-CLI) on PATH: https://wp-cli.org — install a phar, the
# `shivammathur/setup-php` `tools: wp-cli` action (see ci.yml), or run inside
# any container/host that already has it (e.g. `docker exec` into a
# WordPress dev container, then copy the result back out).
#
# Usage:
#   bin/generate-pot.sh            # regenerate languages/cinatra.pot in place
#   bin/generate-pot.sh --check    # regenerate to a temp file and diff against
#                                   # the committed .pot, ignoring the
#                                   # always-changing POT-Creation-Date line;
#                                   # exit non-zero on drift. This is what CI
#                                   # runs (.github/workflows/ci.yml, job
#                                   # pot-i18n-drift) — wp#109.
#
# --slug/--domain are pinned explicitly (not left to directory-basename
# defaults) so the result is identical whether this runs from a repo checkout
# named "wordpress-plugin" (CI) or any other local directory name.
#
# The header overrides below (Report-Msgid-Bugs-To / Last-Translator /
# Language-Team) match the values first hand-set when languages/cinatra.pot
# was created (commit f9c38b2, 2026-06-14) — wp-cli's own defaults are
# generic wordpress.org placeholders, so they are pinned here to keep the
# file's provenance stable across every regeneration.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." >/dev/null 2>&1 && pwd)"
POT_PATH="$REPO_ROOT/languages/cinatra.pot"

command -v wp >/dev/null 2>&1 || {
	echo "error: wp-cli ('wp') not found on PATH. Install it: https://wp-cli.org/#installing" >&2
	exit 1
}

# Keep this exclude list in lockstep with .distignore's "what does NOT ship"
# set: the .pot should reflect only the strings in the files that actually
# ship to WordPress.org, not dev/CI/test/doc cruft.
EXCLUDES="tests,tools,docs,build,bin,.github,.wordpress-org,README.md"
HEADERS='{"Report-Msgid-Bugs-To":"https://cinatra.ai","Last-Translator":"Cinatra <support@cinatra.ai>","Language-Team":"Cinatra <support@cinatra.ai>"}'

generate() {
	local dest="$1"
	wp i18n make-pot "$REPO_ROOT" "$dest" \
		--slug=cinatra \
		--domain=cinatra \
		--exclude="$EXCLUDES" \
		--headers="$HEADERS" \
		--allow-root
}

if [ "${1:-}" = "--check" ]; then
	TMP_POT="$(mktemp -t cinatra-pot-check.XXXXXX)"
	trap 'rm -f "$TMP_POT"' EXIT
	generate "$TMP_POT"
	# POT-Creation-Date is a timestamp that legitimately differs on every run;
	# strip it from both sides before diffing so the check reflects real
	# string drift only, never a false positive from the regen clock.
	if diff -u \
		<(grep -v '^"POT-Creation-Date' "$POT_PATH") \
		<(grep -v '^"POT-Creation-Date' "$TMP_POT"); then
		echo "languages/cinatra.pot is up to date with the current source strings."
		exit 0
	else
		echo "" >&2
		echo "error: languages/cinatra.pot is STALE — it does not match the current" >&2
		echo "source strings (diff above; POT-Creation-Date excluded). Regenerate it:" >&2
		echo "  bin/generate-pot.sh" >&2
		echo "then commit the result." >&2
		exit 1
	fi
else
	generate "$POT_PATH"
	echo "Regenerated $POT_PATH"
fi
