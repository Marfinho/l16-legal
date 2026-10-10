<?php
declare(strict_types=1);

namespace Lindenweg16\Legal\Monitor;

use Lindenweg16\Legal\Core\Masterdata;
use Lindenweg16\Legal\Core\ServiceRegistry;
use Lindenweg16\Legal\Legal\Linter;
use Lindenweg16\Legal\Legal\Texts;

/**
 * Compliance-Prüfungen. Jede liefert [ id, label, status(ok|warn|fail), detail ].
 * Prüfungen laufen als ausgeloggter Besucher gegen die eigene Website.
 */
final class Checks {

	/** @var array<string,string> */
	private array $cache = [];

	/** @return array<int,array{id:string,label:string,status:string,detail:string}> */
	public function run_all(): array {
		$out = [];
		foreach ( [ 'pages', 'placeholders', 'contacts', 'consent', 'payments', 'products', 'checkout', 'shipping', 'withdrawal', 'headers', 'review', 'legacy', 'config' ] as $c ) {
			try {
				foreach ( $this->$c() as $r ) {
					$out[] = $r;
				}
			} catch ( \Throwable $e ) {
				$out[] = self::r( $c, 'Prüfung ' . $c, 'fail', 'Fehler: ' . $e->getMessage() );
			}
		}
		return $out;
	}

	/** @return array{id:string,label:string,status:string,detail:string} */
	private static function r( string $id, string $label, string $status, string $detail = '' ): array {
		return [ 'id' => $id, 'label' => $label, 'status' => $status, 'detail' => $detail ];
	}

	/** Seite als ausgeloggter Besucher laden (kein Cookie, kein Cache-Bypass-Header nötig). */
	public function fetch( string $path ): string {
		if ( isset( $this->cache[ $path ] ) ) {
			return $this->cache[ $path ];
		}
		$res = wp_remote_get( home_url( $path ), [ 'timeout' => 20, 'redirection' => 3, 'cookies' => [], 'headers' => [ 'Cache-Control' => 'no-cache' ], 'sslverify' => apply_filters( 'https_local_ssl_verify', false ) ] );
		$this->cache[ $path ] = is_wp_error( $res ) ? '' : ( 200 === wp_remote_retrieve_response_code( $res ) ? (string) wp_remote_retrieve_body( $res ) : '' );
		return $this->cache[ $path ];
	}

	private function status_of( string $path ): int {
		$res = wp_remote_head( home_url( $path ), [ 'timeout' => 15, 'redirection' => 3, 'sslverify' => apply_filters( 'https_local_ssl_verify', false ) ] );
		return is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
	}

	/** 1) Rechtsseiten erreichbar und verlinkt */
	private function pages(): array {
		$out   = [];
		$slugs = array_merge( array_keys( Texts::PAGES ), [ 'vertrag-widerrufen' ] );
		$bad   = [];
		foreach ( $slugs as $slug ) {
			$code = $this->status_of( '/' . $slug . '/' );
			if ( 200 !== $code ) {
				$bad[] = "/{$slug}/ → {$code}";
			}
		}
		$out[] = self::r( 'pages-200', 'Rechtsseiten erreichbar (HTTP 200)', $bad ? 'fail' : 'ok', implode( '; ', $bad ) );

		$home    = $this->fetch( '/' );
		$missing = [];
		foreach ( [ 'impressum', 'datenschutz', 'agb', 'widerruf', 'vertrag-widerrufen', 'versand-zahlung' ] as $slug ) {
			if ( false === strpos( $home, '/' . $slug . '/' ) ) {
				$missing[] = $slug;
			}
		}
		$out[] = self::r( 'pages-footer', 'Rechtsseiten von der Startseite/Footer verlinkt', $missing ? 'fail' : 'ok', 'Fehlt: ' . implode( ', ', $missing ) );

		$checkout = $this->fetch( '/checkout/' ) . $this->fetch( '/kasse/' ) . $this->fetch( '/abschluss/' );
		$cm       = [];
		foreach ( [ 'agb', 'widerruf', 'datenschutz' ] as $slug ) {
			if ( '' === $checkout || false === strpos( $checkout, '/' . $slug . '/' ) ) {
				$cm[] = $slug;
			}
		}
		$out[] = self::r( 'pages-checkout', 'AGB/Widerruf/Datenschutz im Checkout verlinkt', $cm ? 'warn' : 'ok', 'Block-Checkout wird teils per JS gerendert; E2E-Test ergänzt. Fehlt im HTML: ' . implode( ', ', $cm ) );
		return $out;
	}

