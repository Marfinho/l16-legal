<?php
declare(strict_types=1);

namespace Lindenweg16\Legal\Gate;

use Lindenweg16\Legal\Core\ServiceRegistry;

/**
 * M7 – Drittanbieter-Gate: verhindert, dass Fremdplugins Tracking-/Drittanbieter-Skripte
 * ausliefern, die nicht im Dienste-Register aktiv geführt sind.
 *
 * Doppelt abgesichert: (1) hier per Dequeue/Option-Filter an der Quelle,
 * (2) im Consent-Rewriter als Output-Filter für Skripte, die direkt gedruckt werden.
 */
final class Module {

	/**
	 * Bekannte Altplugins mit Rechts-/Consent-/Tracking-Funktion und Hinweis zur Abschaltung.
	 *
	 * @return array<string,array{name:string,hint:string}>
	 */
	public static function legacy_plugins(): array {
		return [
			'complianz-gdpr/complianz-gdpr.php'                  => [ 'name' => 'Complianz', 'hint' => 'Erst abschalten, wenn M1 und M2 produktiv laufen. Cookie-Banner-Konflikte vermeiden.' ],
			'complianz-gdpr-premium/complianz-gpdr-premium.php'  => [ 'name' => 'Complianz Premium', 'hint' => 'Wie Complianz.' ],
			'woocommerce-germanized/woocommerce-germanized.php'  => [ 'name' => 'Germanized', 'hint' => 'Vorher Lieferzeiten, Gewichte und Produktfelder migrieren (P6).' ],
			'shiptastic-for-woocommerce/shiptastic-for-woocommerce.php' => [ 'name' => 'Shiptastic', 'hint' => 'Retouren-Formular (Shortcode) durch M3 ersetzen, Versandlogik separat prüfen.' ],
			'jetpack/jetpack.php'                                => [ 'name' => 'Jetpack', 'hint' => 'Module Stats/WooCommerce Analytics/Tracks deaktivieren; oder Plugin entfernen, wenn kein Modul gebraucht wird.' ],
			'wpforms-lite/wpforms.php'                           => [ 'name' => 'WPForms Lite', 'hint' => 'Kontaktformular durch M6 ersetzen.' ],
			'wp-smushit/wp-smush.php'                            => [ 'name' => 'Smush', 'hint' => 'Frontend-Skripte prüfen, API-Verbindung zu WPMU DEV deaktivieren.' ],
			'pinterest-for-woocommerce/pinterest-for-woocommerce.php' => [ 'name' => 'Pinterest for WooCommerce', 'hint' => 'Tag/Pixel nur mit Einwilligung; sonst deaktivieren.' ],
			'google-listings-and-ads/google-listings-and-ads.php' => [ 'name' => 'Google Listings & Ads', 'hint' => 'Erzeugt gtag-Ausgaben; deaktivieren, wenn nicht genutzt.' ],
			'extendify/extendify.php'                            => [ 'name' => 'Extendify', 'hint' => 'Frontend nicht benötigt.' ],
			'otter-blocks/otter-blocks.php'                      => [ 'name' => 'Otter Blocks', 'hint' => 'Prüfen, ob Blöcke verwendet werden.' ],
		];
	}

	/** @return string[] aktive Altplugins (Pfad) */
	public static function active_legacy(): array {
		$active = (array) get_option( 'active_plugins', [] );
		return array_values( array_intersect( array_keys( self::legacy_plugins() ), $active ) );
	}

	public static function init(): void {
		$services = ServiceRegistry::all();

		if ( empty( $services['attribution']['active'] ) ) {
			// WooCommerce Order Attribution (sbjs_*-Cookies) abschalten.
			add_filter( 'pre_option_woocommerce_feature_order_attribution_enabled', static fn() => 'no' );
			add_filter( 'woocommerce_order_attribution_enabled', '__return_false' );
		}
		if ( empty( $services['jetpack-analytics']['active'] ) ) {
			add_filter(
				'option_jetpack_active_modules',
				static function ( $modules ) {
					return is_array( $modules ) ? array_values( array_diff( $modules, [ 'stats' ] ) ) : $modules;
				}
			);
			add_filter( 'jetpack_just_in_time_msgs', '__return_false' );
		}

		add_action( 'wp_enqueue_scripts', [ self::class, 'dequeue_blocked' ], 9999 );
		add_action( 'wp_print_footer_scripts', [ self::class, 'dequeue_blocked' ], 1 );
		add_filter( 'script_loader_tag', [ self::class, 'filter_tag' ], 10, 3 );

		add_action( 'admin_notices', [ self::class, 'admin_notice' ] );
	}

	/**
	 * Patterns aller Dienste, die auf dieser Seite nicht laden dürfen.
	 *
	 * @param array<string,array<string,mixed>> $services
	 * @return string[]
	 */
	public static function blocked_patterns( array $services, bool $payment_page ): array {
		$out = [];
		foreach ( \Lindenweg16\Legal\Consent\Module::rules( $services, $payment_page ) as $rule ) {
			if ( 'remove' === $rule['mode'] ) {
				$out = array_merge( $out, (array) $rule['patterns'] );
			}
		}
		return $out;
	}

	private static function payment_page(): bool {
		return function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_wc_endpoint_url( 'order-pay' ) || is_account_page() );
	}

	public static function dequeue_blocked(): void {
		$patterns = self::blocked_patterns( ServiceRegistry::all(), self::payment_page() );
		if ( [] === $patterns ) {
			return;
		}
		$scripts = wp_scripts();
		foreach ( $scripts->registered as $handle => $dep ) {
			$hay = (string) $dep->src . ' ' . $handle;
			foreach ( $patterns as $p ) {
				if ( false !== stripos( $hay, $p ) ) {
					wp_dequeue_script( $handle );
					break;
				}
			}
		}
	}

	/**
	 * @param string $tag
	 * @param string $handle
	 * @param string $src
	 */
	public static function filter_tag( $tag, $handle, $src ) {
		foreach ( self::blocked_patterns( ServiceRegistry::all(), self::payment_page() ) as $p ) {
			if ( false !== stripos( (string) $src, $p ) ) {
				return '';
			}
		}
		return $tag;
	}

	public static function admin_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || 'toplevel_page_ll16-legal' !== ( get_current_screen()->id ?? '' ) ) {
			return;
		}
		$list = self::active_legacy();
		if ( [] === $list ) {
			return;
		}
		$names = array_map( static fn( $p ) => self::legacy_plugins()[ $p ]['name'], $list );
		printf( '<div class="notice notice-warning"><p><strong>Altplugins noch aktiv:</strong> %s. Siehe Migrationsplan (README).</p></div>', esc_html( implode( ', ', $names ) ) );
	}
}
