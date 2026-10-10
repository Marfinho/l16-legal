<?php
declare(strict_types=1);

namespace Lindenweg16\Legal\Legal;

use Lindenweg16\Legal\Core\Masterdata;
use Lindenweg16\Legal\Core\ServiceRegistry;

/**
 * Erzeugt die Rechtstexte aus Stammdaten, Dienste-Register und Vorlagen.
 */
final class Texts {

	/** slug => Titel */
	public const PAGES = [
		'impressum'       => 'Impressum',
		'datenschutz'     => 'Datenschutzerklärung',
		'agb'             => 'Allgemeine Geschäftsbedingungen',
		'widerruf'        => 'Widerrufsbelehrung',
		'versand-zahlung' => 'Versand & Zahlung',
		'cookies'         => 'Cookie-Richtlinie',
	];

	/** Alte Slugs → neue Slugs (301). */
	public const REDIRECTS = [
		'sample-page'          => 'agb',
		'sample-page-2'        => 'datenschutz',
		'widerrufsbelehrung'   => 'widerruf',
		'cookie-richtlinie-eu' => 'cookies',
		'retouren'             => 'vertrag-widerrufen',
	];

	/** @return array<string,mixed> */
	public static function vars(): array {
		$m = Masterdata::all();

		$days_words = [ 14 => 'vierzehn Tagen' ];
		$days_text  = $days_words[ (int) $m['withdrawal_days'] ] ?? ( (int) $m['withdrawal_days'] . ' Tagen' );

		$vars                         = $m;
		$vars['stand']                = self::stand();
		$vars['shop_host']            = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$vars['withdrawal_days_text'] = $days_text;
		unset( $vars['payment_methods'] );
		if ( [] !== (array) $m['payment_methods'] ) {
			// Bewusst nur setzen, wenn gepflegt: sonst bleibt {{payment_methods_text}} stehen und der Linter blockiert.
			$vars['payment_methods_text'] = implode( ', ', (array) $m['payment_methods'] );
		}
		$vars['html_withdrawal_page'] = esc_url( home_url( '/widerruf/' ) );
		$vars['html_withdrawal_url']  = esc_url( home_url( '/vertrag-widerrufen/' ) );
		$vars['html_shipping_page']   = esc_url( home_url( '/versand-zahlung/' ) );
		$vars['html_privacy_page']    = esc_url( home_url( '/datenschutz/' ) );
		$vars['html_authority_url']   = esc_url( (string) $m['authority_url'] );
		$settings                     = (array) get_option( 'll16_settings', [] );
		$vars['contact_retention_days'] = (int) ( $settings['contact_retention_days'] ?? 90 );
		$vars['consent_retention_text'] = (int) ( $settings['consent_retention_days'] ?? 1095 ) . ' Tage';

		$services                     = ServiceRegistry::active();
		$vars['html_cookie_table']    = self::cookie_table( $services );
		$vars['html_services_section'] = self::services_section( $services );
		$vars['html_order_sections']  = self::order_sections( $m, $services );
		$vars['html_forms_section']   = self::forms_section( $settings );
		return $vars;
	}

	public static function stand(): string {
		$versions = (array) get_option( 'll16_text_versions', [] );
		$latest   = '';
		foreach ( $versions as $v ) {
			$latest = max( $latest, (string) ( $v['date'] ?? '' ) );
		}
		return wp_date( 'd.m.Y', $latest ? (int) strtotime( $latest ) : time() );
	}

