<?php
declare(strict_types=1);

namespace Lindenweg16\Legal\Core;

/**
 * Bootstrap: lädt die per Feature-Flag aktivierten Module.
 */
final class Plugin {

	/** Modul-Schlüssel => Klasse mit statischer init()-Methode. */
	public const MODULES = [
		'consent'    => \Lindenweg16\Legal\Consent\Module::class,
		'gate'       => \Lindenweg16\Legal\Gate\Module::class,
		'legal'      => \Lindenweg16\Legal\Legal\Module::class,
		'withdrawal' => \Lindenweg16\Legal\Withdrawal\Module::class,
		'checkout'   => \Lindenweg16\Legal\Checkout\Module::class,
		'product'    => \Lindenweg16\Legal\Product\Module::class,
		'forms'      => \Lindenweg16\Legal\Forms\Module::class,
		'security'   => \Lindenweg16\Legal\Security\Module::class,
		'monitor'    => \Lindenweg16\Legal\Monitor\Module::class,
	];

	public static function boot(): void {
		if ( version_compare( PHP_VERSION, '8.3', '<' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p>Lindenweg16 Legal Suite benötigt PHP 8.3 oder neuer.</p></div>';
				}
			);
			return;
		}

		( new Admin\Page() )->register();
		Updater::maybe_upgrade();

		$flags = Modules::flags();
		foreach ( self::MODULES as $key => $class ) {
			if ( ! empty( $flags[ $key ] ) && class_exists( $class ) ) {
				$class::init();
			}
		}

		add_action( 'll16_daily', [ \Lindenweg16\Legal\Consent\ConsentLog::class, 'purge' ] );
	}

	public static function activate(): void {
		\Lindenweg16\Legal\Consent\ConsentLog::install();
		if ( ! wp_next_scheduled( 'll16_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'll16_daily' );
		}
		update_option( 'll16_db_version', LL16_VERSION );
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'll16_daily' );
	}
}
