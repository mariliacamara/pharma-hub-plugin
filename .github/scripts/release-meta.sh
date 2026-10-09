#!/usr/bin/env bash
# Print the plugin version of a commit and write its CHANGELOG.md section.
#
# Usage: release-meta.sh <commit> <notes-file>
# Prints the version (e.g. 2.1.0) on stdout. Fails when the plugin header
# and PHARMA_HUB_PLUGIN_VERSION differ, or when CHANGELOG.md has no section
# for that version.
set -euo pipefail

sha="$1"
notes="$2"

main_file="$(git show "${sha}:pharma-hub-plugin.php")"
version="$(printf '%s\n' "$main_file" | sed -nE 's/^ \* Version: *([0-9]+\.[0-9]+\.[0-9]+) *$/\1/p')"
constant="$(printf '%s\n' "$main_file" | sed -nE "s/^define\( 'PHARMA_HUB_PLUGIN_VERSION', '([^']+)' \);$/\1/p")"

if [ -z "$version" ] || [ "$version" != "$constant" ]; then
    echo "::error::Version header (${version:-missing}) and PHARMA_HUB_PLUGIN_VERSION (${constant:-missing}) must match." >&2
    exit 1
fi

# Lines after "## [x.y.z]" up to the next "## [" heading or the link list.
changelog="$(git show "${sha}:CHANGELOG.md" 2>/dev/null || true)"
printf '%s\n' "$changelog" | awk -v v="$version" '
    index($0, "## [" v "]") == 1 { found = 1; next }
    found && (/^## \[/ || /^\[[0-9]/) { exit }
    found { print }
' | sed -e '/./,$!d' > "$notes"

if ! grep -q '[^[:space:]]' "$notes"; then
    echo "::error::CHANGELOG.md has no section for ${version}. Add \"## [${version}] - YYYY-MM-DD\" before releasing." >&2
    exit 1
fi

echo "$version"
