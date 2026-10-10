<?php
require __DIR__ . '/lib.php';
global $wpdb;
$tables = [ 'll16_consent_log', 'll16_subscribers', 'll16_contact', 'll16_notify' ];
$exists = fn( $t ) => (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM sqlite_master WHERE type="table" AND name=%s', $wpdb->prefix . $t ) );

section( 'Deinstallation' );
update_option( 'll16_settings', [] );
update_option( 'll16_masterdata', [ 'name' => 'X' ] );
define( 'WP_UNINSTALL_PLUGIN', 'lindenweg16-legal-suite/lindenweg16-legal-suite.php' );
include dirname( __DIR__, 2 ) . '/uninstall.php';
t( 'Ohne Freigabe bleiben Daten erhalten (Optionen)', false !== get_option( 'll16_masterdata' ) );
t( 'Ohne Freigabe bleiben Tabellen erhalten', $exists( 'll16_consent_log' ) && $exists( 'll16_contact' ) );
update_option( 'll16_settings', [ 'delete_on_uninstall' => true ] );
include dirname( __DIR__, 2 ) . '/uninstall.php';
t( 'Mit Freigabe: Optionen gelöscht', false === get_option( 'll16_masterdata' ) && false === get_option( 'll16_settings' ) && false === get_option( 'll16_modules' ) );
t( 'Mit Freigabe: alle vier Tabellen (inkl. personenbezogener Daten) entfernt', [] === array_filter( $tables, $exists ), implode( ',', array_filter( $tables, $exists ) ) );
t( 'Mit Freigabe: Zusatzoptionen entfernt', false === get_option( 'll16_forms_db' ) && false === get_option( 'll16_csp_reports' ) && false === get_option( 'll16_withdrawals_unmatched' ) );
t( 'Mit Freigabe: Cron entfernt', false === wp_next_scheduled( 'll16_daily' ) );
finish();
