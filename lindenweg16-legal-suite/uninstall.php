<?php
/**
 * Deinstallation: Daten werden nur gelöscht, wenn dies in den Einstellungen ausdrücklich erlaubt wurde.
 * Bestellmeta (Rechtstext-Snapshot, Widerrufe) bleibt bewusst erhalten: Nachweisunterlagen.
 *
 * @package Lindenweg16\Legal
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$ll16_settings = get_option( 'll16_settings', [] );
if ( empty( $ll16_settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;
foreach ( [ 'll16_consent_log', 'll16_subscribers', 'll16_contact', 'll16_notify' ] as $ll16_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$ll16_table}" );
}
foreach ( [ 'll16_settings', 'll16_masterdata', 'll16_modules', 'll16_services', 'll16_text_versions', 'll16_db_version', 'll16_forms_db', 'll16_last_check', 'll16_warranty_notice', 'll16_csp_reports', 'll16_withdrawals_unmatched' ] as $ll16_option ) {
	delete_option( $ll16_option );
}
wp_clear_scheduled_hook( 'll16_daily' );
