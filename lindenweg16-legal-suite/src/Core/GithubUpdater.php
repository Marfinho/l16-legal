<?php
declare(strict_types=1);

namespace Lindenweg16\Legal\Core;

/**
 * Plugin-Updates über WordPress (Dashboard → Aktualisierungen, Plugins-Liste, WP-CLI) aus GitHub-Releases.
 *
 * - Quelle: neuestes Release des Repositorys; Paket = Release-Asset `lindenweg16-legal-suite-<version>.zip`
 * - Integrität: SHA-256 aus dem Asset `…zip.sha256` wird nach dem Download geprüft
 * - Privates Repo: Token ausschließlich als Konstante `LL16_GITHUB_TOKEN` in der wp-config.php (nie in der Datenbank)
 * - Es wird nur der Server (nicht Besucher) kontaktiert; abschaltbar unter „Einstellungen“.
 */
final class GithubUpdater {

	public const REPO      = 'Marfinho/l16-legal';
	public const TRANSIENT = 'll16_update_info';
	public const SLUG      = 'lindenweg16-legal-suite';

	public static function init(): void {
		add_filter( 'update_plugins_github.com', [ self::class, 'filter_update' ], 10, 4 );
		add_filter( 'plugins_api', [ self::class, 'plugin_info' ], 10, 3 );
		add_filter( 'upgrader_pre_download', [ self::class, 'download' ], 10, 4 );
		add_filter( 'http_request_args', [ self::class, 'auth_header' ], 10, 2 );
		add_filter( 'upgrader_source_selection', [ self::class, 'fix_folder' ], 10, 4 );
		add_action( 'admin_post_ll16_check_update', [ self::class, 'handle_check' ] );
		if ( defined( 'LL16_UPDATE_API_BASE' ) ) {
			// Nur Tests: WordPress verbietet sonst Downloads von localhost (SSRF-Schutz). Gilt ausschließlich für den Mock-Host.
			add_filter(
				'http_request_host_is_external',
				static fn( $external, $host ) => strtolower( (string) $host ) === strtolower( (string) wp_parse_url( (string) LL16_UPDATE_API_BASE, PHP_URL_HOST ) ) ? true : $external,
				10,
				2
			);
		}
	}

	public static function enabled(): bool {
		$s = (array) get_option( 'll16_settings', [] );
		return ! isset( $s['updates'] ) || ! empty( $s['updates'] );
	}

	/** API-Basis; nur für Tests überschreibbar (lokaler Mock-Server). */
	public static function api_base(): string {
		return defined( 'LL16_UPDATE_API_BASE' ) ? (string) LL16_UPDATE_API_BASE : 'https://api.github.com';
	}

	private static function token(): string {
		return defined( 'LL16_GITHUB_TOKEN' ) ? (string) LL16_GITHUB_TOKEN : '';
	}

	/** Erlaubte Hosts für Token und Download (verhindert, dass das Token an Fremde geht). */
	public static function host_allowed( string $url ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$ok   = [ 'api.github.com', 'github.com', 'objects.githubusercontent.com', 'github-releases.githubusercontent.com', 'release-assets.githubusercontent.com' ];
		if ( defined( 'LL16_UPDATE_API_BASE' ) ) {
			$ok[] = strtolower( (string) wp_parse_url( (string) LL16_UPDATE_API_BASE, PHP_URL_HOST ) );
		}
		return in_array( $host, $ok, true );
	}

