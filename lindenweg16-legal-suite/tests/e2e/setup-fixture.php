<?php
// Fixture für Browser-Tests: aktiver Test-Dienst (Statistik) mit Skript und Cookie, Seite mit Skript + Maps-iframe.
require dirname( __DIR__ ) . '/integration/lib.php';
update_option( \Lindenweg16\Legal\Core\ServiceRegistry::OPTION, [
	'testsvc' => [
		'name' => 'Test-Statistik', 'provider' => 'Testanbieter', 'category' => 'statistics', 'purpose' => 'Nur für Tests.',
		'cookies' => [ [ 'name' => 'tst_cookie', 'duration' => 'Sitzung', 'purpose' => 'Test' ] ],
		'legal_basis' => 'Art. 6 Abs. 1 lit. a DSGVO', 'patterns' => [ 'll16-test-script.js' ], 'active' => true,
	],
] );
file_put_contents( ABSPATH . 'll16-test-script.js', 'document.cookie="tst_cookie=1; path=/"; window.__ll16_test_loaded=true;' );
make_page( 'gate-test', 'Gate-Test', '<p>Gate</p><script src="' . home_url( '/ll16-test-script.js' ) . '"></script><iframe src="https://www.google.com/maps/embed?pb=test" width="400" height="300" title="Karte"></iframe>' );
make_page( 'neue-kategorie-test', 'x', 'x' );
// Versand & Zahlung für den Bestelltest: Zone Deutschland mit Pauschale 6,99 €, Überweisung aktiv.
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_store_pages_only', 'no' );
update_option( 'woocommerce_bacs_settings', [ 'enabled' => 'yes', 'title' => 'Überweisung', 'description' => '' ] );
foreach ( WC_Shipping_Zones::get_zones() as $z ) { ( new WC_Shipping_Zone( $z['id'] ) )->delete(); }
$zone = new WC_Shipping_Zone();
$zone->set_zone_name( 'Deutschland' );
$zone->add_location( 'DE', 'country' );
$zone->save();
$iid = $zone->add_shipping_method( 'flat_rate' );
update_option( 'woocommerce_flat_rate_' . $iid . '_settings', [ 'title' => 'Versand', 'tax_status' => 'none', 'cost' => '6.99' ] );
echo "fixture ok\n";
