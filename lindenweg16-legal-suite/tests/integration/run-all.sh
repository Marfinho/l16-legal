#!/usr/bin/env bash
# Vollständiger Testlauf gegen eine echte WordPress-Instanz (WP-CLI + laufender Webserver).
#   WP_PATH   Pfad zu WordPress
#   WPCLI     WP-CLI-Aufruf (Standard: wp)
#   WP_URL    Basis-URL der Instanz (für die Browser-Tests)
#   LL16_CHROMIUM  optional: Pfad zu Chromium
# Die Instanz muss unter WP_URL erreichbar sein (z. B. php -S 127.0.0.1:8099 router.php).
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
WPCLI="${WPCLI:-wp}"
WP="$WPCLI --allow-root --path=$WP_PATH"
FAIL=0
run() { echo; echo "######## $1"; $WP eval-file "$HERE/$1" 2>&1 | grep -v "_load_textdomain_just_in_time" | tee /tmp/ll16-$1.out | grep -E "FAIL|bestanden|Fatal|Warning: Undefined|Deprecated: .*lindenweg16"; grep -q "0 fehlgeschlagen" /tmp/ll16-$1.out || FAIL=1; }

echo "== Reset =="
$WP plugin deactivate lindenweg16-legal-suite 2>&1 | tail -1
$WP plugin activate lindenweg16-legal-suite 2>&1 | tail -1
$WP option update ll16_modules '{"consent":true,"gate":true,"legal":true,"withdrawal":true,"checkout":true,"product":true,"forms":true,"security":true,"monitor":true}' --format=json | tail -1
$WP option update woocommerce_coming_soon no | tail -1
$WP option update woocommerce_store_pages_only no | tail -1
rm -f "$WP_PATH/wp-content/debug.log" "$WP_PATH/wp-content/mail.log"

for f in 01-activation.php 02-legal.php 03-consent.php 04-withdrawal.php 05-checkout-product.php 06-forms.php 07-security-monitor.php 08-admin.php 10-edge-cases.php; do run "$f"; done

echo; echo "######## WP-CLI: wp ll16-legal check"
$WP ll16-legal check --format=json > /tmp/ll16-cli.json 2>/tmp/ll16-cli.err; CLI=$?
echo "Exit-Code: $CLI (erwartet 1, weil Zahlungs-Gateways/Produktdaten in der Testinstanz fehlen)"
python3 -c "import json;d=json.load(open('/tmp/ll16-cli.json'));print(len(d),'Ergebnisse;', sum(1 for x in d if x['status']=='fail'),'Fehler')" || FAIL=1
[ "$CLI" = "1" ] || { echo "FAIL: CLI-Exit-Code"; FAIL=1; }

if [ -n "${WP_URL:-}" ] && [ -d "$HERE/../e2e/node_modules" ]; then
  echo; echo "######## Browser (Playwright)"
  $WP eval-file "$HERE/../e2e/setup-fixture.php" 2>&1 | grep -v textdomain | tail -1
  BASE_SLUG=$($WP post list --post_type=product --name=base --field=post_name 2>/dev/null | head -1)
  (cd "$HERE/../e2e" && \
    LL16_BASE_URL="$WP_URL" LL16_WP_PATH="$WP_PATH" LL16_WPCLI="$WPCLI" \
    LL16_PRODUCT_PATH="/product/${BASE_SLUG:-base}/" \
    LL16_PAGES="/,/shop/,/impressum/,/datenschutz/,/agb/,/widerruf/,/vertrag-widerrufen/,/versand-zahlung/,/cookies/,/cart/,/gate-test/" \
    npx playwright test consent-behaviour.spec.ts consent.spec.ts checkout.spec.ts checkout-flow.spec.ts scope.spec.ts ux.spec.ts 2>&1 | tail -25) || FAIL=1
  $WP option delete ll16_services >/dev/null 2>&1
fi

echo; echo "######## PHP-Log der Instanz"
if [ -f "$WP_PATH/wp-content/debug.log" ]; then
  grep -E "lindenweg16" "$WP_PATH/wp-content/debug.log" | head -20
  grep -c "lindenweg16" "$WP_PATH/wp-content/debug.log" | sed 's/^/Einträge mit Plugin-Bezug: /'
fi

run 09-uninstall.php
echo; [ "$FAIL" = "0" ] && echo "GESAMT: alles bestanden" || echo "GESAMT: FEHLER"
exit $FAIL
