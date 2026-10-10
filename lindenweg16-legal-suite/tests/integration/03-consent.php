<?php
require __DIR__ . '/lib.php';
global $wpdb;
reset_ratelimits();
$jar = [];

section( 'Consent: Auslieferung' );
$r = http( 'GET', '/', [], $jar );
t( 'Startseite 200', 200 === $r['code'] );
t( 'Banner-Konfiguration im HTML', (bool) preg_match( '/window\.LL16_CONSENT=(\{.*?\});/s', $r['body'], $m ) );
$cfg = json_decode( $m[1] ?? '{}', true );
t( 'Konfiguration: Version, REST-URL, Cookie-Name', ! empty( $cfg['version'] ) && str_contains( (string) $cfg['rest'], 'll16/v1/consent' ) && 'll16_consent' === $cfg['cookie'] );
$cats = array_column( (array) $cfg['categories'], 'key' );
t( 'Nur tatsächlich genutzte Kategorien (notwendig + Präferenzen)', [ 'necessary', 'preferences' ] === $cats, implode( ',', $cats ) );
t( 'Kein Marketing/Statistik im Banner', ! in_array( 'marketing', $cats, true ) && ! in_array( 'statistics', $cats, true ) );
t( 'Banner-JS/CSS eingebunden', str_contains( $r['body'], 'assets/consent.js' ) && str_contains( $r['body'], 'assets/consent.css' ) );
foreach ( [ 'consent.js', 'consent.css' ] as $a ) {
	$x = http( 'GET', '/wp-content/plugins/lindenweg16-legal-suite/assets/' . $a, [], $jar );
	t( "Asset {$a} abrufbar", 200 === $x['code'] && strlen( $x['body'] ) > 500 );
}
t( 'Keine ungelösten Platzhalter im Banner ({vendor_count}, {title})', ! str_contains( $r['body'], '{vendor_count}' ) && ! str_contains( $r['body'], '{title}' ) );
t( 'Startseite setzt vor Einwilligung nur erlaubte Cookies', [] === array_filter( array_keys( $jar ), fn( $n ) => ! preg_match( '/^(woocommerce_|wp_woocommerce_session_|ll16_)/', $n ) ), implode( ',', array_keys( $jar ) ) );

section( 'Consent: serverseitige Blockierung (Output-Rewriter)' );
$html = '<p>Inhalt</p>'
	. '<script src="https://stats.wp.com/e-202301.js" async></script>'
	. '<script src="https://www.google.com/recaptcha/api.js?render=abc"></script>'
	. '<script src="https://js.stripe.com/v3/"></script>'
	. '<script>/* sourcebuster */ window.sbjs = 1;</script>'
	. '<script src="https://connect.facebook.net/en_US/fbevents.js"></script>'
	. '<iframe src="https://www.google.com/maps/embed?pb=123" width="600" height="400"></iframe>';
make_page( 'testblock', 'Testblock', $html );
$r = http( 'GET', '/testblock/', [], $jar );
$b = $r['body'];
t( 'Seite ausgeliefert', 200 === $r['code'], (string) $r['code'] );
t( 'Jetpack-Stats entfernt', ! str_contains( $b, 'stats.wp.com' ) );
t( 'reCAPTCHA entfernt', ! str_contains( $b, 'recaptcha' ) );
t( 'Facebook-Pixel entfernt', ! str_contains( $b, 'connect.facebook.net' ) );
t( 'Stripe außerhalb von Warenkorb/Kasse entfernt', ! str_contains( $b, 'js.stripe.com' ) );
t( 'Order-Attribution-Inline-Skript nicht aktiv (inaktiver Dienst → entfernt)', ! str_contains( $b, 'window.sbjs' ) );
t( 'Maps-iframe lädt nicht (kein src auf google.com)', ! preg_match( '/<iframe[^>]+\ssrc="https:\/\/www\.google\.com\/maps/i', $b ) );
t( 'Maps: Zwei-Klick-Platzhalter mit Button', str_contains( $b, 'data-ll16-load="google-maps"' ) && str_contains( $b, 'data-ll16-src="https://www.google.com/maps/embed?pb=123"' ) );
t( 'Rest der Seite unverändert', str_contains( $b, '<p>Inhalt</p>' ) );
$r = http( 'GET', '/cart/', [], $jar );
t( 'Warenkorbseite lädt (Rewriter bricht nichts)', 200 === $r['code'] );