	/** 2) Platzhalter/Testhinweise im Frontend */
	private function placeholders(): array {
		$bad = [];
		foreach ( [ '/', '/shop/', '/warenkorb/', '/checkout/', '/abschluss/', '/contact-us/', '/impressum/', '/datenschutz/', '/cookies/', '/agb/' ] as $p ) {
			$html = $this->fetch( $p );
			if ( '' === $html ) {
				continue;
			}
			$text = (string) preg_replace( '#<(script|style)\b.*?</\1>#is', ' ', $html );
			$hits = array_merge( Linter::check( wp_strip_all_tags( $text ) ), \Lindenweg16\Legal\Checkout\TestMode::html_has_test_hint( $text ) ? [ 'Testmodus-/Testkarten-Hinweis' ] : [] );
			if ( Masterdata::get( 'small_business' ) && preg_match( '/inkl\.?\s*(gesetzl\.?\s*)?MwSt|inkl\.?\s*USt/i', $text ) ) {
				$hits[] = '„inkl. MwSt." trotz Kleinunternehmerregelung';
			}
			if ( $hits ) {
				$bad[] = $p . ': ' . implode( ', ', array_unique( $hits ) );
			}
		}
		return [ self::r( 'placeholders', 'Keine Platzhalter oder Test-Hinweise im Frontend', $bad ? 'fail' : 'ok', implode( ' | ', $bad ) ) ];
	}

	/** 3) Stammdaten-Konsistenz */
	private function contacts(): array {
		$m   = Masterdata::all();
		$bad = [];
		foreach ( [ '/impressum/', '/datenschutz/', '/cookies/', '/contact-us/', '/agb/', '/widerruf/' ] as $p ) {
			$html = $this->fetch( $p );
			if ( '' === $html ) {
				continue;
			}
			// Nur den Hauptinhalt: Footer-/Header-Dubletten stören nicht, weil dort dieselben Daten stehen sollen.
			foreach ( Contacts::deviations( $html, $m ) as $d ) {
				$bad[] = $p . ' ' . $d;
			}
		}
		return [ self::r( 'contacts', 'Telefon/E-Mail identisch zu den Stammdaten', $bad ? 'fail' : 'ok', implode( ' | ', array_unique( $bad ) ) ) ];
	}

	/** 4) Consent: Drittanbieter-Requests und Cookies ohne Einwilligung (statische Prüfung; Playwright ergänzt) */
	private function consent(): array {
		$allowed = array_filter( [ (string) wp_parse_url( home_url(), PHP_URL_HOST ) ] );
		$bad     = [];
		foreach ( [ '/', '/shop/', '/contact-us/' ] as $p ) {
			$html = $this->fetch( $p );
			if ( '' === $html ) {
				continue;
			}
			preg_match_all( '#<(script|iframe|link|img)\b[^>]*\b(?:src|href)=["\'](https?:)?//([^/"\']+)#i', $html, $m, PREG_SET_ORDER );
			foreach ( $m as $x ) {
				if ( preg_match( '/type=["\']text\/plain/i', $x[0] ) || 'link' === strtolower( $x[1] ) && ! preg_match( '/stylesheet|preload|script/i', $x[0] ) ) {
					continue;
				}
				$host = (string) preg_replace( '/:\d+$/', '', $x[3] );
				if ( ! in_array( $host, $allowed, true ) && ! preg_match( '/^(www\.)?' . preg_quote( $allowed[0] ?? '', '/' ) . '$/', $host ) ) {
					$bad[] = $p . ' → ' . $x[3];
				}
			}
		}
		$res = wp_remote_get( home_url( '/' ), [ 'timeout' => 20, 'cookies' => [], 'sslverify' => apply_filters( 'https_local_ssl_verify', false ) ] );
		$set = [];
		if ( ! is_wp_error( $res ) ) {
			foreach ( (array) wp_remote_retrieve_cookies( $res ) as $c ) {
				$name = $c->name;
				if ( ! preg_match( '/^(woocommerce_|wp_woocommerce_session_|ll16_)/', $name ) ) {
					$set[] = $name;
				}
			}
		}
		$detail = implode( ' | ', array_unique( $bad ) ) . ( $set ? ' Cookies ohne Einwilligung: ' . implode( ', ', $set ) : '' );
		return [ self::r( 'consent', 'Keine Drittanbieter-Requests/Cookies vor Einwilligung (statisch)', ( $bad || $set ) ? 'fail' : 'ok', $detail ) ];
	}

