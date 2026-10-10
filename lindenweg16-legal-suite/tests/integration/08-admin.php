<?php
require __DIR__ . '/lib.php';
use Lindenweg16\Legal\Core\Masterdata;
use Lindenweg16\Legal\Core\Modules;
use Lindenweg16\Legal\Core\ServiceRegistry;

$orig_master  = get_option( Masterdata::OPTION );
$orig_modules = get_option( 'll16_modules' );

function login( string $user, string $pass ): array {
	$jar = [ 'wordpress_test_cookie' => 'WP Cookie check' ];
	$r   = http( 'POST', '/wp-login.php', [ 'log' => $user, 'pwd' => $pass, 'wp-submit' => 'Log In', 'testcookie' => '1' ], $jar );
	return $jar;
}
function nonce_from( string $html, string $name = '_wpnonce', bool $last = false ): string {
	return preg_match_all( '/name="' . $name . '" value="([^"]+)"/', $html, $m ) ? ( $last ? end( $m[1] ) : $m[1][0] ) : '';
}

wp_set_password( 'admin', 1 );
if ( ! get_user_by( 'login', 'kunde' ) ) {
	wp_create_user( 'kunde', 'kundepw', 'kunde@example.com' );
}
$sub = get_user_by( 'login', 'kunde' );
$sub->set_role( 'subscriber' );

section( 'Admin: Zugriffsschutz' );
$anon = [];
$r    = http( 'GET', '/wp-admin/admin.php?page=ll16-legal', [], $anon );
t( 'Anonym: Weiterleitung auf Login', in_array( $r['code'], [ 301, 302 ], true ) && str_contains( (string) $r['headers']['location'], 'wp-login.php' ) );
$kunde = login( 'kunde', 'kundepw' );
t( 'Kunden-Login funktioniert', (bool) array_filter( array_keys( $kunde ), fn( $k ) => str_starts_with( $k, 'wordpress_logged_in' ) ) );
$r = http( 'GET', '/wp-admin/admin.php?page=ll16-legal', [], $kunde );
t( 'Subscriber: kein Zugriff auf Plugin-Seite', 403 === $r['code'] || ! str_contains( $r['body'], 'Statusampel' ), (string) $r['code'] );
$r = http( 'POST', '/wp-admin/admin-post.php', [ 'action' => 'll16_save', 'tab' => 'master', 'm' => [ 'name' => 'HACK' ] ], $kunde );
t( 'Subscriber: Speichern verboten', 'HACK' !== Masterdata::get( 'name' ) );
$r = http( 'POST', '/wp-admin/admin-post.php', [ 'action' => 'll16_export' ], $kunde );
t( 'Subscriber: Export verboten', ! str_contains( $r['body'], 'lindenweg16-legal-suite' ) );

$admin = login( 'admin', 'admin' );
t( 'Admin-Login', (bool) array_filter( array_keys( $admin ), fn( $k ) => str_starts_with( $k, 'wordpress_logged_in' ) ) );
$r = http( 'POST', '/wp-admin/admin-post.php', [ 'action' => 'll16_save', 'tab' => 'master', 'm' => [ 'name' => 'OHNE-NONCE' ] ], $admin );
t( 'Admin ohne Nonce: Speichern abgelehnt (CSRF-Schutz)', 'OHNE-NONCE' !== Masterdata::get( 'name' ) && $r['code'] >= 400 || 'OHNE-NONCE' !== Masterdata::get( 'name' ) );
$r = http( 'POST', '/wp-admin/admin-post.php', [ 'action' => 'll16_sync_pages', 'publish' => '1' ], $admin );
t( 'Seiten-Sync ohne Nonce abgelehnt', $r['code'] >= 400 );
$r = http( 'POST', '/wp-admin/admin-post.php', [ 'action' => 'll16_run_monitor' ], $admin );
t( 'Monitor-Start ohne Nonce abgelehnt', $r['code'] >= 400 );

