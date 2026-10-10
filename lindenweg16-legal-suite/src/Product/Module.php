<?php
declare(strict_types=1);

namespace Lindenweg16\Legal\Product;

use Lindenweg16\Legal\Core\Masterdata;

/**
 * M5 – Produktseiten und Preisangaben.
 */
final class Module {

	public const DEFAULT_CLAIMS = [ 'handgemacht', 'handmade', 'echte menschen', 'bester', 'einzigartig', 'made in germany' ];

	public static function init(): void {
		add_filter( 'woocommerce_get_price_html', [ self::class, 'price_html' ], 20, 2 );
		add_filter( 'option_woocommerce_price_display_suffix', '__return_empty_string' );
		add_action( 'woocommerce_single_product_summary', [ self::class, 'delivery_html' ], 11 );
		add_filter( 'woocommerce_get_availability_text', [ self::class, 'availability_text' ], 20, 2 );
		add_filter( 'woocommerce_product_tabs', [ self::class, 'tabs' ] );
		add_action( 'woocommerce_single_product_summary', [ self::class, 'warranty_notice' ], 12 );
		add_action( 'woocommerce_before_cart_totals', [ self::class, 'warranty_notice' ] );
		add_action( 'woocommerce_product_options_general_product_data', [ self::class, 'admin_fields' ] );
		add_action( 'woocommerce_admin_process_product_object', [ self::class, 'save_fields' ] );
		add_filter( 'wp_insert_post_data', [ self::class, 'guard_publish' ], 10, 2 );
		add_action( 'admin_notices', [ self::class, 'admin_notices' ] );
	}

	public static function delivery_time( \WC_Product $product ): string {
		$own = (string) $product->get_meta( '_ll16_delivery_time' );
		return '' !== trim( $own ) ? $own : (string) Masterdata::get( 'delivery_time' );
	}

	/**
	 * @param \WC_Product         $product
	 * @param array<string,mixed> $cart_item
	 */
	public static function is_individual( $product, array $cart_item = [] ): bool {
		return ( $product instanceof \WC_Product && 'yes' === $product->get_meta( '_ll16_individual' ) ) || ! empty( $cart_item['ll16_individual'] );
	}

	/**
	 * @param string      $html
	 * @param \WC_Product $product
	 */
	public static function price_html( $html, $product ) {
		if ( '' === (string) $html || is_admin() ) {
			return $html;
		}
		$suffix = '';
		if ( Masterdata::get( 'small_business' ) ) {
			$suffix .= ' <small class="ll16-price-note">Kleinunternehmer gem. § 19 UStG, keine Umsatzsteuer ausgewiesen</small>';
		}
		$suffix .= sprintf( ' <small class="ll16-price-note"><a href="%s">zzgl. %s € Versandkosten</a></small>', esc_url( home_url( '/versand-zahlung/' ) ), esc_html( (string) Masterdata::get( 'shipping_cost' ) ) );
		return $html . $suffix;
	}

	public static function delivery_html(): void {
		global $product;
		if ( $product instanceof \WC_Product ) {
			echo '<p class="ll16-delivery-time">Lieferzeit: ' . esc_html( self::delivery_time( $product ) ) . '</p>';
		}
	}

	/**
	 * Einheitlicher Lagerstatus; UI und Store-API hängen am selben WC_Product::is_in_stock().
	 *
	 * @param string      $text
	 * @param \WC_Product $product
	 */
	public static function availability_text( $text, $product ) {
		return $product->is_in_stock() ? 'Auf Lager' : 'Zurzeit nicht auf Lager';
	}

	public static function warranty_text(): string {
		$default = 'Gesetzliche Gewährleistung: Für alle Waren besteht ein gesetzliches Mängelhaftungsrecht von mindestens zwei Jahren ab Erhalt der Ware (gesetzliche Gewährleistung).';
		return (string) get_option( 'll16_warranty_notice', $default );
	}

	public static function warranty_notice(): void {
		echo '<p class="ll16-warranty-notice">' . esc_html( self::warranty_text() ) . '</p>';
	}

	/**
	 * @param array<string,array<string,mixed>> $tabs
	 * @return array<string,array<string,mixed>>
	 */
	public static function tabs( $tabs ) {
		$tabs['ll16_gpsr'] = [
			'title'    => 'Produktsicherheit',
			'priority' => 40,
			'callback' => [ self::class, 'gpsr_tab' ],
		];
		return $tabs;
	}

