<?php
/**
 * Deinstallation: Daten werden nur gelöscht, wenn dies in den Einstellungen ausdrücklich erlaubt wurde.
 *
 * @package Lindenweg16\Legal
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$ll16_settings = get_option( 'll16_settings', [] );
if ( empty( $ll16_settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ll16_consent_log" );
foreach ( [ 'll16_settings', 'll16_masterdata', 'll16_modules', 'll16_services', 'll16_text_versions', 'll16_db_version', 'll16_last_check' ] as $ll16_option ) {
	delete_option( $ll16_option );
}
wp_clear_scheduled_hook( 'll16_daily' );