section( 'Admin: alle Tabs rendern' );
$titles = [ 'status' => 'Statusampel', 'master' => 'Telefon (eine Nummer)', 'modules' => 'Modul', 'services' => 'Dienste-Register', 'texts' => 'Rechtstexte (Entwurf, nicht anwaltlich geprüft)', 'monitor' => 'Prüfung jetzt starten', 'settings' => 'HSTS max-age', 'tools' => 'Konfiguration als JSON exportieren' ];
foreach ( $titles as $tab => $needle ) {
	$r = http( 'GET', '/wp-admin/admin.php?page=ll16-legal&tab=' . $tab, [], $admin );
	t( "Tab {$tab}: 200, Inhalt vorhanden", 200 === $r['code'] && str_contains( $r['body'], $needle ), $r['code'] . ' ' . substr( wp_strip_all_tags( $r['body'] ), 0, 80 ) );
	t( "Tab {$tab}: keine PHP-Fehler", ! preg_match( '/(Fatal error|Warning:|Notice:|Deprecated:)/', wp_strip_all_tags( $r['body'] ) ) );
}
$r = http( 'GET', '/wp-admin/admin.php?page=ll16-legal', [], $admin );
t( 'Hinweis „Entwurf, nicht anwaltlich geprüft“ sichtbar', str_contains( $r['body'], 'nicht anwaltlich geprüft' ) );
$r = http( 'GET', '/wp-admin/admin.php?page=ll16-legal&tab=nope', [], $admin );
t( 'Unbekannter Tab fällt auf Status zurück', 200 === $r['code'] && str_contains( $r['body'], 'Statusampel' ) );

section( 'Admin: Stammdaten speichern' );
$page  = http( 'GET', '/wp-admin/admin.php?page=ll16-legal&tab=master', [], $admin )['body'];
$nonce = nonce_from( $page );
$post  = [ 'action' => 'll16_save', 'tab' => 'master', '_wpnonce' => $nonce, '_wp_http_referer' => '/wp-admin/admin.php?page=ll16-legal&tab=master',
	'm' => [ 'name' => 'Sven Meendermann', 'trade_name' => 'Lindenweg16', 'street' => 'Lindenweg 16', 'zip' => '31559', 'city' => 'Haste', 'country' => 'Deutschland', 'phone' => '01567373047', 'email' => 'sven@lindenweg16.de', 'shipping_cost' => '5,90', 'small_business' => '1', 'delivery_countries' => 'DE', 'payment_methods' => 'PayPal, Karte', 'withdrawal_days' => '14', 'return_days' => '30', 'delivery_time' => '3–5 Werktage', 'shipping_carrier' => 'DHL', 'hosting_provider' => 'IONOS SE', 'retention_years' => '8', 'authority_name' => 'LfD NDS', 'authority_url' => 'https://www.lfd.niedersachsen.de' ] ];
$r = http( 'POST', '/wp-admin/admin-post.php', $post, $admin );
t( 'Speichern leitet zurück (302)', 302 === $r['code'], (string) $r['code'] );
wp_cache_flush();
$m = Masterdata::all();
t( 'Versandkosten gespeichert', '5,90' === $m['shipping_cost'] );
t( 'Zahlungsarten als Liste gespeichert', [ 'PayPal', 'Karte' ] === $m['payment_methods'] );
t( 'Kleinunternehmer-Haken', true === $m['small_business'] );
t( 'Nicht gesetzte Checkbox → false (Schlichtung)', false === $m['adr_willing'] );
$post['m']['name'] = '<script>alert(1)</script>Hans';
$post['m']['email'] = 'kaputt';
$r = http( 'POST', '/wp-admin/admin-post.php', $post, $admin );
wp_cache_flush();
t( 'XSS im Namen wird entfernt', ! str_contains( Masterdata::get( 'name' ), '<script' ) );
t( 'Ungültige E-Mail wird nicht übernommen', '' === Masterdata::get( 'email' ) );
update_option( Masterdata::OPTION, $orig_master );

