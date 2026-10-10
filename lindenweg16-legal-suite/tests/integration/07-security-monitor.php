<?php
require __DIR__ . '/lib.php';
use Lindenweg16\Legal\Monitor\Checks;
use Lindenweg16\Legal\Monitor\Module as Monitor;

\Lindenweg16\Legal\Legal\Module::sync_pages( true );
$jar = [];
section( 'Security-Header (HTTP)' );
foreach ( [ '/' => 'Startseite', '/shop/' => 'Shop', '/nicht-vorhanden-123/' => '404-Seite', '/wp-json/' => 'REST', '/wp-login.php' => 'Login', '/wp-json/wc/store/v1/cart' => 'Store API', '/impressum/' => 'Impressum' ] as $path => $label ) {
	$r = http( 'GET', $path, [], $jar );
	$h = $r['headers'];
	t( "{$label}: nosniff", 'nosniff' === ( $h['x-content-type-options'] ?? '' ) );
	t( "{$label}: Referrer-Policy", 'strict-origin-when-cross-origin' === ( $h['referrer-policy'] ?? '' ) );
	t( "{$label}: X-Frame-Options", 'SAMEORIGIN' === ( $h['x-frame-options'] ?? '' ) );
	t( "{$label}: Permissions-Policy", ! empty( $h['permissions-policy'] ) );
	t( "{$label}: kein X-Powered-By", empty( $h['x-powered-by'] ) );
}
$r = http( 'GET', '/', [], $jar );
$csp = (string) ( $r['headers']['content-security-policy-report-only'] ?? '' );
t( 'CSP nur als Report-Only', '' !== $csp && empty( $r['headers']['content-security-policy'] ) );
t( 'CSP: frame-ancestors, Stripe/PayPal, report-uri', str_contains( $csp, "frame-ancestors 'self'" ) && str_contains( $csp, 'js.stripe.com' ) && str_contains( $csp, 'paypal.com' ) && str_contains( $csp, 'report-uri' ) );
t( 'HSTS nicht über HTTP', empty( $r['headers']['strict-transport-security'] ) );
$r = http( 'GET', '/', [], $jar, [ 'X-Test-Https' => '1' ] );
t( 'HSTS über HTTPS: 1 Woche', 'max-age=604800' === ( $r['headers']['strict-transport-security'] ?? '' ), (string) ( $r['headers']['strict-transport-security'] ?? '' ) );
update_option( 'll16_settings', [ 'hsts_max_age' => 31536000, 'hsts_subdomains' => true ] );
$r = http( 'GET', '/', [], $jar, [ 'X-Test-Https' => '1' ] );
t( 'HSTS konfigurierbar (+includeSubDomains)', 'max-age=31536000; includeSubDomains' === ( $r['headers']['strict-transport-security'] ?? '' ) );
update_option( 'll16_settings', [ 'hsts_max_age' => 0 ] );
$r = http( 'GET', '/', [], $jar, [ 'X-Test-Https' => '1' ] );
t( 'HSTS lässt sich abschalten (0)', empty( $r['headers']['strict-transport-security'] ) );
delete_option( 'll16_settings' );

section( 'CSP-Report-Endpunkt' );
delete_option( 'll16_csp_reports' );
$res = wp_remote_post( rest_url( 'll16/v1/csp' ), [ 'headers' => [ 'Content-Type' => 'application/csp-report' ], 'body' => '{"csp-report":{"blocked-uri":"https://evil.example"}}', 'sslverify' => false ] );
t( 'POST → 204', 204 === wp_remote_retrieve_response_code( $res ), (string) wp_remote_retrieve_response_code( $res ) );
t( 'Bericht gespeichert', 1 === count( (array) get_option( 'll16_csp_reports' ) ) );
wp_remote_post( rest_url( 'll16/v1/csp' ), [ 'body' => str_repeat( 'A', 10000 ), 'sslverify' => false ] );
wp_cache_delete( 'll16_csp_reports', 'options' );
wp_cache_flush();
$reports = (array) get_option( 'll16_csp_reports' );
$lastr   = end( $reports );
t( 'Übergroße Berichte werden gekürzt', is_array( $lastr ) && 2000 >= strlen( $lastr['report'] ) );

section( 'Monitor: Prüfungen im Sollzustand' );
$res = ( new Checks() )->run_all();
$by  = array_column( $res, null, 'id' );
$st  = fn( $id ) => $by[ $id ]['status'] ?? 'fehlt';
t( '12 Prüfgruppen liefern Ergebnisse (≥ 15 Einzelchecks)', count( $res ) >= 15, (string) count( $res ) );
foreach ( [ 'pages-200', 'pages-footer', 'pages-checkout', 'placeholders', 'contacts', 'consent', 'checkout', 'withdrawal-page', 'headers', 'texts-age', 'legacy', 'config-masterdata' ] as $id ) {
	t( "Check {$id}: ok", 'ok' === $st( $id ), $st( $id ) . ' ' . ( $by[ $id ]['detail'] ?? '' ) );
}
t( 'Check payments: erkennt Register ohne Gateway', 'fail' === $st( 'payments' ) );
t( 'Check products: erkennt fehlende Bilder/GPSR/Auswahl', 'fail' === $st( 'products' ) && str_contains( $by['products']['detail'], 'Bild fehlt' ) && str_contains( $by['products']['detail'], 'GPSR' ) );
t( 'Check products: erkennt BSM-Konstellation (einfaches Produkt mit Varianten-Attribut)', str_contains( $by['products']['detail'], 'als variables Produkt anlegen' ) );
t( 'Check withdrawal-mail: bleibt offen (warn)', 'warn' === $st( 'withdrawal-mail' ) );
t( 'Check review-due: warnt ohne Review-Datum', 'warn' === $st( 'review-due' ) );
t( 'Check config-warranty: warnt bei Standardtext', 'warn' === $st( 'config-warranty' ) );

