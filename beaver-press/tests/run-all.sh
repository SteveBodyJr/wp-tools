#!/bin/bash
# Run every Beaver Press test. PHP ones need WP-CLI's PHP (or PHP_BIN); HTTP ones need BP_SITE.
# A test counts as failed unless it exits 0 and its last line says "N passed, M failed": a crash,
# a missing WordPress or a missing summary line is a failure, never a pass.
cd "$(dirname "$0")"; PHP=${PHP_BIN:-php}; failed=0
WP_LOAD=${BP_WP_LOAD:-$(cd ../../../.. 2>/dev/null && pwd)/wp-load.php}
if [ ! -f "$WP_LOAD" ]; then echo "WordPress not found ($WP_LOAD): set BP_WP_LOAD."; exit 2; fi
export BP_WP_LOAD=$WP_LOAD
# The tests switch settings for a moment: never while a translation run is going.
status=$($PHP -r 'require getenv("BP_WP_LOAD"); echo BP_Run::state()["status"] ?? "";' 2>/dev/null)
if [ "$status" = "running" ]; then echo "A translation run is going: pause it (or wait) before running the tests."; exit 2; fi
check() { # $1 test, $2 exit code, $3 last line
	echo "$1: $3"
	if [ "$2" != 0 ] || ! grep -qE '[0-9]+ passed, [0-9]+ failed' <<<"$3"; then
		echo "  -> counted as failed (exit $2, no summary line)"; failed=$((failed+1)); return
	fi
	failed=$((failed+$(sed -n 's/.* \([0-9]*\) failed.*/\1/p' <<<"$3")))
}
for t in test-*.php; do out=$($PHP "$t" 2>&1); code=$?; check "$t" "$code" "$(tail -1 <<<"$out")"; done
for t in test-*.sh; do out=$(./"$t" 2>&1); code=$?; check "$t" "$code" "$(tail -1 <<<"$out")"; done
echo; [ "$failed" = 0 ] && echo "All passed." || { echo "$failed failed."; exit 1; }