section( 'Admin: Module & Dienste' );
$page  = http( 'GET', '/wp-admin/admin.php?page=ll16-legal&tab=modules', [], $admin )['body'];
$r = http( 'POST', '/wp-admin/admin-post.php', [ 'action' => 'll16_save', 'tab' => 'modules', '_wpnonce' => nonce_from( $page ), 'mod' => [ 'consent' => '1', 'legal' => '1' ] ], $admin );
wp_cache_flush();
$fl = Modules::flags();
t( 'Modulschalter: nur markierte aktiv', $fl['consent'] && $fl['legal'] && ! $fl['checkout'] && ! $fl['forms'] );
update_option( 'll16_modules', $orig_modules );
$page = http( 'GET', '/wp-admin/admin.php?page=ll16-legal&tab=services', [], $admin )['body'];
t( 'Dienste-Tab listet alle Dienste', str_contains( $page, 'Stripe' ) && str_contains( $page, 'Google Maps' ) && str_contains( $page, 'nur Warenkorb/Kasse' ) );
$r = http( 'POST', '/wp-admin/admin-post.php', [ 'action' => 'll16_save', 'tab' => 'services', '_wpnonce' => nonce_from( $page ), 'svc' => [ 'consent' => '1', 'woocommerce' => '1' ] ], $admin );
wp_cache_flush();
$all = ServiceRegistry::all();
t( 'Dienst deaktiviert (Maps, Stripe, PayPal aus)', empty( $all['google-maps']['active'] ) && empty( $all['stripe']['active'] ) && ! empty( $all['consent']['active'] ) );
delete_option( ServiceRegistry::OPTION );

section( 'Admin: Einstellungen' );
$page = http( 'GET', '/wp-admin/admin.php?page=ll16-legal&tab=settings', [], $admin )['body'];
$r    = http( 'POST', '/wp-admin/admin-post.php', [ 'action' => 'll16_save', 'tab' => 'settings', '_wpnonce' => nonce_from( $page ), 's' => [ 'consent_retention_days' => '5', 'contact_retention_days' => '1', 'hsts_max_age' => '-9', 'newsletter_enabled' => '1', 'claims' => "foo\nbar\n\n", 'warranty' => 'Amtlicher Text' ] ], $admin );
wp_cache_flush();
$s = (array) get_option( 'll16_settings' );
t( 'Untergrenzen: Consent ≥ 30 Tage, Kontakt ≥ 7 Tage, HSTS ≥ 0', 30 === $s['consent_retention_days'] && 7 === $s['contact_retention_days'] && 0 === $s['hsts_max_age'] );
t( 'Newsletter-Haken & Claims-Liste', true === $s['newsletter_enabled'] && [ 'foo', 'bar' ] === $s['claims'] );
t( 'Gefährliche Optionen standardmäßig aus', empty( $s['retention_anonymize'] ) && empty( $s['delete_on_uninstall'] ) );
t( 'Gewährleistungstext gespeichert', 'Amtlicher Text' === get_option( 'll16_warranty_notice' ) );
delete_option( 'll16_settings' );
delete_option( 'll16_warranty_notice' );

