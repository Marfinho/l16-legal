<?php
declare(strict_types=1);

namespace Lindenweg16\Legal\Core;

/**
 * Dienste-Register: Quelle für Banner, Cookie-Seite, Datenschutzerklärung,
 * Skript-/iframe-Blockierung und Cookie-Bereinigung.
 *
 * Kategorien: necessary | preferences | statistics | marketing
 */
final class ServiceRegistry {

	public const OPTION     = 'll16_services';
	public const CATEGORIES = [
		'necessary'   => 'Notwendig',
		'preferences' => 'Präferenzen',
		'statistics'  => 'Statistik',
		'marketing'   => 'Marketing',
	];

	/**
	 * Standardbestand. "active" bedeutet: Der Dienst wird auf der Seite tatsächlich genutzt
	 * und erscheint daher im Banner und in der Datenschutzerklärung.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function defaults(): array {
		return [
			'consent'      => [
				'name'         => 'Cookie-Einstellungen (Lindenweg16 Legal Suite)',
				'provider'     => 'Sven Meendermann – Lindenweg16',
				'category'     => 'necessary',
				'purpose'      => 'Speichert deine Auswahl im Cookie-Banner.',
				'cookies'      => [ [ 'name' => 'll16_consent', 'duration' => '12 Monate', 'purpose' => 'Speichert deine Cookie-Auswahl' ] ],
				'legal_basis'  => 'Art. 6 Abs. 1 lit. c DSGVO i. V. m. Art. 7 DSGVO; § 25 Abs. 2 Nr. 2 TDDDG',
				'third_country' => '',
				'privacy_url'  => '',
				'patterns'     => [],
				'context'      => 'always',
				'active'       => true,
			],
			'woocommerce'  => [
				'name'         => 'Warenkorb und Shop-Sitzung (WooCommerce)',
				'provider'     => 'Sven Meendermann – Lindenweg16',
				'category'     => 'necessary',
				'purpose'      => 'Hält Warenkorb, Sitzung und Bestellvorgang zusammen.',
				'cookies'      => [
					[ 'name' => 'woocommerce_cart_hash', 'duration' => 'Sitzung', 'purpose' => 'Erkennt Änderungen am Warenkorb' ],
					[ 'name' => 'woocommerce_items_in_cart', 'duration' => 'Sitzung', 'purpose' => 'Merkt, ob der Warenkorb Artikel enthält' ],
					[ 'name' => 'wp_woocommerce_session_*', 'duration' => '2 Tage', 'purpose' => 'Ordnet den Warenkorb deiner Sitzung zu' ],
				],
				'legal_basis'  => 'Art. 6 Abs. 1 lit. b DSGVO; § 25 Abs. 2 Nr. 2 TDDDG',
				'third_country' => '',
				'privacy_url'  => '',
				'patterns'     => [],
				'context'      => 'always',
				'active'       => true,
			],
			'stripe'       => [
				'name'         => 'Stripe (Kartenzahlung, Apple Pay, Google Pay, SEPA)',
				'provider'     => 'Stripe Payments Europe Ltd., Irland; Stripe, Inc., USA',
				'category'     => 'necessary',
				'purpose'      => 'Abwicklung und Betrugsprävention bei der Zahlung. Wird nur im Warenkorb und an der Kasse geladen.',
				'cookies'      => [
					[ 'name' => '__stripe_mid', 'duration' => '1 Jahr', 'purpose' => 'Betrugsprävention' ],
					[ 'name' => '__stripe_sid', 'duration' => '30 Minuten', 'purpose' => 'Betrugsprävention' ],
				],
				'legal_basis'  => 'Art. 6 Abs. 1 lit. b DSGVO; § 25 Abs. 2 Nr. 2 TDDDG (für die Zahlung unbedingt erforderlich – zu klären)',
				'third_country' => 'USA (EU-US Data Privacy Framework / Standardvertragsklauseln – zu klären)',
				'privacy_url'  => 'https://stripe.com/de/privacy',
				'patterns'     => [ 'js.stripe.com', 'm.stripe.network', 'm.stripe.com', 'r.stripe.com' ],
				'context'      => 'checkout',
				'active'       => true,
			],
			'paypal'       => [
				'name'         => 'PayPal',
				'provider'     => 'PayPal (Europe) S.à r.l. et Cie, S.C.A., Luxemburg',
				'category'     => 'necessary',
				'purpose'      => 'Zahlungsabwicklung. Wird nur im Warenkorb und an der Kasse geladen.',
				'cookies'      => [],
				'legal_basis'  => 'Art. 6 Abs. 1 lit. b DSGVO; § 25 Abs. 2 Nr. 2 TDDDG (zu klären)',
				'third_country' => 'möglich (USA) – zu klären',
				'privacy_url'  => 'https://www.paypal.com/de/legalhub/privacy-full',
				'patterns'     => [ 'paypal.com/sdk', 'paypalobjects.com', 'paypal.com/tagmanager' ],
				'context'      => 'checkout',
				'active'       => true,
			],
			'google-maps'  => [
				'name'         => 'Google Maps',
				'provider'     => 'Google Ireland Limited, Irland',
				'category'     => 'preferences',
				'purpose'      => 'Zeigt eine Karte mit unserem Standort. Wird erst nach deinem Klick geladen.',
				'cookies'      => [],
				'legal_basis'  => 'Art. 6 Abs. 1 lit. a DSGVO; § 25 Abs. 1 TDDDG',
				'third_country' => 'USA',
				'privacy_url'  => 'https://policies.google.com/privacy',
				'patterns'     => [ 'google.com/maps', 'maps.google.', 'maps.googleapis.com', 'maps.gstatic.com' ],
				'context'      => 'always',
				'active'       => true,
			],
			'attribution'  => [
				'name'         => 'Bestell-Herkunftsanalyse (WooCommerce Order Attribution)',
				'provider'     => 'Sven Meendermann – Lindenweg16',
				'category'     => 'statistics',
				'purpose'      => 'Erfasst, über welchen Weg Besucher in den Shop kamen.',
				'cookies'      => [ [ 'name' => 'sbjs_*', 'duration' => 'Sitzung bis 6 Monate', 'purpose' => 'Herkunftserfassung' ] ],
				'legal_basis'  => 'Art. 6 Abs. 1 lit. a DSGVO; § 25 Abs. 1 TDDDG',
				'third_country' => '',
				'privacy_url'  => '',
				'patterns'     => [ 'order-attribution', 'sourcebuster' ],
				'context'      => 'always',
				'active'       => false,
			],
			'jetpack-analytics' => [
				'name'         => 'Jetpack / WooCommerce Analytics',
				'provider'     => 'Automattic Inc., USA',
				'category'     => 'statistics',
				'purpose'      => 'Reichweitenmessung. Im Standard abgeschaltet.',
				'cookies'      => [ [ 'name' => 'tk_ai', 'duration' => 'Sitzung', 'purpose' => 'Besucherkennung' ] ],
				'legal_basis'  => 'Art. 6 Abs. 1 lit. a DSGVO; § 25 Abs. 1 TDDDG',
				'third_country' => 'USA',
				'privacy_url'  => 'https://automattic.com/privacy/',
				'patterns'     => [ 'woocommerce-analytics', 'stats.wp.com', 'pixel.wp.com', 'w.logs.wordpress.com' ],
				'context'      => 'always',
				'active'       => false,
			],
			'recaptcha-blocked' => [
				'name'         => 'Nicht genutzte Dienste (dauerhaft blockiert)',
				'provider'     => '',
				'category'     => 'marketing',
				'purpose'      => 'reCAPTCHA, Google Tag Manager/Analytics/Ads/Fonts, Pinterest-/Facebook-Pixel: werden nie geladen.',
				'cookies'      => [],
				'legal_basis'  => '',
				'third_country' => '',
				'privacy_url'  => '',
				'patterns'     => [ 'google.com/recaptcha', 'gstatic.com/recaptcha', 'googletagmanager.com', 'google-analytics.com', 'googleadservices.com', 'doubleclick.net', 's.pinimg.com', 'ct.pinterest.com', 'connect.facebook.net', 'facebook.com/tr', 'fonts.googleapis.com', 'fonts.gstatic.com', 'use.typekit.net', 'cdn.jsdelivr.net/npm/@fortawesome', 'platform.twitter.com', 'static.cloudflareinsights.com' ],
				'context'      => 'always',
				'active'       => false,
			],
		];
	}

	/** @return array<string,array<string,mixed>> */
	public static function all(): array {
		$stored = get_option( self::OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];
		$out    = self::defaults();
		foreach ( $stored as $id => $override ) {
			if ( isset( $out[ $id ] ) && is_array( $override ) ) {
				$out[ $id ] = array_merge( $out[ $id ], $override );
			} elseif ( is_array( $override ) && ! isset( $out[ $id ] ) ) {
				$out[ $id ] = array_merge( self::blank(), $override );
			}
		}
		return $out;
	}

