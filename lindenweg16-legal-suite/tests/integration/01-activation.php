<?php
require __DIR__ . '/lib.php';
global $wpdb;

section( 'Aktivierung, Schema, Cron' );
t( 'Plugin aktiv', is_plugin_active( 'lindenweg16-legal-suite/lindenweg16-legal-suite.php' ) );
t( 'Version gesetzt', LL16_VERSION === get_option( 'll16_db_version' ) );
foreach ( [ 'll16_consent_log', 'll16_subscribers', 'll16_contact', 'll16_notify' ] as $tbl ) {
	$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM sqlite_master WHERE type="table" AND name=%s', $wpdb->prefix . $tbl ) );
	t( "Tabelle {$tbl} existiert", (bool) $exists );
}
t( 'Tagescron geplant', (bool) wp_next_scheduled( 'll16_daily' ) );
t( 'Consent-Log hat keine IP-/UA-Spalte', ! preg_match( '/ip|agent/i', (string) $wpdb->get_var( 'SELECT sql FROM sqlite_master WHERE name="' . $wpdb->prefix . 'll16_consent_log"' ) ) );

section( 'Module / Feature-Flags' );
$flags = \Lindenweg16\Legal\Core\Modules::flags();
t( 'Neun Module bekannt', 9 === count( $flags ) );
t( 'Alle Module aktiv (Testkonfiguration)', ! in_array( false, $flags, true ), wp_json_encode( $flags ) );
foreach ( \Lindenweg16\Legal\Core\Plugin::MODULES as $key => $class ) {
	t( "Modulklasse {$key} ladbar", class_exists( $class ) && method_exists( $class, 'init' ) );
}
t( 'Admin-Menü registriert', (bool) has_action( 'admin_menu' ) );

section( 'Stammdaten & Register' );
$m = \Lindenweg16\Legal\Core\Masterdata::all();
t( 'Standard-Stammdaten', 'Sven Meendermann' === $m['name'] && '31559' === $m['zip'] && true === $m['small_business'] );
$san = \Lindenweg16\Legal\Core\Masterdata::sanitize( [ 'email' => ' bad ', 'withdrawal_days' => '-5', 'address_form' => 'x', 'delivery_countries' => 'de, at', 'name' => '<b>Hans</b>' ] );
t( 'Sanitize: ungültige E-Mail wird leer', '' === $san['email'] );
t( 'Sanitize: negative Zahl → 0', 0 === $san['withdrawal_days'] );
t( 'Sanitize: Ansprache nur du/sie', 'du' === $san['address_form'] );
t( 'Sanitize: Länderliste', [ 'de', 'at' ] === $san['delivery_countries'] );
t( 'Sanitize: HTML entfernt', 'Hans' === $san['name'] );
$all = \Lindenweg16\Legal\Core\ServiceRegistry::all();
t( 'Register enthält Stripe, PayPal, Maps', isset( $all['stripe'], $all['paypal'], $all['google-maps'] ) );
t( 'Register-Version stabil', \Lindenweg16\Legal\Core\ServiceRegistry::version( $all ) === \Lindenweg16\Legal\Core\ServiceRegistry::version( $all ) );

finish();
