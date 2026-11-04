#!/usr/bin/env bash
# Update-Test über WordPress selbst: echtes Plugin-Upgrade (WP-CLI) gegen einen Mock der GitHub-API.
#   WP_PATH, WPCLI setzen. Ersetzt vorübergehend den Plugin-Symlink durch eine echte Kopie (Upgrade überschreibt Dateien!)
#   und stellt ihn am Ende wieder her.
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_SRC="$(cd "$HERE/../.." && pwd)"
WP="$WPCLI --allow-root --path=$WP_PATH"
PLUG="$WP_PATH/wp-content/plugins/lindenweg16-legal-suite"
WORK="$(mktemp -d)"
PORT=8080  # WordPress erlaubt sichere Downloads nur von den Ports 80, 443, 8080
FAIL=0
ok()   { echo "  ok    $1"; }
fail() { echo "  FAIL  $1"; FAIL=1; }
ver()  { $WP plugin get lindenweg16-legal-suite --field=version 2>/dev/null | tail -1; }

echo; echo "== Update über WordPress (WP-CLI) =="
mkdir -p "$WORK/files" "$WORK/build020" "$WORK/build021"
# Pakete bauen: 0.8.0 (installiert) und 0.8.1 (Update)
for v in 0.8.0 0.8.1; do
  B="$WORK/build${v//./}"; mkdir -p "$B"
  cp -r "$PLUGIN_SRC/." "$B/lindenweg16-legal-suite"
  rm -rf "$B/lindenweg16-legal-suite/dist" "$B/lindenweg16-legal-suite/tests/e2e/node_modules"
  sed -i "s/^ \* Version:.*/ * Version:           $v/; s/define( 'LL16_VERSION', '[^']*' )/define( 'LL16_VERSION', '$v' )/" "$B/lindenweg16-legal-suite/lindenweg16-legal-suite.php"
  bash "$B/lindenweg16-legal-suite/bin/build-zip.sh" "$B/out" >/dev/null || { fail "Paket $v nicht gebaut"; }
  cp "$B/out/"* "$WORK/files/"
done
ls "$WORK/files" | sed 's/^/        /'
# fehlerhaftes Paket 0.8.2: Inhalt von 0.8.1, aber falsche Prüfsumme
cp "$WORK/files/lindenweg16-legal-suite-0.8.1.zip" "$WORK/files/lindenweg16-legal-suite-0.8.2.zip"
echo "0000000000000000000000000000000000000000000000000000000000000000  lindenweg16-legal-suite-0.8.2.zip" > "$WORK/files/lindenweg16-legal-suite-0.8.2.zip.sha256"

# Mock-Server (alte Instanz auf dem Port beenden, sonst antwortet ein Server mit veralteten Dateien)
pkill -f "php -S 127.0.0.1:$PORT" 2>/dev/null; sleep 0.5
(cd "$HERE/updater" && MOCK_DIR="$WORK" setsid nohup php -S 127.0.0.1:$PORT mock-github.php > "$WORK/mock.log" 2>&1 &)
sleep 1
BASEURL="http://127.0.0.1:$PORT"
rel() { # version [flags]
  v="$1"; python3 - "$WORK" "$BASEURL" "$v" <<'PY'
import json,sys
w,b,v=sys.argv[1:4]
n=f"lindenweg16-legal-suite-{v}.zip"
a=[{"name":n,"url":f"{b}/repos/Marfinho/l16-legal/releases/assets/{n}","browser_download_url":f"{b}/dl/{n}"},
   {"name":n+".sha256","url":f"{b}/repos/Marfinho/l16-legal/releases/assets/{n}.sha256","browser_download_url":f"{b}/dl/{n}.sha256"}]
json.dump({"tag_name":"v"+v,"draft":False,"prerelease":False,"html_url":"https://github.com/Marfinho/l16-legal/releases/tag/v"+v,"body":"Test","published_at":"2026-11-04T10:00:00Z","assets":a},open(w+"/release.json","w"))
PY
}

# Plugin: Symlink → echte Kopie von 0.8.0
LINK_TARGET="$(readlink "$PLUG" || true)"
unlink "$PLUG" 2>/dev/null
unzip -q "$WORK/files/lindenweg16-legal-suite-0.8.0.zip" -d "$WP_PATH/wp-content/plugins/"
cleanup() {
  rm -rf "$WP_PATH/wp-content/plugins/lindenweg16-legal-suite"
  [ -n "$LINK_TARGET" ] && ln -sfn "$LINK_TARGET" "$PLUG"
  $WP option delete ll16_test_update_api >/dev/null 2>&1; $WP option delete ll16_test_token >/dev/null 2>&1
  pkill -f "php -S 127.0.0.1:$PORT" 2>/dev/null
  rm -rf "$WORK"
}
[ -n "${KEEP:-}" ] || trap cleanup EXIT
$WP plugin activate lindenweg16-legal-suite >/dev/null 2>&1
$WP option update ll16_test_update_api "$BASEURL" >/dev/null
$WP option update ll16_modules '{"consent":true,"gate":true,"legal":true,"withdrawal":true,"checkout":true,"product":true,"forms":true,"security":true,"monitor":true}' --format=json >/dev/null
[ "$(ver)" = "0.8.0" ] && ok "Ausgangslage: echte Kopie, Version 0.8.0" || fail "Ausgangsversion: $(ver)"

