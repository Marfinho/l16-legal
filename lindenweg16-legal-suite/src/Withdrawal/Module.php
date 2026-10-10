<?php
declare(strict_types=1);

namespace Lindenweg16\Legal\Withdrawal;

use Lindenweg16\Legal\Core\Masterdata;

/**
 * M3 – Online-Widerrufsfunktion nach § 356a BGB.
 *
 * Ablauf: 1) Angaben (Name, Vertragskennung, E-Mail für die Bestätigung) → "Weiter"
 *         2) Zusammenfassung → "Widerruf bestätigen" → Eingangsbestätigung per E-Mail
 *            mit Inhalt und Zeitpunkt (dauerhafter Datenträger).
 * Funktioniert ohne Konto. Missbrauchsschutz: Honeypot + Zeitfenster + Rate-Limit.
 */
final class Module {

	public const STATUS     = 'wc-ll16-widerruf';
	public const META       = '_ll16_withdrawals';
	public const UNMATCHED  = 'll16_withdrawals_unmatched';

	public static function init(): void {
		add_shortcode( 'll16_widerruf', [ self::class, 'shortcode' ] );
		add_shortcode( 'll16_widerruf_link', [ self::class, 'link_shortcode' ] );
		add_action( 'init', [ self::class, 'register_status' ] );
		add_filter( 'wc_order_statuses', [ self::class, 'add_status' ] );
		add_filter( 'woocommerce_my_account_my_orders_actions', [ self::class, 'order_action' ], 10, 2 );
		add_action( 'wp_footer', [ self::class, 'footer_link' ], 5 );
	}

	public static function register_status(): void {
		register_post_status(
			self::STATUS,
			[
				'label'                     => 'Widerruf eingegangen',
				'public'                    => false,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: count */
				'label_count'               => _n_noop( 'Widerruf eingegangen <span class="count">(%s)</span>', 'Widerruf eingegangen <span class="count">(%s)</span>' ),
			]
		);
	}

	/**
	 * @param array<string,string> $statuses
	 * @return array<string,string>
	 */
	public static function add_status( array $statuses ): array {
		$statuses[ self::STATUS ] = 'Widerruf eingegangen';
		return $statuses;
	}

	/**
	 * @param array<string,array<string,string>> $actions
	 * @return array<string,array<string,string>>
	 */
	public static function order_action( array $actions, $order ): array {
		$actions['ll16_withdraw'] = [
			'url'  => add_query_arg( 'order', rawurlencode( (string) $order->get_order_number() ), home_url( '/vertrag-widerrufen/' ) ),
			'name' => 'Vertrag widerrufen',
		];
		return $actions;
	}

	public static function footer_link(): void {
		echo '<p class="ll16-footer-links" style="text-align:center;margin:8px 0;"><a href="' . esc_url( home_url( '/vertrag-widerrufen/' ) ) . '">Vertrag widerrufen</a></p>';
	}

	public static function link_shortcode(): string {
		return '<a href="' . esc_url( home_url( '/vertrag-widerrufen/' ) ) . '">Vertrag widerrufen</a>';
	}

	private static function secret(): string {
		return wp_salt( 'auth' ) . 'll16-withdrawal';
	}

	/* ---------- Anzeige ---------- */

	public static function shortcode(): string {
		self::no_cache();
		$step = isset( $_POST['ll16_step'] ) ? sanitize_key( wp_unslash( $_POST['ll16_step'] ) ) : 'form'; // phpcs:ignore WordPress.Security.NonceVerification

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { // phpcs:ignore WordPress.Security
			if ( 'review' === $step ) {
				return self::step_review();
			}
			if ( 'confirm' === $step ) {
				return self::step_confirm();
			}
		}
		return self::form_html( [], [] );
	}

	private static function no_cache(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		do_action( 'litespeed_control_set_nocache', 'll16 withdrawal' );
	}

