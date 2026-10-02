#!/bin/bash
# F1 translated address prefixes over HTTP (needs: slugs on; tour 378 and day-trips drafted fr/es).
# Saves fr "tours" -> "circuits", fr "tour-category" -> "categorie-circuit", es "destinations" ->
# "destinos" for the test and puts the owner's prefixes back on exit, however it ends.
cd "$(dirname "$0")"; B=${BP_SITE:-http://localhost/raya-safaris-wp}; PHP=${PHP_BIN:-php}; pass=0; fail=0
ok(){ if [ "$2" = "$3" ]; then pass=$((pass+1)); echo "PASS $1"; else fail=$((fail+1)); echo "FAIL $1 (got $2, want $3)"; fi; }
code(){ curl -s -o /dev/null -w "%{http_code}" "$@"; }
loc(){ curl -s -o /dev/null -w "%{redirect_url}" "$@"; }
wp(){ $PHP -r 'require getenv("BP_WP_LOAD") ?: dirname(getcwd(), 4) . "/wp-load.php"; '"$1"; }
SAVED=$(wp 'echo base64_encode(serialize(get_option(BP_Slug_Bases::OPTION, null)));')
restore(){ wp '$v=unserialize(base64_decode("'"$SAVED"'")); null===$v ? delete_option(BP_Slug_Bases::OPTION) : update_option(BP_Slug_Bases::OPTION,$v,false); BP_Slugs::forget_map(); BP_Cache::clear();'; }
trap restore EXIT
wp 'update_option(BP_Slug_Bases::OPTION, array("fr_FR"=>array("tours"=>"circuits","tour-category"=>"categorie-circuit"),"es_ES"=>array("destinations"=>"destinos")), false); BP_Slugs::forget_map(); BP_Cache::clear();'

S=safari-en-camping-economique-de-4-jours-tarangire-serengeti-et-ngorongoro; T=tours/4-days-budget-camping-safari-tarangire-serengeti-ngorongoro
ok "FR tour at the new prefix 200" "$(code $B/fr/circuits/$S/)" 200
ok "FR tour old prefix 301" "$(code $B/fr/tours/$S/)" 301
ok "FR old prefix -> new" "$(loc $B/fr/tours/$S/)" "$B/fr/circuits/$S/"
ok "FR original address -> new prefix and slug" "$(loc $B/fr/$T/)" "$B/fr/circuits/$S/"
ok "FR tours archive at the new prefix 200" "$(code $B/fr/circuits/)" 200
ok "FR old archive 301" "$(loc $B/fr/tours/)" "$B/fr/circuits/"
ok "FR term at new prefix and slug 200" "$(code $B/fr/categorie-circuit/excursions-a-la-journee/)" 200
ok "FR old term -> new" "$(loc $B/fr/tour-category/day-trips/)" "$B/fr/categorie-circuit/excursions-a-la-journee/"
ok "ES destinations archive 200" "$(code $B/es/destinos/)" 200
ok "ES tours keep their prefix (none saved) 200" "$(code $B/es/tours/safari-de-camping-economico-de-4-dias-tarangire-serengeti-y-ngorongoro/)" 200
ok "EN unchanged 200" "$(code $B/$T/)" 200
ok "EN with FR prefix 404" "$(code $B/circuits/$S/)" 404
ok "ZH keeps the original prefix 200" "$(code $B/zh/$T/)" 200
H=$(curl -s $B/fr/circuits/$S/)
if grep -q 'id="bp-untranslated"' <<<"$H"; then  # not complete yet: points to the original
  ok "fallback with new prefix: canonical = original" "$(grep -o '<link rel="canonical" href="[^"]*"' <<<"$H" | cut -d'"' -f4)" "$B/$T/"
  ok "fallback with new prefix: no hreflang" "$(grep -c '<link rel="alternate" hreflang' <<<"$H")" 0
else
  ok "canonical with new prefix" "$(grep -o '<link rel="canonical" href="[^"]*"' <<<"$H" | cut -d'"' -f4)" "$B/fr/circuits/$S/"
  ok "hreflang en original" "$(grep -o 'hreflang="en-US" href="[^"]*"' <<<"$H" | head -1 | cut -d'"' -f4)" "$B/$T/"
fi
ok "no FR link to /fr/tours/ on the FR home" "$(curl -s $B/fr/ | grep -c "$B/fr/tours/")" 0
ok "FR home links use /fr/circuits/" "$( [ "$(curl -s $B/fr/ | grep -c "$B/fr/circuits/")" -gt 0 ] && echo yes)" yes
ok "sitemap addresses use the prefix" "$(wp 'echo BP_Run::url_in(home_url("/tours/"), "fr_FR");')" "$B/fr/circuits/"
ok "page 2 of the archive 200" "$(code $B/fr/circuits/page/2/)" 200
ok "a page slug cannot be a prefix" "$(wp 'echo "" !== BP_Slug_Bases::clash("about", "tours", array()) ? "refused" : "taken";')" refused
ok "an original prefix cannot be a prefix" "$(wp 'echo "" !== BP_Slug_Bases::clash("destinations", "tours", array()) ? "refused" : "taken";')" refused
echo; echo "$pass passed, $fail failed"
