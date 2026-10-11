<?php
require __DIR__ . '/lib.php';
use Lindenweg16\Legal\Checkout\Module as Checkout;
use Lindenweg16\Legal\Product\Module as Product;

function rb( string $html, string $name ): string {
	return (string) apply_filters( 'render_block', $html, [ 'blockName' => $name, 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => $html, 'innerContent' => [ $html ] ], new WP_Block( [ 'blockName' => $name ] ) );
}

// --- Testdaten -------------------------------------------------------------------------
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_store_pages_only', 'no' );
update_option( 'woocommerce_calc_taxes', 'no' );
update_option( 'woocommerce_currency', 'EUR' );
update_option( 'woocommerce_default_country', 'DE' );
update_option( 'woocommerce_allowed_countries', 'all' );
update_option( 'woocommerce_ship_to_countries', '' );

function mk_product( string $name, float $price, array $attrs = [], array $meta = [] ): WC_Product_Simple {
	$existing = get_posts( [ 'post_type' => 'product', 'title' => $name, 'numberposts' => 1, 'post_status' => 'any' ] );
	$p        = $existing ? new WC_Product_Simple( $existing[0]->ID ) : new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_regular_price( (string) $price );
	$p->set_status( 'publish' );
	$list = [];
	foreach ( $attrs as $label => $opts ) {
		$a = new WC_Product_Attribute();
		$a->set_name( $label );
		$a->set_options( $opts );
		$a->set_visible( true );
		$a->set_variation( true );
		$list[] = $a;
	}
	$p->set_attributes( $list );
	foreach ( $meta as $k => $v ) {
		$p->update_meta_data( $k, $v );
	}
	$p->save();
	return $p;
}
$bsm  = mk_product( 'BSM - Basismodul', 30, [ 'Farbe' => [ 'Anthrazit', 'Weiß' ], 'Holz' => [ 'Fichte', 'Ahorn', 'Walnuss' ] ] );
$base = mk_product( 'BASE', 26 );
$ind  = mk_product( 'Sonderanfertigung', 40, [], [ '_ll16_individual' => 'yes', '_ll16_delivery_time' => '10–14 Werktage' ] );

