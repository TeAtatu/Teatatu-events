#!/bin/sh
# Builds the zips to attach to a GitHub Release, so installs update cleanly:
# the plugin (teatatu-events-<version>.zip) and, when the folder exists, the
# theme (teatatu-events-theme-<version>.zip).
#
# Usage (from the repository root or anywhere inside it):
#   sh teatatu-events/tools/build-release.sh
#
# Checks that the version in the plugin header, TEATATU_EVENTS_VERSION and
# readme.txt's "Stable tag" all match, and that the plugin folder has no
# uncommitted changes; then writes teatatu-events-<version>.zip (a single
# teatatu-events/ folder, from the committed code) to the repository root.
#
# Then on GitHub: Releases > Draft a new release > tag v<version> > attach
# the zip > Publish. Sites see the update within 12 hours (or straight away
# with "Check for Updates Now" in the plugin settings).

set -e

root=$(git rev-parse --show-toplevel)
cd "$root"
plugin="teatatu-events"

header=$(sed -n 's/^ \* Version: *//p' "$plugin/teatatu-events.php" | head -1 | tr -d '\r ')
constant=$(sed -n "s/^define( 'TEATATU_EVENTS_VERSION', '\([^']*\)' );.*/\1/p" "$plugin/teatatu-events.php" | head -1)
stable=$(sed -n 's/^Stable tag: *//p' "$plugin/readme.txt" | head -1 | tr -d '\r ')

if [ -z "$header" ] || [ "$header" != "$constant" ] || [ "$header" != "$stable" ]; then
	echo "Version mismatch: header '$header', TEATATU_EVENTS_VERSION '$constant', readme Stable tag '$stable'." >&2
	echo "Set all three to the new version and commit first." >&2
	exit 1
fi

theme="teatatu-events-theme"
if [ -d "$theme" ]; then
	t_header=$(sed -n 's/^Version: *//p' "$theme/style.css" | head -1 | tr -d '\r ')
	t_constant=$(sed -n "s/^define( 'TTEV_THEME_VERSION', '\([^']*\)' );.*/\1/p" "$theme/functions.php" | head -1)
	t_stable=$(sed -n 's/^Stable tag: *//p' "$theme/readme.txt" | head -1 | tr -d '\r ')
	if [ -z "$t_header" ] || [ "$t_header" != "$t_constant" ] || [ "$t_header" != "$t_stable" ]; then
		echo "Theme version mismatch: style.css '$t_header', TTEV_THEME_VERSION '$t_constant', readme Stable tag '$t_stable'." >&2
		exit 1
	fi
fi

if [ -n "$(git status --porcelain -- "$plugin" ${theme:+"$theme"})" ]; then
	echo "The plugin or theme folder has uncommitted changes. Commit them first (the zips are built from the last commit)." >&2
	exit 1
fi

out="$root/teatatu-events-$header.zip"
rm -f "$out"
git archive --format=zip --prefix="teatatu-events/" -o "$out" "HEAD:$plugin"

echo "Built $out"
if [ -d "$theme" ]; then
	t_out="$root/teatatu-events-theme-$t_header.zip"
	rm -f "$t_out"
	git archive --format=zip --prefix="teatatu-events-theme/" -o "$t_out" "HEAD:$theme"
	echo "Built $t_out"
fi
echo "Next: create GitHub release tag v$header on this commit ($(git rev-parse --short HEAD)), attach the zip(s), publish."
