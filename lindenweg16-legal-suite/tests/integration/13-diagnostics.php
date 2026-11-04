<?php
require __DIR__ . '/lib.php';
use Lindenweg16\Legal\Core\Diagnostics;
use Lindenweg16\Legal\Legal\Module as Legal;

$m = \Lindenweg16\Legal\Core\Masterdata::defaults();
$m['payment_methods']  = [ 'PayPal', 'Kreditkarte' ];
$m['shipping_carrier'] = 'DHL';
update_option( \Lindenweg16\Legal\Core\Masterdata::OPTION, $m );
Diagnostics::clear();

section( 'Diagnose: eine fehlerhafte Seite bricht die Erzeugung nicht ab' );
$boom = function ( $data ) {
	if ( 'datenschutz' === ( $data['post_name'] ?? '' ) ) {
		throw new \RuntimeException( 'Test: anderes Plugin wirft beim Speichern' );
	}
	return $data;
};
add_filter( 'wp_insert_post_data', $boom, 5 );
$res = Legal::sync_pages( true );
remove_filter( 'wp_insert_post_data', $boom, 5 );
t( 'Fehlerhafte Seite: Ergebnis „FEHLER“ mit Klasse, Text und Datei:Zeile', str_starts_with( (string) $res['datenschutz'], 'FEHLER: RuntimeException: Test: anderes Plugin wirft' ) && str_contains( (string) $res['datenschutz'], '.php' ), (string) $res['datenschutz'] );
t( 'Die übrigen fünf Seiten wurden trotzdem erzeugt', 'ok' === $res['impressum'] && 'ok' === $res['agb'] && 'ok' === $res['widerruf'] && 'ok' === $res['versand-zahlung'] && 'ok' === $res['cookies'], wp_json_encode( $res ) );
$last = Diagnostics::last();
t( 'Fehler wurde für die Diagnose gemerkt (Zeit, Kontext, Meldung, Stelle)', is_array( $last ) && str_contains( $last['context'], '/datenschutz/' ) && str_contains( $last['message'], 'RuntimeException' ) && '' !== $last['where'] );
t( 'Stelle enthält keinen absoluten Serverpfad', ! str_starts_with( (string) $last['where'], '/' ) && ! str_contains( (string) $last['where'], '/tmp/' ) );

section( 'Diagnose: Bericht' );
$r = Diagnostics::report();
t( 'Bericht nennt Plugin-, WordPress-, PHP-, WooCommerce-Version', str_contains( $r, 'Lindenweg16 Legal Suite ' . LL16_VERSION ) && str_contains( $r, 'WordPress ' ) && str_contains( $r, 'PHP ' . PHP_VERSION ) && str_contains( $r, 'WooCommerce ' ) );
t( 'Bericht nennt Speicher-/Zeitlimit und aktive Plugins', str_contains( $r, 'memory_limit' ) && str_contains( $r, 'max_execution_time' ) && str_contains( $r, 'Aktive Plugins' ) );
t( 'Bericht enthält den letzten Fehler', str_contains( $r, 'Letzter Fehler (' ) && str_contains( $r, 'RuntimeException' ) );
t( 'Bericht enthält keine Geheimnisse (Salts, Token, DB-Zugang)', ! preg_match( '/AUTH_KEY|SECURE_AUTH|LOGGED_IN|DB_PASSWORD|github_pat|Bearer/i', $r ) );

section( 'Diagnose: Admin-Oberfläche' );
$admin = ( function () { $jar = [ 'wordpress_test_cookie' => 'WP Cookie check' ]; http( 'POST', '/wp-login.php', [ 'log' => 'admin', 'pwd' => 'admin', 'wp-submit' => 'Log In', 'testcookie' => '1' ], $jar ); return $jar; } )();
$pg = http( 'GET', '/wp-admin/admin.php?page=ll16-legal&tab=status', [], $admin )['body'];
t( 'Status-Tab zeigt „Diagnose“, letzten Fehler und kopierbaren Bericht', str_contains( $pg, '>Diagnose<' ) && str_contains( $pg, 'Letzter aufgezeichneter Fehler' ) && str_contains( $pg, 'RuntimeException' ) && str_contains( $pg, '<textarea readonly' ) );
$tx = http( 'GET', '/wp-admin/admin.php?page=ll16-legal&tab=texts', [], $admin )['body'];
t( 'Rechtstexte-Tab weist auf den Fehler hin und verweist auf Status → Diagnose', str_contains( $tx, 'Beim letzten Erzeugen ist ein Fehler aufgetreten' ) && str_contains( $tx, 'Status → Diagnose' ) );
preg_match_all( '/name="_wpnonce" value="([^"]+)"/', $pg, $nn );
$r1 = http( 'POST', '/wp-admin/admin-post.php', [ 'action' => 'll16_clear_error' ], $admin );
t( 'Zurücksetzen ohne Nonce abgelehnt', $r1['code'] >= 400 && null !== Diagnostics::last() );
$r2 = http( 'POST', '/wp-admin/admin-post.php', [ 'action' => 'll16_clear_error', '_wpnonce' => end( $nn[1] ) ], $admin );
wp_cache_flush();
t( 'Zurücksetzen mit Nonce löscht die Anzeige', 302 === $r2['code'] && null === Diagnostics::last() );
$pg2 = http( 'GET', '/wp-admin/admin.php?page=ll16-legal&tab=status', [], $admin )['body'];
t( 'Danach keine Fehleranzeige mehr, Bericht weiterhin vorhanden', ! str_contains( $pg2, 'Letzter aufgezeichneter Fehler' ) && str_contains( $pg2, 'Letzter Fehler: keiner gespeichert' ) );

section( 'Diagnose: erfolgreicher Lauf überschreibt keinen Fehler' );
Legal::sync_pages( true );
t( 'Fehlerfreier Lauf: nichts gemerkt', null === Diagnostics::last() );
finish();
