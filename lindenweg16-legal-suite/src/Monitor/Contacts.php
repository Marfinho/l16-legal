<?php
declare(strict_types=1);

namespace Lindenweg16\Legal\Monitor;

/**
 * Findet E-Mail-Adressen und Telefonnummern im Text und vergleicht sie mit den Stammdaten.
 * Reine Funktionen → testbar.
 */
final class Contacts {

	/** @return array{emails:string[],phones:string[]} */
	public static function extract( string $html ): array {
		// Tags werden zu Zeilenumbrüchen, damit Nummern nicht mit Ziffern der Folgezeile verschmelzen.
		$clean = (string) preg_replace( '#<(script|style)\b.*?</\1>#is', ' ', $html );
		$text  = html_entity_decode( strip_tags( (string) preg_replace( '/<[^>]+>/', "\n", $clean ) ), ENT_QUOTES );
		preg_match_all( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text, $m );
		$emails = array_values( array_unique( array_map( 'strtolower', $m[0] ) ) );
		// Datumsangaben (04.11.2026) sind keine Telefonnummern.
		$text = (string) preg_replace( '/\b\d{1,2}\.\d{1,2}\.\d{2,4}\b/', ' ', $text );
		preg_match_all( '/(?<![\d])(?:\+49|0049|0)[\d \t\/().-]{8,16}\d(?![\d])/', $text, $p );
		$phones = array_values( array_unique( array_map( [ self::class, 'normalize_phone' ], $p[0] ) ) );
		return [ 'emails' => $emails, 'phones' => $phones ];
	}

	public static function normalize_phone( string $phone ): string {
		$d = (string) preg_replace( '/\D/', '', $phone );
		if ( str_starts_with( $d, '0049' ) ) {
			$d = '0' . substr( $d, 4 );
		} elseif ( str_starts_with( $d, '49' ) ) {
			$d = '0' . substr( $d, 2 );
		}
		return $d;
	}

	/**
	 * @param array<string,mixed> $master
	 * @return string[] Abweichungen
	 */
	public static function deviations( string $html, array $master ): array {
		$found  = self::extract( $html );
		$out    = [];
		$email  = strtolower( (string) $master['email'] );
		$phone  = self::normalize_phone( (string) $master['phone'] );
		foreach ( $found['emails'] as $e ) {
			if ( $e !== $email && ! str_ends_with( $e, '@example.com' ) ) {
				$out[] = 'Abweichende E-Mail: ' . $e;
			}
		}
		foreach ( $found['phones'] as $p ) {
			if ( $p !== $phone ) {
				$out[] = 'Abweichende Telefonnummer: ' . $p;
			}
		}
		return $out;
	}
}