section( 'Admin: Rechtstexte & Monitor per Klick' );
$page = http( 'GET', '/wp-admin/admin.php?page=ll16-legal&tab=texts', [], $admin )['body'];
t( 'Linter-Vorschau je Seite', substr_count( $page, 'sauber' ) >= 6 );
$r = http( 'POST', '/wp-admin/admin-post.php', [ 'action' => 'll16_sync_pages', '_wpnonce' => nonce_from( $page, '_wpnonce' ), 'publish' => '1' ], $admin );
t( 'Sync per Klick → Weiterleitung', 302 === $r['code'], (string) $r['code'] );
$page = http( 'GET', '/wp-admin/admin.php?page=ll16-legal&tab=monitor', [], $admin )['body'];
delete_option( 'll16_last_check' );
$r = http( 'POST', '/wp-admin/admin-post.php', [ 'action' => 'll16_run_monitor', '_wpnonce' => nonce_from( $page ) ], $admin );
wp_cache_flush();
t( 'Monitor per Klick erzeugt Ergebnis', 302 === $r['code'] && ! empty( get_option( 'll16_last_check' )['results'] ) );
$page = http( 'GET', '/wp-admin/admin.php?page=ll16-legal&tab=monitor', [], $admin )['body'];
t( 'Ergebnisliste mit Symbolen und CLI-Hinweis', str_contains( $page, '✅' ) && str_contains( $page, 'wp ll16-legal check' ) );
$page = http( 'GET', '/wp-admin/admin.php?page=ll16-legal&tab=status', [], $admin )['body'];
t( 'Statusampel zeigt Zähler', (bool) preg_match( '/\d+ Fehler, \d+ Warnungen, \d+ in Ordnung/', $page ) );

section( 'Admin: Export / Import' );
$page = http( 'GET', '/wp-admin/admin.php?page=ll16-legal&tab=tools', [], $admin )['body'];
$exp  = http( 'POST', '/wp-admin/admin-post.php', [ 'action' => 'll16_export', '_wpnonce' => nonce_from( $page ) ], $admin );
$json = json_decode( $exp['body'], true );
t( 'Export: JSON mit Stammdaten, Modulen, Diensten', 200 === $exp['code'] && 'lindenweg16-legal-suite' === ( $json['plugin'] ?? '' ) && isset( $json['masterdata']['email'], $json['modules']['consent'], $json['services'] ) );
t( 'Export: als Download markiert', str_contains( (string) $exp['headers']['content-disposition'], 'll16-config.json' ) );
t( 'Export enthält keine Passwörter/Schlüssel', ! preg_match( '/pass|secret|key|token/i', $exp['body'] ) );

// Import: Multipart-Upload mit cURL gegen den echten Endpunkt
$tmp = tempnam( sys_get_temp_dir(), 'll16' );
$json['masterdata']['shipping_cost'] = '7,77';
$json['modules']['forms'] = false;
file_put_contents( $tmp, wp_json_encode( $json ) );
$cookie = implode( '; ', array_map( fn( $k, $v ) => "$k=$v", array_keys( $admin ), $admin ) );
$ch     = curl_init( home_url( '/wp-admin/admin-post.php' ) );
curl_setopt_array( $ch, [ CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIE => $cookie, CURLOPT_POSTFIELDS => [ 'action' => 'll16_import', '_wpnonce' => nonce_from( $page, '_wpnonce', true ), 'config' => new CURLFile( $tmp, 'application/json', 'c.json' ) ] ] );
$out  = (string) curl_exec( $ch );
$code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
curl_close( $ch );
wp_cache_flush();
t( 'Import → Weiterleitung', 302 === $code, (string) $code );
t( 'Import übernimmt Stammdaten und Module', '7,77' === Masterdata::get( 'shipping_cost' ) && false === Modules::flags()['forms'] );
file_put_contents( $tmp, '{"plugin":"fremd","masterdata":{"name":"X"}}' );
$ch = curl_init( home_url( '/wp-admin/admin-post.php' ) );
curl_setopt_array( $ch, [ CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => $cookie, CURLOPT_POSTFIELDS => [ 'action' => 'll16_import', '_wpnonce' => nonce_from( $page, '_wpnonce', true ), 'config' => new CURLFile( $tmp, 'application/json', 'c.json' ) ] ] );
curl_exec( $ch );
curl_close( $ch );
wp_cache_flush();
t( 'Fremde/ungültige Datei wird nicht importiert', 'X' !== Masterdata::get( 'name' ) );
unlink( $tmp );
update_option( Masterdata::OPTION, $orig_master );
update_option( 'll16_modules', $orig_modules );
\Lindenweg16\Legal\Legal\Module::sync_pages( true );

finish();
