#!/usr/bin/env bash
#
# Builds the WordPress-installable plugin zip.
#
# The archive contains a single top-level elementor-mcp-bridge/ directory,
# which is what Plugins > Add New > Upload Plugin expects.
#
# Usage: ./scripts/package-plugin.sh [output-dir]

set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
plugin_dir="$repo_root/plugin/elementor-mcp-bridge"
out_dir="${1:-$repo_root/build}"

version="$(grep -m1 '^ \* Version:' "$plugin_dir/elementor-mcp-bridge.php" | awk '{print $3}')"
archive="$out_dir/elementor-mcp-bridge-${version}.zip"

if [ ! -f "$plugin_dir/elementor-mcp-bridge.php" ]; then
  echo "error: plugin entry file not found at $plugin_dir" >&2
  exit 1
fi

# Refuse to ship a plugin that will not parse.
while IFS= read -r file; do
  php -l "$file" > /dev/null || { echo "error: syntax error in $file" >&2; exit 1; }
done < <(find "$plugin_dir" -name '*.php')

mkdir -p "$out_dir"
rm -f "$archive"

# -x excludes editor and OS cruft that would otherwise ride along.
( cd "$repo_root/plugin" && zip -rq "$archive" elementor-mcp-bridge \
    -x '*.DS_Store' -x '__MACOSX/*' -x '*.swp' -x '*~' )

echo "built: $archive"
echo "size:  $(du -h "$archive" | cut -f1)"
echo "files: $(unzip -l "$archive" | tail -1 | awk '{print $2}')"
