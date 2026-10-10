<?php
declare(strict_types=1);

namespace Lindenweg16\Legal\Core;

final class Updater {
	public static function maybe_upgrade(): void {
		if ( get_option( 'll16_db_version' ) === LL16_VERSION ) {
			return;
		}
		\Lindenweg16\Legal\Consent\ConsentLog::install();
		if ( ! wp_next_scheduled( 'll16_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'll16_daily' );
		}
		update_option( 'll16_db_version', LL16_VERSION );
	}
}
