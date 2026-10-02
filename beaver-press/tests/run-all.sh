#!/bin/bash
# Run every Beaver Press test. PHP ones need WP-CLI's PHP (or PHP_BIN); HTTP ones need BP_SITE.
cd "$(dirname "$0")"; PHP=${PHP_BIN:-php}; total=0; failed=0
# The tests switch settings for a moment: never while a translation run is going.
status=$($PHP -r 'require getenv("BP_WP_LOAD") ?: dirname(getcwd(), 4) . "/wp-load.php"; echo BP_Run::state()["status"] ?? "";' 2>/dev/null)
if [ "$status" = "running" ]; then echo "A translation run is going: pause it (or wait) before running the tests."; exit 2; fi
for t in test-*.php; do r=$($PHP "$t" 2>&1 | tail -1); echo "$t: $r"; f=$(sed -n 's/.* \([0-9]*\) failed.*/\1/p' <<<"$r"); failed=$((failed+${f:-0})); done
for t in test-*.sh; do r=$(./"$t" 2>&1 | tail -1); echo "$t: $r"; f=$(sed -n 's/.* \([0-9]*\) failed.*/\1/p' <<<"$r"); failed=$((failed+${f:-0})); done
echo; [ "$failed" = 0 ] && echo "All passed." || { echo "$failed failed."; exit 1; }
