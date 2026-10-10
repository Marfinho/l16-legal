<?php
require __DIR__ . '/lib.php';
use Lindenweg16\Legal\Withdrawal\Module as W;

$jar = [];
foreach ( [ 'kunde@example.com', 'falsch@example.com' ] as $e ) {
	delete_transient( 'll16_wd_' . md5( $e ) );
}
$order = wc_create_order();
$order->set_billing_email( 'kunde@example.com' );
$order->set_billing_first_name( 'Erika' );
$order->set_status( 'completed' );
$order->save();
$num = (string) $order->get_order_number();
$post_url = '/vertrag-widerrufen/';

section( 'Widerruf: Seite & Schritt 1' );
$r = http( 'GET', $post_url, [], $jar );
t( 'Seite erreichbar ohne Login', 200 === $r['code'] );
t( 'Formular mit Pflichtfeldern Name/Bestellnummer/E-Mail', str_contains( $r['body'], 'name="ll16_name"' ) && str_contains( $r['body'], 'name="ll16_order"' ) && str_contains( $r['body'], 'name="ll16_email"' ) );
t( 'Honeypot vorhanden, kein reCAPTCHA', str_contains( $r['body'], 'name="ll16_website"' ) && ! str_contains( $r['body'], 'recaptcha' ) );
t( 'Seite nicht cachebar', str_contains( strtolower( (string) $r['headers']['cache-control'] ), 'no-cache' ) );
t( 'Kein Konto/Nonce nötig (keine Cookies gesetzt)', [] === array_filter( array_keys( $jar ), fn( $k ) => str_contains( $k, 'logged_in' ) ) );
$ts = hidden( $r['body'], 'll16_ts' );
t( 'Signiertes Zeit-Token', '' !== $ts && 2 === count( explode( '.', $ts ) ) );

$fields = fn( array $o = [] ) => array_merge( [ 'll16_step' => 'review', 'll16_ts' => $ts, 'll16_name' => 'Erika Muster', 'll16_order' => $num, 'll16_email' => 'kunde@example.com', 'll16_scope' => '', 'll16_website' => '' ], $o );

$r = http( 'POST', $post_url, $fields(), $jar );
t( 'Sofortiges Absenden wird abgelehnt (Zeitprüfung)', str_contains( $r['body'], 'zu lange' ) || str_contains( $r['body'], 'erneut' ), substr( wp_strip_all_tags( $r['body'] ), 0, 160 ) );
sleep( 3 );
$r = http( 'POST', $post_url, $fields( [ 'll16_website' => 'http://spam' ] ), $jar );
t( 'Honeypot gefüllt → abgelehnt', str_contains( $r['body'], 'konnte nicht verarbeitet' ) );
$r = http( 'POST', $post_url, $fields( [ 'll16_name' => '' ] ), $jar );
t( 'Name fehlt → Fehlermeldung', str_contains( $r['body'], 'Namen' ) );
$r = http( 'POST', $post_url, $fields( [ 'll16_email' => 'kaputt' ] ), $jar );
t( 'Ungültige E-Mail → Fehlermeldung', str_contains( $r['body'], 'gültige E-Mail' ) );
$r = http( 'POST', $post_url, $fields( [ 'll16_ts' => 'manipuliert.abc' ] ), $jar );
t( 'Manipuliertes Token → abgelehnt', str_contains( $r['body'], 'erneut' ) );

section( 'Widerruf: Schritt 2 (Bestätigung)' );
clear_mails();
$r = http( 'POST', $post_url, $fields(), $jar );
t( 'Zusammenfassung zeigt Angaben', str_contains( $r['body'], 'Erika Muster' ) && str_contains( $r['body'], $num ) && str_contains( $r['body'], 'kunde@example.com' ) );
t( 'Button „Widerruf bestätigen“ vorhanden', str_contains( $r['body'], 'Widerruf bestätigen' ) );
t( 'Noch nichts gespeichert/gesendet vor Bestätigung', [] === mails() && 'completed' === wc_get_order( $order->get_id() )->get_status() );
$data = hidden( $r['body'], 'll16_data' );
t( 'Signierte Daten für Schritt 2', '' !== $data );

