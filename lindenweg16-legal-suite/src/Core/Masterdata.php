<?php
declare(strict_types=1);

namespace Lindenweg16\Legal\Core;

/**
 * Einzige Quelle der Wahrheit für Stammdaten. Alle Rechtstexte, Mails und
 * Prüfungen lesen ausschließlich hier.
 */
final class Masterdata {

	public const OPTION = 'll16_masterdata';

	/** @return array<string,mixed> */
	public static function defaults(): array {
		return [
			'name'              => 'Sven Meendermann',
			'trade_name'        => 'Lindenweg16',
			'street'            => 'Lindenweg 16',
			'zip'               => '31559',
			'city'              => 'Haste',
			'country'           => 'Deutschland',
			'phone'             => '01567373047',
			'phone_hours'       => '',
			'email'             => 'sven@lindenweg16.de',
			'small_business'    => true,
			'vat_id'            => '',
			'authority_name'    => 'Die Landesbeauftragte für den Datenschutz Niedersachsen',
			'authority_url'     => 'https://www.lfd.niedersachsen.de',
			'adr_willing'       => false,
			'adr_body'          => '',
			'address_form'      => 'du',
			'withdrawal_days'   => 14,
			'return_days'       => 30,
			'shipping_cost'     => '6,99',
			'free_shipping_from' => '',
			'delivery_time'     => '3–5 Werktage',
			'delivery_countries' => [ 'DE' ],
			'payment_methods'   => [],
			'shipping_carrier'  => '',
			'hosting_provider'  => 'IONOS SE, Elgendorfer Str. 57, 56410 Montabaur',
			'retention_years'   => 8,
			'shop_url'          => '',
			'last_review'       => '',
		];
	}

	/** @return array<string,mixed> */
	public static function all(): array {
		$stored = get_option( self::OPTION, [] );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : [] );
	}

	/** @return mixed */
	public static function get( string $key ) {
		return self::all()[ $key ] ?? null;
	}

	/** Anschrift einzeilig. */
	public static function address_line(): string {
		$d = self::all();
		return sprintf( '%s – %s, %s, %s %s', $d['name'], $d['trade_name'], $d['street'], $d['zip'], $d['city'] );
	}

	/**
	 * Eingabe bereinigen. Reine Funktion, damit testbar.
	 *
	 * @param array<string,mixed> $in
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $in ): array {
		$d   = self::defaults();
		$out = [];
		foreach ( $d as $key => $default ) {
			$val = $in[ $key ] ?? $default;
			if ( is_bool( $default ) ) {
				$out[ $key ] = ! empty( $val );
			} elseif ( is_int( $default ) ) {
				$out[ $key ] = max( 0, (int) $val );
			} elseif ( is_array( $default ) ) {
				$list        = is_array( $val ) ? $val : preg_split( '/[\s,]+/', (string) $val, -1, PREG_SPLIT_NO_EMPTY );
				$out[ $key ] = array_values( array_map( 'sanitize_text_field', (array) $list ) );
			} elseif ( 'email' === $key ) {
				$out[ $key ] = sanitize_email( (string) $val );
			} elseif ( str_ends_with( $key, '_url' ) ) {
				$out[ $key ] = esc_url_raw( (string) $val );
			} elseif ( 'address_form' === $key ) {
				$out[ $key ] = 'sie' === $val ? 'sie' : 'du';
			} else {
				$out[ $key ] = sanitize_text_field( (string) $val );
			}
		}
		return $out;
	}
}
