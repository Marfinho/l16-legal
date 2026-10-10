<?php
declare(strict_types=1);

namespace Lindenweg16\Legal\Forms;

use Lindenweg16\Legal\Core\Masterdata;
use Lindenweg16\Legal\Withdrawal\Token;

/**
 * M6 – Kontaktformular, Newsletter (Double-Opt-in), Verfügbarkeitsmeldung, DSGVO-Werkzeuge.
 * Honeypot + Zeitprüfung statt reCAPTCHA. Versand über wp_mail (Mailserver des Hosters).
 */
final class Module {

	public const CONSENT_TEXT_VERSION = 1;

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$wpdb->prefix}ll16_subscribers (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			email varchar(190) NOT NULL,
			token_hash char(64) NOT NULL,
			status varchar(12) NOT NULL,
			text_version smallint(5) unsigned NOT NULL,
			created_at datetime NOT NULL,
			confirmed_at datetime NULL,
			PRIMARY KEY  (id), UNIQUE KEY email (email), KEY status (status)
		) {$c};" );
		dbDelta( "CREATE TABLE {$wpdb->prefix}ll16_contact (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			name varchar(120) NOT NULL,
			email varchar(190) NOT NULL,
			message text NOT NULL,
			PRIMARY KEY  (id), KEY created_at (created_at)
		) {$c};" );
		dbDelta( "CREATE TABLE {$wpdb->prefix}ll16_notify (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			product_id bigint(20) unsigned NOT NULL,
			email varchar(190) NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id), UNIQUE KEY pe (product_id,email)
		) {$c};" );
	}

	public static function init(): void {
		self::install_once();
		add_shortcode( 'll16_contact', [ self::class, 'contact_form' ] );
		add_shortcode( 'll16_newsletter', [ self::class, 'newsletter_form' ] );
		add_shortcode( 'll16_notify', [ self::class, 'notify_form' ] );
		add_action( 'template_redirect', [ self::class, 'handle_confirm_link' ] );
		add_action( 'll16_daily', [ self::class, 'purge' ] );
		add_action( 'woocommerce_product_set_stock_status', [ self::class, 'on_stock' ], 10, 3 );
		add_filter( 'wp_privacy_personal_data_exporters', [ self::class, 'exporters' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ self::class, 'erasers' ] );
	}

	private static function install_once(): void {
		if ( get_option( 'll16_forms_db' ) !== LL16_VERSION ) {
			self::install();
			update_option( 'll16_forms_db', LL16_VERSION );
		}
	}

	private static function secret(): string {
		return wp_salt( 'auth' ) . 'll16-forms';
	}

	private static function guard(): ?string {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( '' !== (string) ( $_POST['ll16_website'] ?? '' ) ) {
			return 'Die Anfrage konnte nicht verarbeitet werden.';
		}
		$t = Token::verify( (string) ( $_POST['ll16_ts'] ?? '' ), self::secret() );
		if ( null === $t || ! Token::fresh( $t['t'], time(), 3, 7200 ) ) {
			return 'Bitte sende das Formular erneut ab.';
		}
		// phpcs:enable
		return null;
	}

	private static function hidden(): string {
		return '<input type="hidden" name="ll16_ts" value="' . esc_attr( Token::sign( [], time(), self::secret() ) ) . '">'
			. '<div style="position:absolute;left:-9999px" aria-hidden="true"><label>Bitte leer lassen<input type="text" name="ll16_website" tabindex="-1" autocomplete="off"></label></div>';
	}

	private static function posted( string $form ): bool {
		return 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && $form === ( $_POST['ll16_form'] ?? '' ); // phpcs:ignore WordPress.Security
	}

	private static function nocache(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		do_action( 'litespeed_control_set_nocache', 'll16 form' );
	}

	/* ---------- Kontakt ---------- */

	public static function contact_form(): string {
		self::nocache();
		$msg = '';
		if ( self::posted( 'contact' ) ) {
			$err = self::guard();
			// phpcs:disable WordPress.Security.NonceVerification
			$name  = sanitize_text_field( wp_unslash( $_POST['ll16_name'] ?? '' ) );
			$email = sanitize_email( wp_unslash( $_POST['ll16_email'] ?? '' ) );
			$text  = sanitize_textarea_field( wp_unslash( $_POST['ll16_message'] ?? '' ) );
			$ok    = ! empty( $_POST['ll16_privacy'] );
			// phpcs:enable
			if ( null === $err && ( '' === $name || ! is_email( $email ) || '' === $text ) ) {
				$err = 'Bitte fülle alle Pflichtfelder aus.';
			}
			if ( null === $err && ! $ok ) {
				$err = 'Bitte bestätige den Datenschutzhinweis.';
			}
			if ( null === $err ) {
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->insert( $wpdb->prefix . 'll16_contact', [ 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'name' => $name, 'email' => $email, 'message' => $text ] );
				wp_mail( (string) Masterdata::get( 'email' ), 'Kontaktanfrage von ' . $name, "Von: {$name} <{$email}>\n\n{$text}", [ 'Reply-To: ' . $email ] );
				return '<p role="status">Danke, deine Nachricht ist bei uns angekommen.</p>';
			}
			$msg = '<p role="alert"><strong>' . esc_html( $err ) . '</strong></p>';
		}
		$privacy = esc_url( home_url( '/datenschutz/' ) );
		return $msg . '<form method="post" class="ll16-form">'
			. '<input type="hidden" name="ll16_form" value="contact">' . self::hidden()
			. '<p><label>Name *<br><input type="text" name="ll16_name" required autocomplete="name"></label></p>'
			. '<p><label>E-Mail *<br><input type="email" name="ll16_email" required autocomplete="email"></label></p>'
			. '<p><label>Nachricht *<br><textarea name="ll16_message" rows="6" required></textarea></label></p>'
			. '<p><label><input type="checkbox" name="ll16_privacy" value="1" required> Ich habe die <a href="' . $privacy . '" target="_blank" rel="noopener">Datenschutzerklärung</a> gelesen. Meine Angaben werden zur Bearbeitung meiner Anfrage verarbeitet und nach ' . (int) ( ( (array) get_option( 'll16_settings', [] ) )['contact_retention_days'] ?? 90 ) . ' Tagen gelöscht.</label></p>'
			. '<p><button type="submit" class="wp-element-button">Absenden</button></p></form>';
	}

	/* ---------- Newsletter (Double-Opt-in) ---------- */

	public static function newsletter_form(): string {
		self::nocache();
		$msg = '';
		if ( self::posted( 'newsletter' ) ) {
			$err   = self::guard();
			$email = sanitize_email( wp_unslash( $_POST['ll16_email'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
			if ( null === $err && ( ! is_email( $email ) || empty( $_POST['ll16_consent'] ) ) ) { // phpcs:ignore WordPress.Security
				$err = 'Bitte gib eine gültige E-Mail-Adresse an und stimme zu.';
			}
			if ( null === $err ) {
				self::subscribe( $email );
				return '<p role="status">Fast geschafft: Wir haben dir eine E-Mail mit einem Bestätigungslink geschickt.</p>';
			}
			$msg = '<p role="alert"><strong>' . esc_html( $err ) . '</strong></p>';
		}
		return $msg . '<form method="post" class="ll16-form">'
			. '<input type="hidden" name="ll16_form" value="newsletter">' . self::hidden()
			. '<p><label>E-Mail *<br><input type="email" name="ll16_email" required autocomplete="email"></label></p>'
			. '<p><label><input type="checkbox" name="ll16_consent" value="1" required> Ich möchte den Newsletter erhalten und stimme der Verarbeitung meiner E-Mail-Adresse dafür zu (Double-Opt-in, jederzeit per Link in jeder Mail widerrufbar). Mehr in der <a href="' . esc_url( home_url( '/datenschutz/' ) ) . '">Datenschutzerklärung</a>.</label></p>'
			. '<p><button type="submit" class="wp-element-button">Anmelden</button></p></form>';
	}

	public static function subscribe( string $email ): void {
		global $wpdb;
		$t     = $wpdb->prefix . 'll16_subscribers';
		$token = bin2hex( random_bytes( 24 ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM {$t} WHERE email = %s", $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $row && 'confirmed' === $row->status ) {
			return; // keine Auskunft, ob bereits angemeldet
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->replace( $t, [ 'email' => $email, 'token_hash' => hash( 'sha256', $token ), 'status' => 'pending', 'text_version' => self::CONSENT_TEXT_VERSION, 'created_at' => gmdate( 'Y-m-d H:i:s' ) ] );
		$url = add_query_arg( [ 'll16_nl' => 'confirm', 'e' => rawurlencode( $email ), 't' => $token ], home_url( '/' ) );
		wp_mail( $email, 'Bitte bestätige deine Newsletter-Anmeldung', "Hallo,\n\nbitte bestätige deine Anmeldung zum Newsletter von Lindenweg16 mit diesem Link:\n{$url}\n\nWenn du dich nicht angemeldet hast, ignoriere diese E-Mail. Es wird dann nichts gespeichert, was über 48 Stunden hinausgeht." );
	}

	public static function unsubscribe_url( string $email ): string {
		return add_query_arg( [ 'll16_nl' => 'unsub', 'e' => rawurlencode( $email ), 's' => hash_hmac( 'sha256', $email, self::secret() ) ], home_url( '/' ) );
	}

	public static function handle_confirm_link(): void {
		// phpcs:disable WordPress.Security.NonceVerification
		$action = sanitize_key( $_GET['ll16_nl'] ?? '' );
		if ( '' === $action ) {
			return;
		}
		global $wpdb;
		$t     = $wpdb->prefix . 'll16_subscribers';
		$email = sanitize_email( rawurldecode( (string) ( $_GET['e'] ?? '' ) ) );
		$msg   = 'Der Link ist ungültig oder abgelaufen.';
		if ( 'confirm' === $action && is_email( $email ) ) {
			$hash = hash( 'sha256', (string) ( $_GET['t'] ?? '' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$n = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status='confirmed', confirmed_at=%s WHERE email=%s AND token_hash=%s AND status='pending'", gmdate( 'Y-m-d H:i:s' ), $email, $hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$msg = $n ? 'Danke, deine Anmeldung ist bestätigt.' : $msg;
		} elseif ( 'unsub' === $action && is_email( $email ) && hash_equals( hash_hmac( 'sha256', $email, self::secret() ), (string) ( $_GET['s'] ?? '' ) ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( $t, [ 'email' => $email ] );
			$msg = 'Du bist abgemeldet und deine Adresse ist gelöscht.';
		}
		// phpcs:enable
		wp_die( esc_html( $msg ), 'Newsletter', [ 'response' => 200, 'back_link' => true ] );
	}

	/* ---------- Verfügbarkeit ---------- */

	public static function notify_form( $atts = [] ): string {
		self::nocache();
		$pid = (int) ( $atts['product'] ?? get_the_ID() );
		if ( self::posted( 'notify' ) ) {
			$err   = self::guard();
			$email = sanitize_email( wp_unslash( $_POST['ll16_email'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
			if ( null === $err && is_email( $email ) && ! empty( $_POST['ll16_consent'] ) ) { // phpcs:ignore WordPress.Security
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->replace( $wpdb->prefix . 'll16_notify', [ 'product_id' => $pid, 'email' => $email, 'created_at' => gmdate( 'Y-m-d H:i:s' ) ] );
				return '<p role="status">Wir benachrichtigen dich einmalig per E-Mail, sobald das Produkt verfügbar ist.</p>';
			}
			return '<p role="alert"><strong>Bitte gib eine gültige E-Mail-Adresse an und stimme zu.</strong></p>';
		}
		return '<form method="post" class="ll16-form"><input type="hidden" name="ll16_form" value="notify">' . self::hidden()
			. '<p><label>E-Mail *<br><input type="email" name="ll16_email" required></label></p>'
			. '<p><label><input type="checkbox" name="ll16_consent" value="1" required> Bitte benachrichtigt mich einmalig, wenn dieses Produkt wieder verfügbar ist. Meine E-Mail-Adresse wird nur dafür verwendet und danach gelöscht.</label></p>'
			. '<p><button type="submit" class="wp-element-button">Benachrichtigen</button></p></form>';
	}

	/**
	 * @param int         $product_id
	 * @param string      $status
	 * @param \WC_Product $product
	 */
	public static function on_stock( $product_id, $status, $product ): void {
		if ( 'instock' !== $status ) {
			return;
		}
		global $wpdb;
		$t = $wpdb->prefix . 'll16_notify';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT email FROM {$t} WHERE product_id = %d", $product_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $rows as $r ) {
			wp_mail( $r->email, $product->get_name() . ' ist wieder verfügbar', 'Das Produkt ist wieder verfügbar: ' . get_permalink( $product_id ) . "\n\nDies ist die einmalige Benachrichtigung. Deine Adresse wurde gelöscht." );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $t, [ 'product_id' => $product_id ] );
	}

	/* ---------- Aufbewahrung ---------- */

	public static function purge(): void {
		global $wpdb;
		$s    = (array) get_option( 'll16_settings', [] );
		$days = max( 7, (int) ( $s['contact_retention_days'] ?? 90 ) );
		// phpcs:disable WordPress.DB
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}ll16_contact WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}ll16_subscribers WHERE status='pending' AND created_at < %s", gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}ll16_notify WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - 365 * DAY_IN_SECONDS ) ) );
		// phpcs:enable
		Retention::anonymize_old_orders();
	}

	/* ---------- WP-Privacy-Tools ---------- */

	/**
	 * @param array<string,array<string,mixed>> $exporters
	 * @return array<string,array<string,mixed>>
	 */
	public static function exporters( $exporters ) {
		$exporters['ll16-legal'] = [ 'exporter_friendly_name' => 'Lindenweg16 (Kontakt, Newsletter, Benachrichtigung)', 'callback' => [ self::class, 'export' ] ];
		return $exporters;
	}

	/**
	 * @param array<string,array<string,mixed>> $erasers
	 * @return array<string,array<string,mixed>>
	 */
	public static function erasers( $erasers ) {
		$erasers['ll16-legal'] = [ 'eraser_friendly_name' => 'Lindenweg16 (Kontakt, Newsletter, Benachrichtigung)', 'callback' => [ self::class, 'erase' ] ];
		return $erasers;
	}

	/**
	 * @return array{data:array<int,array<string,mixed>>,done:bool}
	 */
	public static function export( string $email ): array {
		global $wpdb;
		$data = [];
		// phpcs:disable WordPress.DB
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT created_at,name,message FROM {$wpdb->prefix}ll16_contact WHERE email=%s", $email ) ) as $r ) {
			$data[] = [ 'group_id' => 'll16-contact', 'group_label' => 'Kontaktanfragen', 'item_id' => 'c-' . md5( $r->created_at . $email ), 'data' => [ [ 'name' => 'Zeit (UTC)', 'value' => $r->created_at ], [ 'name' => 'Name', 'value' => $r->name ], [ 'name' => 'Nachricht', 'value' => $r->message ] ] ];
		}
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT status,created_at,confirmed_at FROM {$wpdb->prefix}ll16_subscribers WHERE email=%s", $email ) ) as $r ) {
			$data[] = [ 'group_id' => 'll16-newsletter', 'group_label' => 'Newsletter', 'item_id' => 'n-' . md5( $email ), 'data' => [ [ 'name' => 'Status', 'value' => $r->status ], [ 'name' => 'Angemeldet (UTC)', 'value' => $r->created_at ], [ 'name' => 'Bestätigt (UTC)', 'value' => (string) $r->confirmed_at ] ] ];
		}
		// phpcs:enable
		return [ 'data' => $data, 'done' => true ];
	}

	/**
	 * @return array{items_removed:bool,items_retained:bool,messages:string[],done:bool}
	 */
	public static function erase( string $email ): array {
		global $wpdb;
		$n = 0;
		foreach ( [ 'll16_contact', 'll16_subscribers', 'll16_notify' ] as $tbl ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$n += (int) $wpdb->delete( $wpdb->prefix . $tbl, [ 'email' => $email ] );
		}
		return [ 'items_removed' => $n > 0, 'items_retained' => false, 'messages' => [], 'done' => true ];
	}
}
