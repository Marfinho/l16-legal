<?php
declare(strict_types=1);

namespace Lindenweg16\Legal\Security;

/**
 * M8 – Security-Header und Hygiene.
 */
final class Module {

	public static function init(): void {
		add_action( 'send_headers', [ self::class, 'send' ] );
		// Diese Pfade durchlaufen `send_headers` nicht: REST, Login, Admin.
		add_filter( 'rest_pre_serve_request', [ self::class, 'send_filter' ] );
		add_action( 'login_init', [ self::class, 'send' ] );
		add_action( 'admin_init', [ self::class, 'send' ] );
		add_action( 'rest_api_init', [ self::class, 'routes' ] );
	}

	/**
	 * @param mixed $served
	 * @return mixed
	 */
	public static function send_filter( $served ) {
		self::send();
		return $served;
	}

	public static function send(): void {
		if ( headers_sent() ) {
			return;
		}
		$s       = (array) get_option( 'll16_settings', [] );
		$headers = Headers::build(
			[
				'hsts_max_age'    => (int) ( $s['hsts_max_age'] ?? 604800 ),
				'hsts_subdomains' => ! empty( $s['hsts_subdomains'] ),
				'csp_report_uri'  => esc_url_raw( rest_url( 'll16/v1/csp' ) ),
			],
			is_ssl()
		);
		foreach ( $headers as $name => $value ) {
			header( $name . ': ' . $value );
		}
		header_remove( 'X-Powered-By' );
	}

	public static function routes(): void {
		register_rest_route(
			'll16/v1',
			'/csp',
			[
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static function ( \WP_REST_Request $r ) {
					$body = substr( (string) $r->get_body(), 0, 2000 );
					$log  = (array) get_option( 'll16_csp_reports', [] );
					$log[] = [ 'time' => gmdate( 'c' ), 'report' => $body ];
					update_option( 'll16_csp_reports', array_slice( $log, -50 ), false );
					return new \WP_REST_Response( null, 204 );
				},
			]
		);
	}
}
