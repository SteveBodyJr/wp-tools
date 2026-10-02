#!/bin/bash
# P9 translated addresses over HTTP (needs: slugs on; tour 378, about, day-trips drafted fr/es).
B=${BP_SITE:-http://localhost/raya-safaris-wp}; pass=0; fail=0
ok(){ if [ "$2" = "$3" ]; then pass=$((pass+1)); echo "PASS $1"; else fail=$((fail+1)); echo "FAIL $1 (got $2, want $3)"; fi; }
code(){ curl -s -o /dev/null -w "%{http_code}" "$@"; }
loc(){ curl -s -o /dev/null -w "%{redirect_url}" "$@"; }
T=tours/4-days-budget-camping-safari-tarangire-serengeti-ngorongoro; TF=tours/safari-en-camping-economique-de-4-jours-tarangire-serengeti-et-ngorongoro; TE=tours/safari-de-camping-economico-de-4-dias-tarangire-serengeti-y-ngorongoro
ok "FR translated tour 200" "$(code $B/fr/$TF/)" 200
ok "ES translated tour 200" "$(code $B/es/$TE/)" 200
ok "FR old tour 301" "$(code $B/fr/$T/)" 301
ok "FR old tour -> translated" "$(loc $B/fr/$T/)" "$B/fr/$TF/"
ok "old address keeps the query" "$(loc "$B/fr/about/?x=1")" "$B/fr/a-propos/?x=1"
ok "EN tour unchanged 200" "$(code $B/$T/)" 200
ok "EN with FR slug 404" "$(code $B/$TF/)" 404
ok "ZH (other script, original address) 200" "$(code $B/zh/about/)" 200
ok "DE old about 301" "$(code $B/de/about/)" 301
ok "FR page a-propos 200" "$(code $B/fr/a-propos/)" 200
ok "FR term old 301" "$(code $B/fr/tour-category/day-trips/)" 301
ok "FR term new 200" "$(code $B/fr/tour-category/excursions-a-la-journee/)" 200
H=$(curl -s $B/fr/$TF/)
# A complete page links its versions; one not complete yet (fallback) points to the original instead.
if grep -q 'id="bp-untranslated"' <<<"$H"; then
  ok "fallback: canonical = original" "$(grep -o '<link rel="canonical" href="[^"]*"' <<<"$H" | cut -d'"' -f4)" "$B/$T/"
  ok "fallback: no hreflang" "$(grep -c '<link rel="alternate" hreflang' <<<"$H")" 0
  H=$(curl -s $B/fr/a-propos/)  # and check the language links on a complete page
  ok "canonical translated" "$(grep -o '<link rel="canonical" href="[^"]*"' <<<"$H" | cut -d'"' -f4)" "$B/fr/a-propos/"
  ok "hreflang es translated" "$(grep -o 'hreflang="es-ES" href="[^"]*"' <<<"$H" | head -1 | cut -d'"' -f4)" "$B/es/acerca-de/"
  ok "hreflang en original" "$(grep -o 'hreflang="en-US" href="[^"]*"' <<<"$H" | head -1 | cut -d'"' -f4)" "$B/about/"
else
  ok "canonical translated" "$(grep -o '<link rel="canonical" href="[^"]*"' <<<"$H" | cut -d'"' -f4)" "$B/fr/$TF/"
  ok "hreflang es translated" "$(grep -o 'hreflang="es-ES" href="[^"]*"' <<<"$H" | head -1 | cut -d'"' -f4)" "$B/es/$TE/"
  ok "hreflang en original" "$(grep -o 'hreflang="en-US" href="[^"]*"' <<<"$H" | head -1 | cut -d'"' -f4)" "$B/$T/"
fi
ok "no FR link to the old address in FR home" "$(curl -s $B/fr/ | grep -c "$B/fr/about/\"")" 0
ok "EN page links stay original" "$(curl -s $B/ | grep -c "$B/a-propos/")" 0
echo; echo "$pass passed, $fail failed"