section( 'Monitor: erkennt Verstöße' );
$id = get_page_by_path( 'impressum' )->ID;
$orig = get_post_field( 'post_content', $id );
kses_remove_filters();
remove_filter( 'wp_insert_post_data', [ \Lindenweg16\Legal\Legal\Module::class, 'lint_on_publish' ], 10 ); // Linter für diesen Test aus, sonst wird die Seite offline genommen
wp_update_post( [ 'ID' => $id, 'post_content' => $orig . '<p>Hotline 05037 123456 sven@ex.comlindenweg16.de {title} Verwende die Testkarte 4000 0027 6000 0016</p>' ] );
$c = new Checks();
$by = array_column( $c->run_all(), null, 'id' );
t( 'Platzhalter/Testkarte im Frontend → fail', 'fail' === $by['placeholders']['status'] && str_contains( $by['placeholders']['detail'], '/impressum/' ), $by['placeholders']['detail'] );
t( 'Abweichende Telefonnummer → fail', 'fail' === $by['contacts']['status'] && str_contains( $by['contacts']['detail'], '05037123456' ), $by['contacts']['detail'] );
t( 'Kaputte E-Mail → fail', str_contains( $by['contacts']['detail'], 'ex.comlindenweg16.de' ) );
wp_update_post( [ 'ID' => $id, 'post_content' => $orig . '<p>Alle Preise inkl. MwSt.</p>' ] );
$by = array_column( ( new Checks() )->run_all(), null, 'id' );
t( '„inkl. MwSt.“ trotz Kleinunternehmer → fail', 'fail' === $by['placeholders']['status'] && str_contains( $by['placeholders']['detail'], 'MwSt' ) );
wp_update_post( [ 'ID' => $id, 'post_content' => $orig ] );
add_filter( 'wp_insert_post_data', [ \Lindenweg16\Legal\Legal\Module::class, 'lint_on_publish' ], 10, 2 );
$agb = get_page_by_path( 'agb' );
wp_update_post( [ 'ID' => $agb->ID, 'post_status' => 'draft' ] );
$by = array_column( ( new Checks() )->run_all(), null, 'id' );
t( 'Rechtsseite offline (404) → fail', 'fail' === $by['pages-200']['status'] && str_contains( $by['pages-200']['detail'], '/agb/' ) );
wp_update_post( [ 'ID' => $agb->ID, 'post_status' => 'publish' ] );
update_option( 'woocommerce_stripe_settings', [ 'testmode' => 'yes' ] );
$by = array_column( ( new Checks() )->run_all(), null, 'id' );
t( 'Testmodus aktiv → Checkout-Check fail', 'fail' === $by['checkout']['status'] && str_contains( $by['checkout']['detail'], 'Testmodus' ) );
delete_option( 'woocommerce_stripe_settings' );
update_option( 'woocommerce_calc_taxes', 'yes' );
$by = array_column( ( new Checks() )->run_all(), null, 'id' );
t( 'WooCommerce-Steuern trotz Kleinunternehmer → fail', 'fail' === ( $by['config-tax']['status'] ?? '' ) );
update_option( 'woocommerce_calc_taxes', 'no' );
$m = \Lindenweg16\Legal\Core\Masterdata::all();
$m['last_review'] = gmdate( 'Y-m-d' );
update_option( \Lindenweg16\Legal\Core\Masterdata::OPTION, $m );
$by = array_column( ( new Checks() )->run_all(), null, 'id' );
t( 'Review-Datum gesetzt → ok', 'ok' === $by['review-due']['status'] );
$m['last_review'] = gmdate( 'Y-m-d', strtotime( '-4 months' ) );
update_option( \Lindenweg16\Legal\Core\Masterdata::OPTION, $m );
$by = array_column( ( new Checks() )->run_all(), null, 'id' );
t( 'Review älter als 3 Monate → warn', 'warn' === $by['review-due']['status'] );
update_option( 'll16_warranty_notice', 'Amtlicher Text' );
$by = array_column( ( new Checks() )->run_all(), null, 'id' );
t( 'Gewährleistungstext hinterlegt → ok', 'ok' === $by['config-warranty']['status'] );
delete_option( 'll16_warranty_notice' );

section( 'Monitor: Ergebnisse, Cron, E-Mail' );
$results = Monitor::run();
$last    = get_option( 'll16_last_check' );
t( 'Letztes Ergebnis gespeichert', ! empty( $last['time'] ) && count( $last['results'] ) === count( $results ) );
$sum = Monitor::summary( $results );
t( 'Zusammenfassung zählt', $sum['ok'] + $sum['warn'] + $sum['fail'] === count( $results ) && $sum['fail'] > 0 );
clear_mails();
Monitor::daily();
$m = mails();
t( 'Täglicher Lauf mailt bei Fehlern an die Stammdaten-Adresse', 1 === count( $m ) && 'sven@lindenweg16.de' === $m[0]['to'] && str_contains( $m[0]['subject'], 'Compliance-Monitor' ) );
t( 'Mail nennt Fehler im Klartext', str_contains( $m[0]['message'] ?? '', '✗' ) );
t( 'Cron-Hook ll16_daily ist an Monitor + Consent-Purge + Forms-Purge gehängt', has_action( 'll16_daily', [ Monitor::class, 'daily' ] ) && has_action( 'll16_daily', [ \Lindenweg16\Legal\Consent\ConsentLog::class, 'purge' ] ) && has_action( 'll16_daily', [ \Lindenweg16\Legal\Forms\Module::class, 'purge' ] ) );

finish();
