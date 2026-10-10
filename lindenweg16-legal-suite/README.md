# Lindenweg16 Legal Suite

WordPress-/WooCommerce-Plugin für lindenweg16.de. Ersetzt Complianz, Germanized-Rechtstexte, WPForms (Kontakt) und die Tracking-Standardeinstellungen von Jetpack/Order Attribution.
**Alle generierten Rechtstexte sind Entwürfe und nicht anwaltlich geprüft.**

Voraussetzungen: PHP ≥ 8.3, WordPress ≥ 6.5, WooCommerce mit Block-Checkout. Keine Laufzeit-Abhängigkeiten (eigener PSR-4-Autoloader, `vendor/` nur für Entwicklung).

## Architektur

Eine Quelle der Wahrheit, alles andere wird daraus erzeugt:

```
Stammdaten (ll16_masterdata)  ─┐
Dienste-Register (ll16_services)├─►  Rechtstexte (Impressum, Datenschutz, AGB, Widerruf, Versand, Cookies)
                                ├─►  Cookie-Banner + Cookie-Tabelle
                                ├─►  Skript-/iframe-Blockierung (Rewriter) + Gate (Dequeue)
                                └─►  Compliance-Monitor
```

| Modul | Namespace | Aufgabe |
|---|---|---|
| M1 Consent | `Consent\` | Banner (Vanilla JS, clientseitig → cachebar), serverseitiger Output-Rewriter, Consent-Log ohne IP, GPC, Re-Consent bei Registeränderung/12 Monaten |
| M7 Gate | `Gate\` | schaltet Order Attribution/Jetpack-Stats ab, dequeued Skripte nicht aktiver Dienste, Liste der Altplugins |
| M2 Rechtstexte | `Legal\` | Templates (`templates/*.html`), Linter, Versionen, 301-Weiterleitungen, Bestell-Snapshot (AGB/Widerruf/Datenschutz als HTML-Anhang der Bestellbestätigung) |
| M3 Widerruf | `Withdrawal\` | § 356a BGB: Seite `/vertrag-widerrufen/`, zweistufig, zustandslose signierte Tokens, Eingangsbestätigung, Bestellstatus „Widerruf eingegangen" |
| M4 Checkout | `Checkout\` | Button „Zahlungspflichtig bestellen", Hinweise/Links, Pflicht-Checkbox, nur DE, Auswahlpflicht, Testmodus-Wächter |
| M5 Produkt | `Product\` | Preiszusatz § 19 UStG, Lieferzeit, einheitlicher Lagerstatus, GPSR-Tab + Veröffentlichungssperre, Werbeaussagen-Warnliste |
| M6 Formulare | `Forms\` | Kontakt, Newsletter (Double-Opt-in), Verfügbarkeitsmeldung, WP-Privacy-Export/-Löschung, Aufbewahrung |
| M8 Security | `Security\` | HSTS (zunächst 1 Woche), nosniff, Referrer-Policy, X-Frame-Options, Permissions-Policy, CSP **Report-Only**, `X-Powered-By` entfernen |
| M9 Monitor | `Monitor\` | 12 Prüfgruppen, Dashboard, `wp ll16-legal check [--format=json]`, täglicher Cron + E-Mail bei Fehlern |

Admin: **Lindenweg16 Recht** (Status, Stammdaten, Module, Dienste, Rechtstexte, Monitor, Einstellungen, Export/Import JSON).

## Betriebshandbuch

1. Plugin aktivieren. **Alle Module sind zunächst aus.**
2. *Stammdaten* ausfüllen (Telefon: nur eine Nummer; Zahlungsarten; Versanddienstleister; Lieferzeit).
3. *Dienste*: Register prüfen. Inaktive Dienste werden nie geladen. Stripe/PayPal laden nur auf Warenkorb/Kasse/Konto.
4. Module einzeln einschalten (Reihenfolge: Consent → Rechtstexte → Widerruf → Checkout → Produkte → Formulare → Header). Altes Complianz-Banner vorher deaktivieren, sonst erscheinen zwei Banner.
5. *Rechtstexte* → „Seiten erzeugen". Seiten bleiben Entwurf, bis der Linter sauber ist; erst nach Prüfung veröffentlichen.
6. *Monitor* → Prüfung starten. Täglicher Lauf per Cron (`ll16_daily`). Für verlässlichen Lauf Server-Cron statt WP-Cron nutzen.
7. Footer-Links im Theme setzen: `/impressum/`, `/datenschutz/`, `/agb/`, `/widerruf/`, `/versand-zahlung/`, `/cookies/`, `/vertrag-widerrufen/`, Link `#ll16-cookies` (öffnet die Cookie-Einstellungen; alternativ Shortcode `[ll16_cookie_settings]`). Den Satz „Alle Preise inkl. MwSt." im Theme-Footer entfernen.

### LiteSpeed-Cache
Banner und Einwilligungsstatus werden rein clientseitig gerendert. Die Seiten `/vertrag-widerrufen/`, Kontaktformular, Newsletter und Benachrichtigung senden `litespeed_control_set_nocache`/`DONOTCACHEPAGE`. Die REST-Routen `ll16/v1/*` nicht cachen. Eine zusätzliche Ausnahme für das Cookie `ll16_consent` ist nicht nötig.

### Shortcodes
`[ll16_contact]`, `[ll16_newsletter]`, `[ll16_notify product="ID"]`, `[ll16_widerruf]`, `[ll16_widerruf_link]`, `[ll16_cookie_settings]`.

### Produktfelder
Im Reiter *Allgemein*: Lieferzeit, „Individuell angefertigt" (löst im Checkout eine zusätzliche Bestätigung aus), GPSR-Pflichtfelder. Ein Produkt wird nicht veröffentlicht, solange GPSR-Felder oder das Produktbild fehlen.

## Entwicklung

```
cd lindenweg16-legal-suite
composer install
composer test        # PHPUnit
php tests/run-local.php   # gleicher Satz ohne Composer
composer stan        # PHPStan Level 6
composer lint        # WPCS
cd tests/e2e && npm install && LL16_BASE_URL=https://staging.example npx playwright test
```

Vollständiger Testlauf gegen echtes WordPress/WooCommerce/Chromium (ohne Docker): `docs/testen.md`.

Siehe auch `docs/`: `testen.md` (Testebenen, Lauf von Null, Grenzen), `ist-zustand.md` (Befundabgleich L1–L16), `rechtslage.md` (recherchierte Quellen, offene Punkte), `migration.md` (Abschaltung der Altplugins, Rollback).
