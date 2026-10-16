<?php
/**
 * Uninstall: drop the tables only if the site chose not to keep the conversations.
 *
 * @package Flurfunk
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$flurfunk_settings = (array) get_option( 'flurfunk_settings', array() );
if ( isset( $flurfunk_settings['keep_data'] ) && ! $flurfunk_settings['keep_data'] ) {
	require_once __DIR__ . '/inc/schema.php';
	Flurfunk\Schema\drop();
	delete_option( 'flurfunk_settings' );
	delete_metadata( 'user', 0, 'flurfunk_prefs', '', true );
}
