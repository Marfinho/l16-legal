<?php
declare(strict_types=1);

namespace Lindenweg16\Legal\Security;

/**
 * Baut die Security-Header. Reine Funktion → testbar.
 */
final class Headers {

	/**
	 * @param array{hsts_max_age?:int,hsts_subdomains?:bool,csp_report_uri?:string} $o
	 * @return array<string,string>
	 */
	public static function build( array $o, bool $https ): array {
		$h = [
			'X-Content-Type-Options' => 'nosniff',
			'Referrer-Policy'        => 'strict-origin-when-cross-origin',
			'X-Frame-Options'        => 'SAMEORIGIN',
			'Permissions-Policy'     => 'camera=(), microphone=(), geolocation=(), payment=(self "https://js.stripe.com" "https://www.paypal.com"), interest-cohort=()',
		];
		if ( $https ) {
			$age = max( 0, (int) ( $o['hsts_max_age'] ?? 604800 ) );
			if ( $age > 0 ) {
				$h['Strict-Transport-Security'] = 'max-age=' . $age . ( ! empty( $o['hsts_subdomains'] ) ? '; includeSubDomains' : '' );
			}
		}
		// CSP zunächst nur Report-Only; 'unsafe-inline' wegen WordPress/WooCommerce-Inline-Skripten.
		$csp = "default-src 'self'; "
			. "script-src 'self' 'unsafe-inline' https://js.stripe.com https://www.paypal.com https://www.paypalobjects.com; "
			. "style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; "
			. "frame-src https://js.stripe.com https://hooks.stripe.com https://www.paypal.com; "
			. "connect-src 'self' https://api.stripe.com https://www.paypal.com; "
			. "frame-ancestors 'self'; base-uri 'self'; form-action 'self'";
		if ( ! empty( $o['csp_report_uri'] ) ) {
			$csp .= '; report-uri ' . $o['csp_report_uri'];
		}
		$h['Content-Security-Policy-Report-Only'] = $csp;
		return $h;
	}
}