	public static function render( string $slug ): string {
		$file = LL16_DIR . 'templates/' . $slug . '.html';
		if ( ! isset( self::PAGES[ $slug ] ) || ! is_readable( $file ) ) {
			return '';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return Template::render( (string) file_get_contents( $file ), self::vars() );
	}

	/**
	 * @param array<string,array<string,mixed>> $services
	 */
	public static function cookie_table( array $services ): string {
		$h = '<table class="ll16-cookie-table"><thead><tr><th>Name</th><th>Anbieter</th><th>Zweck</th><th>Laufzeit</th><th>Kategorie</th></tr></thead><tbody>';
		$rows = 0;
		foreach ( $services as $s ) {
			foreach ( (array) $s['cookies'] as $c ) {
				$h .= sprintf(
					'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
					esc_html( (string) $c['name'] ),
					esc_html( (string) $s['provider'] ),
					esc_html( (string) $c['purpose'] ),
					esc_html( (string) $c['duration'] ),
					esc_html( ServiceRegistry::CATEGORIES[ $s['category'] ] ?? '' )
				);
				++$rows;
			}
		}
		if ( 0 === $rows ) {
			$h .= '<tr><td colspan="5">Keine Cookies.</td></tr>';
		}
		$h .= '</tbody></table>';

		$h .= '<h3>Dienste</h3><ul>';
		foreach ( $services as $s ) {
			$h .= sprintf(
				'<li><strong>%s</strong> (%s): %s %s</li>',
				esc_html( (string) $s['name'] ),
				esc_html( ServiceRegistry::CATEGORIES[ $s['category'] ] ?? '' ),
				esc_html( (string) $s['purpose'] ),
				$s['privacy_url'] ? '<a href="' . esc_url( (string) $s['privacy_url'] ) . '" rel="noopener">Datenschutz des Anbieters</a>' : ''
			);
		}
		return $h . '</ul>';
	}

	/**
	 * @param array<string,array<string,mixed>> $services
	 */
	public static function services_section( array $services ): string {
		$h = '';
		foreach ( $services as $s ) {
			if ( 'necessary' === $s['category'] && [] === (array) $s['cookies'] && empty( $s['privacy_url'] ) ) {
				continue;
			}
			$h .= '<h3>' . esc_html( (string) $s['name'] ) . '</h3><p>';
			$h .= 'Anbieter: ' . esc_html( (string) $s['provider'] ) . '. Zweck: ' . esc_html( (string) $s['purpose'] );
			$h .= ' Rechtsgrundlage: ' . esc_html( (string) $s['legal_basis'] ) . '.';
			if ( $s['third_country'] ) {
				$h .= ' Drittlandübermittlung: ' . esc_html( (string) $s['third_country'] ) . '.';
			}
			if ( $s['privacy_url'] ) {
				$h .= ' Weitere Informationen: <a href="' . esc_url( (string) $s['privacy_url'] ) . '" rel="noopener">' . esc_html( (string) $s['privacy_url'] ) . '</a>.';
			}
			$h .= '</p>';
		}
		return $h;
	}

	/**
	 * @param array<string,mixed>               $m
	 * @param array<string,array<string,mixed>> $services
	 */
	public static function order_sections( array $m, array $services ): string {
		$h = '<h2>5. Versand und Zahlung</h2>';
		if ( ! empty( $m['shipping_carrier'] ) ) {
			$h .= '<p>Zur Lieferung geben wir Name, Anschrift und ggf. E-Mail-Adresse bzw. Telefonnummer an das Versandunternehmen weiter: ' . esc_html( (string) $m['shipping_carrier'] ) . ' (Art. 6 Abs. 1 lit. b DSGVO).</p>';
		}
		$pay = array_filter( $services, static fn( $s, $id ) => in_array( $id, [ 'stripe', 'paypal' ], true ), ARRAY_FILTER_USE_BOTH );
		if ( $pay ) {
			$h .= '<p>Die Zahlung wird über folgende Zahlungsdienstleister abgewickelt; dabei werden die für die Zahlung erforderlichen Daten an den jeweiligen Anbieter übermittelt (Art. 6 Abs. 1 lit. b DSGVO):</p><ul>';
			foreach ( $pay as $s ) {
				$h .= '<li>' . esc_html( (string) $s['provider'] ) . ' (' . esc_html( (string) $s['name'] ) . ')</li>';
			}
			$h .= '</ul>';
		}
		return $h;
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	public static function forms_section( array $settings ): string {
		$h = '<h2>7. Kontakt, Newsletter und Benachrichtigungen</h2>';
		$h .= '<p><strong>Kontakt:</strong> Wenn du uns per E-Mail oder über das Kontaktformular schreibst, verarbeiten wir deine Angaben zur Bearbeitung der Anfrage (Art. 6 Abs. 1 lit. b bzw. f DSGVO).</p>';
		if ( ! empty( $settings['newsletter_enabled'] ) ) {
			$h .= '<p><strong>Newsletter:</strong> Wir versenden den Newsletter nur mit deiner Einwilligung im Double-Opt-in-Verfahren (Art. 6 Abs. 1 lit. a DSGVO, § 7 UWG). Wir protokollieren Zeitpunkt, Version des Einwilligungstexts und einen Prüfwert des Bestätigungslinks. Du kannst dich in jeder E-Mail abmelden. Der Versand erfolgt über unseren Mailserver beim Hoster.</p>';
		}
		if ( ! empty( $settings['notify_enabled'] ) ) {
			$h .= '<p><strong>„Benachrichtige mich":</strong> Wenn du dich für eine Verfügbarkeitsmeldung einträgst, verwenden wir deine E-Mail-Adresse nur dafür und löschen sie nach dem Versand der Benachrichtigung.</p>';
		}
		return $h;
	}
}
