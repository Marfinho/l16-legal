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
				// Zahlungsarten enthalten Leerzeichen („Apple Pay“): nur an Komma/Zeilenumbruch trennen. Länderlisten auch an Leerzeichen.
				$sep         = 'payment_methods' === $key ? '/\s*[,;\n]\s*/' : '/[\s,;]+/';
				$list        = is_array( $val ) ? $val : preg_split( $sep, trim( (string) $val ), -1, PREG_SPLIT_NO_EMPTY );
				$out[ $key ] = array_values( array_filter( array_map( 'sanitize_text_field', (array) $list ), static fn( $v ) => '' !== $v ) );
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

	/** Zahl in deutscher Schreibweise mit zwei Nachkommastellen („6.99“ → „6,99“); null bei ungültiger Eingabe. */
	public static function money( string $raw ): ?string {
		$t = trim( str_replace( [ '€', ' ' ], '', $raw ) );
		if ( 1 === preg_match( '/^\d{1,3}(\.\d{3})+,\d{1,2}$/', $t ) ) {
			$t = str_replace( '.', '', $t ); // 1.234,50
		}
		$t = str_replace( ',', '.', $t );
		if ( 1 !== preg_match( '/^\d+(\.\d{1,2})?$/', $t ) ) {
			return null;
		}
		return number_format( (float) $t, 2, ',', '' );
	}

	/**
	 * Prüft bereinigte Stammdaten und normalisiert Werte. Ungültige Felder werden gemeldet,
	 * damit sie nicht stillschweigend verschwinden.
	 *
	 * @param array<string,mixed> $san Ergebnis von sanitize()
	 * @return array{values:array<string,mixed>,errors:array<string,string>}
	 */
	public static function check( array $san ): array {
		$errors = [];
		$raw_email = (string) ( $san['email'] ?? '' );
		if ( '' === $raw_email ) {
			$errors['email'] = 'E-Mail-Adresse fehlt oder ist ungültig.';
		}
		$phone = (string) ( $san['phone'] ?? '' );
		$digits = strlen( (string) preg_replace( '/\D/', '', $phone ) );
		if ( '' === $phone || $digits < 6 || $digits > 15 ) {
			$errors['phone'] = 'Telefonnummer ungültig (6 bis 15 Ziffern).';
		}
		if ( 'Deutschland' === ( $san['country'] ?? '' ) && 1 !== preg_match( '/^\d{5}$/', (string) ( $san['zip'] ?? '' ) ) ) {
			$errors['zip'] = 'Postleitzahl muss fünfstellig sein.';
		}
		foreach ( [ 'name', 'street', 'city' ] as $f ) {
			if ( '' === trim( (string) ( $san[ $f ] ?? '' ) ) ) {
				$errors[ $f ] = 'Pflichtfeld.';
			}
		}
		foreach ( [ 'shipping_cost' => false, 'free_shipping_from' => true ] as $f => $optional ) {
			$val = (string) ( $san[ $f ] ?? '' );
			if ( '' === $val && $optional ) {
				continue;
			}
			$m = self::money( $val );
			if ( null === $m ) {
				$errors[ $f ] = 'Bitte einen Betrag wie 6,99 eingeben.';
			} else {
				$san[ $f ] = $m;
			}
		}
		$countries = array_values( array_unique( array_map( 'strtoupper', (array) ( $san['delivery_countries'] ?? [] ) ) ) );
		$bad       = array_filter( $countries, static fn( $c ) => 1 !== preg_match( '/^[A-Z]{2}$/', $c ) );
		if ( [] === $countries || $bad ) {
			$errors['delivery_countries'] = 'Mindestens ein Land als Zwei-Buchstaben-Code (z. B. DE).';
		} else {
			$san['delivery_countries'] = $countries;
		}
		if ( (int) ( $san['withdrawal_days'] ?? 0 ) < 14 ) {
			$errors['withdrawal_days'] = 'Die gesetzliche Widerrufsfrist beträgt mindestens 14 Tage.';
		}
		if ( ! empty( $san['authority_url'] ) && 1 !== preg_match( '#^https?://#', (string) $san['authority_url'] ) ) {
			$errors['authority_url'] = 'Bitte eine vollständige Adresse mit https:// angeben.';
		}
		return [ 'values' => $san, 'errors' => $errors ];
	}
}