	/** 5) Zahlungsarten */
	private function payments(): array {
		if ( ! function_exists( 'WC' ) ) {
			return [ self::r( 'payments', 'Zahlungsarten', 'warn', 'WooCommerce nicht geladen' ) ];
		}
		$enabled = array_keys( WC()->payment_gateways()->get_available_payment_gateways() );
		$active  = ServiceRegistry::active();
		$issues  = [];
		$has_stripe = (bool) array_filter( $enabled, static fn( $id ) => str_contains( $id, 'stripe' ) || str_contains( $id, 'woocommerce_payments' ) );
		$has_paypal = (bool) array_filter( $enabled, static fn( $id ) => str_contains( $id, 'ppcp' ) || str_contains( $id, 'paypal' ) );
		if ( $has_stripe && empty( $active['stripe'] ) ) {
			$issues[] = 'Stripe/WooPayments aktiv, aber nicht im Dienste-Register';
		}
		if ( $has_paypal && empty( $active['paypal'] ) ) {
			$issues[] = 'PayPal aktiv, aber nicht im Dienste-Register';
		}
		if ( ! $has_stripe && ! empty( $active['stripe'] ) ) {
			$issues[] = 'Stripe im Register, aber kein Gateway aktiv';
		}
		if ( ! $has_paypal && ! empty( $active['paypal'] ) ) {
			$issues[] = 'PayPal im Register, aber kein Gateway aktiv';
		}
		if ( [] === (array) Masterdata::get( 'payment_methods' ) ) {
			$issues[] = 'Zahlungsarten in den Stammdaten leer (Text „Versand & Zahlung" unvollständig)';
		}
		return [ self::r( 'payments', 'Zahlungsarten: Gateways = Register = Stammdaten', $issues ? 'fail' : 'ok', implode( '; ', $issues ) . ' [aktiv: ' . implode( ',', $enabled ) . ']' ) ];
	}