section( 'Checkout: Button, Hinweise, Felder' );
t( 'Bestell-Button (klassisch) exakt „Zahlungspflichtig bestellen“', 'Zahlungspflichtig bestellen' === apply_filters( 'woocommerce_order_button_text', 'Place order' ) );
$actions = rb( '<div class="wp-block-woocommerce-checkout-actions-block"></div>', 'woocommerce/checkout-actions-block' );
t( 'Actions-Block bleibt serverseitig unverändert (Hinweis kommt per JS, sonst Duplizierung des Block-Checkouts)', str_contains( $actions, 'wp-block-woocommerce-checkout-actions-block' ) && ! str_contains( $actions, 'll16-checkout-terms' ) && 1 === preg_match( '/^<div[^>]*wp-block-woocommerce-checkout-actions-block[^>]*><\/div>$/', $actions ) );
$actions = Checkout::terms_notice();
foreach ( [ '/agb/', '/widerruf/', '/datenschutz/' ] as $link ) {
	t( "Hinweis verlinkt {$link}", str_contains( $actions, $link ) );
}
t( 'Hinweis nennt Widerrufsrecht (14 Tage) und Ausnahme', str_contains( $actions, '14 Tage' ) && str_contains( $actions, 'individuell angefertigte' ) );
t( 'Hinweis nennt den Button-Text', str_contains( $actions, '„Zahlungspflichtig bestellen“' ) );
ob_start();
do_action( 'woocommerce_review_order_before_submit' );
t( 'Klassischer Checkout: Hinweis vor dem Button', str_contains( ob_get_clean(), 'll16-checkout-terms' ) );
t( 'Inject setzt Markup INNERHALB des Wurzelelements', '<div class="a"><i>x</i><p>y</p></div>' === Checkout::inject( '<div class="a"><p>y</p></div>', '<i>x</i>' ) );
$fields = rb( '<div></div>', 'woocommerce/checkout-fields-block' );
t( 'Versandkosten + Lieferzeit VOR der Adresseingabe', str_contains( $fields, '6,99' ) && str_contains( $fields, 'Lieferzeit' ) && str_contains( $fields, '/versand-zahlung/' ) );
t( 'Hinweis nennt „nur nach Deutschland“', str_contains( $fields, 'nur nach Deutschland' ) );
$cartblk = rb( '<div></div>', 'woocommerce/cart-items-block' );
t( 'Versandhinweis auch im Warenkorb', str_contains( $cartblk, '6,99' ) );
t( 'Andere Blöcke unverändert', '<p>x</p>' === rb( '<p>x</p>', 'core/paragraph' ) );
$fields_api = \Automattic\WooCommerce\Blocks\Package::container()->get( \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class );
$ids = array_keys( $fields_api->get_additional_fields() );
t( 'Pflicht-Checkbox lindenweg16/terms registriert', in_array( 'lindenweg16/terms', $ids, true ), implode( ',', $ids ) );
t( 'Checkbox für individuelle Ware registriert', in_array( 'lindenweg16/individual_ack', $ids, true ) );
$iack = $fields_api->get_additional_fields()['lindenweg16/individual_ack'] ?? [];
t( 'Checkbox für individuelle Ware hat Sichtbarkeits- und Pflicht-Regel (nur bei passender Ware)', ! empty( $iack['hidden'] ) && is_array( $iack['hidden'] ) && ! empty( $iack['required'] ) && is_array( $iack['required'] ) );
t( 'Regeln sind gegensätzlich (hidden = ohne, required = mit individueller Ware)', false === $iack['hidden']['properties']['cart']['properties']['extensions']['properties']['lindenweg16']['properties']['has_individual']['const'] && true === $iack['required']['properties']['cart']['properties']['extensions']['properties']['lindenweg16']['properties']['has_individual']['const'] );
$terms = $fields_api->get_additional_fields()['lindenweg16/terms'] ?? [];
t( 'Terms-Checkbox ist Pflicht und vom Typ checkbox', ! empty( $terms['required'] ) && 'checkbox' === ( $terms['type'] ?? '' ) );

section( 'Checkout: nur Deutschland' );
t( 'Lieferländer == [DE]', [ 'DE' ] === array_keys( WC()->countries->get_shipping_countries() ), implode( ',', array_keys( WC()->countries->get_shipping_countries() ) ) );
t( 'Rechnungsländer == [DE]', [ 'DE' ] === array_keys( WC()->countries->get_allowed_countries() ) );
$m = \Lindenweg16\Legal\Core\Masterdata::all();
$m['delivery_countries'] = [ 'DE', 'AT' ];
update_option( \Lindenweg16\Legal\Core\Masterdata::OPTION, $m );
t( 'Änderung der Stammdaten wirkt (DE, AT)', [ 'DE' => 'Deutschland', 'AT' => 'Österreich' ] === Checkout::countries( [ 'DE' => 'Deutschland', 'AT' => 'Österreich', 'FR' => 'Frankreich', 'US' => 'USA' ] ) );
$m['delivery_countries'] = [ 'DE' ];
update_option( \Lindenweg16\Legal\Core\Masterdata::OPTION, $m );

