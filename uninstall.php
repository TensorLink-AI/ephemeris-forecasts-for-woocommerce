<?php
/**
 * Remove everything the plugin stored.
 *
 * @package EphemerisForecasts
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( array( 'ephfc_settings', 'ephfc_run', 'ephfc_run_items', 'ephfc_last_completed_run', 'ephfc_db_version', 'ephfc_schedule_applied' ) as $ephfc_option ) {
	delete_option( $ephfc_option );
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ephfc_suggestions" );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'ephfc_start_run', array(), 'ephfc' );
	as_unschedule_all_actions( 'ephfc_run_batch', null, 'ephfc' );
}
