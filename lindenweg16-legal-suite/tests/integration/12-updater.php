<?php
require __DIR__ . '/lib.php';
use Lindenweg16\Legal\Core\GithubUpdater as U;

require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
$mock = (string) getenv( 'MOCK_DIR' );
$base = U::api_base();
$plugin_file = plugin_basename( LL16_FILE );
$GLOBALS['base'] = $base; // eval-file läuft in einer Funktion: Helper greifen über $GLOBALS zu
$GLOBALS['mock'] = $mock;

function rel( string $version, array $o = [] ): void {
	global $base, $mock;
	$name = "lindenweg16-legal-suite-{$version}.zip";
	$assets = [];
	if ( empty( $o['no_zip'] ) ) {
		$assets[] = [ 'name' => $name, 'url' => "{$base}/repos/Marfinho/l16-legal/releases/assets/{$name}", 'browser_download_url' => ( $o['zip_host'] ?? $base ) . "/dl/{$name}" ];
	}
	if ( empty( $o['no_sha'] ) ) {
		$assets[] = [ 'name' => $name . '.sha256', 'url' => "{$base}/repos/Marfinho/l16-legal/releases/assets/{$name}.sha256", 'browser_download_url' => "{$base}/dl/{$name}.sha256" ];
	}
	file_put_contents( $mock . '/release.json', wp_json_encode( [ 'tag_name' => $o['tag'] ?? "v{$version}", 'draft' => ! empty( $o['draft'] ), 'prerelease' => ! empty( $o['pre'] ), 'html_url' => 'https://github.com/Marfinho/l16-legal/releases/tag/v' . $version, 'body' => $o['body'] ?? "## Änderungen\n- Test <b>fett</b>", 'published_at' => '2026-11-04T10:00:00Z', 'assets' => $assets ] ) );
	delete_transient( U::TRANSIENT );
}
function reqs(): array { global $mock; return array_map( fn( $l ) => json_decode( $l, true ), array_filter( explode( "\n", (string) @file_get_contents( $mock . '/requests.log' ) ) ) ); }
function clear_reqs(): void { global $mock; @unlink( $mock . '/requests.log' ); }
$check = function () use ( $plugin_file ) {
	delete_site_transient( 'update_plugins' );
	wp_update_plugins();
	$t = get_site_transient( 'update_plugins' );
	return $t->response[ $plugin_file ] ?? null;
};

section( 'Updater: Grundlagen' );
t( 'Installierte Version 0.8.0 (Testausgangslage)', '0.8.0' === LL16_VERSION, LL16_VERSION );
t( 'Plugin-Header trägt Update-URI auf github.com', str_contains( (string) file_get_contents( LL16_FILE ), 'Update URI:        https://github.com/Marfinho/l16-legal' ) );
t( 'API-Basis zeigt auf den Mock', str_starts_with( $base, 'http://127.0.0.1' ), $base );
t( 'Hosts: GitHub erlaubt, Fremdhost verboten', U::host_allowed( 'https://api.github.com/x' ) && U::host_allowed( 'https://objects.githubusercontent.com/x' ) && ! U::host_allowed( 'https://evil.example/x' ) && ! U::host_allowed( 'https://github.com.evil.example/x' ) );

section( 'Updater: Update wird angeboten' );
rel( '0.8.1' );
clear_reqs();
$u = $check();
t( 'Neueres Release → Update-Eintrag für unser Plugin', is_object( $u ) && '0.8.1' === $u->new_version, wp_json_encode( $u ) );
t( 'Eintrag enthält Paket-URL, Details-URL, PHP-Anforderung', is_object( $u ) && str_contains( (string) $u->package, 'lindenweg16-legal-suite-0.8.1.zip' ) && str_contains( (string) $u->url, 'github.com/Marfinho' ) && '8.3' === $u->requires_php );
t( 'Prüfsumme wurde mit abgerufen und gemerkt', 64 === strlen( (string) ( get_transient( U::TRANSIENT )['sha256'] ?? '' ) ) );
t( 'Anfrage ohne Token sendet keinen Authorization-Header', [] === array_filter( reqs(), fn( $r ) => '' !== $r['auth'] ) );
t( 'Ergebnis wird gecacht (zweite Prüfung ohne neuen API-Aufruf)', ( function () use ( $check ) { clear_reqs(); $check(); return 0 === count( array_filter( reqs(), fn( $r ) => str_contains( $r['path'], '/releases/latest' ) ) ); } )() );
t( 'Details-Dialog liefert Version, Changelog (escaped) und Download-Link', ( function () {
	$i = plugins_api( 'plugin_information', (object) [ 'slug' => 'lindenweg16-legal-suite' ] );
	return is_object( $i ) && '0.8.1' === $i->version && str_contains( $i->sections['changelog'], '&lt;b&gt;fett' ) && str_contains( (string) $i->download_link, '.zip' ) && 'Lindenweg16 Legal Suite' === $i->name;
} )() );
t( 'Details für fremde Plugins werden nicht verändert', false === apply_filters( 'plugins_api', false, 'plugin_information', (object) [ 'slug' => 'anderes' ] ) );

