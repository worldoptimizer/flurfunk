<?php
/**
 * Uninstall: drop the tables, remove Flurfunk files and the assistant accounts only if the site
 * chose not to keep the conversations (Settings → Flurfunk → Data). Files already added to the
 * media library stay. The capabilities always go (on every site of a network): without the
 * plugin they mean nothing, and a reinstall grants them again.
 *
 * @package Flurfunk
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$flurfunk_settings = (array) get_option( 'flurfunk_settings', array() );
wp_clear_scheduled_hook( 'flurfunk_cleanup_files' );
wp_clear_scheduled_hook( 'flurfunk_due' );
wp_clear_scheduled_hook( 'flurfunk_digest' );
wp_clear_scheduled_hook( 'flurfunk_remind' );
delete_option( 'flurfunk_rewrite' );
delete_option( 'flurfunk_welcome' );
delete_option( 'flurfunk_modules' );

// Capabilities: Flurfunk's own, and declared ones that no role had before Flurfunk granted them.
$flurfunk_remove_caps = static function (): void {
	$caps = array( 'flurfunk_team', 'flurfunk_guest', 'flurfunk_moderate', 'flurfunk_manage' );
	foreach ( (array) get_option( 'flurfunk_caps_seeded', array() ) as $cap => $info ) {
		if ( ! empty( $info['new'] ) ) {
			$caps[] = (string) $cap;
		}
	}
	foreach ( wp_roles()->role_objects as $role ) {
		foreach ( array_unique( $caps ) as $cap ) {
			if ( isset( $role->capabilities[ $cap ] ) ) {
				$role->remove_cap( $cap );
			}
		}
	}
	delete_option( 'flurfunk_caps_version' );
	delete_option( 'flurfunk_caps_seeded' );
	delete_option( 'flurfunk_caps_version_lock' );
};
if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $flurfunk_site ) {
		switch_to_blog( (int) $flurfunk_site );
		$flurfunk_remove_caps();
		restore_current_blog();
	}
} else {
	$flurfunk_remove_caps();
}
if ( isset( $flurfunk_settings['keep_data'] ) && ! $flurfunk_settings['keep_data'] ) {
	delete_option( 'flurfunk_vapid' ); // Kept with the data, so push subscriptions keep working after a reinstall.
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- runs once on uninstall.
	foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM %i WHERE meta_key = %s', $wpdb->postmeta, '_flurfunk_file' ) ) as $flurfunk_id ) {
		wp_delete_attachment( (int) $flurfunk_id, true );
	}
	require_once __DIR__ . '/inc/schema.php';
	Flurfunk\Schema\drop();

	// Assistant accounts exist only for Flurfunk. Their conversations are gone with the tables and
	// their Flurfunk files above; anything they put into the media library goes to an administrator.
	require_once ABSPATH . 'wp-admin/includes/user.php';
	$flurfunk_admins = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		)
	);
	foreach ( get_users( array( 'meta_key' => 'flurfunk_bot', 'fields' => 'ID' ) ) as $flurfunk_bot ) { // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a handful of accounts, once on uninstall.
		$flurfunk_owns = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE post_author = %d', $wpdb->posts, $flurfunk_bot ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- runs once on uninstall.
		wp_delete_user( (int) $flurfunk_bot, $flurfunk_owns && $flurfunk_admins ? (int) $flurfunk_admins[0] : null );
	}

	delete_option( 'flurfunk_settings' );
	delete_option( 'flurfunk_rev' );
	delete_option( 'flurfunk_bot_default' );
	delete_option( 'flurfunk_welcome_thread' );
	delete_metadata( 'user', 0, 'flurfunk_prefs', '', true );
	foreach ( array( 'theme', 'avatar', 'mail', 'push', 'quiet', 'badge', 'hour', 'channels', 'pause' ) as $flurfunk_k ) {
		delete_metadata( 'user', 0, 'flurfunk_prefs_' . $flurfunk_k, '', true );
	}
	delete_metadata( 'user', 0, 'flurfunk_rev', '', true );
	delete_metadata( 'user', 0, 'flurfunk_bot', '', true );
	delete_metadata( 'user', 0, 'flurfunk_digest_day', '', true );
	delete_metadata( 'user', 0, 'flurfunk_digest_last', '', true );
}