# A) PHP-Tests (Logik, Cache, Ungültiges, Admin)
rel 0.8.1
MOCK_DIR="$WORK" $WP eval-file "$HERE/12-updater.php" 2>&1 | grep -v "_load_textdomain_just_in_time" > "$WORK/a.out"
grep -E "FAIL|bestanden" "$WORK/a.out"
if ! grep -q " 0 fehlgeschlagen" "$WORK/a.out"; then FAIL=1; echo "  FAIL  Teil A (PHP-Tests) nicht sauber durchgelaufen:"; head -8 "$WORK/a.out" | cut -c1-240; fi

# WP-CLI cached heruntergeladene Pakete nach Dateiname: alte Test-Pakete 0.8.1 würden sonst (zu Recht) an der Prüfsumme scheitern
$WP cli cache clear >/dev/null 2>&1

# B) Echtes Update 0.8.0 → 0.8.1 ohne Token
rel 0.8.1
$WP option update ll16_settings '{}' --format=json >/dev/null
: > "$WORK/requests.log"
$WP eval 'delete_transient("ll16_update_info"); delete_site_transient("update_plugins");' >/dev/null 2>&1
out="$($WP plugin update lindenweg16-legal-suite 2>&1 | grep -v textdomain)"
echo "$out" | sed 's/^/        /' | head -8
[ "$(ver)" = "0.8.1" ] && ok "Update 0.8.0 → 0.8.1 über WordPress installiert" || fail "Version nach Update: $(ver)"
$WP plugin is-active lindenweg16-legal-suite && ok "Plugin ist nach dem Update weiterhin aktiv" || fail "Plugin nach Update inaktiv"
grep -q "'0.8.1'" "$PLUG/lindenweg16-legal-suite.php" && ok "Dateien wurden ersetzt (LL16_VERSION 0.8.1)" || fail "Dateien nicht ersetzt"
[ "$(grep -c '"auth": "[^"]' "$WORK/requests.log")" = "0" ] && ok "Ohne Token wurde kein Authorization-Header gesendet" || fail "Token ohne Konfiguration gesendet"
code=$(curl -s -o /dev/null -w "%{http_code}" "$WP_URL/" ); [ "$code" = "200" ] && ok "Website nach Update erreichbar ($code)" || fail "Website nach Update: $code"
$WP option get ll16_modules --format=json | grep -q '"consent":true' && ok "Einstellungen (Module) nach dem Update erhalten" || fail "Einstellungen verloren"
$WP eval 'echo (int) (get_option("ll16_db_version") === LL16_VERSION);' 2>/dev/null | tail -1 | grep -q 1 && ok "DB-Version auf 0.8.1 hochgezogen (Upgrade-Routine lief)" || fail "DB-Version nicht aktualisiert"

$WP cli cache clear >/dev/null 2>&1
# C) Manipuliertes Paket (falsche Prüfsumme) wird NICHT installiert
rel 0.8.2
$WP eval 'delete_transient("ll16_update_info"); delete_site_transient("update_plugins");' >/dev/null 2>&1
out="$($WP plugin update lindenweg16-legal-suite 2>&1 | grep -v textdomain)"
echo "$out" | grep -qi "Prüfsumme" && ok "Falsche Prüfsumme → Update mit Fehlermeldung abgebrochen" || fail "Keine Prüfsummen-Meldung: $out"
[ "$(ver)" = "0.8.1" ] && ok "Version bleibt nach abgelehntem Paket bei 0.8.1" || fail "Version nach Manipulation: $(ver)"

# D) Mit Token (privates Repo): Header nur an API/GitHub-Hosts, Download über API-Asset-URL mit octet-stream
rel 0.8.1   # gleiche Version → kein Update; nur Prüfung
$WP option update ll16_test_token 'github_pat_TESTTOKEN' >/dev/null
: > "$WORK/requests.log"
$WP eval 'delete_transient("ll16_update_info"); $i = \Lindenweg16\Legal\Core\GithubUpdater::latest(true); echo $i ? $i["version"]."|".$i["package"] : "null";' 2>/dev/null | tail -1 > "$WORK/tok.out"
grep -q "releases/assets/lindenweg16-legal-suite-0.8.1.zip" "$WORK/tok.out" && ok "Mit Token: Paket-URL ist die API-Asset-URL (privates Repo)" || fail "Paket-URL mit Token: $(cat "$WORK/tok.out")"
python3 - "$WORK/requests.log" <<'PY' && ok "Mit Token: API-Aufrufe tragen 'Bearer'-Header" || fail "Authorization fehlt"
import json,sys
r=[json.loads(l) for l in open(sys.argv[1]) if l.strip()]
assert r and all(x["auth"]=="Bearer github_pat_TESTTOKEN" for x in r), r
PY
python3 - "$WORK/requests.log" <<'PY' && ok "Prüfsumme wird mit Accept: application/octet-stream geholt" || fail "Accept-Header der Prüfsumme"
import json,sys
r=[json.loads(l) for l in open(sys.argv[1]) if l.strip()]
s=[x for x in r if x["path"].endswith(".sha256")]
assert s and s[0]["accept"]=="application/octet-stream", r
PY
$WP option delete ll16_test_token >/dev/null

echo; [ $FAIL = 0 ] && echo "0 fehlgeschlagen" || echo "FEHLER"
exit $FAIL
