# Ist-Zustand (aus WordPress-Export 10.10.2026) und Abgleich L1–L16

Quelle: WordPress-Export vom 10.10.2026 (Seiten, Produkte, Gutscheine; keine Bestellungen/Nutzer). Der Export liegt bewusst nicht im Repository (enthält Gutschein- und Kontaktdaten).

## Seiten
| Slug | Status | Bemerkung |
|---|---|---|
| `sample-page` | publish | AGB (zieht nach `/agb/`) |
| `sample-page-2` | publish | Datenschutzerklärung (zieht nach `/datenschutz/`) |
| `privacy-policy` | draft | WP-Standard, löschen |
| `vertrag-widerrufen` | **draft** | Widerrufsbelehrung → Ursache für 404 (L1). Wird zur Online-Widerrufsfunktion |
| `retouren` | publish | nur Shortcode `[shiptastic_return_request_form]` (Shiptastic) |
| `cookie-richtlinie-eu` | publish | Complianz-Seite |
| `impressum`, `contact-us`, `ueber-uns`, `shop`, `warenkorb`, `checkout` („Abschluss"), `my-account`, `konfigurator`, `home-2`, `startseite-test` | publish | `startseite-test` prüfen/entfernen |

## Produkte (11)
Alle `instock`; nur BSM hat Gewicht/Maße. **BSM ist ein einfaches Produkt, dessen Attribute `Farbe` und `Holz` als Varianten-Attribute markiert sind** → keine Auswahl erzwingbar (Ursache L7). Lösung: variables Produkt. Es laufen zusätzlich Google Listings & Ads (`_wc_gla_*`, Fehler „imageLink fehlt"), Pinterest (`_wc_pinterest_*`), rankingcoach (SEO), Botiga-Theme. Gutscheine: ein Rabattcode und zwei Geschenkgutschein-Codes im Export (hier bewusst nicht genannt).

## Befunde
| ID | Behoben durch | Rest / zu klären |
|---|---|---|
| L1 | M2 (`/widerruf/`, `/vertrag-widerrufen/`), M3, Redirects, Muster-Widerrufsformular | Wortlaut § 356a/Anlage 1 prüfen |
| L2 | M1 + M7 (Order Attribution aus, Jetpack-Stats aus, Skripte entfernt) | Hook-Namen gegen installierte Versionen **auf Staging verifizieren**; E2E |
| L3 | Stripe-Skripte nur Warenkorb/Kasse/Konto | WooPayments-Express auf Produktseiten entfällt; Einordnung „notwendig" klären |
| L4 | reCAPTCHA-Muster dauerhaft entfernt (Output + Dequeue) | PPCP-Einstellung zusätzlich abschalten |
| L5 | Maps → Zwei-Klick; Kontaktformular `[ll16_contact]` mit Hinweis | Formular auf `/contact-us/` einbauen |
| L6 | M4 (Button, Checkbox, Hinweis, DE, Versandkostenhinweis) | **WooPayments-Testmodus bis Launch**; Block-Checkout-Feldlabels mit Links begrenzt → Links im Hinweisblock |
| L7 | M4 Auswahlpflicht (blockiert Kauf) | BSM als variables Produkt anlegen |
| L8 | M5 einheitlicher Lagerstatus; Monitor | Ursache des doppelten Status im Theme/Germanized/Shiptastic auf Staging ermitteln; Bilder/Alt-Texte pflegen |
| L9 | M5 Preiszusatz, Footer-Prüfung, Lieferzeit-Stammdaten | Footer-Text im Theme manuell ändern; eine Lieferzeit wählen |
| L10 | M2 Datenschutz aus Register | Versanddienstleister, Hoster-Logfrist, Drittlandgrundlagen ergänzen |
| L11 | Cookie-Seite aus Register, eine Nummer/E-Mail | Telefon: Impressum nennt 01567373047, Kontakt/Cookies andere → **welche gilt?** |
| L12 | Eigenes Banner ohne Platzhalter | – |
| L13 | M5 GPSR-Tab + Sperre | Inhalte pro Produkt pflegen |
| L14 | M8 | `X-Powered-By` ggf. zusätzlich in php.ini/.htaccess |
| L15 | Slugs, Art. 246c-Schritte in AGB, OS-Link entfernt | – |
| L16 | Warnliste „handgemacht" etc. (Empfehlung: „3D-gedruckt und von Hand montiert") | Texte „Über uns"/Telefon-Claim manuell anpassen; Katrin nicht im Impressum, „Über uns" anpassen |