section( 'Consent: REST-Nachweis (Art. 7 DSGVO)' );
$uuid = wp_generate_uuid4();
$post = fn( $b ) => wp_remote_post( rest_url( 'll16/v1/consent' ), [ 'headers' => [ 'Content-Type' => 'application/json' ], 'body' => wp_json_encode( $b ), 'sslverify' => false ] );
$res  = $post( [ 'id' => $uuid, 'action' => 'custom', 'categories' => [ 'preferences', 'evil', 'marketing' ] ] );
t( 'POST gültig → 200', 200 === wp_remote_retrieve_response_code( $res ), wp_remote_retrieve_body( $res ) );
t( 'Antwort nicht cachebar', str_contains( (string) wp_remote_retrieve_header( $res, 'cache-control' ), 'no-store' ) );
$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ll16_consent_log WHERE consent_id=%s", $uuid ), ARRAY_A );
t( 'Zeile gespeichert', (bool) $row );
t( 'Nur bekannte Kategorien gespeichert', 'preferences,marketing' === ( $row['categories'] ?? '' ), (string) ( $row['categories'] ?? '' ) );
t( 'Registerversion + Bannerversion + Zeit', ! empty( $row['registry_version'] ) && LL16_VERSION === $row['banner_version'] && ! empty( $row['created_at'] ) );
t( 'Keine IP im Datensatz', ! str_contains( wp_json_encode( $row ), '127.0.0.1' ) );
foreach ( [ 'ungültige UUID' => [ 'id' => 'abc', 'action' => 'accept' ], 'ungültige Aktion' => [ 'id' => wp_generate_uuid4(), 'action' => 'hack' ], 'fehlende ID' => [ 'action' => 'accept' ] ] as $label => $payload ) {
	t( "POST {$label} → 400", 400 === wp_remote_retrieve_response_code( $post( $payload ) ) );
}
foreach ( [ 'accept', 'reject', 'gpc' ] as $act ) {
	t( "Aktion {$act} akzeptiert", 200 === wp_remote_retrieve_response_code( $post( [ 'id' => wp_generate_uuid4(), 'action' => $act, 'categories' => [] ] ) ) );
}
t( 'SQL-Injection im Feld id wirkungslos', 400 === wp_remote_retrieve_response_code( $post( [ 'id' => "x'; DROP TABLE wp_ll16_consent_log;--", 'action' => 'accept' ] ) ) && $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ll16_consent_log" ) > 0 );

section( 'Consent: automatische Löschung' );
$old = wp_generate_uuid4();
$wpdb->insert( $wpdb->prefix . 'll16_consent_log', [ 'consent_id' => $old, 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 5 * YEAR_IN_SECONDS ), 'action' => 'accept', 'categories' => '', 'registry_version' => 'x', 'banner_version' => 'x' ] );
\Lindenweg16\Legal\Consent\ConsentLog::purge();
t( 'Alte Einträge werden gelöscht', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}ll16_consent_log WHERE consent_id=%s", $old ) ) );
t( 'Neue Einträge bleiben', 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}ll16_consent_log WHERE consent_id=%s", $uuid ) ) );

section( 'Consent: Re-Consent bei Registeränderung' );
$v1 = \Lindenweg16\Legal\Core\ServiceRegistry::version( \Lindenweg16\Legal\Core\ServiceRegistry::active() );
update_option( \Lindenweg16\Legal\Core\ServiceRegistry::OPTION, [ 'jetpack-analytics' => [ 'active' => true ] ] );
$v2 = \Lindenweg16\Legal\Core\ServiceRegistry::version( \Lindenweg16\Legal\Core\ServiceRegistry::active() );
t( 'Neuer einwilligungspflichtiger Dienst ändert die Version', $v1 !== $v2 );
$r = http( 'GET', '/', [], $jar );
preg_match( '/window\.LL16_CONSENT=(\{.*?\});/s', $r['body'], $m );
$cfg = json_decode( $m[1], true );
t( 'Banner zeigt dann Statistik-Kategorie', in_array( 'statistics', array_column( $cfg['categories'], 'key' ), true ) );
$r = http( 'GET', '/testblock/', [], $jar );
t( 'Jetpack-Stats: aktiver Dienst → text/plain (Consent-Gate)', (bool) preg_match( '/<script[^>]*type="text\/plain"[^>]*data-ll16-service="jetpack-analytics"/', $r['body'] ) && ! preg_match( '/<script[^>]+\ssrc="https:\/\/stats\.wp\.com/', $r['body'] ) );
delete_option( \Lindenweg16\Legal\Core\ServiceRegistry::OPTION );

section( 'Gate: Option-Filter' );
t( 'Order Attribution ist abgeschaltet', 'no' === get_option( 'woocommerce_feature_order_attribution_enabled' ) );
t( 'Jetpack-Stats-Modul wird herausgefiltert', ! in_array( 'stats', (array) apply_filters( 'option_jetpack_active_modules', [ 'stats', 'sso' ] ), true ) );
t( 'Altplugin-Erkennung', \Lindenweg16\Legal\Gate\Module::active_legacy() === [] );
update_option( 'active_plugins', array_merge( (array) get_option( 'active_plugins' ), [ 'jetpack/jetpack.php' ] ) );
t( 'Altplugin-Erkennung findet Jetpack', [ 'jetpack/jetpack.php' ] === \Lindenweg16\Legal\Gate\Module::active_legacy() );
update_option( 'active_plugins', array_values( array_diff( (array) get_option( 'active_plugins' ), [ 'jetpack/jetpack.php' ] ) ) );

section( 'Gate: Dequeue echter Skripte' );
wp_register_script( 'fake-stats', 'https://stats.wp.com/x.js', [], null, true );
wp_register_script( 'fake-recaptcha', 'https://www.google.com/recaptcha/api.js', [], null, true );
wp_register_script( 'fake-own', home_url( '/own.js' ), [], null, true );
wp_enqueue_script( 'fake-stats' );
wp_enqueue_script( 'fake-recaptcha' );
wp_enqueue_script( 'fake-own' );
\Lindenweg16\Legal\Gate\Module::dequeue_blocked();
t( 'Stats-Skript wird dequeued', ! wp_script_is( 'fake-stats', 'enqueued' ) );
t( 'reCAPTCHA wird dequeued', ! wp_script_is( 'fake-recaptcha', 'enqueued' ) );
t( 'Eigenes Skript bleibt', wp_script_is( 'fake-own', 'enqueued' ) );
t( 'script_loader_tag verwirft geblockte Tags', '' === \Lindenweg16\Legal\Gate\Module::filter_tag( '<script src="https://stats.wp.com/x.js"></script>', 'x', 'https://stats.wp.com/x.js' ) );

finish();
