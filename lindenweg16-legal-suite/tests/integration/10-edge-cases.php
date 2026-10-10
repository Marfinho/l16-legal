<?php
require __DIR__ . '/lib.php';
use Lindenweg16\Legal\Consent\Rewriter;
use Lindenweg16\Legal\Core\Masterdata;
use Lindenweg16\Legal\Core\Ratelimit;
use Lindenweg16\Legal\Forms\Module as Forms;
use Lindenweg16\Legal\Legal\Module as Legal;
use Lindenweg16\Legal\Withdrawal\Module as W;
global $wpdb;

reset_ratelimits();
clear_mails();
$jar = [];

/* ---------------------------------------------------------------------------------------- */
section( 'Consent-Blockierung: Randfälle im HTML' );
make_page(
	'edge-block',
	'Edge',
	'<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="dns-prefetch" href="//stats.wp.com">'
	. '<link href="https://fonts.googleapis.com/css2?family=Roboto" rel="stylesheet">'
	. '<img src="https://stats.wp.com/g.gif?x=1" width="1" height="1" alt="">'
	. '<img src="https://www.facebook.com/tr?id=1&ev=PageView" alt="">'
	. '<SCRIPT SRC="https://stats.wp.com/UPPER.js" ASYNC></SCRIPT>'
	. '<script async src=https://stats.wp.com/noquotes.js></script>'
	. "<script type='text/javascript' src='https://www.google.com/recaptcha/api.js'></script>"
	. '<script type="application/ld+json">{"url":"https://stats.wp.com/info"}</script>'
	. '<script>var note="https://stats.wp.com/in-string";</script>'
	. '<script src="/own.js?ref=https://stats.wp.com"></script>'
	. '<p>Text mit stats.wp.com im Fließtext</p>'
	. '<iframe src="https://www.google.com/maps/embed?pb=!1m18" loading="lazy" title="Karte"></iframe>'
	. '<noscript><iframe src="https://www.google.com/maps/embed?pb=noscript"></iframe></noscript>'
);
$b = http( 'GET', '/edge-block/', [], $jar )['body'];
t( 'Seite ausgeliefert', str_contains( $b, 'Text mit stats.wp.com im Fließtext' ) );
t( 'preconnect/dns-prefetch/Fonts-Stylesheet zu Drittanbietern entfernt', ! preg_match( '/<link[^>]+(fonts\.googleapis|stats\.wp\.com)/i', $b ) );
t( 'Tracking-Pixel (<img>) entfernt', ! preg_match( '/<img[^>]+(stats\.wp\.com|facebook\.com\/tr)/i', $b ) );
t( 'Großgeschriebenes SCRIPT-Tag entfernt', ! str_contains( $b, 'UPPER.js' ) );
t( 'Script-Attribut ohne Anführungszeichen entfernt', ! str_contains( $b, 'noquotes.js' ) );
t( 'Einfache Anführungszeichen (reCAPTCHA) entfernt', ! str_contains( $b, 'recaptcha/api.js' ) );
t( 'JSON-LD bleibt unangetastet', str_contains( $b, '"url":"https://stats.wp.com/info"' ) );
t( 'Inline-Skript mit Muster im String wird entfernt (konservativ)', ! str_contains( $b, 'in-string' ) );
t( 'Eigenes Skript mit Muster nur im Query-String bleibt unangetastet (kein Fehlalarm)', str_contains( $b, '<script src="/own.js?ref=https://stats.wp.com"></script>' ) );
t( 'Mehrere Maps-iframes → je ein Platzhalter, kein Request-iframe', 2 <= substr_count( $b, 'data-ll16-load="google-maps"' ) && ! preg_match( '/<iframe[^>]+\ssrc="https:\/\/www\.google/i', $b ) );
t( 'Ohne JavaScript: Link „in neuem Tab öffnen“ als Ausweg', str_contains( $b, '<noscript><p><a href="https://www.google.com/maps/embed' ) && str_contains( $b, 'noopener noreferrer' ) );
$big  = str_repeat( '<div class="x">Text ' . str_repeat( 'a', 40 ) . '</div><script src="/ok.js"></script>', 20000 );
$t0   = microtime( true );
$out  = Rewriter::rewrite( '<html>' . $big . '<script src="https://stats.wp.com/x.js"></script></html>', [ [ 'id' => 'j', 'patterns' => [ 'stats.wp.com' ], 'mode' => 'remove' ] ] );
$ms   = ( microtime( true ) - $t0 ) * 1000;
t( 'Große Seite (≈1,8 MB, 20.000 Skripte) wird in unter 1,5 s verarbeitet', $ms < 1500 && ! str_contains( $out, 'stats.wp.com' ), round( $ms ) . ' ms' );
t( 'Regex-Fehler (zu große Eingabe) liefert Original statt leerer Seite', '' !== Rewriter::rewrite( str_repeat( '<script>', 2000 ) . 'x', [ [ 'id' => 'j', 'patterns' => [ 'a' ], 'mode' => 'remove' ] ] ) );
foreach ( [ 'HEAD' => [ 'HEAD', '/' ], 'JSON-Accept' => [ 'GET', '/' ], 'Suche' => [ 'GET', '/?s=test' ], '404' => [ 'GET', '/gibt-es-nicht/' ], 'Feed' => [ 'GET', '/feed/' ], 'robots' => [ 'GET', '/robots.txt' ] ] as $label => [ $m, $u ] ) {
	$r = http( $m, $u, [], $jar, 'JSON-Accept' === $label ? [ 'Accept' => 'application/json' ] : [] );
	t( "{$label}: keine Fehlerseite (kein 5xx), Header gesetzt", $r['code'] > 0 && $r['code'] < 500 && ! empty( $r['headers']['x-content-type-options'] ), (string) $r['code'] );
}
$f = http( 'GET', '/feed/', [], $jar );
t( 'Feed ist valides XML (Rewriter greift nicht in Feeds)', null !== @simplexml_load_string( $f['body'] ) );

