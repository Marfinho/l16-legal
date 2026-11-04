<?php
declare(strict_types=1);

namespace Lindenweg16\Legal\Legal;

/**
 * M2 – Rechtstexte-Engine: feste Slugs, 301-Weiterleitungen, Versionierung,
 * unveränderlicher Snapshot je Bestellung, Platzhalter-Linter beim Veröffentlichen.
 */
final class Module {

	public const SNAPSHOT_META = '_ll16_legal_snapshot';
	public const SNAPSHOT_SLUGS = [ 'agb', 'widerruf', 'datenschutz' ];
	/** Vollständige Überschriften im Vertragstext-Anhang (die Seite selbst heißt kurz „AGB“). */
	public const SNAPSHOT_TITLES = [ 'agb' => 'Allgemeine Geschäftsbedingungen (AGB)', 'widerruf' => 'Widerrufsbelehrung', 'datenschutz' => 'Datenschutzerklärung' ];

	public static function init(): void {
		add_action( 'template_redirect', [ self::class, 'redirects' ], 1 );
		add_action( 'admin_post_ll16_sync_pages', [ self::class, 'handle_sync' ] );
		add_action( 'll16_daily', [ self::class, 'cleanup_attachments' ] );
		add_action( 'woocommerce_checkout_order_created', [ self::class, 'snapshot_order' ] );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ self::class, 'snapshot_order' ] );
		add_filter( 'woocommerce_email_attachments', [ self::class, 'attach_snapshot' ], 10, 4 );
		add_filter( 'wp_insert_post_data', [ self::class, 'lint_on_publish' ], 10, 2 );
		add_action( 'admin_notices', [ self::class, 'lint_notice' ] );
	}

	/* ---------- Weiterleitungen ---------- */

	public static function redirects(): void {
		if ( ! is_404() && ! is_page() ) {
			return;
		}
		$path = trim( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ), '/' ); // phpcs:ignore WordPress.Security
		$last = basename( $path );
		if ( isset( Texts::REDIRECTS[ $last ] ) ) {
			$target = Texts::REDIRECTS[ $last ];
			// Nur weiterleiten, wenn der Zielslug nicht der aktuelle ist und die Zielseite existiert.
			if ( $target !== $last && get_page_by_path( $target ) instanceof \WP_Post ) {
				wp_safe_redirect( home_url( '/' . $target . '/' ), 301 );
				exit;
			}
		}
	}

	/* ---------- Seiten & Versionen ---------- */

	/**
	 * Legt die Rechtsseiten an bzw. aktualisiert sie. Nie automatisch veröffentlichen:
	 * neue Seiten bleiben Entwurf, bis der Linter sauber ist und du sie freigibst.
	 *
	 * @return array<string,string> slug => Ergebnis
	 */
	public static function sync_pages( bool $publish = false ): array {
		$result = [];
		foreach ( array_keys( Texts::PAGES ) as $slug ) {
			$content = Texts::render( $slug );
			$errors  = Linter::check( $content );
			$page    = get_page_by_path( $slug, OBJECT, 'page' );
			$hash    = md5( $content );
			$args    = [
				'post_title'   => Texts::PAGES[ $slug ],
				'post_name'    => $slug,
				'post_type'    => 'page',
				'post_content' => $content,
			];
			if ( $page instanceof \WP_Post ) {
				$args['ID'] = $page->ID;
			} else {
				$args['post_status'] = 'draft';
			}
			if ( $publish && [] === $errors ) {
				$args['post_status'] = 'publish';
			}
			$id = wp_insert_post( $args, true );
			if ( is_wp_error( $id ) ) {
				$result[ $slug ] = 'Fehler: ' . $id->get_error_message();
				continue;
			}
			self::record_version( $slug, $hash );
			if ( [] === $errors ) {
				$result[ $slug ] = 'ok';
			} else {
				$missing         = Texts::missing_fields( $slug );
				$result[ $slug ] = [] !== $missing
					? 'Linter: als Entwurf angelegt, nicht veröffentlicht. In den Stammdaten fehlt: ' . implode( ', ', $missing ) . '. Danach erneut „Seiten erzeugen“.'
					: 'Linter: als Entwurf angelegt, nicht veröffentlicht. ' . implode( '; ', $errors );
			}
		}
		self::ensure_withdrawal_page( $publish );
		return $result;
	}

	public static function ensure_withdrawal_page( bool $publish = false ): void {
		$slug = 'vertrag-widerrufen';
		$page = get_page_by_path( $slug, OBJECT, 'page' );
		$args = [
			'post_title'   => 'Vertrag widerrufen',
			'post_name'    => $slug,
			'post_type'    => 'page',
			'post_content' => '[ll16_widerruf]',
		];
		if ( $page instanceof \WP_Post ) {
			$args['ID'] = $page->ID;
		} else {
			$args['post_status'] = 'draft';
		}
		if ( $publish ) {
			$args['post_status'] = 'publish';
		}
		wp_insert_post( $args );
	}

	private static function record_version( string $slug, string $hash ): void {
		$versions = (array) get_option( 'll16_text_versions', [] );
		$last     = $versions[ $slug ] ?? [];
		if ( ( $last['hash'] ?? '' ) === $hash ) {
			return;
		}
		$versions[ $slug ] = [
			'hash'    => $hash,
			'version' => (int) ( $last['version'] ?? 0 ) + 1,
			'date'    => gmdate( 'c' ),
		];
		update_option( 'll16_text_versions', $versions, false );
	}

	public static function handle_sync(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'll16-legal' ), 403 );
		}
		check_admin_referer( 'll16_sync_pages' );
		$publish = ! empty( $_POST['publish'] );
		$res     = self::sync_pages( $publish );
		set_transient( 'll16_sync_result_' . get_current_user_id(), $res, 120 );
		wp_safe_redirect( admin_url( 'admin.php?page=ll16-legal&tab=texts' ) );
		exit;
	}

	/* ---------- Linter beim Veröffentlichen ---------- */

	/**
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $postarr
	 * @return array<string,mixed>
	 */
	public static function lint_on_publish( array $data, array $postarr ): array {
		if ( 'page' !== ( $data['post_type'] ?? '' ) || 'publish' !== ( $data['post_status'] ?? '' ) ) {
			return $data;
		}
		if ( ! isset( Texts::PAGES[ (string) ( $data['post_name'] ?? '' ) ] ) ) {
			return $data;
		}
		$errors = Linter::check( (string) $data['post_content'] );
		if ( [] !== $errors ) {
			$data['post_status'] = 'draft';
			set_transient( 'll16_lint_' . get_current_user_id(), $errors, 60 );
		}
		return $data;
	}

	public static function lint_notice(): void {
		$errors = get_transient( 'll16_lint_' . get_current_user_id() );
		if ( ! is_array( $errors ) ) {
			return;
		}
		delete_transient( 'll16_lint_' . get_current_user_id() );
		printf( '<div class="notice notice-error"><p><strong>Veröffentlichung blockiert (Rechtstext-Linter):</strong> %s</p></div>', esc_html( implode( '; ', $errors ) ) );
	}

	/* ---------- Bestell-Snapshot ---------- */

	/**
	 * @param \WC_Order|int $order
	 */
	public static function snapshot_order( $order ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order );
		if ( ! $order || $order->get_meta( self::SNAPSHOT_META ) ) {
			return; // einmal gesetzt, nie überschrieben
		}
		$snap = [ 'created' => gmdate( 'c' ), 'texts' => [] ];
		foreach ( self::SNAPSHOT_SLUGS as $slug ) {
			$html                    = Texts::render( $slug );
			$snap['texts'][ $slug ] = [
				'title'   => self::SNAPSHOT_TITLES[ $slug ] ?? Texts::PAGES[ $slug ],
				'html'    => $html,
				'hash'    => md5( $html ),
				'version' => (int) ( (array) get_option( 'll16_text_versions', [] )[ $slug ]['version'] ?? 0 ),
			];
		}
		$order->update_meta_data( self::SNAPSHOT_META, wp_json_encode( $snap ) );
		$order->save();
	}

	/**
	 * Hängt den Snapshot als HTML-Datei an die Bestellbestätigung (Textform / dauerhafter Datenträger).
	 *
	 * @param string[]   $attachments
	 * @param string     $email_id
	 * @param mixed      $object
	 * @param mixed|null $email
	 * @return string[]
	 */
	public static function attach_snapshot( $attachments, $email_id, $object, $email = null ) {
		if ( ! in_array( $email_id, [ 'customer_processing_order', 'customer_on_hold_order' ], true ) || ! $object instanceof \WC_Order ) {
			return $attachments;
		}
		$raw = $object->get_meta( self::SNAPSHOT_META );
		$snap = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $snap ) ) {
			return $attachments;
		}
		$html = '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>Vertragsbedingungen Bestellung ' . esc_html( $object->get_order_number() ) . '</title></head><body>';
		foreach ( (array) $snap['texts'] as $t ) {
			$html .= '<h1>' . esc_html( (string) $t['title'] ) . '</h1>' . wp_kses_post( (string) $t['html'] );
		}
		$html .= '<hr><p>Stand bei Bestellung: ' . esc_html( (string) $snap['created'] ) . '</p></body></html>';
		$dir = trailingslashit( get_temp_dir() );
		self::cleanup_attachments( $dir );
		$file = $dir . 'vertragsbedingungen-' . sanitize_file_name( (string) $object->get_order_number() ) . '.html';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false !== file_put_contents( $file, $html ) ) {
			$attachments[] = $file;
		}
		return $attachments;
	}

	/** Löscht übrig gebliebene Anhänge (E-Mail-Versand kann verzögert laufen, daher erst nach einer Stunde). */
	public static function cleanup_attachments( ?string $dir = null ): void {
		$dir = $dir ?: trailingslashit( get_temp_dir() );
		foreach ( (array) glob( $dir . 'vertragsbedingungen-*.html' ) as $f ) {
			if ( is_file( $f ) && filemtime( $f ) < time() - HOUR_IN_SECONDS ) {
				@unlink( $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
	}
}
