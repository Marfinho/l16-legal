<?php
declare(strict_types=1);

namespace Lindenweg16\Legal\Legal;

/**
 * Minimale Template-Engine: {{platzhalter}} und {{#if key}}…{{/if}}.
 * Werte werden HTML-escaped, außer Schlüssel mit Präfix "html_".
 * Reine Funktion → PHPUnit-tauglich.
 */
final class Template {

	/**
	 * @param array<string,mixed> $vars
	 */
	public static function render( string $tpl, array $vars ): string {
		$tpl = (string) preg_replace_callback(
			'/\{\{#if (!?)([a-z0-9_]+)\}\}(.*?)\{\{\/if\}\}/s',
			static function ( array $m ) use ( $vars ): string {
				$truthy = ! empty( $vars[ $m[2] ] );
				return ( '!' === $m[1] ? ! $truthy : $truthy ) ? $m[3] : '';
			},
			$tpl
		);
		return (string) preg_replace_callback(
			'/\{\{([a-z0-9_]+)\}\}/',
			static function ( array $m ) use ( $vars ): string {
				if ( ! array_key_exists( $m[1], $vars ) ) {
					return $m[0]; // bleibt stehen → Linter blockiert die Veröffentlichung
				}
				$val = is_array( $vars[ $m[1] ] ) ? implode( ', ', array_map( 'strval', $vars[ $m[1] ] ) ) : (string) $vars[ $m[1] ];
				return str_starts_with( $m[1], 'html_' ) ? $val : htmlspecialchars( $val, ENT_QUOTES, 'UTF-8' );
			},
			$tpl
		);
	}
}
