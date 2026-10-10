<?php
declare(strict_types=1);

namespace Lindenweg16\Legal\Withdrawal;

/**
 * Zustandsloses, signiertes Token für den zweistufigen Widerruf.
 * Ersetzt Nonces, damit die Seite nicht personalisiert gecacht werden muss.
 */
final class Token {

	/**
	 * @param array<string,mixed> $payload
	 */
	public static function sign( array $payload, int $issued_at, string $secret ): string {
		$json = json_encode( [ 'p' => $payload, 't' => $issued_at ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
		$body = rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' );
		return $body . '.' . hash_hmac( 'sha256', $body, $secret );
	}

	/**
	 * @return array{p:array<string,mixed>,t:int}|null
	 */
	public static function verify( string $token, string $secret ): ?array {
		$parts = explode( '.', $token );
		if ( 2 !== count( $parts ) || ! hash_equals( hash_hmac( 'sha256', $parts[0], $secret ), $parts[1] ) ) {
			return null;
		}
		$data = json_decode( (string) base64_decode( strtr( $parts[0], '-_', '+/' ), true ), true );
		return is_array( $data ) && isset( $data['p'], $data['t'] ) && is_array( $data['p'] ) ? [ 'p' => $data['p'], 't' => (int) $data['t'] ] : null;
	}

	/**
	 * Zeitfenster: frühestens $min Sekunden nach Ausstellung (Bot-Schutz), spätestens $max.
	 */
	public static function fresh( int $issued_at, int $now, int $min = 3, int $max = 3600 ): bool {
		$age = $now - $issued_at;
		return $age >= $min && $age <= $max;
	}
}