/* ---------------------------------------------------------------------------------------- */
section( 'Widerruf: Randfälle der Eingabe' );
$mk = function ( string $email, string $status = 'completed', string $when = 'now' ) {
	$o = wc_create_order();
	$o->set_billing_email( $email );
	$o->set_status( $status );
	$o->set_date_created( strtotime( $when ) );
	$o->save();
	return $o;
};
$flow = function ( array $f, ?array &$jarx = null, bool $confirm = true ) {
	$jarx = $jarx ?? [];
	$r    = http( 'GET', '/vertrag-widerrufen/', [], $jarx );
	$ts   = hidden( $r['body'], 'll16_ts' );
	sleep( 3 );
	$rv = http( 'POST', '/vertrag-widerrufen/', array_merge( [ 'll16_step' => 'review', 'll16_ts' => $ts, 'll16_name' => 'T', 'll16_order' => '1', 'll16_email' => 'a@b.de', 'll16_scope' => '' ], $f ), $jarx );
	if ( ! $confirm ) {
		return [ $rv, null ];
	}
	sleep( 2 );
	return [ $rv, http( 'POST', '/vertrag-widerrufen/', [ 'll16_step' => 'confirm', 'll16_data' => hidden( $rv['body'], 'll16_data' ) ], $jarx ) ];
};
$o1 = $mk( 'Gross.Klein+tag@Example.COM' );
foreach ( [ 'mit Raute' => '#' . $o1->get_order_number(), 'mit Leerzeichen' => '  ' . $o1->get_order_number() . ' ', 'mit Präfix' => 'WC-' . $o1->get_order_number() ] as $label => $num ) {
	reset_ratelimits();
	$o = $mk( 'Gross.Klein+tag@Example.COM' );
	$num2 = str_replace( $o1->get_order_number(), $o->get_order_number(), $num );
	[ , $c ] = $flow( [ 'll16_order' => $num2, 'll16_email' => ' gross.klein+tag@example.com ' ] );
	wp_cache_flush();
	t( "Bestellnummer {$label}, E-Mail in anderer Schreibweise → Bestellung gefunden", 'll16-widerruf' === wc_get_order( $o->get_id() )->get_status(), $num2 . ' ' . substr( wp_strip_all_tags( $c['body'] ), 0, 80 ) );
}
reset_ratelimits();
clear_mails();
$name = 'Jürgen Müller-Lüdenscheidt ß 日本語 😀 O\'Brien';
$o    = $mk( 'uni@example.com' );
[ , $c ] = $flow( [ 'll16_name' => $name, 'll16_order' => (string) $o->get_order_number(), 'll16_email' => 'uni@example.com', 'll16_scope' => 'Helm & "Schuh" <b>' ] );
$cust = array_values( array_filter( mails(), fn( $m ) => 'uni@example.com' === $m['to'] && str_starts_with( $m['subject'], 'Eingangsbestätigung' ) ) )[0] ?? [];
t( 'Unicode/Emoji/Apostroph im Namen bleiben in der Mail erhalten', str_contains( $cust['message'] ?? '', 'Jürgen Müller-Lüdenscheidt ß 日本語 😀' ), substr( $cust['message'] ?? '', 0, 120 ) );
t( 'Teilwiderruf-Text ohne HTML-Tags in der Mail', str_contains( $cust['message'] ?? '', 'Helm & "Schuh"' ) && ! str_contains( $cust['message'] ?? '', '<b>' ) );
t( 'Bestätigungsseite escaped den Namen', ! str_contains( $c['body'], '<b>' ) );
reset_ratelimits();
[ $rv ] = $flow( [ 'll16_name' => '"><script>alert(1)</script>', 'll16_order' => '5', 'll16_email' => 'x@example.com', 'll16_scope' => "'><img src=x onerror=alert(2)>" ], $j9, false );
t( 'XSS in Name/Umfang wird auf der Prüfseite nicht ausgeführt', ! str_contains( $rv['body'], '<script>alert(1)' ) && ! str_contains( $rv['body'], '<img src=x' ) );
$g = http( 'GET', '/vertrag-widerrufen/?order=' . rawurlencode( '"><script>alert(3)</script>' ), [], $jar )['body'];
t( 'XSS im ?order=-Parameter wird escaped', ! str_contains( $g, '<script>alert(3)' ) );
$g = http( 'GET', '/vertrag-widerrufen/?order=4711', [], $jar )['body'];
t( 'Bestellnummer aus Konto-Link ist vorbelegt', str_contains( $g, 'value="4711"' ) );
reset_ratelimits();
[ $rv ] = $flow( [ 'll16_name' => str_repeat( 'Ä', 500 ), 'll16_email' => 'x@example.com' ], $j8, false );
t( 'Sehr langer Name wird serverseitig auf 120 Zeichen begrenzt', 120 === mb_strlen( (string) ( W::class ? ( preg_match( '/<dd>(Ä+)<\/dd>/u', $rv['body'], $mm ) ? $mm[1] : '' ) : '' ) ) );

