<?php
declare(strict_types=1);

namespace Lindenweg16\Legal\Core;

/**
 * Feature-Flags je Modul. Neue Installationen starten bewusst mit allem AUS,
 * damit das Plugin neben den Alt-Plugins getestet werden kann (Migrationsplan).
 */
final class Modules {

	public const LABELS = [
		'consent'    => 'M1 Consent-Manager',
		'gate'       => 'M7 Drittanbieter-Gate',
		'legal'      => 'M2 Rechtstexte',
		'withdrawal' => 'M3 Online-Widerrufsfunktion',
		'checkout'   => 'M4 Checkout & Warenkorb',
		'product'    => 'M5 Produktseiten & Preisangaben',
		'forms'      => 'M6 Formulare, Newsletter, DSGVO',
		'security'   => 'M8 Security-Header',
		'monitor'    => 'M9 Compliance-Monitor',
	];

	/** @return array<string,bool> */
	public static function flags(): array {
		$stored = get_option( 'll16_modules', [] );
		$stored = is_array( $stored ) ? $stored : [];
		$out    = [];
		foreach ( array_keys( self::LABELS ) as $key ) {
			$out[ $key ] = ! empty( $stored[ $key ] );
		}
		return $out;
	}

	public static function enabled( string $key ): bool {
		return ! empty( self::flags()[ $key ] );
	}
}
