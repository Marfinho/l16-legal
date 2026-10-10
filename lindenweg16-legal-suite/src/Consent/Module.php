<?php
declare(strict_types=1);

namespace Lindenweg16\Legal\Consent;

use Lindenweg16\Legal\Core\ServiceRegistry;

/**
 * M1 – Consent-Manager.
 *
 * Blockiert serverseitig (Output-Buffer) und aktiviert clientseitig nach Einwilligung.
 * Das Banner wird komplett im Browser gerendert; die ausgelieferte HTML-Seite ist für
 * alle Besucher identisch und damit LiteSpeed-cachebar.
 */
final class Module {

	public const COOKIE = 'll16_consent';

	public static function init(): void {
		add_action( 'template_redirect', [ self::class, 'start_buffer' ], 0 );
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue' ], 1 );
		add_action( 'rest_api_init', [ self::class, 'routes' ] );
		add_shortcode( 'll16_cookie_settings', [ self::class, 'shortcode' ] );
	}

	/**
	 * Regeln für den Rewriter.
	 *
	 * @param array<string,array<string,mixed>> $services
	 * @return array<int,array<string,mixed>>
	 */
	public static function rules( array $services, bool $is_payment_page ): array {
		$rules = [];
		foreach ( $services as $id => $s ) {
			$patterns = array_values( array_filter( (array) ( $s['patterns'] ?? [] ) ) );
			if ( [] === $patterns ) {
				continue;
			}
			$active    = ! empty( $s['active'] );
			$necessary = 'necessary' === ( $s['category'] ?? '' );
			$mode      = null;
			if ( ! $active ) {
				$mode = 'remove';
			} elseif ( ! $necessary ) {
				$mode = 'gate';
			} elseif ( 'checkout' === ( $s['context'] ?? 'always' ) && ! $is_payment_page ) {
				$mode = 'remove';
			}
			if ( null !== $mode ) {
				$rules[] = [ 'id' => $id, 'patterns' => $patterns, 'mode' => $mode, 'label' => $s['name'] ?? $id ];
			}
		}
		return $rules;
	}

	public static function start_buffer(): void {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() || is_embed() ) {
			return;
		}
		if ( function_exists( 'is_cart' ) ) {
			$payment_page = is_cart() || is_checkout() || is_wc_endpoint_url( 'order-pay' ) || is_account_page();
		} else {
			$payment_page = false;
		}
		/**
		 * Zusätzliche Seiten, auf denen notwendige Zahlungsskripte erlaubt sind.
		 */
		$payment_page = (bool) apply_filters( 'll16_is_payment_page', $payment_page );
		$rules        = self::rules( ServiceRegistry::all(), $payment_page );
		ob_start(
			static fn( string $html ): string => str_contains( $html, '<html' ) ? Rewriter::rewrite( $html, $rules ) : $html
		);
	}

	public static function enqueue(): void {
		$services = ServiceRegistry::active();
		$config   = [
			'version'    => ServiceRegistry::version( $services ),
			'cookie'     => self::COOKIE,
			'rest'       => esc_url_raw( rest_url( 'll16/v1/consent' ) ),
			'categories' => self::category_config( $services ),
			'services'   => self::service_config( $services ),
			'privacyUrl' => home_url( '/datenschutz/' ),
			'cookiesUrl' => home_url( '/cookies/' ),
			'imprintUrl' => home_url( '/impressum/' ),
			'maxAgeDays' => 365,
		];
		wp_register_style( 'll16-consent', LL16_URL . 'assets/consent.css', [], LL16_VERSION );
		wp_register_script( 'll16-consent', LL16_URL . 'assets/consent.js', [], LL16_VERSION, [ 'in_footer' => false, 'strategy' => 'defer' ] );
		wp_add_inline_script( 'll16-consent', 'window.LL16_CONSENT=' . wp_json_encode( $config ) . ';', 'before' );
		wp_enqueue_style( 'll16-consent' );
		wp_enqueue_script( 'll16-consent' );
	}

	/**
	 * @param array<string,array<string,mixed>> $services
	 * @return array<int,array<string,string>>
	 */
	private static function category_config( array $services ): array {
		$descriptions = [
			'necessary'   => 'Diese Dienste sind für Warenkorb, Bestellung und Zahlung erforderlich und können nicht abgewählt werden.',
			'preferences' => 'Diese Dienste laden Inhalte wie Karten von Drittanbietern nach.',
			'statistics'  => 'Diese Dienste messen, wie die Seite genutzt wird.',
			'marketing'   => 'Diese Dienste dienen Werbung und Erfolgsmessung.',
		];
		$out = [];
		foreach ( ServiceRegistry::categories_in_use( $services ) as $key ) {
			$out[] = [ 'key' => $key, 'label' => ServiceRegistry::CATEGORIES[ $key ], 'description' => $descriptions[ $key ] ];
		}
		return $out;
	}

	/**
	 * @param array<string,array<string,mixed>> $services
	 * @return array<int,array<string,mixed>>
	 */
	private static function service_config( array $services ): array {
		$out = [];
		foreach ( $services as $id => $s ) {
			$out[] = [
				'id'       => $id,
				'name'     => $s['name'],
				'provider' => $s['provider'],
				'category' => $s['category'],
				'purpose'  => $s['purpose'],
				'cookies'  => array_map( static fn( $c ) => (string) $c['name'], (array) $s['cookies'] ),
			];
		}
		return $out;
	}

	public static function routes(): void {
		register_rest_route(
			'll16/v1',
			'/consent',
			[
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => [ self::class, 'rest_log' ],
				'args'                => [
					'id'         => [ 'type' => 'string', 'required' => true ],
					'action'     => [ 'type' => 'string', 'required' => true ],
					'categories' => [ 'type' => 'array', 'default' => [] ],
				],
			]
		);
	}

	public static function rest_log( \WP_REST_Request $req ): \WP_REST_Response {
		$ok = ConsentLog::record(
			(string) $req['id'],
			(string) $req['action'],
			(array) $req['categories'],
			ServiceRegistry::version( ServiceRegistry::active() )
		);
		$res = new \WP_REST_Response( [ 'ok' => $ok ], $ok ? 200 : 400 );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	public static function shortcode(): string {
		return '<a href="#ll16-cookies" data-ll16-open class="ll16-settings-link">Cookie-Einstellungen</a>';
	}
}
