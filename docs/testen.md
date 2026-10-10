# Tests

Vier Ebenen, alle gegen **echte** Software (WordPress 7.1.3, WooCommerce 11.2.1, PHP 8.3, Chromium):

| Ebene | Wo | Was |
|---|---|---|
| 1 Unit | `tests/Unit/PureLogicTest.php` | reine Logik (Rewriter, Template, Linter, Token, Selection, Headers, Retention …); `php tests/run-local.php` oder PHPUnit |
| 2 Integration | `tests/integration/0*.php` | läuft per `wp eval-file` im geladenen WordPress, dazu HTTP-Requests gegen den Webserver (Aktivierung/Schema, Rechtstexte, Consent-REST/Rewriter, Widerruf, Checkout/Produkt/Store-API, Formulare/Newsletter/DSGVO, Header/Monitor, Admin, Deinstallation) |
| 3 Browser | `tests/e2e/*.spec.ts` | Playwright mit Chromium: Banner-Verhalten, Allowlist für Requests/Cookies auf allen Seiten (vorher/Ablehnen/Akzeptieren), Block-Checkout mit echter Bestellung, Skript-Scope für Zahlungsdienste |
| 4 CLI | `run-all.sh` | `wp ll16-legal check` (Exit-Code) |

## Lauf von Null (ohne Docker/MySQL)

```bash
tests/integration/bootstrap-local.sh /pfad/zum/ziel 8099      # WordPress + SQLite + WooCommerce + Plugin, startet PHP-Server
export WP_PATH=/pfad/zum/ziel/site/wordpress WPCLI="php /pfad/zum/ziel/wp-cli.phar" WP_URL=http://127.0.0.1:8099
export LL16_CHROMIUM=/opt/pw-browsers/chromium                  # optional: vorhandenes Chromium
(cd tests/e2e && npm install)
tests/integration/run-all.sh
```

`run-all.sh` setzt die Instanz zurück (Plugin de-/aktivieren), führt 01–08 aus, die CLI, die Browser-Tests und zuletzt die Deinstallation (zerstörend, deshalb am Ende).

## Testmittel
- Mails werden abgefangen (`wp-content/mail.log`, mu-plugin `harness/ll16-test-harness.php`); HTTPS wird über den Header `X-Test-Https` simuliert; Fremdplugin-Skripte (Stripe/PayPal/reCAPTCHA/Stats) werden per Option `ll16_test_inject_third_party` simuliert.
- Die Testinstanz ist bewusst **ohne** WooPayments/PayPal/Germanized/Complianz/Jetpack: deren echte Skripte und Hook-Namen sind damit **nicht** getestet (siehe `docs/ist-zustand.md`, Spalte „Rest“).

## Bekannte Grenzen
- Zahlung über Stripe/PayPal (3-D-Secure, Apple/Google Pay) nicht testbar ohne Konten.
- LiteSpeed-Cache nicht vorhanden → Cachefreiheit nur über Header/Konstanten geprüft, nicht gegen echten Cache.
- Mailversand ist abgefangen; Zustellbarkeit/SPF/DKIM bei IONOS ungeprüft.
- Sprache der Testinstanz ist Englisch (WooCommerce-Texte); die Plugin-Texte sind Deutsch.