section( 'Checkout: Auswahlpflicht (L7)' );
t( 'BSM ohne Auswahl: Validierung schlägt fehl', false === Checkout::validate_selection( true, $bsm->get_id(), 1 ) );
t( 'Fehlermeldung wird ausgegeben', (bool) array_filter( wc_get_notices( 'error' ), fn( $n ) => str_contains( $n['notice'], 'Farbe und Holz' ) ) );
wc_clear_notices();
$_REQUEST = [ 'attribute_farbe' => 'Weiß' ];
t( 'Nur Farbe gewählt: weiterhin blockiert', false === Checkout::validate_selection( true, $bsm->get_id(), 1 ) );
$_REQUEST = [ 'attribute_farbe' => 'Weiß', 'attribute_holz' => 'Ahorn' ];
t( 'Farbe + Holz gewählt: erlaubt', true === Checkout::validate_selection( true, $bsm->get_id(), 1 ) );
$_REQUEST = [];
t( 'Produkt ohne Optionen: erlaubt', true === Checkout::validate_selection( true, $base->get_id(), 1 ) );
add_filter( 'll16_missing_selection', '__return_empty_array' );
t( 'Ausnahme-Filter für Konfigurator', true === Checkout::validate_selection( true, $bsm->get_id(), 1 ) );
remove_filter( 'll16_missing_selection', '__return_empty_array' );
wc_clear_notices();

// Store API (Block-Warenkorb) – echte HTTP-Requests
$jar = [];
$r   = http( 'GET', '/wp-json/wc/store/v1/cart', [], $jar );
$nonce = (string) ( $r['headers']['nonce'] ?? '' );
t( 'Store-API-Cart erreichbar, Nonce vorhanden', 200 === $r['code'] && '' !== $nonce, (string) $r['code'] );
$add = function ( int $id ) use ( &$jar, $nonce ) {
	return http( 'POST', '/wp-json/wc/store/v1/cart/add-item', wp_json_encode( [ 'id' => $id, 'quantity' => 1 ] ), $jar, [ 'Nonce' => $nonce, 'Content-Type' => 'application/json' ] );
};
$r = $add( $bsm->get_id() );
t( 'Store API: BSM ohne Auswahl → abgelehnt (4xx)', $r['code'] >= 400 && $r['code'] < 500, $r['code'] . ' ' . substr( $r['body'], 0, 120 ) );
$cart = json_decode( http( 'GET', '/wp-json/wc/store/v1/cart', [], $jar )['body'], true );
t( 'Warenkorb nach Ablehnung leer', 0 === count( $cart['items'] ?? [ 1 ] ) );
$r = $add( $base->get_id() );
t( 'Store API: BASE → 2xx', $r['code'] >= 200 && $r['code'] < 300, $r['code'] . ' ' . substr( $r['body'], 0, 160 ) );
$cart = json_decode( http( 'GET', '/wp-json/wc/store/v1/cart', [], $jar )['body'], true );
t( 'Warenkorb enthält BASE', 1 === count( $cart['items'] ?? [] ) );
$item_data = wp_json_encode( $cart['items'][0]['item_data'] ?? [], JSON_UNESCAPED_UNICODE );
t( 'Lieferzeit je Position im Warenkorb (item_data)', str_contains( $item_data, 'Lieferzeit' ) && str_contains( $item_data, '3–5 Werktage' ), $item_data );
$r = $add( $ind->get_id() );
$cart = json_decode( http( 'GET', '/wp-json/wc/store/v1/cart', [], $jar )['body'], true );
t( 'Eigene Lieferzeit der Sonderanfertigung im Warenkorb', str_contains( wp_json_encode( $cart['items'][1]['item_data'] ?? [], JSON_UNESCAPED_UNICODE ), '10–14 Werktage' ) );
$cart_page = http( 'GET', '/cart/', [], $jar );
t( 'Warenkorbseite lädt mit Inhalt', 200 === $cart_page['code'] );

