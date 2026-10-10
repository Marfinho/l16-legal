# Migration und Abschaltung der Altplugins

1. **Backup** (Datenbank + Dateien) auf Staging. Keine Backups ins Git-Repo.
2. Plugin installieren, Stammdaten füllen, Module aus.
3. Umschalten pro Modul auf Staging:
   1. **Consent + Gate** – Complianz-Banner aus (`Cookie-Banner deaktivieren`), Plugin aktiv lassen bis Schritt 6. Playwright-Consent-Test grün.
   2. **Rechtstexte** – Seiten erzeugen, prüfen, veröffentlichen; Footer-Links im Theme auf neue Slugs; 301 von `/sample-page/`, `/sample-page-2/`, `/cookie-richtlinie-eu/`, `/retouren/`, `/widerrufsbelehrung/`.
   3. **Widerruf** – Seite `/vertrag-widerrufen/` (bestehender Entwurf wird überschrieben), Testwiderruf auf Staging.
   4. **Checkout** – WooPayments/PayPal im Testmodus: Testkauf. Produkt BSM als **variables Produkt** neu anlegen (Farbe/Holz); sonst blockiert M4 den Kauf.
   5. **Produkte** – GPSR-Felder, Lieferzeiten, Bilder und Alt-Texte pflegen.
   6. **Formulare** – `[ll16_contact]` auf `/contact-us/`, Google-Maps-iframe wird automatisch Zwei-Klick-Platzhalter; WPForms entfernen.
   7. **Header** – HSTS 1 Woche, CSP Report-Only beobachten (`ll16_csp_reports`).
4. **Daten übernehmen**: Germanized `_ts_mpn`, `_weight`/`_shipping_*`, `_safety_instructions`, `_default_delivery_time` manuell oder per WP-CLI in die `_ll16_*`-Felder kopieren (kein Automatismus, da Datenqualität unbekannt). Newsletter: **keine Bestandsabonnenten** → nichts zu migrieren.
5. **Abnahme**: Monitor ohne ❌, E2E grün, manueller Testkauf (Karte, PayPal), Testwiderruf.
6. **Go-Live** im Wartungsfenster: WooPayments/PayPal auf Live, Testmodus-Wächter muss grün sein. Danach Altplugins nacheinander **deaktivieren** (Complianz → Germanized → Shiptastic → WPForms → Jetpack-Module → Pinterest/GLA), 14 Tage beobachten, dann entfernen.

## Rollback
- Vor jedem Schritt Backup. Modul im Admin ausschalten stellt den Zustand ohne Plugin-Eingriffe wieder her (außer angelegte Seiten).
- Altplugin wieder aktivieren; bei doppeltem Banner das M1-Flag ausschalten.
- Bei Zahlungsproblemen sofort Modul *Consent* und *Gate* aus (blockieren nur Drittskripte).
