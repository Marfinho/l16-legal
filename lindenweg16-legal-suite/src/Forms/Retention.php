<?php
declare(strict_types=1);

namespace Lindenweg16\Legal\Forms;

/**
 * Anonymisiert Bestelldaten nach Ablauf der Aufbewahrungsfrist.
 * Standardmäßig AUS (Einstellung "retention_anonymize"), weil unwiderruflich.
 */
final class Retention {

	/** Stichtag: Ende des Kalenderjahres der Bestellung + $years Jahre. Reine Funktion. */
	public static function expired( int $order_ts, int $years, int $now ): bool {
		$end_of_year = (int) strtotime( gmdate( 'Y', $order_ts ) . '-12-31 23:59:59 UTC' );
		return $now > strtotime( '+' . $years . ' years', $end_of_year );
	}

	public static function anonymize_old_orders(): void {
		$s = (array) get_option( 'll16_settings', [] );
		if ( empty( $s['retention_anonymize'] ) || ! function_exists( 'wc_get_orders' ) ) {
			return;
		}
		$years  = max( 6, (int) \Lindenweg16\Legal\Core\Masterdata::get( 'retention_years' ) );
		$cutoff = strtotime( '-' . $years . ' years' );
		$orders = wc_get_orders( [ 'limit' => 50, 'date_created' => '<' . $cutoff, 'meta_key' => '_ll16_anonymized', 'meta_compare' => 'NOT EXISTS', 'type' => 'shop_order' ] );
		foreach ( $orders as $order ) {
			if ( ! self::expired( (int) $order->get_date_created()->getTimestamp(), $years, time() ) ) {
				continue;
			}
			foreach ( [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'phone' ] as $f ) {
				$order->{"set_billing_$f"}( 'anonymisiert' );
				$order->{"set_shipping_$f"}( 'anonymisiert' );
			}
			$order->set_billing_email( 'anonymisiert@invalid.example' );
			$order->update_meta_data( '_ll16_anonymized', gmdate( 'c' ) );
			$order->save();
		}
	}
}