section( 'Widerruf: Bestellstatus und Hinweise für den Shop' );
clear_mails();
foreach ( [ 'cancelled', 'refunded' ] as $st ) {
	reset_ratelimits();
	$o = $mk( "st-{$st}@example.com", $st, '-40 days' );
	$flow( [ 'll16_order' => (string) $o->get_order_number(), 'll16_email' => "st-{$st}@example.com" ] );
	wp_cache_flush();
	$notes = implode( ' | ', array_map( fn( $n ) => $n->content, wc_get_order_notes( [ 'order_id' => $o->get_id() ] ) ) );
	t( "Status „{$st}“ wird NICHT überschrieben, Widerruf trotzdem vermerkt", $st === wc_get_order( $o->get_id() )->get_status() && str_contains( $notes, 'Widerruf online eingegangen' ) && str_contains( $notes, 'bleibt unverändert' ), $notes );
}
reset_ratelimits();
clear_mails();
$o = $mk( 'alt@example.com', 'completed', '-40 days' );
$flow( [ 'll16_order' => (string) $o->get_order_number(), 'll16_email' => 'alt@example.com' ] );
$shop = array_values( array_filter( mails(), fn( $m ) => str_starts_with( $m['subject'], 'Widerruf eingegangen' ) ) )[0] ?? [];
t( 'Shopmail nennt Bestelldatum, Alter in Tagen und vorherigen Status', str_contains( $shop['message'] ?? '', 'vor 40 Tagen' ) && str_contains( $shop['message'] ?? '', 'vorher: Abgeschlossen' ) && str_contains( $shop['message'] ?? '', '14 Tage' ), substr( $shop['message'] ?? '', -260 ) );
t( 'Widerruf nach 40 Tagen wird trotzdem angenommen (Prüfung liegt beim Shop)', 'll16-widerruf' === wc_get_order( $o->get_id() )->get_status() );

section( 'Widerruf: Eingabe nach langer Wartezeit, „Angaben ändern“, Mailfehler' );
reset_ratelimits();
$jx = [];
$r  = http( 'GET', '/vertrag-widerrufen/', [], $jx );
$old_ts = \Lindenweg16\Legal\Withdrawal\Token::sign( [], time() - 9000, wp_salt( 'auth' ) . 'll16-withdrawal' );
$r  = http( 'POST', '/vertrag-widerrufen/', [ 'll16_step' => 'review', 'll16_ts' => $old_ts, 'll16_name' => 'Langsam', 'll16_order' => '77', 'll16_email' => 'langsam@example.com' ], $jx );
t( 'Nach über 2 Stunden: freundliche Meldung, Eingaben bleiben erhalten', str_contains( $r['body'], 'lange geöffnet' ) && str_contains( $r['body'], 'value="Langsam"' ) && str_contains( $r['body'], 'value="langsam@example.com"' ) );
sleep( 3 );
$r  = http( 'GET', '/vertrag-widerrufen/', [], $jx );
$ts = hidden( $r['body'], 'll16_ts' );
sleep( 3 );
$rv = http( 'POST', '/vertrag-widerrufen/', [ 'll16_step' => 'review', 'll16_ts' => $ts, 'll16_name' => 'Zurück', 'll16_order' => '78', 'll16_email' => 'zurueck@example.com', 'll16_scope' => 'Haken' ], $jx );
$ed = http( 'POST', '/vertrag-widerrufen/', [ 'll16_step' => 'edit', 'll16_data' => hidden( $rv['body'], 'll16_data' ) ], $jx );
t( '„Angaben ändern“ zeigt das Formular mit allen bisherigen Werten', str_contains( $ed['body'], 'value="Zurück"' ) && str_contains( $ed['body'], 'value="78"' ) && str_contains( $ed['body'], 'value="Haken"' ) && str_contains( $ed['body'], 'name="ll16_ts"' ) );
t( 'Prüfseite hat beide Schaltflächen', str_contains( $rv['body'], 'Widerruf bestätigen' ) && str_contains( $rv['body'], 'Angaben ändern' ) );
$ed2 = http( 'POST', '/vertrag-widerrufen/', [ 'll16_step' => 'edit', 'll16_data' => 'kaputt' ], $jx );
t( '„Angaben ändern“ mit manipuliertem Token: leeres Formular statt Fehler', 200 === $ed2['code'] && str_contains( $ed2['body'], 'name="ll16_name"' ) );
update_option( 'll16_test_mail_fail', 1 );
clear_mails();
reset_ratelimits();
$o = $mk( 'mailfail@example.com' );
[ , $c ] = $flow( [ 'll16_order' => (string) $o->get_order_number(), 'll16_email' => 'mailfail@example.com' ] );
wp_cache_flush();
t( 'Mailversand scheitert: Widerruf ist trotzdem erfasst (Status gesetzt)', 'll16-widerruf' === wc_get_order( $o->get_id() )->get_status() && str_contains( $c['body'], 'Widerruf eingegangen' ) );
delete_option( 'll16_test_mail_fail' );