section( 'Checkout: Cart-Datum „enthält individuelle Ware“' );
WC()->initialize_session();
WC()->cart = new WC_Cart();
t( 'Leerer Warenkorb: kein individuelles Produkt', false === Checkout::cart_has_individual() );
WC()->cart->add_to_cart( $base->get_id() );
t( 'Standardware: false', false === Checkout::cart_has_individual() );
WC()->cart->add_to_cart( $ind->get_id() );
t( 'Mit Sonderanfertigung: true', true === Checkout::cart_has_individual() );
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $base->get_id(), 1, 0, [], [ 'll16_individual' => 'Personalisierung: Name' ] );
t( 'Personalisierung aus dem Konfigurator (Warenkorb-Position) wird erkannt', true === Checkout::cart_has_individual() );
WC()->cart->empty_cart();
$store = wp_remote_get( rest_url( 'wc/store/v1/cart' ), [ 'sslverify' => false ] );
$cart_json = json_decode( wp_remote_retrieve_body( $store ), true );
t( 'Store API liefert extensions.lindenweg16.has_individual', isset( $cart_json['extensions']['lindenweg16']['has_individual'] ) && false === $cart_json['extensions']['lindenweg16']['has_individual'] );

section( 'Checkout: Bestätigung bei individueller Ware' );
WC()->initialize_session();
WC()->cart = new WC_Cart();
WC()->cart->add_to_cart( $ind->get_id() );
$order = wc_create_order();
$threw = false;
try {
	Checkout::validate_individual_ack( $order, new WP_REST_Request() );
} catch ( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException $e ) {
	$threw = 'll16_individual_ack' === $e->getErrorCode();
}
t( 'Ohne Bestätigung → RouteException ll16_individual_ack', $threw );
$order->update_meta_data( '_wc_other/lindenweg16/individual_ack', '1' );
$order->save();
$ok = true;
try {
	Checkout::validate_individual_ack( wc_get_order( $order->get_id() ), new WP_REST_Request() );
} catch ( \Throwable $e ) {
	$ok = false;
}
t( 'Mit gesetzter Bestätigung → durchgelassen', $ok );
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $base->get_id() );
$ok = true;
try {
	Checkout::validate_individual_ack( $order, new WP_REST_Request() );
} catch ( \Throwable $e ) {
	$ok = false;
}
t( 'Standardware braucht keine Bestätigung', $ok );
WC()->cart->empty_cart();

section( 'Testmodus-Wächter' );
t( 'Ohne Testoptionen: nichts erkannt', [] === \Lindenweg16\Legal\Checkout\TestMode::detect() );
update_option( 'woocommerce_woocommerce_payments_settings', [ 'test_mode' => 'yes' ] );
update_option( 'woocommerce_stripe_settings', [ 'testmode' => 'yes' ] );
update_option( 'woocommerce-ppcp-settings', [ 'sandbox_on' => true ] );
$tm = \Lindenweg16\Legal\Checkout\TestMode::detect();
t( 'WooPayments-Testmodus erkannt', in_array( 'WooPayments (Testmodus)', $tm, true ) );
t( 'Stripe-Testmodus erkannt', in_array( 'Stripe (Testmodus)', $tm, true ) );
t( 'PayPal-Sandbox erkannt', in_array( 'PayPal (Sandbox)', $tm, true ) );
ob_start();
Checkout::test_mode_notice();
t( 'Admin-Warnung nur für berechtigte Nutzer', '' === ob_get_clean() );
wp_set_current_user( 1 );
ob_start();
Checkout::test_mode_notice();
$notice = ob_get_clean();
t( 'Rote Admin-Warnung für Admin', str_contains( $notice, 'notice-error' ) && str_contains( $notice, 'Testmodus' ) );
wp_set_current_user( 0 );
t( 'HTML-Erkennung: Testkarten-Hinweis', \Lindenweg16\Legal\Checkout\TestMode::html_has_test_hint( 'Verwende die Testkarte 4000 0027 6000 0016' ) );
t( 'HTML-Erkennung: sauberer Text', ! \Lindenweg16\Legal\Checkout\TestMode::html_has_test_hint( '<p>Zahlungspflichtig bestellen</p>' ) );
delete_option( 'woocommerce_woocommerce_payments_settings' );
delete_option( 'woocommerce_stripe_settings' );
delete_option( 'woocommerce-ppcp-settings' );