	/** @return array<string,array<string,mixed>> nur aktive Dienste */
	public static function active(): array {
		return array_filter( self::all(), static fn( $s ) => ! empty( $s['active'] ) );
	}

	/**
	 * Kategorien, die tatsächlich vorkommen (notwendig immer).
	 *
	 * @param array<string,array<string,mixed>> $services
	 * @return string[]
	 */
	public static function categories_in_use( array $services ): array {
		$used = [ 'necessary' ];
		foreach ( $services as $s ) {
			$used[] = $s['category'];
		}
		return array_values( array_intersect( array_keys( self::CATEGORIES ), array_unique( $used ) ) );
	}

	/**
	 * Version des Registers. Ändert sich, sobald ein einwilligungspflichtiger
	 * Dienst hinzukommt oder die Kategorie wechselt → Re-Consent.
	 *
	 * @param array<string,array<string,mixed>> $services
	 */
	public static function version( array $services ): string {
		$parts = [];
		foreach ( $services as $id => $s ) {
			if ( 'necessary' !== ( $s['category'] ?? '' ) ) {
				$parts[] = $id . ':' . $s['category'];
			}
		}
		sort( $parts );
		return substr( sha1( implode( '|', $parts ) ), 0, 10 );
	}

	/** @return array<string,mixed> */
	private static function blank(): array {
		return [
			'name' => '', 'provider' => '', 'category' => 'marketing', 'purpose' => '', 'cookies' => [],
			'legal_basis' => '', 'third_country' => '', 'privacy_url' => '', 'patterns' => [], 'context' => 'always', 'active' => false,
		];
	}
}
