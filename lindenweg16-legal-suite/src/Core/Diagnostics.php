<?php
declare(strict_types=1);

namespace Lindenweg16\Legal\Core;

/**
 * Diagnose: macht Fehler sichtbar, die WordPress sonst nur als weiße Seite zeigt.
 *  - arm()/disarm(): merkt sich harte PHP-Fehler (Fatal, Speicher, Zeit) während einer Aktion
 *  - remember(): merkt sich abgefangene Ausnahmen
 *  - report(): Textblock zum Kopieren (Status-Tab)
 */
final class Diagnostics {

	public const OPTION = 'll16_last_error';

	private static bool $armed = false;
	private static string $context = '';

	public static function arm( string $context ): void {
		self::$armed   = true;
		self::$context = $context;
		// WordPress' eigener Fatal-Error-Handler beendet den Prozess vor späteren Shutdown-Funktionen. Deshalb
		// zusätzlich dessen Filter nutzen: Fehler aufzeichnen UND den Text in die Fehlerseite schreiben.
		add_filter( 'wp_php_error_message', [ self::class, 'on_wp_fatal' ], 10, 2 );
		register_shutdown_function( [ self::class, 'on_shutdown' ] ); // Fallback, falls der WP-Handler abgeschaltet ist
	}

	/**
	 * @param string              $message
	 * @param array<string,mixed> $error
	 */
	public static function on_wp_fatal( $message, $error ) {
		if ( ! self::$armed || ! is_array( $error ) ) {
			return $message;
		}
		self::record_fatal( $error );
		$detail = sprintf(
			'<p><strong>Lindenweg16 Legal Suite – Fehler bei „%s“:</strong><br><code>%s</code><br>Stelle: <code>%s</code></p><p>Dieser Fehler ist jetzt unter <em>Lindenweg16 Recht → Status → Diagnose</em> gespeichert. Bitte den Bericht dort kopieren und mitschicken.</p>',
			esc_html( self::$context ),
			esc_html( (string) ( $error['message'] ?? '' ) ),
			esc_html( self::short_path( (string) ( $error['file'] ?? '' ) ) . ':' . (int) ( $error['line'] ?? 0 ) )
		);
		return (string) $message . $detail;
	}

	/** @param array<string,mixed> $e */
	private static function record_fatal( array $e ): void {
		update_option(
			self::OPTION,
			[
				'time'    => time(),
				'context' => self::$context,
				'message' => (string) ( $e['message'] ?? '' ),
				'where'   => self::short_path( (string) ( $e['file'] ?? '' ) ) . ':' . (int) ( $e['line'] ?? 0 ),
				'kind'    => 'PHP-Fehler (Seite blieb weiß)',
			],
			false
		);
	}

	public static function disarm(): void {
		self::$armed = false;
	}

	public static function on_shutdown(): void {
		if ( ! self::$armed ) {
			return;
		}
		$e = error_get_last();
		if ( $e && in_array( $e['type'], [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR ], true ) ) {
			self::record_fatal( $e );
		}
	}

	public static function remember( \Throwable $e, string $context ): void {
		update_option(
			self::OPTION,
			[
				'time'    => time(),
				'context' => $context,
				'message' => get_class( $e ) . ': ' . $e->getMessage(),
				'where'   => self::short_path( $e->getFile() ) . ':' . $e->getLine(),
				'kind'    => 'abgefangene Ausnahme',
			],
			false
		);
	}

	/** Pfad ab wp-content kürzen (keine Serverpfade im Klartext weitergeben). */
	public static function short_path( string $file ): string {
		$pos = strpos( $file, 'wp-content' );
		return false !== $pos ? substr( $file, $pos ) : basename( $file );
	}

	/** @return array<string,mixed>|null */
	public static function last(): ?array {
		$v = get_option( self::OPTION );
		return is_array( $v ) ? $v : null;
	}

	public static function clear(): void {
		delete_option( self::OPTION );
	}

	/** Kopierbarer Diagnose-Text für den Support (ohne Passwörter/Schlüssel). */
	public static function report(): string {
		global $wp_version;
		$plugins = [];
		foreach ( (array) get_option( 'active_plugins', [] ) as $p ) {
			$plugins[] = dirname( (string) $p ) ?: (string) $p;
		}
		$last  = self::last();
		$lines = [
			'Lindenweg16 Legal Suite ' . LL16_VERSION,
			'WordPress ' . $wp_version . ' · PHP ' . PHP_VERSION . ' · WooCommerce ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : 'nicht aktiv' ),
			'memory_limit ' . ini_get( 'memory_limit' ) . ' · max_execution_time ' . ini_get( 'max_execution_time' ) . ' s',
			'WP_DEBUG ' . ( defined( 'WP_DEBUG' ) && WP_DEBUG ? 'an' : 'aus' ) . ' · Objekt-Cache ' . ( wp_using_ext_object_cache() ? 'ja' : 'nein' ),
			'Aktive Plugins (' . count( $plugins ) . '): ' . implode( ', ', array_unique( $plugins ) ),
		];
		if ( $last ) {
			$lines[] = 'Letzter Fehler (' . wp_date( 'd.m.Y H:i:s', (int) $last['time'] ) . ', ' . $last['context'] . ', ' . $last['kind'] . '): ' . $last['message'] . ' @ ' . $last['where'];
		} else {
			$lines[] = 'Letzter Fehler: keiner gespeichert';
		}
		return implode( "\n", $lines );
	}
}
