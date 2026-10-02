#!/bin/bash
# P11 cache checks over HTTP (no provider calls: only complete pages, visitors).
B=${BP_SITE:-http://localhost/raya-safaris-wp}; P=/opt/lampp/bin/php; pass=0; fail=0
ok(){ if [ "$2" = "$3" ]; then pass=$((pass+1)); echo "PASS $1"; else fail=$((fail+1)); echo "FAIL $1 (got $2, want $3)"; fi; }
hit(){ curl -s -o /dev/null -D - "$@" | grep -ci "x-beaver-press-cache: hit"; }
curl -s -o /dev/null $B/fr/kilimanjaro/; curl -s -o /dev/null $B/fr/kilimanjaro/
ok "complete FR page served ready" "$(hit $B/fr/kilimanjaro/)" 1
ok "original language ready too" "$(curl -s -o /dev/null $B/kilimanjaro/; hit $B/kilimanjaro/)" 1
ok "query string never" "$(hit "$B/fr/kilimanjaro/?x=1")" 0
ok "forced reload rebuilds" "$(hit -H 'Cache-Control: no-cache' $B/fr/kilimanjaro/)" 0
ok "login cookie never" "$(hit -b 'wordpress_logged_in_x=1' $B/fr/kilimanjaro/)" 0
ok "POST never" "$(curl -s -o /dev/null -D - -X POST -d a=1 $B/fr/kilimanjaro/ | grep -ci 'x-beaver-press-cache: hit')" 0
ok "cache folder refused over HTTP" "$(curl -s -o /dev/null -w '%{http_code}' $B/wp-content/uploads/beaver-press-cache/)" 403
ok "no-store kept on form page" "$(curl -s -D - -o /dev/null $B/fr/kilimanjaro/ | grep -ci '^cache-control: no-store')" 1
a=$(curl -s -H 'Cache-Control: no-cache' $B/fr/kilimanjaro/ | md5sum); b=$(curl -s $B/fr/kilimanjaro/ | md5sum)
ok "ready page identical to a fresh build" "$a" "$b"
$P -r 'require getenv("BP_WP_LOAD") ?: dirname(getcwd(), 4) . "/wp-load.php"; do_action("beaver_press_translations_saved","fr_FR");'
ok "translation save empties" "$(hit $B/fr/kilimanjaro/)" 0
ok "then ready again" "$(hit $B/fr/kilimanjaro/)" 1
ok "prefetch script for visitors" "$(curl -s $B/kilimanjaro/ | grep -c 'function fetchAll')" 1
echo; echo "$pass passed, $fail failed"
