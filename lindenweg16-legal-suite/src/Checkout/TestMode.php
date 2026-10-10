<?php
declare(strict_types=1);

namespace Lindenweg16\Legal\Checkout;

/**
 * Testmodus-Wächter für WooPayments, Stripe und PayPal.
 */
final class TestMode {

	/**
	 * @return string[] Beschreibung aktiver Testmodi
	 */
	public static function detect(): array {
		$found = [];
		$wcp_gateway = (array) get_option( 'woocommerce_woocommerce_payments_settings', [] );
		if ( 'yes' === ( $wcp_gateway['test_mode'] ?? '' ) ) {
			$found[] = 'WooPayments (Testmodus)';
		}
		$stripe = (array) get_option( 'woocommerce_stripe_settings', [] );
		if ( 'yes' === ( $stripe['testmode'] ?? '' ) ) {
			$found[] = 'Stripe (Testmodus)';
		}
		$ppcp = (array) get_option( 'woocommerce-ppcp-settings', [] );
		if ( ! empty( $ppcp['sandbox_on'] ) ) {
			$found[] = 'PayPal (Sandbox)';
		}
		return array_values( array_unique( $found ) );
	}

	/**
	 * Erkennt Testkarten-Hinweise im gerenderten HTML.
	 */
	public static function html_has_test_hint( string $html ): bool {
		return (bool) preg_match( '/Testkarte|test card|4242 4242|4000 0027|Testmodus|test mode/i', $html );
	}
}