section( 'Produkt: Preisangaben' );
$ph = apply_filters( 'woocommerce_get_price_html', '<span class="price">26,00 €</span>', $base );
t( 'Kleinunternehmer-Hinweis', str_contains( $ph, 'Kleinunternehmer gem. § 19 UStG' ) );
t( 'Kein „inkl. MwSt.“', ! preg_match( '/inkl\.?\s*MwSt/i', $ph ) );
t( '„zzgl. Versandkosten“ mit Höhe und funktionierendem Link', str_contains( $ph, 'zzgl. 6,99 € Versandkosten' ) && str_contains( $ph, '/versand-zahlung/' ) );
$link = http( 'GET', '/versand-zahlung/' );
t( 'Link-Ziel /versand-zahlung/ liefert 200', 200 === $link['code'] );
$m['small_business'] = false;
update_option( \Lindenweg16\Legal\Core\Masterdata::OPTION, $m );
t( 'Ohne Kleinunternehmer: Hinweis entfällt', ! str_contains( apply_filters( 'woocommerce_get_price_html', '<span>1</span>', $base ), '§ 19' ) );
$m['small_business'] = true;
update_option( \Lindenweg16\Legal\Core\Masterdata::OPTION, $m );
t( 'Preis-Suffix von WooCommerce leer', '' === get_option( 'woocommerce_price_display_suffix' ) );

section( 'Produkt: Lieferzeit & Lagerstatus' );
t( 'Standard-Lieferzeit aus Stammdaten', '3–5 Werktage' === Product::delivery_time( $base ) );
t( 'Produktspezifische Lieferzeit', '10–14 Werktage' === Product::delivery_time( $ind ) );
$base->set_stock_status( 'instock' );
t( 'Lagertext „Auf Lager“', 'Auf Lager' === apply_filters( 'woocommerce_get_availability_text', 'Vorrätig', $base ) );
$base->set_stock_status( 'outofstock' );
t( 'Lagertext „Zurzeit nicht auf Lager“', 'Zurzeit nicht auf Lager' === apply_filters( 'woocommerce_get_availability_text', 'Vorrätig', $base ) );
$base->set_stock_status( 'instock' );
$base->save();

section( 'Produkt: GPSR, Veröffentlichungssperre' );
$tabs = apply_filters( 'woocommerce_product_tabs', [] );
t( 'Tab „Produktsicherheit“', isset( $tabs['ll16_gpsr'] ) );
$fields_in = [ '_ll16_gpsr_model' => 'HAKEN BASE v2', '_ll16_gpsr_batch' => 'Charge auf Rückseite', '_ll16_gpsr_warnings' => 'Nur an tragfähigen Wänden montieren.', '_ll16_gpsr_assembly' => 'Max. 15 kg je Modul' ];
$_POST = $fields_in + [ '_ll16_delivery_time' => '3–5 Werktage', '_ll16_individual' => '1' ];
Product::save_fields( $base );
$base->save();
$base = wc_get_product( $base->get_id() );
t( 'GPSR-Felder gespeichert', 'HAKEN BASE v2' === $base->get_meta( '_ll16_gpsr_model' ) && 'yes' === $base->get_meta( '_ll16_individual' ) );
$base->update_meta_data( '_ll16_individual', 'no' );
$base->save();
$GLOBALS['product'] = $base;
ob_start();
Product::gpsr_tab();
$tab = ob_get_clean();
t( 'GPSR-Tab: Hersteller mit Anschrift & E-Mail aus Stammdaten', str_contains( $tab, 'Sven Meendermann – Lindenweg16, Lindenweg 16, 31559 Haste' ) && str_contains( $tab, 'sven@lindenweg16.de' ) );
t( 'GPSR-Tab: Pflichtfelder', str_contains( $tab, 'HAKEN BASE v2' ) && str_contains( $tab, 'Tragfähigkeit' ) );

