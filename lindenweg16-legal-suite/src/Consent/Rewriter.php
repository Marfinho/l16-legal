<?php
declare(strict_types=1);

namespace Lindenweg16\Legal\Consent;

/**
 * Schreibt HTML so um, dass einwilligungspflichtige Skripte/iframes nicht laden.
 * Reine Funktion ohne WordPress-Abhängigkeit (PHPUnit-tauglich).
 *
 * Regel: [ 'id' => string, 'patterns' => string[], 'mode' => 'gate'|'remove', 'label' => string ]
 *  - gate:   Skript wird `type=text/plain`, iframe wird Zwei-Klick-Platzhalter
 *  - remove: Element wird entfernt
 */
final class Rewriter {

	/**
	 * @param array<int,array<string,mixed>> $rules
	 */
	public static function rewrite( string $html, array $rules ): string {
		if ( [] === $rules || '' === $html ) {
			return $html;
		}

		$html = preg_replace_callback(
			'#<script\b([^>]*)>(.*?)</script>#is',
			static function ( array $m ) use ( $rules ): string {
				$attrs = $m[1];
				$body  = $m[2];
				if ( preg_match( '/\bdata-ll16-skip\b/i', $attrs ) ) {
					return $m[0];
				}
				$type = self::attr( $attrs, 'type' );
				if ( null !== $type && ! preg_match( '#^(text/javascript|application/javascript|module)$#i', $type ) ) {
					return $m[0];
				}
				$src  = self::attr( $attrs, 'src' );
				$hay  = null !== $src ? $src : $body;
				$rule = self::match( $hay, $rules );
				if ( null === $rule ) {
					return $m[0];
				}
				if ( 'remove' === $rule['mode'] ) {
					return '';
				}
				$new = self::drop_attr( $attrs, 'type' );
				if ( null !== $src ) {
					$new = self::drop_attr( $new, 'src' );
					$new .= ' data-src="' . self::esc( $src ) . '"';
				}
				$new .= ' type="text/plain" data-ll16-service="' . self::esc( (string) $rule['id'] ) . '"';
				if ( 'module' === $type ) {
					$new .= ' data-ll16-module="1"';
				}
				return '<script' . rtrim( ' ' . trim( $new ) ) . '>' . $body . '</script>';
			},
			$html
		) ?? $html;

		// <link> (preconnect, dns-prefetch, preload, prefetch, stylesheet) und Tracking-Pixel (<img>, <source>, <embed>)
		// bauen bereits beim Laden eine Verbindung zum Drittanbieter auf: für nicht erlaubte Dienste entfernen.
		$html = preg_replace_callback(
			'#<(link|img|source|embed)\b([^>]*?)/?>#i',
			static function ( array $m ) use ( $rules ): string {
				$attrs = $m[2];
				$url   = self::attr( $attrs, 'href' ) ?? self::attr( $attrs, 'src' ) ?? self::attr( $attrs, 'srcset' );
				if ( null === $url ) {
					return $m[0];
				}
				$rule = self::match( $url, $rules );
				return null === $rule ? $m[0] : '';
			},
			$html
		) ?? $html;

		$html = preg_replace_callback(
			'#<iframe\b([^>]*)>(.*?)</iframe>#is',
			static function ( array $m ) use ( $rules ): string {
				$src = self::attr( $m[1], 'src' ) ?? self::attr( $m[1], 'data-src' );
				if ( null === $src ) {
					return $m[0];
				}
				$rule = self::match( $src, $rules );
				if ( null === $rule ) {
					return $m[0];
				}
				if ( 'remove' === $rule['mode'] ) {
					return '';
				}
				$inert  = self::drop_attr( $m[1], 'src' );
				$inert  = self::drop_attr( $inert, 'data-src' );
				$inert .= ' data-ll16-src="' . self::esc( $src ) . '"';
				$id     = self::esc( (string) $rule['id'] );
				$label  = self::esc( (string) ( $rule['label'] ?? $rule['id'] ) );
				$host   = self::host( $src );

				return '<div class="ll16-embed" data-ll16-embed="' . $id . '">'
					. '<p class="ll16-embed__text">Dieser Inhalt wird von <strong>' . $label . '</strong> bereitgestellt. '
					. 'Beim Laden werden Daten an ' . self::esc( $host ) . ' übertragen.</p>'
					. '<button type="button" class="ll16-btn" data-ll16-load="' . $id . '">Inhalt laden</button>'
					. '<noscript><p><a href="' . self::esc( $src ) . '" target="_blank" rel="noopener noreferrer">Inhalt in neuem Tab öffnen (Verbindung zu ' . self::esc( $host ) . ')</a></p></noscript>'
					. '<template><iframe' . rtrim( ' ' . trim( $inert ) ) . '></iframe></template>'
					. '</div>';
			},
			$html
		) ?? $html;

		return $html;
	}

	/**
	 * @param array<int,array<string,mixed>> $rules
	 * @return array<string,mixed>|null
	 */
	public static function match( string $haystack, array $rules ): ?array {
		// URLs: nur Host und Pfad vergleichen, nicht Query/Fragment (z. B. ?ref=https://stats.wp.com ist kein Tracking-Skript).
		if ( 1 === preg_match( '#^(https?:)?//|^/#i', $haystack ) ) {
			$haystack = (string) strtok( $haystack, '?#' );
		}
		foreach ( $rules as $rule ) {
			foreach ( (array) $rule['patterns'] as $needle ) {
				if ( '' !== $needle && false !== stripos( $haystack, (string) $needle ) ) {
					return $rule;
				}
			}
		}
		return null;
	}

	public static function attr( string $attrs, string $name ): ?string {
		if ( preg_match( '/(?:^|\s)' . preg_quote( $name, '/' ) . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $attrs, $m ) ) {
			$val = ( $m[1] ?? '' ) . ( $m[2] ?? '' ) . ( $m[3] ?? '' );
			return html_entity_decode( $val, ENT_QUOTES );
		}
		return null;
	}

	private static function drop_attr( string $attrs, string $name ): string {
		return (string) preg_replace( '/(?:^|\s)' . preg_quote( $name, '/' ) . '\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $attrs );
	}

	private static function esc( string $v ): string {
		return htmlspecialchars( $v, ENT_QUOTES, 'UTF-8' );
	}

	private static function host( string $url ): string {
		$host = parse_url( str_starts_with( $url, '//' ) ? 'https:' . $url : $url, PHP_URL_HOST );
		return is_string( $host ) ? $host : '';
	}
}
