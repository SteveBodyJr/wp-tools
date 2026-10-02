#!/bin/bash
# Update the translation engine bundled in Beaver Press (engine/) to a TranslatePress free
# release from wordpress.org, then run the tests.
#
#   tools/update-engine.sh            # latest release
#   tools/update-engine.sh 3.3.8      # a given version
#
# Only the free plugin from wordpress.org is ever used. The engine's files are copied as
# they come (its GPL licence and copyright notices included); Beaver Press changes nothing
# inside engine/. The old engine is kept as engine.previous/ until the tests pass.
set -euo pipefail

cd "$(dirname "$0")/.."
PLUGIN_DIR=$(pwd)
VERSION=${1:-}

if [ -z "$VERSION" ]; then
	VERSION=$(curl -fsS "https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=translatepress-multilingual" | ${PHP_BIN:-php} -r '$d = json_decode(stream_get_contents(STDIN), true); echo $d["version"] ?? "";')
fi
[ -n "$VERSION" ] || { echo "Could not find the latest version."; exit 1; }

CURRENT=$(grep -m1 "^Version:" engine/index.php 2>/dev/null | awk '{print $2}' || true)
echo "Engine now: ${CURRENT:-none}; installing: $VERSION"
[ "$CURRENT" = "$VERSION" ] && { echo "Already up to date."; exit 0; }

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
curl -fsSLo "$TMP/engine.zip" "https://downloads.wordpress.org/plugin/translatepress-multilingual.$VERSION.zip"
unzip -q "$TMP/engine.zip" -d "$TMP"
grep -q "^Version: $VERSION" "$TMP/translatepress-multilingual/index.php" || { echo "Downloaded files are not version $VERSION."; exit 1; }

rm -rf engine.previous
[ -d engine ] && mv engine engine.previous
mv "$TMP/translatepress-multilingual" engine
echo "Engine $VERSION in place. Running the tests..."

if PHP_BIN=${PHP_BIN:-php} "$PLUGIN_DIR/tests/run-all.sh"; then
	rm -rf engine.previous
	echo "Done: engine $VERSION, all tests passed. Raise Beaver Press's version and release it."
else
	echo "Tests failed: engine $VERSION kept for inspection; the previous engine is in engine.previous/."
	echo "To go back: rm -rf engine && mv engine.previous engine"
	exit 1
fi
