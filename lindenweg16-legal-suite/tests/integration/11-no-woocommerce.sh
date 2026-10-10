#!/usr/bin/env bash
# Randfall: WooCommerce ist deaktiviert (Wartung, Update-Fehler). Das Plugin darf die Seite nicht beschädigen.
#   WP_PATH, WPCLI, WP_URL setzen. Aktiviert WooCommerce am Ende wieder.
set -u
WP="$WPCLI --allow-root --path=$WP_PATH"
FAIL=0
ok()   { echo "  ok    $1"; }
fail() { echo "  FAIL  $1"; FAIL=1; }
echo; echo "== WooCommerce deaktiviert =="
rm -f "$WP_PATH/wp-content/debug.log"
$WP plugin deactivate woocommerce >/dev/null 2>&1
for u in / /impressum/ /datenschutz/ /vertrag-widerrufen/ /kontakt-test/ /wp-login.php /wp-json/ll16/v1/csp /feed/ /nicht-da/; do
  code=$(curl -s -o /tmp/ll16-nowc.html -w "%{http_code}" "$WP_URL$u")
  if [ "$code" -lt 500 ] && [ "$code" -gt 0 ] && ! grep -qiE "Fatal error|Uncaught|Parse error" /tmp/ll16-nowc.html; then ok "$u → $code, kein Fatal Error"; else fail "$u → $code"; fi
done
body=$(curl -s "$WP_URL/")
echo "$body" | grep -q "ll16-banner\|LL16_CONSENT" && ok "Consent-Banner funktioniert auch ohne WooCommerce" || fail "Banner fehlt"
echo "$body" | grep -q 'class="ll16-footer-links"' && ok "Footer-Links vorhanden" || fail "Footer-Links fehlen"
out=$($WP ll16-legal check --format=json 2>&1); 
echo "$out" | python3 -c "import sys,json,re;t=sys.stdin.read();d=json.loads(t[t.index('['):t.rindex(']')+1]);print(len(d))" >/tmp/ll16-nowc-count 2>/dev/null && ok "Monitor läuft ohne WooCommerce durch ($(cat /tmp/ll16-nowc-count) Ergebnisse)" || fail "Monitor bricht ab: $(echo "$out" | tail -2)"
$WP eval 'echo json_encode(["pages"=>(new \Lindenweg16\Legal\Legal\Module)::class]);' >/dev/null 2>&1 && ok "Plugin-Code ladbar" || fail "Plugin-Code nicht ladbar"
if [ -f "$WP_PATH/wp-content/debug.log" ] && grep -E "lindenweg16" "$WP_PATH/wp-content/debug.log" | grep -vE "textdomain" | head -3 | grep -q .; then fail "PHP-Meldungen aus dem Plugin: $(grep lindenweg16 "$WP_PATH/wp-content/debug.log" | head -3)"; else ok "Keine PHP-Warnungen aus dem Plugin"; fi
$WP plugin activate woocommerce >/dev/null 2>&1
echo; [ $FAIL = 0 ] && echo "0 fehlgeschlagen" || echo "FEHLER"
exit $FAIL
