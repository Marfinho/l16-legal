<?php
require __DIR__ . '/lib.php';
use Lindenweg16\Legal\Legal\Texts;
use Lindenweg16\Legal\Legal\Module as Legal;
use Lindenweg16\Legal\Legal\Linter;

section( 'Rechtstexte: Linter blockiert unvollständige Stammdaten' );
delete_option( 'll16_masterdata' );
delete_option( 'll16_text_versions' );
$res = Legal::sync_pages( true );
t( 'sync_pages liefert sechs Seiten', 6 === count( $res ) );
$agb = get_page_by_path( 'agb' );
t( 'AGB angelegt', (bool) $agb );
t( 'AGB bleibt Entwurf (Zahlungsarten fehlen → Platzhalter)', $agb && 'draft' === $agb->post_status, $agb ? $agb->post_status : '' );
t( 'Linter meldet Platzhalter', str_contains( (string) ( $res['agb'] ?? '' ), 'Linter' ), (string) ( $res['agb'] ?? '' ) );
t( 'Impressum ohne Platzhalter wird publiziert', 'publish' === get_page_by_path( 'impressum' )->post_status );

section( 'Rechtstexte: vollständige Stammdaten' );
$m                   = \Lindenweg16\Legal\Core\Masterdata::defaults();
$m['payment_methods'] = [ 'PayPal', 'Kreditkarte', 'Apple Pay' ];
$m['shipping_carrier'] = 'DHL Paket (Deutsche Post DHL Group)';
update_option( \Lindenweg16\Legal\Core\Masterdata::OPTION, $m );
$res = Legal::sync_pages( true );
foreach ( array_keys( Texts::PAGES ) as $slug ) {
	$p = get_page_by_path( $slug );
	t( "/{$slug}/ veröffentlicht, Linter sauber", $p && 'publish' === $p->post_status && 'ok' === $res[ $slug ], (string) $res[ $slug ] );
	t( "/{$slug}/ ohne Template-Reste", $p && ! str_contains( $p->post_content, '{{' ) );
}
$w = get_page_by_path( 'vertrag-widerrufen' );
t( 'Widerrufsfunktion-Seite mit Shortcode', $w && '[ll16_widerruf]' === $w->post_content );

