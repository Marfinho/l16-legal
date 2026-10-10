<?php
declare(strict_types=1);

namespace Lindenweg16\Legal\Product;

/**
 * GPSR-Pflichtangaben (VO (EU) 2023/988): reine Prüflogik.
 */
final class Gpsr {

	/** meta key => Label (Pflichtfelder je Produkt; Hersteller kommt aus den Stammdaten). */
	public const REQUIRED = [
		'_ll16_gpsr_model'    => 'Typ-/Modellkennung',
		'_ll16_gpsr_batch'    => 'Chargen-/Seriennummernhinweis',
		'_ll16_gpsr_warnings' => 'Warn- und Sicherheitshinweise',
		'_ll16_gpsr_assembly' => 'Montage- und Tragfähigkeitshinweis',
	];

	/**
	 * @param array<string,string> $values meta key => Wert
	 * @return string[] Labels fehlender Felder
	 */
	public static function missing( array $values ): array {
		$out = [];
		foreach ( self::REQUIRED as $key => $label ) {
			if ( '' === trim( (string) ( $values[ $key ] ?? '' ) ) ) {
				$out[] = $label;
			}
		}
		return $out;
	}

	/**
	 * Werbeaussagen-Prüfung.
	 *
	 * @param string[] $phrases
	 * @return string[] gefundene Begriffe
	 */
	public static function claims( string $text, array $phrases ): array {
		$hits = [];
		foreach ( $phrases as $p ) {
			if ( '' !== trim( $p ) && false !== mb_stripos( $text, trim( $p ) ) ) {
				$hits[] = trim( $p );
			}
		}
		return $hits;
	}
}
