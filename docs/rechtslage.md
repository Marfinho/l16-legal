# Rechtslage: Recherche und offene Punkte (Stand 10.10.2026)

Keine Rechtsberatung. Quellen sind überwiegend Sekundärquellen (IHK, Kanzleien); der amtliche Wortlaut wurde nur dort geprüft, wo angegeben. **Alle Punkte vor Go-Live anwaltlich prüfen lassen.**

| Thema | Stand der Recherche | Umsetzung | Status |
|---|---|---|---|
| Online-Widerrufsfunktion § 356a BGB (Richtlinie (EU) 2023/2673) | In Kraft seit 19.06.2026. Funktion „Vertrag widerrufen" dauerhaft und gut erreichbar; Angaben: Name, Vertragskennung, elektronischer Weg für die Bestätigung; Bestätigungsfunktion „Widerruf bestätigen"; danach unverzügliche Eingangsbestätigung auf dauerhaftem Datenträger mit Inhalt, Datum und Uhrzeit des Eingangs; rechtzeitig abgesendet = fristwahrend. Quelle: Zusammenfassung von dejure.org (nicht verbatim), [Rödl & Partner](https://www.roedl.com/insights/widerrufsbutton-neue-pflichten-online-haendler/), IHK. | M3 | Wortlaut auf gesetze-im-internet.de nicht abrufbar (503) → **Wortlaut und Anlage 1 EGBGB (Muster) manuell gegenprüfen** |
| OS-Plattform | Plattform seit 20.07.2025 eingestellt (VO (EU) 2024/3228); Link-Pflicht entfällt, Verweis kann abmahnbar sein. Quellen: IHK-Meldungen. | Link aus Impressum/AGB entfernt | Primärquelle EUR-Lex nicht geprüft |
| Verbraucherschlichtung § 36 VSBG | Bereitschaft/Nichtbereitschaft angeben | Stammdatenfeld, Standard „nicht bereit" | **Entscheidung des Inhabers bestätigen** |
| Gewährleistungshinweis DVO (EU) 2025/1960 | Gilt ab 27.09.2026; harmonisierte Mitteilung zur gesetzlichen Gewährleistung, Garantie-Label nur bei Herstellergarantie > 2 Jahre. Quellen: [Taylor Wessing](https://www.taylorwessing.com/de/insights-and-events/insights/2025/11/madewe/neue-eu-pflichten-kennzeichnung-von-herstellergarantie-und-gewaehrleistung-ab-2026), IHK/WKO. | Textfeld in Einstellungen, Standardtext **nicht** der amtliche Wortlaut | **Offiziellen Wortlaut der Kommission einsetzen** (Monitor warnt) |
| Individuell gefertigte Waren § 312g Abs. 2 Nr. 1 BGB | Ausschluss nur für nicht vorgefertigte, nach Kundenspezifikation gefertigte Waren. | Widerruf/AGB-Text, Produktflag, Checkout-Bestätigung | Wortlaut prüfen; Konfigurator ohne Personalisierung bleibt widerrufbar |
| Button „Zahlungspflichtig bestellen" § 312j Abs. 3 BGB | Beschriftung eindeutig. | M4 + AGB + Monitor | OK |
| Cookies/TDDDG § 25 | Einwilligung für nicht unbedingt erforderliche Zugriffe; Zahlungsdienste: Einordnung als „unbedingt erforderlich" ist Auslegungsfrage. | Stripe/PayPal nur auf Warenkorb/Kasse, im Register „notwendig" | **Zu klären** (Stripe `__stripe_mid`) |
| Aufbewahrungsfristen | § 147 AO, § 257 HGB; Fristen wurden zuletzt geändert (Buchungsbelege) | `retention_years` (Standard 8), Anonymisierung standardmäßig aus | **Steuerberater fragen** |
| BFSG | Ausnahme für Kleinstunternehmen prüfen (nicht recherchiert) | – | **Zu klären** |
| GPSR VO (EU) 2023/988 | Pflichtangaben: Hersteller mit Anschrift/E-Mail, Typ/Charge, Warn-/Sicherheitshinweise | M5 | Umfang der Pflichtangaben für diese Produkte prüfen |
| Preisangabenverordnung / Kleinunternehmer | Hinweis auf § 19 UStG, keine „inkl. MwSt."-Aussage | M5, Monitor | OK |
| Sie-Form | Nur Du-Form umgesetzt | – | Nicht umgesetzt (Backlog) |

## Annahmen in den Rechtstexten
- Freiwillige 30-Tage-Rückgabe: gleiche Rückgabeabwicklung wie beim Widerruf, **Rücksendekosten trägt der Kunde**, nicht für individuelle Anfertigungen. Bitte bestätigen.
- Lieferzeit-Standard „3–5 Werktage" (aus AGB); „Versand in 48 h" auf der Startseite widerspricht dem. **Eine Angabe wählen** (Stammdaten).
- Versandkosten pauschal 6,99 € (aus AGB). Die Lange-Pakete-Versandklasse (BSM) kann abweichen → prüfen.
- Gutscheine: kein eigener Text; Widerrufsrecht gilt wie bei Waren. Prüfen, ob eine Sonderregel nötig ist.
