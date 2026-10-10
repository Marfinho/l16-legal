<?php
declare(strict_types=1);

namespace Lindenweg16\Legal\Core\Admin;

use Lindenweg16\Legal\Core\Masterdata;
use Lindenweg16\Legal\Core\Modules;
use Lindenweg16\Legal\Core\ServiceRegistry;

/**
 * Admin-Seite „Lindenweg16 Recht": Status, Stammdaten, Module, Dienste, Rechtstexte,
 * Monitor, Einstellungen, Export/Import. Alle Aktionen: Capability + Nonce.
 */
final class Page {

	public const TABS = [
		'status'   => 'Status',
		'master'   => 'Stammdaten',
		'modules'  => 'Module',
		'services' => 'Dienste',
		'texts'    => 'Rechtstexte',
		'monitor'  => 'Monitor',
		'settings' => 'Einstellungen',
		'tools'    => 'Export/Import',
	];

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_post_ll16_save', [ $this, 'save' ] );
		add_action( 'admin_post_ll16_run_monitor', [ $this, 'run_monitor' ] );
		add_action( 'admin_post_ll16_export', [ $this, 'export' ] );
		add_action( 'admin_post_ll16_import', [ $this, 'import' ] );
	}

	public function menu(): void {
		add_menu_page( 'Lindenweg16 Recht', 'Lindenweg16 Recht', 'manage_options', 'll16-legal', [ $this, 'render' ], 'dashicons-shield', 58 );
	}

	private static function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'll16-legal' ), 403 );
		}
		check_admin_referer( $action );
	}

	private static function back( string $tab, string $notice = '' ): void {
		wp_safe_redirect( add_query_arg( [ 'page' => 'll16-legal', 'tab' => $tab, 'll16_notice' => rawurlencode( $notice ) ], admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ---------- Aktionen ---------- */

	public function save(): void {
		self::guard( 'll16_save' );
		$tab = sanitize_key( $_POST['tab'] ?? 'status' ); // phpcs:ignore WordPress.Security.NonceVerification
		// phpcs:disable WordPress.Security.NonceVerification
		switch ( $tab ) {
			case 'master':
				update_option( Masterdata::OPTION, Masterdata::sanitize( wp_unslash( (array) ( $_POST['m'] ?? [] ) ) ) );
				break;
			case 'modules':
				$flags = [];
				foreach ( array_keys( Modules::LABELS ) as $k ) {
					$flags[ $k ] = ! empty( $_POST['mod'][ $k ] );
				}
				update_option( 'll16_modules', $flags );
				break;
			case 'services':
				$over = [];
				foreach ( array_keys( ServiceRegistry::defaults() ) as $id ) {
					$over[ $id ] = [ 'active' => ! empty( $_POST['svc'][ $id ] ) ];
				}
				update_option( ServiceRegistry::OPTION, $over );
				break;
			case 'settings':
				$in = (array) wp_unslash( $_POST['s'] ?? [] );
				update_option(
					'll16_settings',
					[
						'consent_retention_days' => max( 30, (int) ( $in['consent_retention_days'] ?? 1095 ) ),
						'contact_retention_days' => max( 7, (int) ( $in['contact_retention_days'] ?? 90 ) ),
						'hsts_max_age'           => max( 0, (int) ( $in['hsts_max_age'] ?? 604800 ) ),
						'hsts_subdomains'        => ! empty( $in['hsts_subdomains'] ),
						'newsletter_enabled'     => ! empty( $in['newsletter_enabled'] ),
						'notify_enabled'         => ! empty( $in['notify_enabled'] ),
						'retention_anonymize'    => ! empty( $in['retention_anonymize'] ),
						'delete_on_uninstall'    => ! empty( $in['delete_on_uninstall'] ),
						'claims'                 => array_values( array_filter( array_map( 'sanitize_text_field', preg_split( '/\R/', (string) ( $in['claims'] ?? '' ) ) ?: [] ) ) ),
					]
				);
				update_option( 'll16_warranty_notice', sanitize_textarea_field( (string) ( $in['warranty'] ?? '' ) ), false );
				break;
		}
		// phpcs:enable
		self::back( $tab, 'Gespeichert.' );
	}

	public function run_monitor(): void {
		self::guard( 'll16_run_monitor' );
		\Lindenweg16\Legal\Monitor\Module::run();
		self::back( 'monitor', 'Prüfung abgeschlossen.' );
	}

	public function export(): void {
		self::guard( 'll16_export' );
		$data = [
			'plugin'     => 'lindenweg16-legal-suite',
			'version'    => LL16_VERSION,
			'masterdata' => Masterdata::all(),
			'modules'    => Modules::flags(),
			'services'   => get_option( ServiceRegistry::OPTION, [] ),
			'settings'   => get_option( 'll16_settings', [] ),
		];
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ll16-config.json"' );
		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	public function import(): void {
		self::guard( 'll16_import' );
		$raw  = isset( $_FILES['config']['tmp_name'] ) && is_uploaded_file( $_FILES['config']['tmp_name'] ) ? (string) file_get_contents( $_FILES['config']['tmp_name'] ) : ''; // phpcs:ignore
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || ( $data['plugin'] ?? '' ) !== 'lindenweg16-legal-suite' ) {
			self::back( 'tools', 'Ungültige Datei.' );
		}
		update_option( Masterdata::OPTION, Masterdata::sanitize( (array) ( $data['masterdata'] ?? [] ) ) );
		$flags = [];
		foreach ( array_keys( Modules::LABELS ) as $k ) {
			$flags[ $k ] = ! empty( $data['modules'][ $k ] );
		}
		update_option( 'll16_modules', $flags );
		$over = [];
		foreach ( array_keys( ServiceRegistry::defaults() ) as $id ) {
			if ( isset( $data['services'][ $id ]['active'] ) ) {
				$over[ $id ] = [ 'active' => (bool) $data['services'][ $id ]['active'] ];
			}
		}
		update_option( ServiceRegistry::OPTION, $over );
		self::back( 'tools', 'Konfiguration importiert.' );
	}

	/* ---------- Anzeige ---------- */

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tab = sanitize_key( $_GET['tab'] ?? 'status' ); // phpcs:ignore WordPress.Security.NonceVerification
		$tab = isset( self::TABS[ $tab ] ) ? $tab : 'status';
		echo '<div class="wrap"><h1>Lindenweg16 Recht</h1>';
		echo '<div class="notice notice-warning inline"><p><strong>Entwurf, nicht anwaltlich geprüft.</strong> Die generierten Rechtstexte ersetzen keine Rechtsberatung.</p></div>';
		if ( ! empty( $_GET['ll16_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['ll16_notice'] ) ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		echo '<h2 class="nav-tab-wrapper">';
		foreach ( self::TABS as $key => $label ) {
			printf( '<a class="nav-tab %s" href="%s">%s</a>', $key === $tab ? 'nav-tab-active' : '', esc_url( add_query_arg( [ 'page' => 'll16-legal', 'tab' => $key ], admin_url( 'admin.php' ) ) ), esc_html( $label ) );
		}
		echo '</h2>';
		$this->{'tab_' . $tab}();
		echo '</div>';
	}

	private function form_open( string $tab ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ll16_save"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
		wp_nonce_field( 'll16_save' );
	}

	private function tab_status(): void {
		$last = get_option( 'll16_last_check' );
		$res  = is_array( $last ) ? (array) $last['results'] : [];
		$sum  = \Lindenweg16\Legal\Monitor\Module::summary( $res );
		$tm   = \Lindenweg16\Legal\Checkout\TestMode::detect();
		echo '<h2>Statusampel</h2>';
		if ( $tm ) {
			echo '<div class="notice notice-error inline"><p><strong>Zahlungs-Testmodus aktiv:</strong> ' . esc_html( implode( ', ', $tm ) ) . '</p></div>';
		}
		if ( ! $res ) {
			echo '<p>Noch keine Prüfung. Starte sie im Tab „Monitor".</p>';
		} else {
			$colour = $sum['fail'] ? '#d63638' : ( $sum['warn'] ? '#dba617' : '#00a32a' );
			printf( '<p><span style="display:inline-block;width:14px;height:14px;border-radius:50%%;background:%s;vertical-align:middle"></span> <strong>%d Fehler, %d Warnungen, %d in Ordnung</strong> (Stand %s UTC)</p>', esc_attr( $colour ), (int) $sum['fail'], (int) $sum['warn'], (int) $sum['ok'], esc_html( (string) $last['time'] ) );
		}
		$legacy = \Lindenweg16\Legal\Gate\Module::active_legacy();
		if ( $legacy ) {
			echo '<h3>Altplugins noch aktiv</h3><ul>';
			foreach ( $legacy as $p ) {
				$l = \Lindenweg16\Legal\Gate\Module::legacy_plugins()[ $p ];
				echo '<li><strong>' . esc_html( $l['name'] ) . '</strong>: ' . esc_html( $l['hint'] ) . '</li>';
			}
			echo '</ul>';
		}
	}

	private function tab_master(): void {
		$m = Masterdata::all();
		$this->form_open( 'master' );
		echo '<table class="form-table">';
		$labels = [
			'name' => 'Name', 'trade_name' => 'Firmierung/Marke', 'street' => 'Straße', 'zip' => 'PLZ', 'city' => 'Ort', 'country' => 'Land',
			'phone' => 'Telefon (eine Nummer)', 'phone_hours' => 'Erreichbarkeit (z. B. Mo–Fr 9–17 Uhr)', 'email' => 'E-Mail', 'vat_id' => 'USt-IdNr. (leer bei Kleinunternehmer)',
			'authority_name' => 'Aufsichtsbehörde', 'authority_url' => 'URL Aufsichtsbehörde', 'adr_body' => 'Schlichtungsstelle (nur wenn bereit)',
			'withdrawal_days' => 'Widerrufsfrist (Tage)', 'return_days' => 'Freiwilliges Rückgaberecht (Tage)', 'shipping_cost' => 'Versandkosten (€)',
			'free_shipping_from' => 'Gratisversand ab (€, leer = nie)', 'delivery_time' => 'Standard-Lieferzeit', 'shipping_carrier' => 'Versanddienstleister (Datenschutz)',
			'hosting_provider' => 'Hoster', 'retention_years' => 'Aufbewahrung Bestelldaten (Jahre)', 'last_review' => 'Letzter Rechts-Review (JJJJ-MM-TT)',
		];
		foreach ( $labels as $k => $label ) {
			printf( '<tr><th><label for="m_%1$s">%2$s</label></th><td><input class="regular-text" id="m_%1$s" name="m[%1$s]" value="%3$s"></td></tr>', esc_attr( $k ), esc_html( $label ), esc_attr( (string) $m[ $k ] ) );
		}
		printf( '<tr><th>Kleinunternehmer (§ 19 UStG)</th><td><label><input type="checkbox" name="m[small_business]" value="1" %s> ja</label></td></tr>', checked( ! empty( $m['small_business'] ), true, false ) );
		printf( '<tr><th>Schlichtung (VSBG)</th><td><label><input type="checkbox" name="m[adr_willing]" value="1" %s> bereit zur Teilnahme</label></td></tr>', checked( ! empty( $m['adr_willing'] ), true, false ) );
		printf( '<tr><th>Lieferländer (ISO, Komma)</th><td><input class="regular-text" name="m[delivery_countries]" value="%s"></td></tr>', esc_attr( implode( ',', (array) $m['delivery_countries'] ) ) );
		printf( '<tr><th>Zahlungsarten (Komma)</th><td><input class="large-text" name="m[payment_methods]" value="%s"><p class="description">z. B. PayPal, Kreditkarte, Apple Pay</p></td></tr>', esc_attr( implode( ', ', (array) $m['payment_methods'] ) ) );
		echo '</table>';
		submit_button();
		echo '</form>';
	}

	private function tab_modules(): void {
		$this->form_open( 'modules' );
		echo '<p>Neue Installation: alles aus. Modulweise auf Staging umschalten (Consent → Rechtstexte → Widerruf → Checkout → Produkte → Formulare → Header).</p><table class="form-table">';
		foreach ( Modules::flags() as $k => $on ) {
			printf( '<tr><th>%s</th><td><label><input type="checkbox" name="mod[%s]" value="1" %s> aktiv</label></td></tr>', esc_html( Modules::LABELS[ $k ] ), esc_attr( $k ), checked( $on, true, false ) );
		}
		echo '</table>';
		submit_button();
		echo '</form>';
	}

	private function tab_services(): void {
		$this->form_open( 'services' );
		echo '<p>Dienste-Register: Quelle für Banner, Cookie-Seite, Datenschutzerklärung und Blockierung. Inaktive Dienste werden nie geladen. Versionsstand: <code>' . esc_html( ServiceRegistry::version( ServiceRegistry::active() ) ) . '</code> (ändert er sich, wird die Einwilligung neu eingeholt).</p>';
		echo '<table class="widefat striped"><thead><tr><th>Aktiv</th><th>Dienst</th><th>Kategorie</th><th>Cookies</th><th>Kontext</th></tr></thead><tbody>';
		foreach ( ServiceRegistry::all() as $id => $s ) {
			printf(
				'<tr><td><input type="checkbox" name="svc[%s]" value="1" %s></td><td><strong>%s</strong><br>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_attr( $id ),
				checked( ! empty( $s['active'] ), true, false ),
				esc_html( (string) $s['name'] ),
				esc_html( (string) $s['purpose'] ),
				esc_html( ServiceRegistry::CATEGORIES[ $s['category'] ] ?? '' ),
				esc_html( implode( ', ', array_map( static fn( $c ) => (string) $c['name'], (array) $s['cookies'] ) ) ),
				esc_html( 'checkout' === $s['context'] ? 'nur Warenkorb/Kasse' : 'überall' )
			);
		}
		echo '</tbody></table>';
		submit_button();
		echo '</form>';
	}

	private function tab_texts(): void {
		$res = get_transient( 'll16_sync_result_' . get_current_user_id() );
		echo '<h2>Rechtstexte (Entwurf, nicht anwaltlich geprüft)</h2>';
		if ( is_array( $res ) ) {
			echo '<ul>';
			foreach ( $res as $slug => $r ) {
				printf( '<li><code>/%s/</code>: %s</li>', esc_html( $slug ), esc_html( (string) $r ) );
			}
			echo '</ul>';
		}
		echo '<p>Erzeugt/aktualisiert die Seiten aus Stammdaten und Dienste-Register. Neue Seiten bleiben Entwurf. Veröffentlichen geht nur, wenn der Platzhalter-Linter sauber ist. Bestehende Seiten gleichen Namens (z. B. <code>vertrag-widerrufen</code>) werden überschrieben.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ll16_sync_pages">';
		wp_nonce_field( 'll16_sync_pages' );
		echo '<p><label><input type="checkbox" name="publish" value="1"> Wenn der Linter sauber ist, direkt veröffentlichen</label></p>';
		submit_button( 'Seiten erzeugen/aktualisieren' );
		echo '</form><h3>Vorschau-Prüfung</h3><table class="widefat"><thead><tr><th>Seite</th><th>Linter</th></tr></thead><tbody>';
		foreach ( \Lindenweg16\Legal\Legal\Texts::PAGES as $slug => $title ) {
			$errors = \Lindenweg16\Legal\Legal\Linter::check( \Lindenweg16\Legal\Legal\Texts::render( $slug ) );
			printf( '<tr><td>%s</td><td>%s</td></tr>', esc_html( $title ), $errors ? '<span style="color:#d63638">' . esc_html( implode( '; ', $errors ) ) . '</span>' : 'sauber' );
		}
		echo '</tbody></table>';
	}

	private function tab_monitor(): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ll16_run_monitor">';
		wp_nonce_field( 'll16_run_monitor' );
		submit_button( 'Prüfung jetzt starten', 'primary', 'submit', false );
		echo '</form>';
		$last = get_option( 'll16_last_check' );
		if ( ! is_array( $last ) ) {
			return;
		}
		echo '<p>Stand: ' . esc_html( (string) $last['time'] ) . ' UTC. CLI: <code>wp ll16-legal check</code></p><table class="widefat striped"><tbody>';
		$icons = [ 'ok' => '✅', 'warn' => '⚠️', 'fail' => '❌' ];
		foreach ( (array) $last['results'] as $r ) {
			printf( '<tr><td>%s</td><td><strong>%s</strong><br>%s</td></tr>', esc_html( $icons[ $r['status'] ] ?? '' ), esc_html( $r['label'] ), esc_html( $r['detail'] ) );
		}
		echo '</tbody></table>';
	}

	private function tab_settings(): void {
		$s = (array) get_option( 'll16_settings', [] );
		$this->form_open( 'settings' );
		echo '<table class="form-table">';
		printf( '<tr><th>Consent-Log Aufbewahrung (Tage)</th><td><input type="number" name="s[consent_retention_days]" value="%d"></td></tr>', (int) ( $s['consent_retention_days'] ?? 1095 ) );
		printf( '<tr><th>Kontaktanfragen Aufbewahrung (Tage)</th><td><input type="number" name="s[contact_retention_days]" value="%d"></td></tr>', (int) ( $s['contact_retention_days'] ?? 90 ) );
		printf( '<tr><th>HSTS max-age (Sekunden)</th><td><input type="number" name="s[hsts_max_age]" value="%d"><p class="description">Start: 604800 (1 Woche). Erst nach Beobachtung auf 31536000 erhöhen.</p></td></tr>', (int) ( $s['hsts_max_age'] ?? 604800 ) );
		printf( '<tr><th>HSTS Subdomains</th><td><label><input type="checkbox" name="s[hsts_subdomains]" value="1" %s> includeSubDomains</label></td></tr>', checked( ! empty( $s['hsts_subdomains'] ), true, false ) );
		printf( '<tr><th>Newsletter aktiv</th><td><label><input type="checkbox" name="s[newsletter_enabled]" value="1" %s> (erscheint dann in der Datenschutzerklärung)</label></td></tr>', checked( ! empty( $s['newsletter_enabled'] ), true, false ) );
		printf( '<tr><th>„Benachrichtige mich" aktiv</th><td><label><input type="checkbox" name="s[notify_enabled]" value="1" %s></label></td></tr>', checked( ! empty( $s['notify_enabled'] ), true, false ) );
		printf( '<tr><th>Bestelldaten nach Frist anonymisieren</th><td><label><input type="checkbox" name="s[retention_anonymize]" value="1" %s> unwiderruflich! Erst nach Abstimmung mit Steuerberater einschalten.</label></td></tr>', checked( ! empty( $s['retention_anonymize'] ), true, false ) );
		printf( '<tr><th>Daten bei Deinstallation löschen</th><td><label><input type="checkbox" name="s[delete_on_uninstall]" value="1" %s> ja</label></td></tr>', checked( ! empty( $s['delete_on_uninstall'] ), true, false ) );
		printf( '<tr><th>Warnliste Werbeaussagen (eine je Zeile)</th><td><textarea name="s[claims]" rows="6" class="large-text">%s</textarea></td></tr>', esc_textarea( implode( "\n", ! empty( $s['claims'] ) ? (array) $s['claims'] : \Lindenweg16\Legal\Product\Module::DEFAULT_CLAIMS ) ) );
		printf( '<tr><th>Gewährleistungshinweis (DVO (EU) 2025/1960)</th><td><textarea name="s[warranty]" rows="4" class="large-text">%s</textarea><p class="description">Verbindlichen Wortlaut der Kommissionsvorlage prüfen und hier einsetzen.</p></td></tr>', esc_textarea( \Lindenweg16\Legal\Product\Module::warranty_text() ) );
		echo '</table>';
		submit_button();
		echo '</form>';
	}

	private function tab_tools(): void {
		echo '<h2>Export</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ll16_export">';
		wp_nonce_field( 'll16_export' );
		submit_button( 'Konfiguration als JSON exportieren', 'secondary' );
		echo '</form><h2>Import</h2><form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ll16_import">';
		wp_nonce_field( 'll16_import' );
		echo '<input type="file" name="config" accept="application/json" required>';
		submit_button( 'Importieren', 'secondary' );
		echo '</form>';
	}
}