	public static function gpsr_tab(): void {
		global $product;
		if ( ! $product instanceof \WC_Product ) {
			return;
		}
		$m = Masterdata::all();
		echo '<h2>Produktsicherheit (GPSR)</h2><dl>';
		printf( '<dt>Hersteller</dt><dd>%s</dd>', esc_html( sprintf( '%s – %s, %s, %s %s, %s', $m['name'], $m['trade_name'], $m['street'], $m['zip'], $m['city'], $m['country'] ) ) );
		printf( '<dt>E-Mail</dt><dd>%s</dd>', esc_html( (string) $m['email'] ) );
		foreach ( Gpsr::REQUIRED as $key => $label ) {
			printf( '<dt>%s</dt><dd>%s</dd>', esc_html( $label ), nl2br( esc_html( (string) $product->get_meta( $key ) ) ) );
		}
		echo '</dl>';
	}

	public static function admin_fields(): void {
		echo '<div class="options_group"><p><strong>Lindenweg16 Recht</strong></p>';
		woocommerce_wp_text_input( [ 'id' => '_ll16_delivery_time', 'label' => 'Lieferzeit', 'description' => 'z. B. 3–5 Werktage. Leer = Standard aus den Stammdaten.', 'desc_tip' => true ] );
		woocommerce_wp_checkbox( [ 'id' => '_ll16_individual', 'label' => 'Individuell angefertigt', 'description' => 'Kein Widerrufsrecht (§ 312g Abs. 2 Nr. 1 BGB). Löst im Checkout die zusätzliche Bestätigung aus.' ] );
		foreach ( Gpsr::REQUIRED as $key => $label ) {
			woocommerce_wp_textarea_input( [ 'id' => $key, 'label' => 'GPSR: ' . $label, 'rows' => 2 ] );
		}
		echo '</div>';
	}

	public static function save_fields( \WC_Product $product ): void {
		// phpcs:disable WordPress.Security.NonceVerification
		$product->update_meta_data( '_ll16_delivery_time', sanitize_text_field( wp_unslash( $_POST['_ll16_delivery_time'] ?? '' ) ) );
		$product->update_meta_data( '_ll16_individual', isset( $_POST['_ll16_individual'] ) ? 'yes' : 'no' );
		foreach ( array_keys( Gpsr::REQUIRED ) as $key ) {
			$product->update_meta_data( $key, sanitize_textarea_field( wp_unslash( $_POST[ $key ] ?? '' ) ) );
		}
		// phpcs:enable
	}

	/**
	 * Verhindert das Veröffentlichen ohne GPSR-Pflichtfelder und Produktbild.
	 *
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $postarr
	 * @return array<string,mixed>
	 */
	public static function guard_publish( array $data, array $postarr ): array {
		if ( 'product' !== ( $data['post_type'] ?? '' ) || 'publish' !== ( $data['post_status'] ?? '' ) ) {
			return $data;
		}
		// phpcs:disable WordPress.Security.NonceVerification
		if ( ! isset( $_POST['_ll16_gpsr_model'] ) ) {
			return $data; // kein Produktformular (z. B. Import/REST): hier nicht eingreifen, Monitor meldet es
		}
		$values  = [];
		foreach ( array_keys( Gpsr::REQUIRED ) as $key ) {
			$values[ $key ] = sanitize_textarea_field( wp_unslash( $_POST[ $key ] ?? '' ) );
		}
		$errors = Gpsr::missing( $values );
		if ( empty( $_POST['_thumbnail_id'] ) || (int) $_POST['_thumbnail_id'] < 1 ) {
			$errors[] = 'Produktbild';
		}
		// phpcs:enable
		if ( [] !== $errors ) {
			$data['post_status'] = 'draft';
			set_transient( 'll16_product_guard_' . get_current_user_id(), $errors, 60 );
		}
		return $data;
	}

	public static function admin_notices(): void {
		$errors = get_transient( 'll16_product_guard_' . get_current_user_id() );
		if ( is_array( $errors ) ) {
			delete_transient( 'll16_product_guard_' . get_current_user_id() );
			printf( '<div class="notice notice-error"><p><strong>Produkt nicht veröffentlicht.</strong> Es fehlt: %s</p></div>', esc_html( implode( ', ', $errors ) ) );
		}
		$screen = get_current_screen();
		if ( $screen && in_array( $screen->post_type, [ 'product', 'page', 'post' ], true ) && 'post' === $screen->base ) {
			global $post;
			if ( $post instanceof \WP_Post ) {
				$settings = (array) get_option( 'll16_settings', [] );
				$phrases  = ! empty( $settings['claims'] ) ? (array) $settings['claims'] : self::DEFAULT_CLAIMS;
				$hits     = Gpsr::claims( $post->post_title . ' ' . $post->post_content . ' ' . $post->post_excerpt, $phrases );
				if ( $hits ) {
					printf( '<div class="notice notice-warning"><p><strong>Werbeaussagen prüfen:</strong> %s. Beispiel: „3D-gedruckt und von Hand montiert" statt „handgemacht".</p></div>', esc_html( implode( ', ', $hits ) ) );
				}
			}
		}
	}
}
