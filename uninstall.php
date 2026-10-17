<?php
/**
 * Uninstall: drop the tables and remove Flurfunk files only if the site chose not to keep the
 * conversations (Settings → Flurfunk → Data). Files already added to the media library stay.
 *
 * @package Flurfunk
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$flurfunk_settings = (array) get_option( 'flurfunk_settings', array() );
wp_clear_scheduled_hook( 'flurfunk_cleanup_files' );
wp_clear_scheduled_hook( 'flurfunk_due' );
wp_clear_scheduled_hook( 'flurfunk_digest' );
wp_clear_scheduled_hook( 'flurfunk_remind' );
delete_option( 'flurfunk_vapid' );
if ( isset( $flurfunk_settings['keep_data'] ) && ! $flurfunk_settings['keep_data'] ) {
	global $wpdb;
	foreach ( $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_flurfunk_file'" ) as $flurfunk_id ) { // phpcs:ignore WordPress.DB
		wp_delete_attachment( (int) $flurfunk_id, true );
	}
	require_once __DIR__ . '/inc/schema.php';
	Flurfunk\Schema\drop();
	delete_option( 'flurfunk_settings' );
	delete_option( 'flurfunk_rev' );
	delete_option( 'flurfunk_bot_default' );
	delete_metadata( 'user', 0, 'flurfunk_prefs', '', true );
	foreach ( array( 'theme', 'avatar', 'mail', 'push', 'quiet', 'badge', 'hour', 'channels', 'pause' ) as $flurfunk_k ) {
		delete_metadata( 'user', 0, 'flurfunk_prefs_' . $flurfunk_k, '', true );
	}
	delete_metadata( 'user', 0, 'flurfunk_rev', '', true );
	delete_metadata( 'user', 0, 'flurfunk_bot', '', true );
}