	/**
	 * @param array<string,string> $values
	 * @param string[]             $errors
	 */
	private static function form_html( array $values, array $errors ): string {
		$order = isset( $_GET['order'] ) ? sanitize_text_field( wp_unslash( $_GET['order'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$v     = array_merge( [ 'name' => '', 'order' => $order, 'email' => '', 'scope' => '' ], $values );
		$ts    = time();
		$h     = '<div class="ll16-withdrawal"><h2>Vertrag widerrufen</h2>';
		$h    .= '<p>Hier kannst du deinen Vertrag widerrufen, ohne Konto und ohne Angabe von Gründen. Du brauchst nur deinen Namen, die Bestellnummer und eine E-Mail-Adresse für die Bestätigung. Im nächsten Schritt prüfst du deine Angaben und bestätigst den Widerruf.</p>';
		foreach ( $errors as $e ) {
			$h .= '<p role="alert" class="ll16-error"><strong>' . esc_html( $e ) . '</strong></p>';
		}
		$h .= '<form method="post" action="' . esc_url( home_url( '/vertrag-widerrufen/' ) ) . '">';
		$h .= '<input type="hidden" name="ll16_step" value="review">';
		$h .= '<input type="hidden" name="ll16_ts" value="' . esc_attr( Token::sign( [], $ts, self::secret() ) ) . '">';
		$h .= '<p><label>Dein Name *<br><input type="text" name="name" required autocomplete="name" value="' . esc_attr( $v['name'] ) . '"></label></p>';
		$h .= '<p><label>Bestellnummer (Vertragskennung) *<br><input type="text" name="order" required inputmode="numeric" value="' . esc_attr( $v['order'] ) . '"></label></p>';
		$h .= '<p><label>E-Mail-Adresse für die Bestätigung *<br><input type="email" name="email" required autocomplete="email" value="' . esc_attr( $v['email'] ) . '"></label></p>';
		$h .= '<p><label>Nur einen Teil widerrufen? Welche Artikel (leer = ganze Bestellung)<br><input type="text" name="scope" maxlength="300" value="' . esc_attr( $v['scope'] ) . '"></label></p>';
		$h .= '<div style="position:absolute;left:-9999px" aria-hidden="true"><label>Bitte leer lassen<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>';
		$h .= '<p><button type="submit" class="button wp-element-button">Weiter</button></p>';
		$h .= '</form></div>';
		return $h;
	}

	/**
	 * @return array<string,string>
	 */
	private static function read_input(): array {
		// phpcs:disable WordPress.Security.NonceVerification
		return [
			'name'  => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'order' => sanitize_text_field( wp_unslash( $_POST['order'] ?? '' ) ),
			'email' => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
			'scope' => sanitize_text_field( wp_unslash( $_POST['scope'] ?? '' ) ),
		];
		// phpcs:enable
	}

	private static function step_review(): string {
		$in     = self::read_input();
		$errors = [];
		if ( '' !== (string) ( $_POST['website'] ?? '' ) ) { // phpcs:ignore WordPress.Security
			return self::form_html( $in, [ 'Die Anfrage konnte nicht verarbeitet werden.' ] );
		}
		if ( '' === $in['name'] ) {
			$errors[] = 'Bitte gib deinen Namen an.';
		}
		if ( '' === $in['order'] ) {
			$errors[] = 'Bitte gib die Bestellnummer an.';
		}
		if ( ! is_email( $in['email'] ) ) {
			$errors[] = 'Bitte gib eine gültige E-Mail-Adresse an.';
		}
		if ( $errors ) {
			return self::form_html( $in, $errors );
		}
		$issued = Token::verify( (string) ( $_POST['ll16_ts'] ?? '' ), self::secret() ); // phpcs:ignore WordPress.Security
		if ( null === $issued || ! Token::fresh( $issued['t'], time(), 2, 7200 ) ) {
			return self::form_html( $in, [ 'Die Seite war zu lange offen. Bitte sende das Formular erneut.' ] );
		}
		$token = Token::sign( $in, time(), self::secret() );
		$h     = '<div class="ll16-withdrawal"><h2>Widerruf prüfen und bestätigen</h2>';
		$h    .= '<p>Bitte prüfe deine Angaben. Erst mit Klick auf „Widerruf bestätigen" wird der Widerruf an uns übermittelt.</p><dl>';
		$h    .= '<dt>Name</dt><dd>' . esc_html( $in['name'] ) . '</dd>';
		$h    .= '<dt>Bestellnummer</dt><dd>' . esc_html( $in['order'] ) . '</dd>';
		$h    .= '<dt>E-Mail für die Bestätigung</dt><dd>' . esc_html( $in['email'] ) . '</dd>';
		$h    .= '<dt>Umfang</dt><dd>' . esc_html( '' !== $in['scope'] ? 'Teilwiderruf: ' . $in['scope'] : 'Gesamte Bestellung' ) . '</dd></dl>';
		$h    .= '<form method="post" action="' . esc_url( home_url( '/vertrag-widerrufen/' ) ) . '">';
		$h    .= '<input type="hidden" name="ll16_step" value="confirm"><input type="hidden" name="ll16_data" value="' . esc_attr( $token ) . '">';
		$h    .= '<button type="submit" class="button wp-element-button">Widerruf bestätigen</button></form>';
		$h    .= '<form method="get" action="' . esc_url( home_url( '/vertrag-widerrufen/' ) ) . '"><button type="submit" class="button wp-element-button">Angaben ändern</button></form></div>';
		return $h;
	}

	private static function step_confirm(): string {
		$data = Token::verify( (string) ( $_POST['ll16_data'] ?? '' ), self::secret() ); // phpcs:ignore WordPress.Security
		if ( null === $data || ! Token::fresh( $data['t'], time(), 1, 3600 ) ) {
			return self::form_html( [], [ 'Die Bestätigung ist abgelaufen oder ungültig. Bitte gib deine Angaben erneut ein.' ] );
		}
		$in = array_merge( [ 'name' => '', 'order' => '', 'email' => '', 'scope' => '' ], $data['p'] );

		$rate_key = 'll16_wd_' . md5( strtolower( (string) $in['email'] ) );
		$count    = (int) get_transient( $rate_key );
		if ( $count >= 5 ) {
			return self::form_html( [], [ 'Zu viele Versuche. Bitte schreibe uns eine E-Mail an ' . (string) Masterdata::get( 'email' ) . '.' ] );
		}
		set_transient( $rate_key, $count + 1, HOUR_IN_SECONDS );

		$received = time();
		$matched  = self::record( $in, $received );
		self::send_mails( $in, $received, $matched );

		$when = wp_date( 'd.m.Y, H:i:s', $received ) . ' Uhr';
		return '<div class="ll16-withdrawal" role="status"><h2>Widerruf eingegangen</h2>'
			. '<p>Dein Widerruf ist am <strong>' . esc_html( $when ) . '</strong> bei uns eingegangen. Wir haben dir eine Bestätigung an <strong>' . esc_html( (string) $in['email'] ) . '</strong> geschickt. Bewahre sie bitte auf.</p>'
			. '<p>Diese Bestätigung bestätigt nur den Eingang deines Widerrufs.</p></div>';
	}

	/**
	 * Speichert den Widerruf an der Bestellung (falls gefunden) oder in der Warteliste.
	 *
	 * @param array<string,mixed> $in
	 */
	private static function record( array $in, int $received ): bool {
		$entry = [
			'received_at' => gmdate( 'c', $received ),
			'name'        => (string) $in['name'],
			'email'       => (string) $in['email'],
			'order_input' => (string) $in['order'],
			'scope'       => (string) $in['scope'],
		];
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( absint( preg_replace( '/\D/', '', (string) $in['order'] ) ) ) : null;
		if ( $order instanceof \WC_Order && strtolower( (string) $order->get_billing_email() ) === strtolower( (string) $in['email'] ) ) {
			$list   = (array) json_decode( (string) $order->get_meta( self::META ), true );
			$list[] = $entry;
			$order->update_meta_data( self::META, wp_json_encode( $list ) );
			$order->add_order_note( sprintf( 'Widerruf online eingegangen am %s (%s). Umfang: %s', wp_date( 'd.m.Y H:i:s', $received ), (string) $in['name'], '' !== $in['scope'] ? (string) $in['scope'] : 'gesamte Bestellung' ) );
			$order->set_status( 'll16-widerruf', 'Widerruf eingegangen.' );
			$order->save();
			return true;
		}
		$open   = (array) get_option( self::UNMATCHED, [] );
		$open[] = $entry;
		update_option( self::UNMATCHED, array_slice( $open, -200 ), false );
		return false;
	}

	/**
	 * @param array<string,mixed> $in
	 */
	private static function send_mails( array $in, int $received, bool $matched ): void {
		$when = wp_date( 'd.m.Y, H:i:s', $received ) . ' Uhr';
		$shop = Masterdata::all();
		$body = self::receipt_text( $in, $when, $shop );
		wp_mail( (string) $in['email'], 'Eingangsbestätigung deines Widerrufs', $body );
		wp_mail(
			(string) $shop['email'],
			'Widerruf eingegangen: Bestellung ' . (string) $in['order'],
			$body . "\n\n" . ( $matched ? 'Bestellung gefunden, Status gesetzt.' : 'ACHTUNG: Bestellung/E-Mail passt nicht zu einer Bestellung. Bitte manuell prüfen (Warteliste im Admin).' )
		);
	}

	/**
	 * Inhalt der Eingangsbestätigung nach § 356a Abs. 4 BGB: Inhalt des Widerrufs sowie Datum und Uhrzeit des Eingangs.
	 *
	 * @param array<string,mixed> $in
	 * @param array<string,mixed> $shop
	 */
	public static function receipt_text( array $in, string $when, array $shop ): string {
		return "Hallo {$in['name']},\n\n"
			. "dein Widerruf ist bei uns eingegangen.\n\n"
			. "Eingang: {$when}\n"
			. "Name: {$in['name']}\n"
			. "Bestellnummer: {$in['order']}\n"
			. 'Umfang: ' . ( '' !== (string) $in['scope'] ? 'Teilwiderruf: ' . $in['scope'] : 'gesamte Bestellung' ) . "\n"
			. "E-Mail für die Bestätigung: {$in['email']}\n\n"
			. "Diese E-Mail bestätigt nur den Eingang deines Widerrufs. Wir melden uns zur Abwicklung (Rücksendung, Erstattung) bei dir.\n\n"
			. "{$shop['name']} – {$shop['trade_name']}\n{$shop['street']}, {$shop['zip']} {$shop['city']}\n{$shop['email']}";
	}
}