$r = http( 'POST', $post_url, [ 'll16_step' => 'confirm', 'll16_data' => $data . 'x' ], $jar );
t( 'Manipulierte Daten → abgelehnt', str_contains( $r['body'], 'ungültig' ) && 'completed' === wc_get_order( $order->get_id() )->get_status() );
$r = http( 'POST', $post_url, [ 'll16_step' => 'confirm', 'll16_data' => $data ], $jar );
t( 'Sofort bestätigen (<1 s) wird abgelehnt', str_contains( $r['body'], 'abgelaufen' ) || str_contains( $r['body'], 'Widerruf eingegangen' ) );
$already = str_contains( $r['body'], 'Widerruf eingegangen' );
if ( ! $already ) {
	sleep( 2 );
	$r = http( 'POST', $post_url, [ 'll16_step' => 'confirm', 'll16_data' => $data ], $jar );
}
t( 'Bestätigung → „Widerruf eingegangen“ mit Datum/Uhrzeit', str_contains( $r['body'], 'Widerruf eingegangen' ) && (bool) preg_match( '/\d{2}\.\d{2}\.\d{4}, \d{2}:\d{2}:\d{2} Uhr/', $r['body'] ) );
wp_cache_flush();
$o = wc_get_order( $order->get_id() );
t( 'Bestellstatus „Widerruf eingegangen“', 'll16-widerruf' === $o->get_status(), $o->get_status() );
$notes = array_map( fn( $n ) => $n->content, wc_get_order_notes( [ 'order_id' => $o->get_id() ] ) );
t( 'Bestellnotiz im Admin', (bool) array_filter( $notes, fn( $n ) => str_contains( $n, 'Widerruf online eingegangen' ) ) );
$wd = json_decode( (string) $o->get_meta( W::META ), true );
t( 'Widerruf an Bestellung gespeichert (UTC-Zeitstempel)', 1 === count( (array) $wd ) && ! empty( $wd[0]['received_at'] ) );
$ms = mails();
$ms = array_values( array_filter( $ms, fn( $m ) => str_contains( $m['subject'], 'Widerruf' ) ) );
t( 'Zwei Widerrufs-Mails (Kunde + Shop)', 2 === count( $ms ), (string) count( $ms ) );
$cust = array_values( array_filter( $ms, fn( $m ) => 'kunde@example.com' === $m['to'] ) )[0] ?? [];
t( 'Kundenmail: Eingangsbestätigung mit Datum, Uhrzeit, Inhalt', ! empty( $cust ) && str_contains( $cust['message'], 'Eingang:' ) && str_contains( $cust['message'], 'Erika Muster' ) && str_contains( $cust['message'], $num ) && str_contains( $cust['message'], 'gesamte Bestellung' ) );
t( 'Kundenmail: nur Eingang bestätigt, keine Wirksamkeitsaussage', str_contains( $cust['message'] ?? '', 'bestätigt nur den Eingang' ) );
$shop = array_values( array_filter( $ms, fn( $m ) => 'sven@lindenweg16.de' === $m['to'] && str_starts_with( $m['subject'], 'Widerruf eingegangen' ) ) )[0] ?? [];
t( 'Shopmail an Stammdaten-Adresse, Bestellung gefunden', ! empty( $shop ) && str_contains( $shop['message'], 'Bestellung gefunden' ) );
t( 'Status ist in WooCommerce-Statusliste', isset( wc_get_order_statuses()[ W::STATUS ] ) );

section( 'Widerruf: Bestellung nicht auffindbar / falsche E-Mail' );
clear_mails();
sleep( 3 );
$jar2 = [];
$r    = http( 'GET', $post_url, [], $jar2 );
$ts2  = hidden( $r['body'], 'll16_ts' );
sleep( 3 );
$o2 = wc_create_order();
$o2->set_billing_email( 'andere@example.com' );
$o2->set_status( 'completed' );
$o2->save();
$r    = http( 'POST', $post_url, [ 'll16_step' => 'review', 'll16_ts' => $ts2, 'll16_name' => 'Fremd', 'll16_order' => (string) $o2->get_order_number(), 'll16_email' => 'falsch@example.com', 'll16_scope' => 'Helmhalter' ], $jar2 );
$d2   = hidden( $r['body'], 'll16_data' );
sleep( 2 );
$r = http( 'POST', $post_url, [ 'll16_step' => 'confirm', 'll16_data' => $d2 ], $jar2 );
t( 'Verbraucher bekommt trotzdem Eingangsbestätigung (kein Informationsleck)', str_contains( $r['body'], 'Widerruf eingegangen' ) );
wp_cache_flush();
t( 'Fremde Bestellung bleibt unverändert', 'completed' === wc_get_order( $o2->get_id() )->get_status() );
$un = (array) get_option( W::UNMATCHED );
t( 'Landet in der Warteliste für manuelle Prüfung', (bool) array_filter( $un, fn( $e ) => 'falsch@example.com' === $e['email'] && 'Helmhalter' === $e['scope'] ) );
$shop = array_values( array_filter( mails(), fn( $m ) => 'sven@lindenweg16.de' === $m['to'] && str_starts_with( $m['subject'], 'Widerruf eingegangen' ) ) )[0] ?? [];
t( 'Shopmail warnt „manuell prüfen“', str_contains( $shop['message'] ?? '', 'manuell prüfen' ) );
t( 'Teilwiderruf-Umfang in Mail', str_contains( $shop['message'] ?? '', 'Teilwiderruf: Helmhalter' ) );

section( 'Widerruf: Missbrauchsschutz (Rate-Limit)' );
$blocked = false;
for ( $i = 0; $i < 7 && ! $blocked; $i++ ) {
	$r = http( 'POST', $post_url, [ 'll16_step' => 'confirm', 'll16_data' => $d2 ], $jar2 );
	$blocked = str_contains( $r['body'], 'Zu viele Versuche' );
}
t( 'Mehr als 5 Bestätigungen/Stunde je E-Mail werden gebremst', $blocked );

section( 'Widerruf: Verknüpfungen' );
$footer = http( 'GET', '/', [], $jar2 )['body'];
t( 'Footer-Link „Vertrag widerrufen“ auf jeder Seite', str_contains( $footer, '>Vertrag widerrufen<' ) && str_contains( $footer, '/vertrag-widerrufen/' ) );
$acts = W::order_action( [], $order );
t( 'Konto-Aktion „Vertrag widerrufen“ mit Bestellnummer', str_contains( $acts['ll16_withdraw']['url'], 'order=' . $num ) );
t( 'Shortcode-Link', str_contains( do_shortcode( '[ll16_widerruf_link]' ), 'Vertrag widerrufen' ) );
$text = W::receipt_text( [ 'name' => 'A', 'order' => '1', 'email' => 'a@b.de', 'scope' => '' ], '01.01.2027, 10:00:00 Uhr', \Lindenweg16\Legal\Core\Masterdata::all() );
t( 'Receipt-Text enthält Zeitpunkt und Adresse des Shops', str_contains( $text, '01.01.2027, 10:00:00 Uhr' ) && str_contains( $text, 'Lindenweg 16' ) );

finish();
