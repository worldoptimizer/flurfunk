<?php
/**
 * Plugin Name:       Flurfunk
 * Description:       Team conversations inside WordPress: channels, threads and tasks, attachable to posts, with mentions, files and cards from other plugins via slash commands. Works in plain wp-admin and inside the OpenStation desktop.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Max Ziebell
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       flurfunk
 * Domain Path:       /languages
 *
 * Layout:
 *   inc/schema.php     own tables (versioned, upgrade steps)
 *   inc/access.php     who sees what: team and guests per channel
 *   inc/store.php      reading and writing, data format "flurfunk/1"
 *   inc/providers.php  providers for slash commands and cards (the extension API)
 *   inc/targets.php    what threads can be attached to (posts built in, more via the API)
 *   inc/files.php      Flurfunk files: own folder, hidden from the media library until adopted
 *   inc/privacy.php    personal data export/erase, cleanup when users are deleted
 *   inc/settings.php   branding and defaults (name, logo, colours, access)
 *   inc/notify.php     notifications: in-app list, mail, digest, push; due reminders
 *   inc/push.php       Web Push (VAPID, aes128gcm) without external libraries
 *   inc/rest.php       REST API for the interface (flurfunk/v1)
 *   inc/admin.php      overview app, editor panel, posts list column, OpenStation bits
 *   inc/standalone.php full-screen app outside wp-admin (/flurfunk/), web app manifest
 *   inc/transfer.php   JSON export and import
 *   inc/data.php       export/import in the settings, welcome after the first activation
 *   inc/cli.php        wp flurfunk …
 *   integrations/      optional cards for other plugins (MailerPress), loaded only when present
 *
 * Everything site-specific lives outside this plugin and plugs in through the hooks and
 * functions documented in README.md.
 *
 * @package Flurfunk
 */

defined( 'ABSPATH' ) || exit;

const FLURFUNK_VERSION = '0.1.0';
define( 'FLURFUNK_FILE', __FILE__ );
define( 'FLURFUNK_DIR', plugin_dir_path( __FILE__ ) );
define( 'FLURFUNK_URL', plugin_dir_url( __FILE__ ) );

require_once FLURFUNK_DIR . 'inc/strings.php';
require_once FLURFUNK_DIR . 'inc/schema.php';
require_once FLURFUNK_DIR . 'inc/access.php';
require_once FLURFUNK_DIR . 'inc/store.php';
require_once FLURFUNK_DIR . 'inc/providers.php';
require_once FLURFUNK_DIR . 'inc/targets.php';
require_once FLURFUNK_DIR . 'inc/files.php';
require_once FLURFUNK_DIR . 'inc/privacy.php';
require_once FLURFUNK_DIR . 'inc/settings.php';
require_once FLURFUNK_DIR . 'inc/push.php';
require_once FLURFUNK_DIR . 'inc/notify.php';
require_once FLURFUNK_DIR . 'inc/rest.php';
require_once FLURFUNK_DIR . 'inc/admin.php';
require_once FLURFUNK_DIR . 'inc/standalone.php';
require_once FLURFUNK_DIR . 'inc/transfer.php';
require_once FLURFUNK_DIR . 'inc/data.php';

// Optional integrations with other plugins, each only when that plugin is active.
add_action(
	'plugins_loaded',
	static function (): void {
		if ( defined( 'MAILERPRESS_VERSION' ) ) {
			require_once FLURFUNK_DIR . 'integrations/mailerpress.php';
		}
	}
);

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once FLURFUNK_DIR . 'inc/cli.php';
}

// A fresh install (no settings yet) gets a short welcome in wp-admin.
register_activation_hook( __FILE__, static fn () => false === get_option( 'flurfunk_settings' ) && add_option( 'flurfunk_welcome', 1, '', false ) );
register_activation_hook( __FILE__, 'Flurfunk\\Schema\\activate' );
// The next request flushes the rewrite rules, once ours are registered (inc/standalone.php).
register_activation_hook( __FILE__, static fn () => delete_option( 'flurfunk_rewrite' ) );
register_deactivation_hook(
	__FILE__,
	static function (): void {
		foreach ( array( 'flurfunk_cleanup_files', 'flurfunk_due', 'flurfunk_digest', 'flurfunk_remind' ) as $hook ) { // phpcs:ignore
			wp_clear_scheduled_hook( $hook );
		}
		// WordPress rebuilds the rules on the next request, without the /flurfunk/ address.
		delete_option( 'flurfunk_rewrite' );
		delete_option( 'rewrite_rules' );
	}
);

add_action(
	'init',
	static function (): void {
		load_plugin_textdomain( 'flurfunk', false, dirname( plugin_basename( FLURFUNK_FILE ) ) . '/languages' );
	},
	1
);

/**
 * Register a provider for slash commands and cards (see inc/providers.php and README.md).
 *
 * @param string $id   Identifier, e.g. "myplugin/report".
 * @param array  $args Provider definition.
 */
function flurfunk_register_provider( string $id, array $args ): void {
	Flurfunk\Providers\register( $id, $args );
}

/**
 * Register something threads can be attached to (see inc/targets.php and README.md).
 *
 * @param string $type Type key, e.g. "dossier".
 * @param array  $args label, search, info, can.
 */
function flurfunk_register_target( string $type, array $args ): void {
	Flurfunk\Targets\register( $type, $args );
}

/**
 * Create a channel unless it exists (for plugins that bring their own channels).
 *
 * @param string $slug Short name.
 * @param array  $args name, topic, access_team, access_guests, position.
 * @return int Channel ID.
 */
function flurfunk_ensure_channel( string $slug, array $args = array() ): int {
	return Flurfunk\Store\ensure_channel( $slug, $args );
}

/**
 * Number of unread messages for a person (for badges elsewhere).
 *
 * @param int $user_id User (0 = current).
 */
function flurfunk_unread_count( int $user_id = 0 ): int {
	$user_id = $user_id ?: get_current_user_id();
	return Flurfunk\Access\can_use( $user_id ) ? Flurfunk\Store\counts( $user_id )['total'] : 0;
}

/**
 * URL of the overview app, optionally opening a view.
 *
 * @param string $view e.g. "channel:general", "target:post:12", "thread:5".
 */
function flurfunk_url( string $view = '' ): string {
	return Flurfunk\Admin\url( $view );
}

/**
 * URL of the full-screen app outside wp-admin (installable on a phone's home screen).
 *
 * @param string $view e.g. "channel:general", "thread:5".
 */
function flurfunk_app_url( string $view = '' ): string {
	return Flurfunk\Standalone\url( $view );
}