$data = [ 'post_type' => 'product', 'post_status' => 'publish' ];
$_POST = [ '_ll16_gpsr_model' => '', '_thumbnail_id' => '' ];
$res = Product::guard_publish( $data, [] );
t( 'Ohne GPSR-Felder & Bild: auf Entwurf gesetzt', 'draft' === $res['post_status'] );
t( 'Admin-Hinweis nennt fehlende Felder', in_array( 'Produktbild', (array) get_transient( 'll16_product_guard_0' ), true ) );
$_POST = $fields_in + [ '_thumbnail_id' => '' ];
t( 'Mit GPSR, aber ohne Bild: gesperrt', 'draft' === Product::guard_publish( $data, [] )['post_status'] );
$_POST = $fields_in + [ '_thumbnail_id' => '123' ];
t( 'Mit GPSR und Bild: darf publiziert werden', 'publish' === Product::guard_publish( $data, [] )['post_status'] );
$_POST = [];
t( 'Ohne Formular (Import/REST): kein Eingriff', 'publish' === Product::guard_publish( $data, [] )['post_status'] );
t( 'Andere Post-Typen unberührt', 'publish' === Product::guard_publish( [ 'post_type' => 'page', 'post_status' => 'publish' ], [] )['post_status'] );
delete_transient( 'll16_product_guard_0' );

section( 'Produkt: Frontend' );
$_POST = $fields_in + [ '_thumbnail_id' => '' ];
$base->save();
$url = get_permalink( $base->get_id() );
$r   = http( 'GET', str_replace( home_url(), '', $url ) );
t( 'Produktseite 200', 200 === $r['code'], $url . ' ' . $r['code'] );
t( 'Kleinunternehmer + Versandkosten sichtbar', str_contains( $r['body'], '§ 19 UStG' ) && str_contains( $r['body'], 'zzgl. 6,99' ) );
t( 'Lieferzeit-Zeile', str_contains( $r['body'], 'Lieferzeit: 3–5 Werktage' ) );
t( 'Gewährleistungshinweis aus dem Plugin', str_contains( $r['body'], 'll16-warranty-notice' ) );
t( 'Genau ein Lagerstatus (kein „Vorrätig“ neben „nicht auf Lager“)', ! ( str_contains( $r['body'], 'Zurzeit nicht auf Lager' ) && str_contains( $r['body'], 'Vorrätig' ) ) );
t( 'Kein „inkl. MwSt.“ auf der Produktseite', ! preg_match( '/inkl\.?\s*MwSt/i', wp_strip_all_tags( $r['body'] ) ) );
$r = http( 'GET', '/shop/' );
t( 'Shop 200 mit Preisen', 200 === $r['code'] && str_contains( $r['body'], '§ 19 UStG' ) );

section( 'Produkt: Werbeaussagen-Warnliste' );
t( '„handgemacht“ wird erkannt (Groß/Klein egal)', [ 'handgemacht' ] === \Lindenweg16\Legal\Product\Gpsr::claims( 'Alles HANDGEMACHT', Product::DEFAULT_CLAIMS ) );
t( 'Empfohlene Formulierung bleibt unbeanstandet', [] === \Lindenweg16\Legal\Product\Gpsr::claims( '3D-gedruckt und von Hand montiert', Product::DEFAULT_CLAIMS ) );
t( 'Telefon-Claim „Echte Menschen“ in Liste', [] !== \Lindenweg16\Legal\Product\Gpsr::claims( 'Echte Menschen am Telefon', Product::DEFAULT_CLAIMS ) );

section( 'Gewährleistungshinweis' );
t( 'Standardtext vorhanden', str_contains( Product::warranty_text(), 'gesetzliche' ) );
update_option( 'll16_warranty_notice', 'Eigener amtlicher Text' );
t( 'Konfigurierbarer Wortlaut', 'Eigener amtlicher Text' === Product::warranty_text() );
delete_option( 'll16_warranty_notice' );

finish();
