#!/usr/bin/env bash
# Harter PHP-Fehler (Fatal) während „Seiten erzeugen“: darf nicht spurlos bleiben (weiße Seite beim Nutzer).
#   WP_PATH, WPCLI setzen.
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
WP="$WPCLI --allow-root --path=$WP_PATH"
FAIL=0
ok()   { echo "  ok    $1"; }
fail() { echo "  FAIL  $1"; FAIL=1; }
echo; echo "== Diagnose: Fehler beim Erzeugen der Seiten =="
$WP eval 'delete_option("ll16_last_error");' >/dev/null 2>&1

# 1) Undefinierte Funktion eines anderen Plugins (PHP-Error, abfangbar): übrige Seiten laufen weiter, Fehler ist sichtbar
$WP eval '
add_filter("wp_insert_post_data", function($d){ if (($d["post_name"] ?? "") === "agb") { nicht_vorhandene_funktion_eines_plugins(); } return $d; });
$r = \Lindenweg16\Legal\Legal\Module::sync_pages(false);
echo "ERG:".json_encode($r);' 2>/dev/null | grep "^ERG:" > /tmp/ll16-d1.out
grep -q '"agb":"FEHLER: Error: Call to undefined function nicht_vorhandene_funktion_eines_plugins' /tmp/ll16-d1.out && ok "Undefinierte Funktion: Seite meldet FEHLER mit Funktionsname" || fail "Meldung fehlt: $(cat /tmp/ll16-d1.out | cut -c1-300)"
grep -q '"impressum":"ok"' /tmp/ll16-d1.out && grep -q '"widerruf":"ok"' /tmp/ll16-d1.out && ok "Die anderen Seiten wurden trotzdem erzeugt" || fail "Folgeseiten nicht erzeugt"
$WP eval '$l=\Lindenweg16\Legal\Core\Diagnostics::last(); echo $l ? $l["kind"]."|".$l["message"] : "KEIN-EINTRAG";' 2>/dev/null | tail -1 | grep -q "abgefangene Ausnahme|Error: Call to undefined function" && ok "Als abgefangene Ausnahme aufgezeichnet" || fail "Eintrag fehlt/anders"

# 2) Echter harter Fehler (Zeitlimit überschritten, z. B. träge Fremdplugins): nicht abfangbar → Shutdown-Handler zeichnet ihn auf (der Nutzer sähe eine weiße Seite)
$WP eval 'delete_option("ll16_last_error");' >/dev/null 2>&1
$WP eval '
\Lindenweg16\Legal\Core\Diagnostics::arm("Seiten erzeugen");
add_filter("wp_insert_post_data", function($d){ if (($d["post_name"] ?? "") === "agb") { set_time_limit(1); while (true) { $z = md5((string) microtime()); } } return $d; });
\Lindenweg16\Legal\Legal\Module::sync_pages(false);' >/dev/null 2>&1
OUT="$($WP eval '$l=\Lindenweg16\Legal\Core\Diagnostics::last(); echo $l ? json_encode($l) : "KEIN-EINTRAG";' 2>/dev/null | tail -1)"
echo "$OUT" | grep -q "Maximum execution time" && ok "Zeitlimit-Fatal wurde aufgezeichnet (Meldung: Maximum execution time …)" || fail "Kein Eintrag: $OUT"
echo "$OUT" | grep -q "PHP-Fehler" && ok "Als „PHP-Fehler (Seite blieb weiß)“ gekennzeichnet" || fail "Art fehlt: $OUT"
PAGE="$($WP eval '\Lindenweg16\Legal\Core\Diagnostics::arm("Seiten erzeugen"); add_filter("wp_insert_post_data", function($d){ if (($d["post_name"] ?? "") === "agb") { set_time_limit(1); while (true) { $z = md5((string) microtime()); } } return $d; }); \Lindenweg16\Legal\Legal\Module::sync_pages(false);' 2>&1 | grep -v textdomain)"
echo "$PAGE" | grep -q "Maximum execution time" && ok "Die Fehlerseite selbst nennt jetzt den Fehler (statt leerer Box)" || fail "Fehlerseite ohne Fehlertext: $(echo "$PAGE" | head -3)"
echo "$OUT" | grep -q '"where":"[^/]' && ok "Fundstelle ohne absoluten Serverpfad" || fail "Pfad: $OUT"
$WP eval 'delete_option("ll16_last_error");' >/dev/null 2>&1
echo; [ $FAIL = 0 ] && echo "0 fehlgeschlagen" || echo "FEHLER"
exit $FAIL