section( 'Updater: Kein Update / ungültige Releases' );
foreach ( [
	'gleiche Version (0.8.0)' => [ '0.8.0', [] ],
	'ältere Version (0.1.9)'  => [ '0.1.9', [] ],
	'Entwurf (draft)'         => [ '0.9.0', [ 'draft' => true ] ],
	'Vorabversion'            => [ '0.9.0', [ 'pre' => true ] ],
	'Tag ohne Versionsformat' => [ '0.9.0', [ 'tag' => 'latest' ] ],
	'Tag mit Zusatz 1.0.0-rc1' => [ '1.0.0', [ 'tag' => 'v1.0.0-rc1' ] ],
	'Paket fehlt'             => [ '0.9.0', [ 'no_zip' => true ] ],
	'Prüfsumme fehlt'         => [ '0.9.0', [ 'no_sha' => true ] ],
	'Paket auf Fremdhost'     => [ '0.9.0', [ 'zip_host' => 'https://evil.example' ] ],
] as $label => [ $v, $o ] ) {
	rel( $v, $o );
	t( "{$label}: kein Update angeboten", null === $check() );
}
$wpdb_ok = true;
file_put_contents( $mock . '/release.json', '{kaputt' );
delete_transient( U::TRANSIENT );
t( 'Kaputtes JSON: kein Update, kein Absturz', null === $check() );
@unlink( $mock . '/release.json' );
delete_transient( U::TRANSIENT );
clear_reqs();
t( 'API 404 (kein Release): kein Update', null === $check() );
$before = count( reqs() );
$check();
$check();
t( 'Fehler wird 1 h gemerkt (kein Dauerfeuer auf GitHub)', count( reqs() ) === $before );

section( 'Updater: Token-Schutz' );
$args2 = U::auth_header( [ 'headers' => [] ], 'https://evil.example/x' );
t( 'Token geht NIE an fremde Hosts', empty( $args2['headers']['Authorization'] ) );
t( 'Token geht nicht an Look-alike-Hosts (github.com.evil.example)', empty( U::auth_header( [ 'headers' => [] ], 'https://github.com.evil.example/x' )['headers']['Authorization'] ) );

section( 'Updater: Abschalten' );
update_option( 'll16_settings', [ 'updates' => false ] );
rel( '0.8.1' );
clear_reqs();
t( 'Update-Prüfung abgeschaltet: kein Eintrag und keine Anfrage', null === $check() && [] === reqs() );
update_option( 'll16_settings', [] );
t( 'Standard: Prüfung ist an', U::enabled() );
delete_option( 'll16_settings' );

section( 'Updater: Admin-Oberfläche' );
$admin = ( function () { $jar = [ 'wordpress_test_cookie' => 'WP Cookie check' ]; http( 'POST', '/wp-login.php', [ 'log' => 'admin', 'pwd' => 'admin', 'wp-submit' => 'Log In', 'testcookie' => '1' ], $jar ); return $jar; } )();
rel( '0.8.1' );
delete_transient( U::TRANSIENT );
$pg = http( 'GET', '/wp-admin/admin.php?page=ll16-legal&tab=status', [], $admin )['body'];
t( 'Status-Tab zeigt installierte Version und Prüfen-Schaltfläche', str_contains( $pg, 'Plugin-Updates' ) && str_contains( $pg, 'Installiert:' ) && str_contains( $pg, 'Jetzt auf Updates prüfen' ) );
t( 'Kein Token-Hinweis, solange die Prüfung funktioniert (öffentliches Repo braucht keins)', ! str_contains( $pg, 'LL16_GITHUB_TOKEN' ) );
preg_match_all( '/name="_wpnonce" value="([^"]+)"/', $pg, $nn );
$r = http( 'POST', '/wp-admin/admin-post.php', [ 'action' => 'll16_check_update', '_wpnonce' => end( $nn[1] ) ], $admin );
t( 'Prüfen-Schaltfläche → Weiterleitung mit Ergebnis', 302 === $r['code'] && str_contains( rawurldecode( (string) $r['headers']['location'] ), '0.8.1' ), (string) ( $r['headers']['location'] ?? '' ) );
$pg2 = http( 'GET', '/wp-admin/admin.php?page=ll16-legal&tab=status', [], $admin )['body'];
t( 'Danach: „Neueste Version: 0.8.1“ und Link zum Aktualisieren', str_contains( $pg2, '0.8.1' ) && str_contains( $pg2, 'update-core.php' ) );
$r = http( 'POST', '/wp-admin/admin-post.php', [ 'action' => 'll16_check_update' ], $admin );
t( 'Prüfen ohne Nonce abgelehnt', $r['code'] >= 400 );
$kunde = [];
if ( ! get_user_by( 'login', 'kunde' ) ) { wp_create_user( 'kunde', 'kundepw', 'kunde@example.com' ); }
$kj = []; http( 'POST', '/wp-login.php', [ 'log' => 'kunde', 'pwd' => 'kundepw', 'wp-submit' => 'x', 'testcookie' => '1' ], $kj );
$r = http( 'POST', '/wp-admin/admin-post.php', [ 'action' => 'll16_check_update' ], $kj );
t( 'Prüfen als Subscriber verboten', $r['code'] >= 400 );
t( 'Einstellungen-Tab: Schalter für Update-Prüfung', str_contains( http( 'GET', '/wp-admin/admin.php?page=ll16-legal&tab=settings', [], $admin )['body'], 'Auf Plugin-Updates prüfen' ) );

section( 'Updater: Hinweis bei fehlgeschlagener Prüfung' );
@unlink( $mock . '/release.json' );
delete_transient( U::TRANSIENT );
U::latest( true );
$pg3 = http( 'GET', '/wp-admin/admin.php?page=ll16-legal&tab=status', [], $admin )['body'];
t( 'Prüfung fehlgeschlagen → Hinweis auf Token (privates Repo) und „letzte Prüfung fehlgeschlagen“', str_contains( $pg3, 'LL16_GITHUB_TOKEN' ) && str_contains( $pg3, 'fehlgeschlagen' ) );
rel( '0.8.1' );
delete_transient( U::TRANSIENT );

section( 'Updater: Aufräumen' );
t( 'Deinstallations-Skript löscht den Update-Cache', str_contains( (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' ), "'ll16_update_info'" ) );
finish();
