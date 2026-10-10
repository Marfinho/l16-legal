<?php
declare(strict_types=1);

namespace Lindenweg16\Legal\Consent;

/**
 * Einwilligungsnachweis (Art. 7 Abs. 1 DSGVO): pseudonyme ID, Zeit, Versionen,
 * Kategorien. Keine IP, kein User-Agent.
 */
final class ConsentLog {

	public const DEFAULT_RETENTION_DAYS = 1095;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'll16_consent_log';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				consent_id char(36) NOT NULL,
				created_at datetime NOT NULL,
				action varchar(16) NOT NULL,
				categories varchar(120) NOT NULL,
				registry_version varchar(16) NOT NULL,
				banner_version varchar(16) NOT NULL,
				PRIMARY KEY  (id),
				KEY consent_id (consent_id),
				KEY created_at (created_at)
			) {$charset};"
		);
	}

	/**
	 * @param string[] $categories
	 */
	public static function record( string $consent_id, string $action, array $categories, string $registry_version ): bool {
		global $wpdb;
		if ( ! self::valid_uuid( $consent_id ) || ! in_array( $action, [ 'accept', 'reject', 'custom', 'gpc' ], true ) ) {
			return false;
		}
		$allowed = array_keys( \Lindenweg16\Legal\Core\ServiceRegistry::CATEGORIES );
		$cats    = array_values( array_intersect( $allowed, array_map( 'strval', $categories ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert(
			self::table(),
			[
				'consent_id'       => $consent_id,
				'created_at'       => gmdate( 'Y-m-d H:i:s' ),
				'action'           => $action,
				'categories'       => implode( ',', $cats ),
				'registry_version' => substr( preg_replace( '/[^a-z0-9]/i', '', $registry_version ) ?? '', 0, 16 ),
				'banner_version'   => LL16_VERSION,
			]
		);
		return false !== $ok;
	}

	public static function valid_uuid( string $id ): bool {
		return (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id );
	}

	public static function purge(): void {
		global $wpdb;
		$settings = get_option( 'll16_settings', [] );
		$days     = max( 30, (int) ( $settings['consent_retention_days'] ?? self::DEFAULT_RETENTION_DAYS ) );
		$table    = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
	}
}