	/** 6) Produkte */
	private function products(): array {
		$ids = get_posts( [ 'post_type' => 'product', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids' ] );
		$bad     = [];
		$fetched = 0;
		foreach ( $ids as $id ) {
			$p = wc_get_product( $id );
			if ( ! $p ) {
				continue;
			}
			$issues = [];
			$img    = (int) $p->get_image_id();
			if ( $img < 1 ) {
				$issues[] = 'Bild fehlt';
			} elseif ( '' === trim( (string) get_post_meta( $img, '_wp_attachment_image_alt', true ) ) ) {
				$issues[] = 'Alt-Text fehlt';
			}
			foreach ( \Lindenweg16\Legal\Product\Gpsr::missing( array_combine( array_keys( \Lindenweg16\Legal\Product\Gpsr::REQUIRED ), array_map( static fn( $k ) => (string) $p->get_meta( $k ), array_keys( \Lindenweg16\Legal\Product\Gpsr::REQUIRED ) ) ) ) as $label ) {
				$issues[] = 'GPSR: ' . $label;
			}
			if ( $p->is_type( 'simple' ) ) {
				foreach ( $p->get_attributes() as $a ) {
					if ( $a->get_variation() && count( $a->is_taxonomy() ? wc_get_product_terms( $id, $a->get_name(), [ 'fields' => 'ids' ] ) : $a->get_options() ) > 1 ) {
						$issues[] = 'einfaches Produkt mit Varianten-Attribut „' . wc_attribute_label( $a->get_name() ) . '" (als variables Produkt anlegen)';
						break;
					}
				}
			}
			$html = $fetched++ < 15 ? $this->fetch( '/?p=' . $id ) : ''; // Seitenabruf nur für die ersten 15 Produkte (Laufzeit)
			if ( $html && str_contains( $html, 'Zurzeit nicht auf Lager' ) && preg_match( '/Vorr(ä|&auml;)tig|Auf Lager/', $html ) ) {
				$issues[] = 'widersprüchliche Lagerangabe in der Ansicht';
			}
			if ( $issues ) {
				$bad[] = $p->get_name() . ': ' . implode( ', ', $issues );
			}
		}
		return [ self::r( 'products', 'Produkte: Bild, Alt-Text, GPSR, Lagerstatus, Auswahlpflicht', $bad ? 'fail' : 'ok', implode( ' | ', $bad ) ) ];
	}

	/** 7) Checkout */
	private function checkout(): array {
		$issues = [];
		$test   = \Lindenweg16\Legal\Checkout\TestMode::detect();
		if ( $test ) {
			$issues[] = 'Testmodus aktiv: ' . implode( ', ', $test );
		}
		if ( 'Zahlungspflichtig bestellen' !== \Lindenweg16\Legal\Checkout\Module::BUTTON ) {
			$issues[] = 'Button-Text weicht ab';
		}
		$countries = function_exists( 'WC' ) ? array_keys( WC()->countries->get_shipping_countries() ) : [];
		if ( $countries !== array_values( array_map( 'strtoupper', (array) Masterdata::get( 'delivery_countries' ) ) ) ) {
			$issues[] = 'Lieferländer: ' . count( $countries ) . ' statt ' . implode( ',', (array) Masterdata::get( 'delivery_countries' ) );
		}
		$agb = file_get_contents( LL16_DIR . 'templates/agb.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === strpos( (string) $agb, \Lindenweg16\Legal\Checkout\Module::BUTTON ) ) {
			$issues[] = 'AGB nennen den Button-Text nicht';
		}
		return [ self::r( 'checkout', 'Checkout: Button, Lieferländer, Testmodus', $issues ? 'fail' : 'ok', implode( '; ', $issues ) ) ];
	}

	/** Versandkosten/Gratisversand: WooCommerce-Zone = Stammdaten = Text */
	private function shipping(): array {
		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return [];
		}
		$m       = Masterdata::all();
		$issues  = [];
		$flat    = null;
		$free    = null;
		$covered = [];
		foreach ( \WC_Shipping_Zones::get_zones() as $z ) {
			$zone = new \WC_Shipping_Zone( $z['id'] );
			foreach ( $zone->get_zone_locations() as $loc ) {
				if ( 'country' === $loc->type ) {
					$covered[] = $loc->code;
				}
			}
			foreach ( $zone->get_shipping_methods( true ) as $method ) {
				$opt = (array) get_option( 'woocommerce_' . $method->id . '_' . $method->instance_id . '_settings', [] );
				if ( 'flat_rate' === $method->id ) {
					$flat = Masterdata::money( (string) ( $opt['cost'] ?? '' ) );
				}
				if ( 'free_shipping' === $method->id ) {
					$free = Masterdata::money( (string) ( $opt['min_amount'] ?? '0' ) ) ?? '0,00';
				}
			}
		}
		if ( [] === $covered ) {
			$issues[] = 'Keine Versandzone mit Ländern eingerichtet (Kunden sehen keine Versandart).';
		}
		$stamm = Masterdata::money( (string) $m['shipping_cost'] );
		if ( null !== $flat && $flat !== $stamm ) {
			$issues[] = "Pauschale in WooCommerce ({$flat} €) weicht von den Stammdaten ({$stamm} €) ab. Texte nennen {$stamm} €.";
		}
		if ( null === $flat && null === $free && [] !== $covered ) {
			$issues[] = 'Keine Pauschale/Gratisversand-Methode in den Versandzonen gefunden.';
		}
		$thr = '' !== (string) $m['free_shipping_from'] ? Masterdata::money( (string) $m['free_shipping_from'] ) : null;
		if ( null !== $thr && $thr !== $free ) {
			$issues[] = "Texte versprechen Gratisversand ab {$thr} €, in WooCommerce " . ( null === $free ? 'ist keine Gratisversand-Methode aktiv.' : "gilt ab {$free} €." );
		}
		if ( null === $thr && null !== $free && '0,00' !== $free ) {
			$issues[] = "WooCommerce liefert ab {$free} € versandkostenfrei, die Rechtstexte erwähnen es nicht (Stammdaten „Gratisversand ab“ pflegen).";
		}
		$allowed = array_map( 'strtoupper', (array) $m['delivery_countries'] );
		$extra   = array_diff( $covered, $allowed );
		if ( $extra ) {
			$issues[] = 'Versandzonen enthalten Länder außerhalb der Lieferländer: ' . implode( ', ', $extra );
		}
		return [ self::r( 'shipping', 'Versandkosten: WooCommerce = Stammdaten = Rechtstexte', $issues ? 'fail' : 'ok', implode( ' ', $issues ) ) ];
	}

	/** 8) Widerrufsfunktion */
	private function withdrawal(): array {
		$html = $this->fetch( '/vertrag-widerrufen/' );
		$ok   = str_contains( $html, 'Weiter' ) && str_contains( $html, 'name="ll16_order"' );
		return [
			self::r( 'withdrawal-page', 'Widerrufsfunktion erreichbar und Formular vorhanden', $ok ? 'ok' : 'fail', $ok ? '' : '/vertrag-widerrufen/ liefert kein Formular' ),
			self::r( 'withdrawal-mail', 'Testwiderruf erzeugt Bestätigungs-Mail', 'warn', 'Nur auf Staging manuell/per E2E testbar (E-Mail-Versand). Bis zum Test als offen führen.' ),
		];
	}

	/** 9) Security-Header */
	private function headers(): array {
		$res = wp_remote_head( home_url( '/' ), [ 'timeout' => 15, 'sslverify' => apply_filters( 'https_local_ssl_verify', false ) ] );
		if ( is_wp_error( $res ) ) {
			return [ self::r( 'headers', 'Security-Header', 'fail', $res->get_error_message() ) ];
		}
		$h       = wp_remote_retrieve_headers( $res );
		$missing = [];
		foreach ( [ 'x-content-type-options', 'referrer-policy', 'x-frame-options', 'permissions-policy' ] as $k ) {
			if ( empty( $h[ $k ] ) ) {
				$missing[] = $k;
			}
		}
		if ( is_ssl() && empty( $h['strict-transport-security'] ) ) {
			$missing[] = 'strict-transport-security';
		}
		if ( ! empty( $h['x-powered-by'] ) ) {
			$missing[] = 'X-Powered-By sichtbar (' . $h['x-powered-by'] . ')';
		}
		return [ self::r( 'headers', 'Security-Header vorhanden', $missing ? 'fail' : 'ok', implode( ', ', $missing ) ) ];
	}

	/** 10) Alter der Rechtstexte, Quartals-Review */
	private function review(): array {
		$out   = [];
		$old   = [];
		foreach ( (array) get_option( 'll16_text_versions', [] ) as $slug => $v ) {
			if ( strtotime( (string) ( $v['date'] ?? '' ) ) < strtotime( '-6 months' ) ) {
				$old[] = $slug;
			}
		}
		$out[] = self::r( 'texts-age', 'Rechtstexte jünger als 6 Monate', $old ? 'warn' : 'ok', implode( ', ', $old ) );
		$last  = (string) Masterdata::get( 'last_review' );
		$due   = '' === $last || strtotime( $last ) < strtotime( '-3 months' );
		$out[] = self::r(
			'review-due',
			'Quartals-Review der Rechtslage',
			$due ? 'warn' : 'ok',
			$due ? 'Review fällig. Checkliste: neue Gesetze/Urteile (Widerruf, GPSR, Gewährleistungslabel, Preisangaben, TDDDG, BFSG), Zahlungsarten, Dienste-Register, Aufbewahrungsfristen, Lieferzeiten/Versandkosten. Danach Datum in den Stammdaten setzen.' : ''
		);
		return $out;
	}

	/** Altplugins */
	private function legacy(): array {
		$list = \Lindenweg16\Legal\Gate\Module::active_legacy();
		$names = array_map( static fn( $p ) => \Lindenweg16\Legal\Gate\Module::legacy_plugins()[ $p ]['name'], $list );
		return [ self::r( 'legacy', 'Keine Fremdplugins mit Rechts-/Consent-/Tracking-Funktion aktiv', $list ? 'warn' : 'ok', implode( ', ', $names ) ) ];
	}

	/** Konfiguration */
	private function config(): array {
		$out = [];
		$m   = Masterdata::all();
		$missing = [];
		foreach ( [ 'shipping_carrier' => 'Versanddienstleister (Datenschutz)', 'payment_methods' => 'Zahlungsarten', 'delivery_time' => 'Lieferzeit' ] as $k => $label ) {
			if ( empty( $m[ $k ] ) ) {
				$missing[] = $label;
			}
		}
		$out[] = self::r( 'config-masterdata', 'Stammdaten vollständig', $missing ? 'warn' : 'ok', implode( ', ', $missing ) );
		$out[] = self::r( 'config-warranty', 'Gewährleistungshinweis (DVO (EU) 2025/1960) geprüft', get_option( 'll16_warranty_notice' ) ? 'ok' : 'warn', 'Standardtext aktiv: verbindlichen Wortlaut der Kommissionsvorlage prüfen und unter Einstellungen hinterlegen.' );
		if ( function_exists( 'wc_tax_enabled' ) && ! empty( $m['small_business'] ) && wc_tax_enabled() ) {
			$out[] = self::r( 'config-tax', 'Kleinunternehmer: WooCommerce-Steuern deaktiviert', 'fail', 'Steuern sind in WooCommerce aktiv, Preise könnten „inkl. MwSt." zeigen.' );
		}
		return $out;
	}
}