	/**
	 * Release-Daten holen (12 h Cache, bei Fehlern 1 h).
	 *
	 * @return array<string,mixed>|null version, package, sha256, url, notes, published
	 */
	public static function latest( bool $force = false ): ?array {
		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT );
			if ( is_array( $cached ) ) {
				return ! empty( $cached['error'] ) ? null : $cached;
			}
		}
		$info = self::fetch();
		if ( null === $info ) {
			set_transient( self::TRANSIENT, [ 'error' => 1, 'time' => time() ], HOUR_IN_SECONDS );
			return null;
		}
		$info['checked'] = time();
		set_transient( self::TRANSIENT, $info, 12 * HOUR_IN_SECONDS );
		return $info;
	}

	/** @return array<string,mixed>|null */
	private static function fetch(): ?array {
		$headers = [ 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'lindenweg16-legal-suite/' . LL16_VERSION ];
		if ( '' !== self::token() ) {
			$headers['Authorization'] = 'Bearer ' . self::token();
		}
		$res = wp_remote_get( self::api_base() . '/repos/' . self::REPO . '/releases/latest', [ 'timeout' => 10, 'headers' => $headers ] );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return null;
		}
		$rel = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		return is_array( $rel ) ? self::parse_release( $rel, $headers ) : null;
	}

	/**
	 * Release-JSON auswerten. Reine Logik (testbar).
	 *
	 * @param array<string,mixed>  $rel
	 * @param array<string,string> $headers Header für den Abruf der Prüfsummen-Datei
	 * @return array<string,mixed>|null
	 */
	public static function parse_release( array $rel, array $headers = [] ): ?array {
		if ( ! empty( $rel['draft'] ) || ! empty( $rel['prerelease'] ) ) {
			return null;
		}
		$tag = (string) ( $rel['tag_name'] ?? '' );
		if ( 1 !== preg_match( '/^v?(\d+\.\d+\.\d+)$/', $tag, $m ) ) {
			return null;
		}
		$zip = null;
		$sha = null;
		foreach ( (array) ( $rel['assets'] ?? [] ) as $a ) {
			$name = (string) ( $a['name'] ?? '' );
			if ( 1 === preg_match( '/^' . preg_quote( self::SLUG, '/' ) . '-\d+\.\d+\.\d+\.zip$/', $name ) ) {
				$zip = $a;
			} elseif ( 1 === preg_match( '/^' . preg_quote( self::SLUG, '/' ) . '-\d+\.\d+\.\d+\.zip\.sha256$/', $name ) ) {
				$sha = $a;
			}
		}
		if ( ! $zip || ! $sha ) {
			return null; // ohne Paket UND Prüfsumme wird nichts angeboten
		}
		// Mit Token über die API-URL (Accept: octet-stream), ohne Token über die öffentliche Download-URL.
		$package = '' !== self::token() ? (string) ( $zip['url'] ?? '' ) : (string) ( $zip['browser_download_url'] ?? '' );
		$hash    = self::fetch_sha( $sha, $headers );
		if ( null === $hash || '' === $package || ! self::host_allowed( $package ) ) {
			return null;
		}
		return [
			'version'   => $m[1],
			'package'   => $package,
			'sha256'    => $hash,
			'url'       => (string) ( $rel['html_url'] ?? '' ),
			'notes'     => (string) ( $rel['body'] ?? '' ),
			'published' => (string) ( $rel['published_at'] ?? '' ),
		];
	}

	/** @param array<string,mixed> $asset */
	private static function fetch_sha( array $asset, array $headers ): ?string {
		$url = '' !== self::token() ? (string) ( $asset['url'] ?? '' ) : (string) ( $asset['browser_download_url'] ?? '' );
		if ( '' === $url || ! self::host_allowed( $url ) ) {
			return null;
		}
		$headers['Accept'] = 'application/octet-stream';
		$res               = wp_remote_get( $url, [ 'timeout' => 10, 'headers' => $headers ] );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return null;
		}
		return 1 === preg_match( '/\b([a-f0-9]{64})\b/i', (string) wp_remote_retrieve_body( $res ), $m ) ? strtolower( $m[1] ) : null;
	}

	/**
	 * WordPress fragt (Update-URI-Host github.com) nach Updates.
	 *
	 * @param mixed                $update
	 * @param array<string,string> $plugin_data
	 * @param string               $plugin_file
	 * @return mixed
	 */
	public static function filter_update( $update, array $plugin_data, string $plugin_file, $locales = [] ) {
		if ( plugin_basename( LL16_FILE ) !== $plugin_file || ! self::enabled() ) {
			return $update;
		}
		$info = self::latest();
		if ( ! $info || ! version_compare( (string) $info['version'], LL16_VERSION, '>' ) ) {
			return $update;
		}
		return [
			'id'           => 'github.com/' . self::REPO,
			'slug'         => self::SLUG,
			'plugin'       => $plugin_file,
			'version'      => $info['version'],
			'url'          => $info['url'],
			'package'      => $info['package'],
			'tested'       => get_bloginfo( 'version' ),
			'requires_php' => '8.3',
			'autoupdate'   => false,
		];
	}

	/**
	 * „Details anzeigen“ im Dashboard.
	 *
	 * @param mixed  $result
	 * @param string $action
	 * @param object $args
	 * @return mixed
	 */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || self::SLUG !== ( $args->slug ?? '' ) ) {
			return $result;
		}
		$info = self::latest();
		return (object) [
			'name'          => 'Lindenweg16 Legal Suite',
			'slug'          => self::SLUG,
			'version'       => $info['version'] ?? LL16_VERSION,
			'author'        => 'Lindenweg16',
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => '6.5',
			'requires_php'  => '8.3',
			'last_updated'  => $info['published'] ?? '',
			'download_link' => $info['package'] ?? '',
			'sections'      => [
				'description' => 'Consent, Rechtstexte, Widerrufsfunktion, Checkout- und Produkt-Compliance, Monitor. Rechtstexte sind Entwürfe und nicht anwaltlich geprüft.',
				'changelog'   => '' !== trim( (string) ( $info['notes'] ?? '' ) ) ? '<pre style="white-space:pre-wrap">' . esc_html( (string) $info['notes'] ) . '</pre>' : 'Siehe GitHub-Release.',
			],
		];
	}

	/** Token nur an erlaubte GitHub-Hosts senden. */
	public static function auth_header( $args, $url ) {
		if ( '' === self::token() || ! self::host_allowed( (string) $url ) ) {
			return $args;
		}
		$args['headers']                  = (array) ( $args['headers'] ?? [] );
		$args['headers']['Authorization'] = 'Bearer ' . self::token();
		if ( str_contains( (string) $url, '/releases/assets/' ) ) {
			$args['headers']['Accept'] = 'application/octet-stream';
		}
		return $args;
	}

	/**
	 * Eigener Download mit SHA-256-Prüfung. Greift nur für unser Paket.
	 *
	 * @param mixed  $reply
	 * @param string $package
	 * @return mixed
	 */
	public static function download( $reply, $package, $upgrader = null, $hook_extra = [] ) {
		$info = get_transient( self::TRANSIENT );
		if ( ! is_array( $info ) || empty( $info['package'] ) || $package !== $info['package'] ) {
			return $reply;
		}
		if ( ! self::host_allowed( (string) $package ) ) {
			return new \WP_Error( 'll16_update_host', 'Update-Quelle nicht erlaubt.' );
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$file = download_url( (string) $package, 300 );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( ! hash_equals( (string) $info['sha256'], strtolower( (string) hash_file( 'sha256', $file ) ) ) ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.unlink_unlink
			return new \WP_Error( 'll16_update_hash', 'Die Prüfsumme des Update-Pakets stimmt nicht. Das Update wurde nicht installiert.' );
		}
		return $file;
	}

	/**
	 * Falls das entpackte Paket einen anderen Ordnernamen hat, auf den Plugin-Ordner umbenennen.
	 *
	 * @param string $source
	 * @param string $remote_source
	 * @param mixed  $upgrader
	 * @param array<string,mixed> $hook_extra
	 * @return mixed
	 */
	public static function fix_folder( $source, $remote_source, $upgrader, $hook_extra = [] ) {
		if ( ( $hook_extra['plugin'] ?? '' ) !== plugin_basename( LL16_FILE ) ) {
			return $source;
		}
		$wanted = trailingslashit( $remote_source ) . self::SLUG . '/';
		if ( trailingslashit( $source ) === $wanted ) {
			return $source;
		}
		global $wp_filesystem;
		if ( $wp_filesystem && $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $wanted ) ) ) {
			return $wanted;
		}
		return $source;
	}

	public static function handle_check(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'll16-legal' ), 403 );
		}
		check_admin_referer( 'll16_check_update' );
		delete_transient( self::TRANSIENT );
		delete_site_transient( 'update_plugins' );
		$info = self::latest( true );
		$msg  = null === $info
			? 'Update-Prüfung fehlgeschlagen (GitHub nicht erreichbar, kein Release oder fehlende Zugriffsrechte).'
			: ( version_compare( (string) $info['version'], LL16_VERSION, '>' ) ? 'Version ' . $info['version'] . ' ist verfügbar. Du findest sie unter Dashboard → Aktualisierungen.' : 'Du hast die neueste Version (' . LL16_VERSION . ').' );
		wp_safe_redirect( add_query_arg( [ 'page' => 'll16-legal', 'tab' => 'status', 'll16_notice' => rawurlencode( $msg ) ], admin_url( 'admin.php' ) ) );
		exit;
	}
}
