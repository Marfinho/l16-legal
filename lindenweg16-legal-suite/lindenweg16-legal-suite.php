<?php
/**
 * Plugin Name:       Lindenweg16 Legal Suite
 * Description:       Consent, Rechtstexte, Widerrufsfunktion, Checkout-Compliance und Compliance-Monitor für lindenweg16.de. Rechtstexte sind Entwürfe und nicht anwaltlich geprüft.
 * Version:           0.2.1
 * Requires at least: 6.5
 * Requires PHP:      8.3
 * Author:            Lindenweg16
 * License:           Proprietary
 * Update URI:        https://github.com/Marfinho/l16-legal
 * Text Domain:       ll16-legal
 *
 * WC requires at least: 9.0
 *
 * @package Lindenweg16\Legal
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'LL16_VERSION', '0.2.1' );
define( 'LL16_FILE', __FILE__ );
define( 'LL16_DIR', plugin_dir_path( __FILE__ ) );
define( 'LL16_URL', plugin_dir_url( __FILE__ ) );

if ( is_readable( LL16_DIR . 'vendor/autoload.php' ) ) {
	require_once LL16_DIR . 'vendor/autoload.php';
} else {
	// Eigener PSR-4-Autoloader: das Plugin läuft ohne Composer-Installation auf dem Server.
	spl_autoload_register(
		static function ( string $class ): void {
			$prefix = 'Lindenweg16\\Legal\\';
			if ( ! str_starts_with( $class, $prefix ) ) {
				return;
			}
			$file = LL16_DIR . 'src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	);
}

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', LL16_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', LL16_FILE, true );
		}
	}
);

register_activation_hook( __FILE__, [ \Lindenweg16\Legal\Core\Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \Lindenweg16\Legal\Core\Plugin::class, 'deactivate' ] );

add_action( 'plugins_loaded', [ \Lindenweg16\Legal\Core\Plugin::class, 'boot' ], 5 );