section( 'Rechtstexte: Inhalte' );
$c = fn( $s ) => get_page_by_path( $s )->post_content;
t( 'Impressum: § 5 DDG, Anschrift', str_contains( $c( 'impressum' ), '§ 5 DDG' ) && str_contains( $c( 'impressum' ), 'Lindenweg 16' ) && str_contains( $c( 'impressum' ), '31559 Haste' ) );
t( 'Impressum: Kleinunternehmer', str_contains( $c( 'impressum' ), '§ 19 UStG' ) );
t( 'Impressum: genau eine Telefonnummer', 1 === count( \Lindenweg16\Legal\Monitor\Contacts::extract( $c( 'impressum' ) )['phones'] ) );
t( 'Kein OS-Plattform-Link mehr', ! str_contains( $c( 'impressum' ) . $c( 'agb' ), 'ec.europa.eu/consumers/odr' ) );
t( 'Nicht bereit zur Schlichtung (§ 36 VSBG)', str_contains( $c( 'impressum' ), '§ 36 VSBG' ) );
t( 'AGB: Button-Text identisch zum Checkout', str_contains( $c( 'agb' ), \Lindenweg16\Legal\Checkout\Module::BUTTON ) );
t( 'AGB: Art.-246c-Schritte, Speicherung, Sprache', str_contains( $c( 'agb' ), 'Eingabefehler' ) && str_contains( $c( 'agb' ), 'Vertragstext speichern' ) && str_contains( $c( 'agb' ), 'Vertragssprache' ) );
t( 'AGB: Widerrufsausschluss individuell', str_contains( $c( 'agb' ), 'individuell gefertigt' ) );
t( 'Widerruf: Muster-Formular & 30 Tage & Online-Funktion', str_contains( $c( 'widerruf' ), 'Muster-Widerrufsformular' ) && str_contains( $c( 'widerruf' ), '30 Tagen' ) && str_contains( $c( 'widerruf' ), '/vertrag-widerrufen/' ) );
t( 'Widerruf: Konfigurator-Standardware bleibt widerrufbar', str_contains( $c( 'widerruf' ), 'Konfigurator sind vorgefertigt' ) );
t( 'Versand: Kosten & Zahlungsarten', str_contains( $c( 'versand-zahlung' ), '6,99' ) && str_contains( $c( 'versand-zahlung' ), 'PayPal, Kreditkarte, Apple Pay' ) );
t( 'Versand: Kleinunternehmer statt "inkl. MwSt."', ! preg_match( '/inkl\.?\s*MwSt/i', $c( 'versand-zahlung' ) ) );
t( 'Datenschutz: Aufsichtsbehörde LfD Niedersachsen', str_contains( $c( 'datenschutz' ), 'Niedersachsen' ) );
t( 'Datenschutz: Widerspruchsrecht hervorgehoben', str_contains( $c( 'datenschutz' ), '<strong>Widerspruchsrecht (Art. 21 DSGVO)' ) );
t( 'Datenschutz: Stripe & PayPal aus Register', str_contains( $c( 'datenschutz' ), 'Stripe' ) && str_contains( $c( 'datenschutz' ), 'PayPal' ) );
t( 'Datenschutz: Google Maps aus Register', str_contains( $c( 'datenschutz' ), 'Google Maps' ) );
t( 'Datenschutz: Versanddienstleister', str_contains( $c( 'datenschutz' ), 'DHL' ) );
t( 'Datenschutz: Consent-Log ohne IP erwähnt', str_contains( $c( 'datenschutz' ), 'ohne IP-Adresse' ) );
t( 'Datenschutz: Newsletter fehlt solange deaktiviert', ! str_contains( $c( 'datenschutz' ), 'Double-Opt-in' ) );
t( 'Datenschutz: Querverweise/Nummerierung 1–10', 1 === preg_match_all( '/<h2>(10)\./', $c( 'datenschutz' ) ) && str_contains( $c( 'datenschutz' ), '<h2>5. Versand und Zahlung' ) );
t( 'Cookie-Seite: Tabelle mit ll16_consent', str_contains( $c( 'cookies' ), 'll16_consent' ) && str_contains( $c( 'cookies' ), 'wp_woocommerce_session_*' ) );
t( 'Cookie-Seite: keine "Zweck wird noch ermittelt"-Zeilen', ! str_contains( $c( 'cookies' ), 'ermittelt' ) );

$s = (array) get_option( 'll16_settings', [] );
$s['newsletter_enabled'] = true;
update_option( 'll16_settings', $s );
t( 'Datenschutz: Newsletter-Abschnitt erscheint nach Aktivierung', str_contains( Texts::render( 'datenschutz' ), 'Double-Opt-in' ) );
update_option( 'll16_settings', [] );

section( 'Versionierung' );
$v = (array) get_option( 'll16_text_versions' );
t( 'Version je Seite gespeichert', isset( $v['agb']['version'], $v['agb']['hash'], $v['agb']['date'] ) );
$before = $v['agb']['version'];
Legal::sync_pages( true );
t( 'Unveränderter Text erzeugt keine neue Version', $before === get_option( 'll16_text_versions' )['agb']['version'] );
$m['shipping_cost'] = '4,90';
update_option( \Lindenweg16\Legal\Core\Masterdata::OPTION, $m );
Legal::sync_pages( true );
t( 'Geänderter Text erzeugt neue Version', $before + 1 === get_option( 'll16_text_versions' )['agb']['version'] );
t( 'Neuer Preis steht im Text', str_contains( get_page_by_path( 'agb' )->post_content, '4,90' ) );
$m['shipping_cost'] = '6,99';
update_option( \Lindenweg16\Legal\Core\Masterdata::OPTION, $m );
Legal::sync_pages( true );

