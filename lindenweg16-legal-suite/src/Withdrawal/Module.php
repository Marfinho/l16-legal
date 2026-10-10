<?php
declare(strict_types=1);

namespace Lindenweg16\Legal\Withdrawal;

use Lindenweg16\Legal\Core\Assets;
use Lindenweg16\Legal\Core\Masterdata;
use Lindenweg16\Legal\Core\Ratelimit;

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
			if ( 'edit' === $step ) {
				return self::step_edit();
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
		Assets::force_forms();
		$h  = '<div class="ll16-withdrawal">';
		$h .= '<p>Hier kannst du deinen Vertrag widerrufen – ohne Konto und ohne Angabe von Gründen. Du brauchst nur deinen Namen, die Bestellnummer (steht in deiner Bestellbestätigung) und eine E-Mail-Adresse für die Bestätigung. Im nächsten Schritt prüfst du deine Angaben und bestätigst den Widerruf.</p>';
		if ( $errors ) {
			$h .= '<div role="alert" class="ll16-error" tabindex="-1" id="ll16-errors"><ul style="margin:0;padding-left:1.1rem">';
			foreach ( $errors as $e ) {
				$h .= '<li>' . esc_html( $e ) . '</li>';
			}
			$h .= '</ul></div><script>document.getElementById("ll16-errors")&&document.getElementById("ll16-errors").focus();</script>';
		}
		$h .= '<form method="post" class="ll16-form" action="' . esc_url( home_url( '/vertrag-widerrufen/' ) ) . '">';
		$h .= '<input type="hidden" name="ll16_step" value="review">';
		$h .= '<input type="hidden" name="ll16_ts" value="' . esc_attr( Token::sign( [], $ts, self::secret() ) ) . '">';
		$h .= '<p><label for="ll16_name">Dein Name *</label><input type="text" id="ll16_name" name="ll16_name" required maxlength="120" autocomplete="name" value="' . esc_attr( $v['name'] ) . '"></p>';
		$h .= '<p><label for="ll16_order">Bestellnummer (Vertragskennung) *</label><input type="text" id="ll16_order" name="ll16_order" required maxlength="40" inputmode="numeric" autocomplete="off" aria-describedby="ll16_order_hint" value="' . esc_attr( $v['order'] ) . '"><span class="ll16-hint" id="ll16_order_hint">Zum Beispiel „1234“ oder „#1234“ aus deiner Bestellbestätigung.</span></p>';
		$h .= '<p><label for="ll16_email">E-Mail-Adresse für die Bestätigung *</label><input type="email" id="ll16_email" name="ll16_email" required maxlength="190" autocomplete="email" aria-describedby="ll16_email_hint" value="' . esc_attr( $v['email'] ) . '"><span class="ll16-hint" id="ll16_email_hint">An diese Adresse schicken wir die Eingangsbestätigung. Am besten die Adresse deiner Bestellung.</span></p>';
		$h .= '<p><label for="ll16_scope">Nur einen Teil widerrufen? (optional)</label><input type="text" id="ll16_scope" name="ll16_scope" maxlength="300" aria-describedby="ll16_scope_hint" value="' . esc_attr( $v['scope'] ) . '"><span class="ll16-hint" id="ll16_scope_hint">Welche Artikel? Leer lassen, um die gesamte Bestellung zu widerrufen.</span></p>';
		$h .= '<div style="position:absolute;left:-9999px" aria-hidden="true"><label>Bitte leer lassen<input type="text" name="ll16_website" tabindex="-1" autocomplete="off"></label></div>';
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
			'name'  => mb_substr( sanitize_text_field( wp_unslash( $_POST['ll16_name'] ?? '' ) ), 0, 120 ),
			'order' => mb_substr( sanitize_text_field( wp_unslash( $_POST['ll16_order'] ?? '' ) ), 0, 40 ),
			'email' => substr( sanitize_email( wp_unslash( $_POST['ll16_email'] ?? '' ) ), 0, 190 ),
			'scope' => mb_substr( sanitize_text_field( wp_unslash( $_POST['ll16_scope'] ?? '' ) ), 0, 300 ),
		];
		// phpcs:enable
	}

	/** Entfernt „#“, „WC-“, Leerzeichen; übrig bleiben Ziffern. */
	public static function normalize_order_number( string $raw ): string {
		return (string) preg_replace( '/\D+/', '', $raw );
	}

	private static function step_review(): string {
		$in = self::read_input();
		if ( '' !== (string) ( $_POST['ll16_website'] ?? '' ) ) { // phpcs:ignore WordPress.Security
			return self::form_html( $in, [ 'Die Anfrage konnte nicht verarbeitet werden.' ] );
		}
		$errors = [];
		if ( '' === $in['name'] ) {
			$errors[] = 'Bitte gib deinen Namen an.';
		}
		if ( '' === self::normalize_order_number( $in['order'] ) ) {
			$errors[] = 'Bitte gib die Bestellnummer an (nur Ziffern, z. B. 1234).';
		}
		if ( ! is_email( $in['email'] ) ) {
			$errors[] = 'Bitte gib eine gültige E-Mail-Adresse an.';
		}
		if ( $errors ) {
			return self::form_html( $in, $errors );
		}
		$issued = Token::verify( (string) ( $_POST['ll16_ts'] ?? '' ), self::secret() ); // phpcs:ignore WordPress.Security
		if ( null === $issued ) {
			return self::form_html( $in, [ 'Die Seite konnte nicht geprüft werden. Bitte sende das Formular noch einmal ab.' ] );
		}
		$age = time() - $issued['t'];
		if ( $age < 2 ) {
			return self::form_html( $in, [ 'Das ging sehr schnell – bitte prüfe deine Angaben und sende noch einmal ab.' ] );
		}
		if ( $age > 7200 ) {
			return self::form_html( $in, [ 'Die Seite war lange geöffnet. Deine Angaben sind noch eingetragen – bitte sende das Formular noch einmal ab.' ] );
		}
		Assets::force_forms();
		$token = Token::sign( $in, time(), self::secret() );
		$h     = '<div class="ll16-withdrawal"><h2>Widerruf prüfen und bestätigen</h2>';
		$h    .= '<p>Bitte prüfe deine Angaben. <strong>Erst mit Klick auf „Widerruf bestätigen“ wird der Widerruf an uns übermittelt.</strong></p><dl class="ll16-summary">';
		$h    .= '<dt>Name</dt><dd>' . esc_html( $in['name'] ) . '</dd>';
		$h    .= '<dt>Bestellnummer</dt><dd>' . esc_html( $in['order'] ) . '</dd>';
		$h    .= '<dt>E-Mail für die Bestätigung</dt><dd>' . esc_html( $in['email'] ) . '</dd>';
		$h    .= '<dt>Umfang</dt><dd>' . esc_html( '' !== $in['scope'] ? 'Teilwiderruf: ' . $in['scope'] : 'Gesamte Bestellung' ) . '</dd></dl>';
		$h    .= '<div class="ll16-actions-row">';
		$h    .= '<form method="post" class="ll16-form" style="margin:0" action="' . esc_url( home_url( '/vertrag-widerrufen/' ) ) . '"><input type="hidden" name="ll16_step" value="confirm"><input type="hidden" name="ll16_data" value="' . esc_attr( $token ) . '"><button type="submit" class="button wp-element-button">Widerruf bestätigen</button></form>';
		$h    .= '<form method="post" class="ll16-form" style="margin:0" action="' . esc_url( home_url( '/vertrag-widerrufen/' ) ) . '"><input type="hidden" name="ll16_step" value="edit"><input type="hidden" name="ll16_data" value="' . esc_attr( $token ) . '"><button type="submit" class="button wp-element-button is-style-outline">Angaben ändern</button></form>';
		$h    .= '</div></div>';
		return $h;
	}

	/** „Angaben ändern“: Formular mit den bisherigen Werten (aus dem signierten Token). */
	private static function step_edit(): string {
		$data = Token::verify( (string) ( $_POST['ll16_data'] ?? '' ), self::secret() ); // phpcs:ignore WordPress.Security
		return self::form_html( null === $data ? [] : array_map( 'strval', $data['p'] ), [] );
	}

	private static function step_confirm(): string {
		$data = Token::verify( (string) ( $_POST['ll16_data'] ?? '' ), self::secret() ); // phpcs:ignore WordPress.Security
		if ( null === $data ) {
			return self::form_html( [], [ 'Die Bestätigung ist ungültig. Bitte gib deine Angaben noch einmal ein.' ] );
		}
		$in = array_merge( [ 'name' => '', 'order' => '', 'email' => '', 'scope' => '' ], array_map( 'strval', $data['p'] ) );
		if ( ! Token::fresh( $data['t'], time(), 1, 3600 ) ) {
			$too_old = time() - $data['t'] > 3600;
			return self::form_html( $in, [ $too_old ? 'Die Bestätigung ist abgelaufen. Deine Angaben sind noch eingetragen – bitte sende sie noch einmal ab.' : 'Bitte bestätige noch einmal.' ] );
		}

		// Doppelklick/Reload/Zurück-Button: derselbe Token wird nur einmal verarbeitet.
		$done_key = 'll16_wd_done_' . md5( (string) $_POST['ll16_data'] ); // phpcs:ignore WordPress.Security
		$done     = (int) get_transient( $done_key ); // Optionen werden als String gespeichert
		if ( $done > 0 ) {
			return self::confirmation_html( $in, $done, true );
		}

		if ( ! Ratelimit::hit( 'wd-ip', Ratelimit::client_key(), 15, HOUR_IN_SECONDS ) || ! Ratelimit::hit( 'wd-all', 'all', 300, DAY_IN_SECONDS ) ) {
			return self::form_html( $in, [ 'Gerade sind zu viele Anfragen eingegangen. Bitte versuche es später noch einmal oder schreibe uns eine E-Mail an ' . (string) Masterdata::get( 'email' ) . '.' ] );
		}
		if ( ! Ratelimit::hit( 'wd-mail', strtolower( (string) $in['email'] ), 5, HOUR_IN_SECONDS ) ) {
			return self::form_html( $in, [ 'Zu viele Versuche mit dieser E-Mail-Adresse. Bitte schreibe uns eine E-Mail an ' . (string) Masterdata::get( 'email' ) . '.' ] );
		}

		$received = time();
		set_transient( $done_key, $received, DAY_IN_SECONDS );
		$info     = self::record( $in, $received );
		self::send_mails( $in, $received, $info );
		return self::confirmation_html( $in, $received, false );
	}

	/**
	 * @param array<string,mixed> $in
	 */
	private static function confirmation_html( array $in, int $received, bool $again ): string {
		Assets::force_forms();
		$when = wp_date( 'd.m.Y, H:i:s', $received ) . ' Uhr';
		return '<div class="ll16-withdrawal ll16-ok" role="status"><h2>Widerruf eingegangen</h2>'
			. '<p>Dein Widerruf ist am <strong>' . esc_html( $when ) . '</strong> bei uns eingegangen.' . ( $again ? ' (Du hast die Seite erneut aufgerufen – es wurde nichts doppelt erfasst.)' : '' ) . '</p>'
			. '<p>Wir haben dir eine Bestätigung an <strong>' . esc_html( (string) $in['email'] ) . '</strong> geschickt. Bitte bewahre sie auf. Schau auch im Spam-Ordner nach, falls sie nicht ankommt.</p>'
			. '<p>Diese Bestätigung bestätigt nur den Eingang deines Widerrufs. Wir melden uns bei dir zur Abwicklung (Rücksendung, Erstattung).</p></div>';
	}

	/** Bestellstatus, die wir nicht überschreiben (Vorgang ist bereits abgeschlossen/erstattet). */
	public const KEEP_STATUS = [ 'cancelled', 'refunded', 'failed', 'trash', 'll16-widerruf' ];

	/**
	 * Speichert den Widerruf an der Bestellung (falls gefunden) oder in der Warteliste.
	 *
	 * @param array<string,mixed> $in
	 * @return array{matched:bool,order_date:string,days:int,status:string}
	 */
	private static function record( array $in, int $received ): array {
		$entry = [
			'received_at' => gmdate( 'c', $received ),
			'name'        => (string) $in['name'],
			'email'       => (string) $in['email'],
			'order_input' => (string) $in['order'],
			'scope'       => (string) $in['scope'],
		];
		$info  = [ 'matched' => false, 'order_date' => '', 'days' => 0, 'status' => '' ];
		$id    = (int) self::normalize_order_number( (string) $in['order'] );
		$order = ( $id > 0 && function_exists( 'wc_get_order' ) ) ? wc_get_order( $id ) : null;
		if ( $order instanceof \WC_Order && strtolower( trim( (string) $order->get_billing_email() ) ) === strtolower( trim( (string) $in['email'] ) ) ) {
			$list   = (array) json_decode( (string) $order->get_meta( self::META ), true );
			$list[] = $entry;
			$order->update_meta_data( self::META, wp_json_encode( $list ) );
			$status = $order->get_status();
			$note   = sprintf( 'Widerruf online eingegangen am %s (%s). Umfang: %s', wp_date( 'd.m.Y H:i:s', $received ), (string) $in['name'], '' !== $in['scope'] ? (string) $in['scope'] : 'gesamte Bestellung' );
			if ( in_array( $status, self::KEEP_STATUS, true ) ) {
				$note .= sprintf( ' Status „%s“ bleibt unverändert.', wc_get_order_status_name( $status ) );
			} else {
				$order->set_status( 'll16-widerruf', 'Widerruf eingegangen.' );
			}
			$order->add_order_note( $note );
			$order->save();
			$created = $order->get_date_created();
			$info    = [
				'matched'    => true,
				'order_date' => $created ? $created->date_i18n( 'd.m.Y' ) : '',
				'days'       => $created ? (int) floor( ( $received - $created->getTimestamp() ) / DAY_IN_SECONDS ) : 0,
				'status'     => wc_get_order_status_name( $status ),
			];
			return $info;
		}
		$open   = (array) get_option( self::UNMATCHED, [] );
		$open[] = $entry;
		update_option( self::UNMATCHED, array_slice( $open, -200 ), false );
		return $info;
	}

	/**
	 * @param array<string,mixed>                                                 $in
	 * @param array{matched:bool,order_date:string,days:int,status:string} $info
	 */
	private static function send_mails( array $in, int $received, array $info ): void {
		$when = wp_date( 'd.m.Y, H:i:s', $received ) . ' Uhr';
		$shop = Masterdata::all();
		$body = self::receipt_text( $in, $when, $shop );
		$sent = wp_mail( (string) $in['email'], 'Eingangsbestätigung deines Widerrufs', $body );
		$extra = $info['matched']
			? sprintf( "Bestellung gefunden, Status gesetzt (vorher: %s). Bestellt am %s (vor %d Tagen). Widerrufsfrist %d Tage ab Erhalt der Ware: Lieferdatum prüfen.", $info['status'], $info['order_date'], $info['days'], (int) $shop['withdrawal_days'] )
			: 'ACHTUNG: Bestellung/E-Mail passt nicht zu einer Bestellung. Bitte manuell prüfen (Warteliste im Admin).';
		if ( ! $sent ) {
			$extra .= "\n\nACHTUNG: Die Eingangsbestätigung an den Verbraucher konnte NICHT versendet werden. Bitte manuell bestätigen: " . (string) $in['email'];
		}
		wp_mail( (string) $shop['email'], 'Widerruf eingegangen: Bestellung ' . (string) $in['order'], $body . "\n\n" . $extra );
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