/* ---------------------------------------------------------------------------------------- */
section( 'Kontaktformular: Randfälle' );
make_page( 'kontakt-edge', 'Kontakt', '[ll16_contact]' );
reset_ratelimits();
$wpdb->query( "DELETE FROM {$wpdb->prefix}ll16_contact" );
$cj = [];
$get = fn() => hidden( http( 'GET', '/kontakt-edge/', [], $cj )['body'], 'll16_ts' );
$post = function ( array $o = [] ) use ( &$cj, $get ) {
	static $ts = null;
	if ( null === $ts ) { $ts = $get(); sleep( 4 ); }
	return http( 'POST', '/kontakt-edge/', array_merge( [ 'll16_form' => 'contact', 'll16_ts' => $ts, 'll16_name' => 'Max', 'll16_email' => 'max@example.com', 'll16_message' => 'Hallo', 'll16_privacy' => '1', 'll16_website' => '' ], $o ), $cj );
};
$x = $post( [ 'll16_message' => "Zeile 1\n\nZeile 3 ä ö ü ß 😀", 'll16_name' => "Evil\r\nBcc: spam@example.com", 'll16_email' => "max@example.com\r\nBcc: x@example.com" ] );
$mail = array_values( array_filter( mails(), fn( $m ) => str_starts_with( $m['subject'], 'Kontaktanfrage' ) ) )[0] ?? [];
t( 'Header-Injection (CRLF) in Name/E-Mail: Betreff ohne Zeilenumbruch, nichts ungewöhnliches gesendet', ! str_contains( $mail['subject'] ?? '', "\n" ) && ! str_contains( $mail['subject'] ?? '', "\r" ) );
$msg = $wpdb->get_var( "SELECT message FROM {$wpdb->prefix}ll16_contact ORDER BY id DESC LIMIT 1" );
t( 'Zeilenumbrüche und Umlaute/Emoji bleiben in der Nachricht', is_string( $msg ) && str_contains( $msg, "Zeile 1\n\nZeile 3 ä ö ü ß 😀" ), (string) $msg );
$x = $post( [ 'll16_message' => str_repeat( 'x', 5001 ) ] );
t( 'Zu lange Nachricht: klare Meldung, Text bleibt im Feld erhalten', str_contains( $x['body'], 'zu lang' ) && str_contains( $x['body'], str_repeat( 'x', 100 ) ) );
$x = $post( [ 'll16_message' => 'Mein <b>wichtiger</b> Text', 'll16_email' => 'kaputt', 'll16_name' => 'Anna' ] );
t( 'Fehler: eingegebene Werte bleiben im Formular (nichts muss neu getippt werden)', str_contains( $x['body'], 'value="Anna"' ) && str_contains( $x['body'], 'wichtiger' ) );
t( 'Fehlermeldung ist ein Alert für Screenreader', str_contains( $x['body'], 'role="alert"' ) );
update_option( 'll16_test_mail_fail', 1 );
$x = $post( [ 'll16_message' => 'Mail kaputt' ] );
t( 'Mailversand scheitert: Nutzer bekommt ehrliche Fehlermeldung mit Alternative, Text bleibt', str_contains( $x['body'], 'nicht zugestellt' ) && str_contains( $x['body'], 'sven@lindenweg16.de' ) && str_contains( $x['body'], 'Mail kaputt' ) && ! str_contains( $x['body'], 'angekommen' ) );
delete_option( 'll16_test_mail_fail' );
reset_ratelimits();
$wpdb->query( "DELETE FROM {$wpdb->prefix}ll16_contact" );
$ok = 0;
$blocked = false;
for ( $i = 0; $i < 7; $i++ ) {
	$x = $post( [ 'll16_message' => "Nachricht {$i}" ] );
	if ( str_contains( $x['body'], 'angekommen' ) ) { ++$ok; }
	if ( str_contains( $x['body'], 'mehrere Nachrichten' ) ) { $blocked = true; }
}
t( 'Spam-Schutz: höchstens 5 Nachrichten je Stunde und Besucher', 5 === $ok && $blocked, "ok={$ok}" );
reset_ratelimits();