section( 'Linter beim manuellen Veröffentlichen' );
kses_remove_filters();
$id = wp_insert_post( [ 'post_type' => 'page', 'post_name' => 'datenschutz', 'ID' => get_page_by_path( 'datenschutz' )->ID, 'post_status' => 'publish', 'post_content' => '<p>Hier steht TODO und {title}</p>' ] );
t( 'Veröffentlichung mit TODO/{title} wird auf Entwurf gesetzt', 'draft' === get_post_status( $id ) );
Legal::sync_pages( true );
t( 'Danach wieder sauber veröffentlicht', 'publish' === get_post_status( $id ) );
foreach ( [ 'Lorem ipsum', 'Platzhalter', '{{x}}', 'Testkarte 4000', 'sven@ex.comlindenweg16.de', '/sample-page/' ] as $bad ) {
	t( "Linter erkennt: {$bad}", [] !== Linter::check( $bad ) );
}

section( 'Weiterleitungen (HTTP 301)' );
make_page( 'retouren', 'Retouren', '[shiptastic_return_request_form]' );
make_page( 'cookie-richtlinie-eu', 'Cookie-Richtlinie (EU)', 'alt' );
make_page( 'widerrufsbelehrung', 'alt', 'alt' );
$jar = [];
foreach ( [ 'sample-page' => 'agb', 'sample-page-2' => 'datenschutz', 'cookie-richtlinie-eu' => 'cookies', 'retouren' => 'vertrag-widerrufen', 'widerrufsbelehrung' => 'widerruf' ] as $old => $new ) {
	if ( ! get_page_by_path( $old ) ) {
		make_page( $old, 'alt', 'alt' );
	}
	$r = http( 'GET', "/{$old}/", [], $jar );
	t( "/{$old}/ → 301 /{$new}/", 301 === $r['code'] && str_ends_with( (string) $r['headers']['location'], "/{$new}/" ), $r['code'] . ' ' . ( $r['headers']['location'] ?? '' ) );
}
foreach ( array_merge( array_keys( Texts::PAGES ), [ 'vertrag-widerrufen' ] ) as $slug ) {
	$r = http( 'GET', "/{$slug}/", [], $jar );
	t( "/{$slug}/ → 200 und nicht leer", 200 === $r['code'] && strlen( $r['body'] ) > 2000, (string) $r['code'] );
}

section( 'Bestell-Snapshot' );
$order = wc_create_order();
$order->set_billing_email( 'kunde@example.com' );
$order->save();
Legal::snapshot_order( $order );
$order = wc_get_order( $order->get_id() );
$snap  = json_decode( (string) $order->get_meta( Legal::SNAPSHOT_META ), true );
t( 'Snapshot enthält AGB, Widerruf, Datenschutz', isset( $snap['texts']['agb'], $snap['texts']['widerruf'], $snap['texts']['datenschutz'] ) );
t( 'Snapshot-Hash passt zum Text', md5( $snap['texts']['agb']['html'] ) === $snap['texts']['agb']['hash'] );
$old_html = $snap['texts']['agb']['html'];
$m['shipping_cost'] = '9,99';
update_option( \Lindenweg16\Legal\Core\Masterdata::OPTION, $m );
Legal::snapshot_order( $order );
$order = wc_get_order( $order->get_id() );
$snap2 = json_decode( (string) $order->get_meta( Legal::SNAPSHOT_META ), true );
t( 'Snapshot ist unveränderlich (zweiter Aufruf überschreibt nicht)', $old_html === $snap2['texts']['agb']['html'] && ! str_contains( $snap2['texts']['agb']['html'], '9,99' ) );
$m['shipping_cost'] = '6,99';
update_option( \Lindenweg16\Legal\Core\Masterdata::OPTION, $m );
$att = Legal::attach_snapshot( [], 'customer_processing_order', $order );
t( 'Snapshot wird der Bestellbestätigung als HTML angehängt', 1 === count( $att ) && is_file( $att[0] ) && str_contains( (string) file_get_contents( $att[0] ), 'Allgemeine Geschäftsbedingungen' ) );
t( 'Anhang nur für Kundenmails', [] === Legal::attach_snapshot( [], 'admin_new_order', $order ) );
Legal::sync_pages( true );

finish();
