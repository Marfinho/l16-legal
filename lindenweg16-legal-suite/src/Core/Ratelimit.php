<?php
declare(strict_types=1);

namespace Lindenweg16\Legal\Core;

/**
 * Einfaches Rate-Limit auf Transient-Basis. Die IP wird nie gespeichert: der Schlüssel ist ein
 * salted Hash (täglich wechselnd) und lebt nur als Transient-Name bis zum Ablauf des Zeitfensters.
 * Schützt Formulare davor, als Mail-Relay (Bestätigungsmails an fremde Adressen) missbraucht zu werden.
 */
final class Ratelimit {

	/** Pseudonymer Schlüssel des Besuchers (kein Klartext, wechselt täglich). */
	public static function client_key(): string {
		$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore WordPress.Security
		return substr( hash( 'sha256', $ip . '|' . wp_salt( 'auth' ) . '|' . gmdate( 'Y-m-d' ) ), 0, 24 );
	}

	/**
	 * Zählt einen Zugriff. @return bool true = erlaubt, false = Limit erreicht.
	 */
	public static function hit( string $bucket, string $key, int $limit, int $window ): bool {
		$name  = 'll16_rl_' . substr( md5( $bucket . '|' . $key ), 0, 20 );
		$count = (int) get_transient( $name );
		if ( $count >= $limit ) {
			return false;
		}
		set_transient( $name, $count + 1, $window );
		return true;
	}

	/** Nur lesen, ohne zu zählen. */
	public static function exceeded( string $bucket, string $key, int $limit ): bool {
		return (int) get_transient( 'll16_rl_' . substr( md5( $bucket . '|' . $key ), 0, 20 ) ) >= $limit;
	}
}
