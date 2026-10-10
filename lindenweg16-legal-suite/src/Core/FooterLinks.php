<?php
declare(strict_types=1);

namespace Lindenweg16\Legal\Core;

/**
 * Footer-Navigation mit den Pflichtlinks (Impressum, Datenschutz, AGB, Widerruf, Versand & Zahlung,
 * „Vertrag widerrufen“, Cookie-Einstellungen). Ersetzt den schwebenden Cookie-Button, der auf dem
 * Smartphone Inhalte (z. B. „In den Warenkorb“) verdeckt. Abschaltbar, wenn das Theme die Links selbst setzt.
 */
final class FooterLinks {

	public static function init(): void {
		add_action( 'wp_footer', [ self::class, 'render' ], 5 );
	}

	/** @return array<int,array{label:string,href:string,attrs:string}> */
	public static function items(): array {
		$items = [];
		$add   = static function ( string $slug, string $label ) use ( &$items ): void {
			$p = get_page_by_path( $slug, OBJECT, 'page' );
			if ( $p instanceof \WP_Post && 'publish' === $p->post_status ) {
				$items[] = [ 'label' => $label, 'href' => get_permalink( $p ), 'attrs' => '' ];
			}
		};
		$add( 'impressum', 'Impressum' );
		$add( 'datenschutz', 'Datenschutz' );
		$add( 'agb', 'AGB' );
		$add( 'widerruf', 'Widerrufsbelehrung' );
		$add( 'versand-zahlung', 'Versand & Zahlung' );
		if ( Modules::enabled( 'withdrawal' ) ) {
			$add( 'vertrag-widerrufen', 'Vertrag widerrufen' );
		}
		if ( Modules::enabled( 'consent' ) ) {
			$items[] = [ 'label' => 'Cookie-Einstellungen', 'href' => '#ll16-cookies', 'attrs' => ' data-ll16-open' ];
		}
		return $items;
	}

	public static function render(): void {
		$s = (array) get_option( 'll16_settings', [] );
		if ( isset( $s['footer_links'] ) && ! $s['footer_links'] ) {
			return;
		}
		$items = self::items();
		if ( [] === $items ) {
			return;
		}
		Assets::force_forms();
		echo '<nav class="ll16-footer-links-wrap" aria-label="Rechtliche Hinweise"><ul class="ll16-footer-links">';
		foreach ( $items as $i ) {
			printf( '<li><a href="%s"%s>%s</a></li>', esc_url( $i['href'] ), $i['attrs'], esc_html( $i['label'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</ul></nav>';
	}
}
