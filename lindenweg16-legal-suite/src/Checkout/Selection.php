<?php
declare(strict_types=1);

namespace Lindenweg16\Legal\Checkout;

/**
 * Prüft, ob ein Produkt eine Auswahl (Farbe, Holz …) verlangt.
 * Reine Logik auf Attribut-Arrays, damit sie ohne WordPress testbar ist.
 */
final class Selection {

	/**
	 * @param array<string,array<string,mixed>> $attributes name => [ is_variation, options[] ]
	 * @return string[] Namen der Attribute, für die eine Auswahl fehlt
	 */
	public static function missing( array $attributes, array $posted ): array {
		$missing = [];
		foreach ( $attributes as $name => $a ) {
			if ( empty( $a['is_variation'] ) || count( (array) ( $a['options'] ?? [] ) ) < 2 ) {
				continue;
			}
			$key = 'attribute_' . $name;
			$alt = 'attribute_' . strtolower( rawurlencode( (string) $name ) );
			$val = $posted[ $key ] ?? $posted[ $alt ] ?? '';
			if ( '' === trim( (string) $val ) ) {
				$missing[] = (string) $name;
			}
		}
		return $missing;
	}
}
