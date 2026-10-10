<?php
declare(strict_types=1);

namespace Lindenweg16\Legal\Legal;

/**
 * Platzhalter-Linter: findet Reste, die nicht veröffentlicht werden dürfen.
 */
final class Linter {

	private const PATTERNS = [
		'/\{\{[^}]*\}\}/'                 => 'Nicht aufgelöster Platzhalter {{…}}',
		'/\{[a-z_]+\}/i'                  => 'Nicht aufgelöster Platzhalter {…}',
		'/\bTODO\b/i'                      => 'TODO',
		'/Platzhalter/i'                   => 'Wort „Platzhalter"',
		'/lorem ipsum/i'                   => 'Lorem Ipsum',
		'/\bXXX+\b/'                       => 'XXX',
		'/\[(?:bitte|hier)[^\]]*\]/i'      => 'Eckige Ausfüllhinweise',
		'/Testkarte/i'                     => 'Testkarten-Hinweis',
		'/ex\.com/i'                       => 'Beispiel-Domain (ex.com)',
		'/sample-page/i'                   => 'sample-page-Verweis',
	];

	/** @return string[] Fehlermeldungen; leer = sauber */
	public static function check( string $html ): array {
		$text   = $html;
		$errors = [];
		foreach ( self::PATTERNS as $re => $label ) {
			if ( preg_match( $re, $text ) ) {
				$errors[] = $label;
			}
		}
		return $errors;
	}
}
