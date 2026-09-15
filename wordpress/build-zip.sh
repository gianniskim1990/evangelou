#!/usr/bin/env bash
# Builds an installable evangelou-club-api.zip from the plugin source, for
# uploading via WordPress's Plugins → Add New → Upload Plugin.
#
# The zip is a build artifact — this script does not commit it, and
# wordpress/.gitignore keeps it out of git regardless. Re-run this
# whenever the plugin source changes; the zip is not kept in sync
# automatically.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$SCRIPT_DIR/evangelou-club-api"
OUTPUT_ZIP="$SCRIPT_DIR/evangelou-club-api.zip"

if [ ! -d "$PLUGIN_DIR" ]; then
  echo "error: plugin directory not found at $PLUGIN_DIR" >&2
  exit 1
fi

rm -f "$OUTPUT_ZIP"

# Zip from inside wordpress/ so the archive contains evangelou-club-api/...
# at its root, matching what WordPress expects to unpack.
( cd "$SCRIPT_DIR" && zip -rq "$OUTPUT_ZIP" "evangelou-club-api" \
    -x "evangelou-club-api/.DS_Store" \
    -x "*/.DS_Store" )

echo "Built: $OUTPUT_ZIP"
