<?php
declare(strict_types=1);

namespace Lindenweg16\Legal\Checkout;

use Lindenweg16\Legal\Core\Masterdata;

/**
 * M4 – Checkout- und Warenkorb-Compliance (Block-Checkout/-Cart).
 */
final class Module {

	public const BUTTON = 'Zahlungspflichtig bestellen';

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue' ] );
		add_filter( 'woocommerce_order_button_text', static fn() => self::BUTTON );
		add_filter( 'render_block', [ self::class, 'render_block' ], 10, 2 );
		add_action( 'woocommerce_init', [ self::class, 'register_fields' ] );
		add_filter( 'woocommerce_countries_allowed_countries', [ self::class, 'countries' ] );
		add_filter( 'woocommerce_countries_shipping_countries', [ self::class, 'countries' ] );
		add_filter( 'woocommerce_add_to_cart_validation', [ self::class, 'validate_selection' ], 10, 3 );
		add_filter( 'woocommerce_get_item_data', [ self::class, 'item_delivery_time' ], 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ self::class, 'validate_individual_ack' ], 10, 2 );
		add_action( 'admin_notices', [ self::class, 'test_mode_notice' ] );
	}

	public static function enqueue(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		wp_enqueue_script( 'll16-checkout', LL16_URL . 'assets/checkout.js', [ 'wc-blocks-checkout' ], LL16_VERSION, true );
		wp_add_inline_script( 'll16-checkout', 'window.LL16_CHECKOUT=' . wp_json_encode( [ 'buttonLabel' => self::BUTTON ] ) . ';', 'before' );
	}

	/**
	 * @param string               $html
	 * @param array<string,mixed>  $block
	 */
	public static function render_block( $html, $block ) {
		$name = $block['blockName'] ?? '';
		if ( 'woocommerce/checkout-actions-block' === $name ) {
			$links = sprintf(
				'<div class="ll16-checkout-terms"><p>Mit Klick auf „%s" gibst du eine verbindliche Bestellung ab. Es gelten unsere <a href="%s" target="_blank" rel="noopener">AGB</a>. Deine <a href="%s" target="_blank" rel="noopener">Widerrufsbelehrung</a> (inkl. Muster-Widerrufsformular) und die <a href="%s" target="_blank" rel="noopener">Datenschutzerklärung</a> findest du hier. Du hast ein Widerrufsrecht von %d Tagen; ausgenommen sind individuell angefertigte Waren.</p></div>',
				esc_html( self::BUTTON ),
				esc_url( home_url( '/agb/' ) ),
				esc_url( home_url( '/widerruf/' ) ),
				esc_url( home_url( '/datenschutz/' ) ),
				(int) Masterdata::get( 'withdrawal_days' )
			);
			return $links . $html;
		}
		if ( in_array( $name, [ 'woocommerce/checkout-fields-block', 'woocommerce/cart-items-block' ], true ) ) {
			return self::shipping_notice() . $html;
		}
		return $html;
	}

	private static function shipping_notice(): string {
		$m    = Masterdata::all();
		$free = '' !== (string) $m['free_shipping_from'] ? sprintf( ' Ab %s € Bestellwert liefern wir versandkostenfrei.', esc_html( (string) $m['free_shipping_from'] ) ) : '';
		return sprintf(
			'<p class="ll16-shipping-notice">Lieferung nur nach Deutschland. Versandkosten: %s € pro Bestellung.%s Lieferzeit: %s. <a href="%s">Mehr zu Versand &amp; Zahlung</a></p>',
			esc_html( (string) $m['shipping_cost'] ),
			$free,
			esc_html( (string) $m['delivery_time'] ),
			esc_url( home_url( '/versand-zahlung/' ) )
		);
	}

	public static function register_fields(): void {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		woocommerce_register_additional_checkout_field(
			[
				'id'            => 'lindenweg16/terms',
				'label'         => 'Ich habe die AGB, die Widerrufsbelehrung und die Datenschutzerklärung (verlinkt über dem Bestell-Button) gelesen und akzeptiere die AGB.',
				'location'      => 'order',
				'type'          => 'checkbox',
				'required'      => true,
			]
		);
		woocommerce_register_additional_checkout_field(
			[
				'id'       => 'lindenweg16/individual_ack',
				'label'    => 'Nur bei individuell angefertigten Waren: Ich weiß, dass für diese Ware kein Widerrufsrecht besteht.',
				'location' => 'order',
				'type'     => 'checkbox',
				'required' => false,
			]
		);
	}

	/**
	 * @param array<string,string> $countries
	 * @return array<string,string>
	 */
	public static function countries( $countries ) {
		$allowed = array_map( 'strtoupper', (array) Masterdata::get( 'delivery_countries' ) );
		return array_intersect_key( (array) $countries, array_flip( $allowed ) );
	}

	/**
	 * @param bool $passed
	 * @param int  $product_id
	 * @param int  $qty
	 */
	public static function validate_selection( $passed, $product_id, $qty ) {
		$product = wc_get_product( $product_id );
		if ( ! $product || $product->is_type( 'variable' ) ) {
			return $passed; // Variable Produkte prüft WooCommerce selbst.
		}
		$attrs = [];
		foreach ( $product->get_attributes() as $key => $attr ) {
			$attrs[ (string) $key ] = [ 'is_variation' => $attr->get_variation(), 'options' => $attr->is_taxonomy() ? wc_get_product_terms( $product->get_id(), $attr->get_name(), [ 'fields' => 'slugs' ] ) : $attr->get_options() ];
		}
		$missing = Selection::missing( $attrs, $_REQUEST ); // phpcs:ignore WordPress.Security
		/**
		 * Ausnahme für Konfigurator o. Ä., die die Auswahl auf eigenem Weg erfassen.
		 */
		$missing = (array) apply_filters( 'll16_missing_selection', $missing, $product );
		if ( [] === $missing ) {
			return $passed;
		}
		wc_add_notice( 'Bitte wähle zuerst alle Optionen (z. B. Farbe und Holz) aus, bevor du dieses Produkt in den Warenkorb legst.', 'error' );
		return false;
	}

	/**
	 * @param array<int,array<string,mixed>> $item_data
	 * @param array<string,mixed>            $cart_item
	 * @return array<int,array<string,mixed>>
	 */
	public static function item_delivery_time( $item_data, $cart_item ) {
		$product = $cart_item['data'] ?? null;
		if ( $product instanceof \WC_Product ) {
			$item_data[] = [ 'key' => 'Lieferzeit', 'value' => \Lindenweg16\Legal\Product\Module::delivery_time( $product ) ];
		}
		return $item_data;
	}

	/**
	 * @param \WC_Order        $order
	 * @param \WP_REST_Request $request
	 */
	public static function validate_individual_ack( $order, $request ): void {
		$has_individual = false;
		foreach ( WC()->cart ? WC()->cart->get_cart() : [] as $item ) {
			if ( \Lindenweg16\Legal\Product\Module::is_individual( $item['data'], $item ) ) {
				$has_individual = true;
				break;
			}
		}
		if ( ! $has_individual ) {
			return;
		}
		$ack = null;
		if ( class_exists( \Automattic\WooCommerce\Blocks\Package::class ) ) {
			try {
				$fields = \Automattic\WooCommerce\Blocks\Package::container()->get( \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class );
				$ack    = $fields->get_field_from_object( 'lindenweg16/individual_ack', $order, 'other' );
			} catch ( \Throwable $e ) {
				$ack = null; // zu klären: Feld-API in neuer WooCommerce-Version prüfen
			}
		}
		if ( ! $ack ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'll16_individual_ack', 'Bitte bestätige den Hinweis zum Ausschluss des Widerrufsrechts bei individuell angefertigten Waren.', 400 );
		}
	}

	public static function test_mode_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$active = TestMode::detect();
		if ( [] === $active ) {
			return;
		}
		printf( '<div class="notice notice-error"><p><strong>Zahlungs-Testmodus aktiv:</strong> %s. Vor dem Go-Live abschalten.</p></div>', esc_html( implode( ', ', $active ) ) );
	}
}
