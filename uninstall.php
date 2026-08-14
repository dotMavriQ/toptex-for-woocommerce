<?php
/**
 * Uninstall handler: remove plugin data when the user deletes the plugin.
 *
 * @package TopTex_WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$option_names = array(
	'toptex_options',
	'toptex_last_sync',
);

foreach ( $option_names as $option_name ) {
	delete_option( $option_name );
}

delete_transient( 'toptex_sync_running' );

$table = $wpdb->prefix . 'toptex_sync_log';
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Clear the scheduled cron event.
wp_clear_scheduled_hook( 'toptex_sync_event' );