section( 'Newsletter: Randfälle' );
make_page( 'nl-edge', 'NL', '[ll16_newsletter]' );
$wpdb->query( "DELETE FROM {$wpdb->prefix}ll16_subscribers" );
$nj  = [];
$tok = hidden( http( 'GET', '/nl-edge/', [], $nj )['body'], 'll16_ts' );
sleep( 4 );
$nl = fn( array $o ) => http( 'POST', '/nl-edge/', array_merge( [ 'll16_form' => 'newsletter', 'll16_ts' => $tok, 'll16_email' => 'a@example.com', 'll16_consent' => '1', 'll16_website' => '' ], $o ), $nj );
$long = str_repeat( 'a', 180 ) . '@example.com';
$x = $nl( [ 'll16_email' => $long ] );
t( 'E-Mail > 190 Zeichen: klare Meldung statt stillem Fehler', str_contains( $x['body'], 'gültige E-Mail' ) && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ll16_subscribers" ) );
$x = $nl( [ 'll16_email' => 'x@example.com', 'll16_consent' => '' ] );
t( 'Ohne Einwilligung: Meldung, E-Mail-Adresse bleibt eingetragen', str_contains( $x['body'], 'Einwilligung' ) && str_contains( $x['body'], 'value="x@example.com"' ) );
clear_mails();
$nl( [ 'll16_email' => 'Mixed.Case+tag@Example.com' ] );
$mail = mails()[0] ?? [];
t( 'Mixed-Case/Plus-Adresse bekommt die Bestätigungsmail', 'mixed.case+tag@example.com' === strtolower( (string) ( $mail['to'] ?? '' ) ) );
clear_mails();
$nl( [ 'll16_email' => 'Mixed.Case+tag@Example.com' ] );
t( 'Zweite Anmeldung derselben Adresse binnen 10 Minuten löst keine zweite Mail aus (kein Mail-Relay)', [] === mails() );
t( 'Anmeldung zeigt dem Nutzer Hinweis auf Spam-Ordner und Link-Pflicht', str_contains( $nl( [ 'll16_email' => 'dritte@example.com' ] )['body'], 'Spam' ) );
$un = http( 'GET', '/?ll16_nl=unsub&e=nobody%40example.com&s=falsch' );
t( 'Abmeldung mit ungültigem Link: verständliche Seite mit Weg zurück, kein Fehler 500', 200 === $un['code'] && str_contains( $un['body'], 'ungültig' ) && str_contains( $un['body'], 'Zurück zu' ) );
$cf = http( 'GET', '/?ll16_nl=confirm&e=&t=' );
t( 'Bestätigung ohne Parameter: kein Absturz', 200 === $cf['code'] );

/* ---------------------------------------------------------------------------------------- */
section( 'Consent-Log: Missbrauchsschutz' );
reset_ratelimits();
$codes = [];
for ( $i = 0; $i < 70; $i++ ) {
	$r = wp_remote_post( rest_url( 'll16/v1/consent' ), [ 'headers' => [ 'Content-Type' => 'application/json' ], 'body' => wp_json_encode( [ 'id' => wp_generate_uuid4(), 'action' => 'reject', 'categories' => [] ] ), 'sslverify' => false ] );
	$codes[] = wp_remote_retrieve_response_code( $r );
}
t( 'Flut von 70 Einträgen/Minute: erste 60 ok, danach 429', 60 === count( array_filter( $codes, fn( $c ) => 200 === $c ) ) && 10 === count( array_filter( $codes, fn( $c ) => 429 === $c ) ), implode( ',', array_slice( $codes, 55, 10 ) ) );
t( '429 enthält Retry-After', '60' === wp_remote_retrieve_header( $r, 'retry-after' ) );
reset_ratelimits();

/* ---------------------------------------------------------------------------------------- */
section( 'Footer-Links' );
$h = http( 'GET', '/impressum/', [], $jar )['body'];
t( 'Footer-Navigation hat ein Label für Screenreader', str_contains( $h, 'aria-label="Rechtliche Hinweise"' ) );
foreach ( [ '/impressum/', '/datenschutz/', '/agb/', '/widerruf/', '/versand-zahlung/', '/vertrag-widerrufen/' ] as $slug ) {
	t( "Footer verlinkt {$slug}", (bool) preg_match( '#class="ll16-footer-links".*href="[^"]*' . preg_quote( $slug, '#' ) . '"#s', $h ) );
}
t( 'Footer enthält „Cookie-Einstellungen“ als Link (kein verdeckender Schwebebutton)', (bool) preg_match( '#<a href="\#ll16-cookies" data-ll16-open>Cookie-Einstellungen</a>#', $h ) );
update_option( 'll16_settings', [ 'footer_links' => false ] );
$h2 = http( 'GET', '/impressum/', [], $jar )['body'];
t( 'Footer-Links abschaltbar (Theme setzt sie selbst)', ! str_contains( $h2, 'll16-footer-links' ) );
delete_option( 'll16_settings' );
$d = get_page_by_path( 'agb' );
wp_update_post( [ 'ID' => $d->ID, 'post_status' => 'draft' ] );
$h3 = http( 'GET', '/impressum/', [], $jar )['body'];
t( 'Unveröffentlichte Seite wird nicht im Footer verlinkt (kein toter Link)', ! preg_match( '#class="ll16-footer-links".*href="[^"]*/agb/"#s', $h3 ) );
wp_update_post( [ 'ID' => $d->ID, 'post_status' => 'publish' ] );

/* ---------------------------------------------------------------------------------------- */
section( 'Stammdaten: Prüfung und Normalisierung' );
$san = fn( array $o ) => Masterdata::check( Masterdata::sanitize( array_merge( Masterdata::defaults(), $o ) ) );
t( '6.99 → 6,99, 5 → 5,00, 1.234,5 → 1234,50, 7,5 € → 7,50', '6,99' === Masterdata::money( '6.99' ) && '5,00' === Masterdata::money( '5' ) && '1234,50' === Masterdata::money( '1.234,5' ) && '7,50' === Masterdata::money( '7,5 €' ) );
t( 'Ungültige Beträge werden abgelehnt (abc, -3, 6,999)', null === Masterdata::money( 'abc' ) && null === Masterdata::money( '-3' ) && null === Masterdata::money( '6,999' ) );
$r = $san( [ 'shipping_cost' => '4.90' ] );
t( 'Versandkosten werden normalisiert gespeichert', '4,90' === $r['values']['shipping_cost'] && [] === $r['errors'] );
$r = $san( [ 'email' => 'kaputt', 'phone' => '12', 'zip' => '3155', 'withdrawal_days' => 7, 'delivery_countries' => 'Deutschland', 'shipping_cost' => 'gratis', 'authority_url' => 'lfd.niedersachsen.de' ] );
t( 'Alle sechs Fehler werden einzeln gemeldet', 6 === count( $r['errors'] ) && isset( $r['errors']['email'], $r['errors']['phone'], $r['errors']['zip'], $r['errors']['withdrawal_days'], $r['errors']['delivery_countries'], $r['errors']['shipping_cost'] ), implode( ',', array_keys( $r['errors'] ) ) );
t( 'URL ohne https:// wird automatisch ergänzt statt abgelehnt', 'http://lfd.niedersachsen.de' === $r['values']['authority_url'] || str_starts_with( (string) $r['values']['authority_url'], 'http' ) );
t( 'Widerrufsfrist unter 14 Tagen wird abgelehnt (gesetzliches Minimum)', isset( $san( [ 'withdrawal_days' => 13 ] )['errors']['withdrawal_days'] ) && ! isset( $san( [ 'withdrawal_days' => 14 ] )['errors']['withdrawal_days'] ) );
t( 'Pflichtfelder Name/Straße/Ort dürfen nicht leer sein', 3 === count( array_intersect_key( $san( [ 'name' => '', 'street' => ' ', 'city' => '' ] )['errors'], array_flip( [ 'name', 'street', 'city' ] ) ) ) );
t( 'Lieferländer werden in Großbuchstaben normalisiert', [ 'DE', 'AT' ] === $san( [ 'delivery_countries' => 'de, at, DE' ] )['values']['delivery_countries'] );

$orig = get_option( Masterdata::OPTION );
$admin = (function () { $jar = [ 'wordpress_test_cookie' => 'WP Cookie check' ]; http( 'POST', '/wp-login.php', [ 'log' => 'admin', 'pwd' => 'admin', 'wp-submit' => 'Log In', 'testcookie' => '1' ], $jar ); return $jar; })();
$page = http( 'GET', '/wp-admin/admin.php?page=ll16-legal&tab=master', [], $admin )['body'];
preg_match( '/name="_wpnonce" value="([^"]+)"/', $page, $nm );
$post = [ 'action' => 'll16_save', 'tab' => 'master', '_wpnonce' => $nm[1] ?? '', 'm' => [ 'name' => 'Sven Meendermann', 'trade_name' => 'Lindenweg16', 'street' => 'Lindenweg 16', 'zip' => '3155', 'city' => 'Haste', 'country' => 'Deutschland', 'phone' => '01567373047', 'email' => 'tippfehler', 'shipping_cost' => '5.50', 'delivery_countries' => 'DE', 'withdrawal_days' => '14', 'return_days' => '30', 'authority_url' => 'https://www.lfd.niedersachsen.de', 'small_business' => '1' ] ];
$r = http( 'POST', '/wp-admin/admin-post.php', $post, $admin );
wp_cache_flush();
t( 'Teilweise gültige Eingabe: gültige Felder gespeichert, ungültige behalten alten Wert', '5,50' === Masterdata::get( 'shipping_cost' ) && '31559' === Masterdata::get( 'zip' ) && 'sven@lindenweg16.de' === Masterdata::get( 'email' ) );
$page = http( 'GET', '/wp-admin/admin.php?page=ll16-legal&tab=master', [], $admin )['body'];
t( 'Admin sieht, welche Felder nicht übernommen wurden und warum', str_contains( $page, 'Nicht übernommen' ) && str_contains( $page, 'fünfstellig' ) && str_contains( $page, 'E-Mail-Adresse' ) );
$page2 = http( 'GET', '/wp-admin/admin.php?page=ll16-legal&tab=master', [], $admin )['body'];
t( 'Hinweis erscheint nur einmal', ! str_contains( $page2, 'Nicht übernommen' ) );
update_option( Masterdata::OPTION, $orig );

section( 'Admin: Konflikt-Hinweis Complianz' );
update_option( 'active_plugins', array_merge( (array) get_option( 'active_plugins' ), [ 'complianz-gdpr/complianz-gdpr.php' ] ) );
$pg = http( 'GET', '/wp-admin/admin.php?page=ll16-legal', [], $admin )['body'];
t( 'Warnung „Zwei Cookie-Banner“ mit Anleitung', str_contains( $pg, 'Zwei Cookie-Banner' ) && str_contains( $pg, 'Complianz' ) );
update_option( 'active_plugins', array_values( array_diff( (array) get_option( 'active_plugins' ), [ 'complianz-gdpr/complianz-gdpr.php' ] ) ) );
$pg = http( 'GET', '/wp-admin/admin.php?page=ll16-legal&tab=texts', [], $admin )['body'];
t( 'Seiten-Sync fragt vor dem Überschreiben nach', str_contains( $pg, 'confirm(' ) && str_contains( $pg, 'Revision' ) );

/* ---------------------------------------------------------------------------------------- */
section( 'Stammdaten: Listenfelder (Rundlauf wie im Formular)' );
$s2 = Masterdata::sanitize( [ 'payment_methods' => 'PayPal, Kreditkarte, Apple Pay, SEPA-Lastschrift' ] );
t( 'Zahlungsarten mit Leerzeichen bleiben ganz („Apple Pay“)', [ 'PayPal', 'Kreditkarte', 'Apple Pay', 'SEPA-Lastschrift' ] === $s2['payment_methods'], wp_json_encode( $s2['payment_methods'] ) );
t( 'Anzeige → Speichern → Anzeige ist stabil (kein Wort-Zerfall)', Masterdata::sanitize( [ 'payment_methods' => implode( ', ', $s2['payment_methods'] ) ] )['payment_methods'] === $s2['payment_methods'] );
t( 'Zeilenumbrüche und Semikolon trennen ebenfalls', [ 'A', 'B C', 'D' ] === Masterdata::sanitize( [ 'payment_methods' => "A\nB C; D" ] )['payment_methods'] );
t( 'Leere Einträge werden verworfen', [ 'A' ] === Masterdata::sanitize( [ 'payment_methods' => ',, A ,' ] )['payment_methods'] );
t( 'Länderliste: Leerzeichen/Komma/Semikolon', [ 'DE', 'AT' ] === Masterdata::sanitize( [ 'delivery_countries' => 'DE AT' ] )['delivery_countries'] || [ 'de', 'at' ] === Masterdata::sanitize( [ 'delivery_countries' => 'de, at' ] )['delivery_countries'] );

section( 'Rechtstexte: Injection über Stammdaten' );
$m = Masterdata::defaults();
$m['payment_methods']  = [ 'PayPal' ];
$m['shipping_carrier'] = 'DHL';
$m['name']  = 'Max {{email}} <img src=x onerror=alert(1)>';
update_option( Masterdata::OPTION, Masterdata::sanitize( $m ) );
$imp = \Lindenweg16\Legal\Legal\Texts::render( 'impressum' );
t( 'HTML im Namen wird entfernt (sanitize)', ! str_contains( $imp, '<img' ) );
t( '{{platzhalter}} im Wert wird nicht rekursiv expandiert, Linter blockiert Veröffentlichung', str_contains( $imp, '{{email}}' ) && [] !== \Lindenweg16\Legal\Legal\Linter::check( $imp ) );
$m['name']  = 'Max Muster';
$m['phone'] = '0156"onmouseover="alert(1)';
update_option( Masterdata::OPTION, Masterdata::sanitize( $m ) );
$imp = \Lindenweg16\Legal\Legal\Texts::render( 'impressum' );
t( 'Attribut-Injection in der Telefonnummer ist wirkungslos (tel:-Link)', ! preg_match( '/<a href="tel:[^"]*"[^>]*onmouseover/i', $imp ) );
$m = Masterdata::defaults();
$m['payment_methods'] = [ 'PayPal', 'Kreditkarte', 'Apple Pay' ];
$m['shipping_carrier'] = 'DHL Paket (Deutsche Post DHL Group)';
update_option( Masterdata::OPTION, $m );
$imp = \Lindenweg16\Legal\Legal\Texts::render( 'impressum' );
t( 'Impressum: E-Mail als mailto-Link, Telefon als tel:-Link (+49)', str_contains( $imp, 'href="mailto:sven@lindenweg16.de"' ) && str_contains( $imp, 'href="tel:+491567373047"' ) );
t( 'Überschriftenhierarchie der Widerrufsbelehrung beginnt bei h2 (Titel ist h1)', ! str_contains( \Lindenweg16\Legal\Legal\Texts::render( 'widerruf' ), '<h3>' ) && str_starts_with( \Lindenweg16\Legal\Legal\Texts::render( 'widerruf' ), '<h2>Widerrufsrecht' ) );
Legal::sync_pages( true );

/* ---------------------------------------------------------------------------------------- */
section( 'Monitor: Versandkosten-Konsistenz' );
$by = fn() => array_column( ( new \Lindenweg16\Legal\Monitor\Checks() )->run_all(), null, 'id' );
$b0 = $by();
t( 'Zone Deutschland 6,99 = Stammdaten 6,99 → ok', 'ok' === ( $b0['shipping']['status'] ?? '' ), $b0['shipping']['detail'] ?? '' );
$m = Masterdata::all();
$m['shipping_cost'] = '4,90';
update_option( Masterdata::OPTION, $m );
$b1 = $by();
t( 'Abweichende Pauschale wird gemeldet (Texte würden falsche Kosten nennen)', 'fail' === $b1['shipping']['status'] && str_contains( $b1['shipping']['detail'], '4,90' ) && str_contains( $b1['shipping']['detail'], '6,99' ) );
$m['shipping_cost'] = '6,99';
$m['free_shipping_from'] = '50,00';
update_option( Masterdata::OPTION, $m );
$b2 = $by();
t( 'Texte versprechen Gratisversand, WooCommerce kennt ihn nicht → fail', 'fail' === $b2['shipping']['status'] && str_contains( $b2['shipping']['detail'], 'Gratisversand ab 50,00' ) );
$zone = null;
foreach ( WC_Shipping_Zones::get_zones() as $z ) { $zone = new WC_Shipping_Zone( $z['id'] ); }
$iid = $zone->add_shipping_method( 'free_shipping' );
update_option( 'woocommerce_free_shipping_' . $iid . '_settings', [ 'requires' => 'min_amount', 'min_amount' => '50' ] );
$b3 = $by();
t( 'Gratisversand ab 50 € in WooCommerce = Stammdaten → ok', 'ok' === $b3['shipping']['status'], $b3['shipping']['detail'] );
$m['free_shipping_from'] = '';
update_option( Masterdata::OPTION, $m );
$b4 = $by();
t( 'WooCommerce liefert frei, Texte erwähnen es nicht → fail', 'fail' === $b4['shipping']['status'] && str_contains( $b4['shipping']['detail'], 'erwähnen es nicht' ) );
$zone->delete_shipping_method( $iid );
$zone->add_location( 'AT', 'country' );
$zone->save();
$b5 = $by();
t( 'Versandzone enthält Land außerhalb der Lieferländer → fail', 'fail' === $b5['shipping']['status'] && str_contains( $b5['shipping']['detail'], 'AT' ) );
$locs = array_filter( $zone->get_zone_locations(), fn( $l ) => 'AT' !== $l->code );
$zone->set_locations( array_map( fn( $l ) => (array) $l, $locs ) );
$zone->save();
update_option( Masterdata::OPTION, Masterdata::defaults() + [ 'payment_methods' => [ 'PayPal', 'Kreditkarte', 'Apple Pay' ] ] );
$m = Masterdata::all(); $m['payment_methods'] = [ 'PayPal', 'Kreditkarte', 'Apple Pay' ]; $m['shipping_carrier'] = 'DHL'; update_option( Masterdata::OPTION, $m );

/* ---------------------------------------------------------------------------------------- */
section( 'Checkout: klassischer Shortcode-Checkout' );
update_option( 'woocommerce_coming_soon', 'no' );
make_page( 'kasse-klassisch', 'Kasse klassisch', '[woocommerce_checkout]' );
$cj = [];
$base = wc_get_products( [ 'name' => 'BASE', 'limit' => 1 ] )[0];
http( 'GET', '/?add-to-cart=' . $base->get_id(), [], $cj );
$k = http( 'GET', '/kasse-klassisch/', [], $cj )['body'];
t( 'Klassische Kasse: Button „Zahlungspflichtig bestellen“', str_contains( $k, 'Zahlungspflichtig bestellen' ) );
t( 'Klassische Kasse: Pflicht-Checkbox mit Links zu AGB, Widerruf, Datenschutz', (bool) preg_match( '#name="terms"#', $k ) && str_contains( $k, '/agb/' ) && str_contains( $k, '/widerruf/' ) && str_contains( $k, '/datenschutz/' ) );
t( 'Klassische Kasse: Hinweis mit Widerrufsrecht vor dem Button', str_contains( $k, 'll16-checkout-terms' ) );
t( 'Klassische Kasse: kein anderes Land wählbar', ! str_contains( $k, 'value="FR"' ) && ! str_contains( $k, 'value="US"' ) && ! str_contains( $k, 'value="AT"' ) && str_contains( $k, 'value="DE"' ) );

/* ---------------------------------------------------------------------------------------- */
section( 'Upgrade-Pfad' );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ll16_notify" );
update_option( 'll16_db_version', '0.0.1' );
delete_option( 'll16_forms_db' );
http( 'GET', '/', [], $jar );
http( 'GET', '/', [], $jar );
wp_cache_flush();
$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM sqlite_master WHERE type="table" AND name=%s', $wpdb->prefix . 'll16_notify' ) );
t( 'Veraltete DB-Version/fehlende Tabelle: nächster Aufruf repariert Schema', $exists && LL16_VERSION === get_option( 'll16_db_version' ) && LL16_VERSION === get_option( 'll16_forms_db' ) );
$cnt = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ll16_consent_log" );
t( 'Vorhandene Daten bleiben beim Upgrade erhalten', $cnt > 0 );

/* ---------------------------------------------------------------------------------------- */
section( 'Anhänge: Aufräumen' );
$tmp = trailingslashit( get_temp_dir() );
file_put_contents( $tmp . 'vertragsbedingungen-alt1.html', 'x' );
touch( $tmp . 'vertragsbedingungen-alt1.html', time() - 2 * HOUR_IN_SECONDS );
file_put_contents( $tmp . 'vertragsbedingungen-neu1.html', 'x' );
Legal::cleanup_attachments();
t( 'Alte Anhänge (>1 h) werden gelöscht, frische bleiben', ! is_file( $tmp . 'vertragsbedingungen-alt1.html' ) && is_file( $tmp . 'vertragsbedingungen-neu1.html' ) );
@unlink( $tmp . 'vertragsbedingungen-neu1.html' );

finish();
