<?php
/**
 * Plugin Name:       Flurfunk
 * Plugin URI:        https://worldoptimizer.github.io/flurfunk/
 * Description:       Team conversations inside WordPress: channels, threads and tasks, attachable to posts, with mentions, files and cards from other plugins via slash commands. Works in plain wp-admin and inside the OpenStation desktop.
 * Version:           0.6.0-dev
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
 *   inc/caps.php       capabilities (flurfunk_team, _guest, _moderate, _manage, declared ones)
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
 *   inc/modules.php    module register: optional parts, switchable under Settings → Flurfunk
 *   modules/           Flurfunk's own modules: comment cards, daily moderation thread, review requests
 *
 * Everything site-specific lives outside this plugin and plugs in through the hooks and
 * functions documented in README.md.
 *
 * @package Flurfunk
 */

defined( 'ABSPATH' ) || exit;

const FLURFUNK_VERSION = '0.6.0-dev';
define( 'FLURFUNK_FILE', __FILE__ );
define( 'FLURFUNK_DIR', plugin_dir_path( __FILE__ ) );
define( 'FLURFUNK_URL', plugin_dir_url( __FILE__ ) );

require_once FLURFUNK_DIR . 'inc/strings.php';
require_once FLURFUNK_DIR . 'inc/schema.php';
require_once FLURFUNK_DIR . 'inc/access.php';
require_once FLURFUNK_DIR . 'inc/caps.php';
require_once FLURFUNK_DIR . 'inc/people.php'; // Team or guest per person, where it differs from the role.
require_once FLURFUNK_DIR . 'inc/store.php';
require_once FLURFUNK_DIR . 'inc/reactions.php';
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
require_once FLURFUNK_DIR . 'inc/abilities.php'; // WordPress abilities (6.9+); without the API the hooks never fire.
require_once FLURFUNK_DIR . 'inc/drafts.php'; // Drafts from conversations (abilities, too).
require_once FLURFUNK_DIR . 'inc/changes.php'; // Changes to existing posts (an ability AI assistants propose).
require_once FLURFUNK_DIR . 'inc/code.php'; // Reading the code of plugins and themes (abilities, administrators only).
require_once FLURFUNK_DIR . 'inc/insights.php'; // Site check, numbers and snapshots (abilities).
require_once FLURFUNK_DIR . 'inc/stats.php'; // Visits from Koko Analytics or WP Statistics, for the numbers.
require_once FLURFUNK_DIR . 'inc/styles.php'; // The site's look: colors and CSS (abilities; changes as cards).
require_once FLURFUNK_DIR . 'inc/templates.php'; // Block theme templates and parts (abilities; changes as cards).

require_once FLURFUNK_DIR . 'inc/modules.php'; // Optional parts, incl. modules/ (switchable).

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once FLURFUNK_DIR . 'inc/cli.php';
}

// Capabilities first: it tells a fresh site from one that ran 0.1 by the settings and channels.
register_activation_hook( __FILE__, 'Flurfunk\\Caps\\install' );
// A fresh install (no settings yet) gets a short welcome in wp-admin.
register_activation_hook( __FILE__, static fn () => false === get_option( 'flurfunk_settings' ) && add_option( 'flurfunk_welcome', 1, '', false ) );
register_activation_hook( __FILE__, 'Flurfunk\\Schema\\activate' );
// The next request flushes the rewrite rules, once ours are registered (inc/standalone.php).
register_activation_hook( __FILE__, static fn () => delete_option( 'flurfunk_rewrite' ) );
register_deactivation_hook(
	__FILE__,
	static function (): void {
		foreach ( array( 'flurfunk_cleanup_files', 'flurfunk_due', 'flurfunk_digest', 'flurfunk_remind', 'flurfunk_comments_daily' ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
		// WordPress rebuilds the rules on the next request, without the /flurfunk/ address.
		delete_option( 'flurfunk_rewrite' );
		delete_option( 'rewrite_rules' );
	}
);

// The German translation ships with the plugin (languages/); language packs from
// translate.wordpress.org take precedence once they exist.
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
 * @param array  $args name, topic, access_team, access_guests, position, capability (required on
 *                     top of the levels, '' = none; see inc/access.php).
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

/**
 * Run code as another person (for posting from PHP), then switch back. As an assistant, the code
 * may also use channels that require a capability (it is the site acting, see inc/access.php).
 *
 * @param int      $user_id User; 0 = the default assistant (created if needed).
 * @param callable $fn      Work.
 * @return mixed What $fn returns.
 */
function flurfunk_as( int $user_id, callable $fn ) {
	if ( ! $user_id ) {
		$user_id = (int) get_option( 'flurfunk_bot_default' );
		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			$made    = Flurfunk\Access\create_bot();
			$user_id = is_wp_error( $made ) ? 0 : (int) $made;
		}
	}
	$before = get_current_user_id();
	wp_set_current_user( $user_id );
	try {
		// An assistant acting for PHP code may post into channels that require a capability.
		return Flurfunk\Access\is_bot( $user_id ) ? Flurfunk\Access\as_server( $user_id, $fn ) : $fn();
	} finally {
		wp_set_current_user( $before );
	}
}

/**
 * Start a thread from PHP, e.g. when something happens on the site. Posts as the default
 * assistant unless `author` names someone; mentions, tasks and notifications work as in the app.
 * With a `ref_key` the call is idempotent: the existing thread's ID comes back.
 *
 * @param array $args channel (slug, required), title, body, target ['type','id'], task (bool),
 *                    due (Y-m-d), assignees (user IDs), files (attachment IDs), attachments
 *                    (cards: provider, ref), ref_key, author (user ID).
 * @return int|WP_Error Thread ID.
 */
function flurfunk_post( array $args ) {
	$ref = (string) ( $args['ref_key'] ?? '' );
	if ( '' !== $ref ) {
		$found = Flurfunk\Store\thread_by_ref( $ref );
		if ( $found ) {
			return (int) $found->id;
		}
	}
	return flurfunk_as(
		(int) ( $args['author'] ?? 0 ),
		static function () use ( $args ) {
			$args['task'] = ! empty( $args['task'] ) || ! empty( $args['due'] ) || ! empty( $args['assignees'] );
			unset( $args['author'] );
			return Flurfunk\Store\create_thread( $args );
		}
	);
}

/**
 * Reply to a thread from PHP (thread ID or the ref_key it was created with).
 *
 * @param int|string $thread Thread ID or ref_key.
 * @param string     $body   Text (mentions as <@ID>).
 * @param array      $args   files, attachments, author (user ID; default: the assistant).
 * @return int|WP_Error Message ID.
 */
function flurfunk_reply( $thread, string $body, array $args = array() ) {
	$row = is_int( $thread ) ? Flurfunk\Store\thread_row( $thread ) : Flurfunk\Store\thread_by_ref( (string) $thread );
	if ( ! $row ) {
		return new WP_Error( 'flurfunk_thread', __( 'This conversation does not exist.', 'flurfunk' ) );
	}
	return flurfunk_as(
		(int) ( $args['author'] ?? 0 ),
		static fn () => Flurfunk\Store\add_message( (int) $row->id, array( 'body' => $body ) + array_intersect_key( $args, array_flip( array( 'files', 'attachments' ) ) ) )
	);
}

/**
 * Set a thread's task state from PHP: open, doing, waiting, done, or '' (no task).
 *
 * @param int|string $thread Thread ID or ref_key.
 * @param string     $status State.
 * @param int        $author Who changes it (default: the assistant).
 * @return true|WP_Error
 */
function flurfunk_set_status( $thread, string $status, int $author = 0 ) {
	$row = is_int( $thread ) ? Flurfunk\Store\thread_row( $thread ) : Flurfunk\Store\thread_by_ref( (string) $thread );
	if ( ! $row ) {
		return new WP_Error( 'flurfunk_thread', __( 'This conversation does not exist.', 'flurfunk' ) );
	}
	return flurfunk_as( $author, static fn () => Flurfunk\Store\set_status( (int) $row->id, $status ) );
}

/**
 * Register an optional module (see inc/modules.php): it shows up under Settings → Flurfunk with
 * a switch and is only loaded when switched on. Call it on the `flurfunk_modules` action.
 *
 * @param string $id   ID like "vendor/name".
 * @param array  $args label, description, group, requires, default, load, settings_url,
 *                     capabilities (cap => label, description, roles: granted once it is on),
 *                     settings (fields shown under the switch; read with flurfunk_module_setting()).
 */
function flurfunk_register_module( string $id, array $args ): bool {
	return Flurfunk\Modules\register( $id, $args );
}

/**
 * Is a module switched on?
 *
 * @param string $id Module ID.
 */
function flurfunk_module_enabled( string $id ): bool {
	return Flurfunk\Modules\enabled( $id );
}

/**
 * A module's setting (see the `settings` argument of flurfunk_register_module()): the value saved
 * under Settings → Flurfunk → Modules, or the field's default. Null for an unknown module or key.
 *
 * @param string $module_id Module ID.
 * @param string $key       Setting key.
 * @return mixed
 */
function flurfunk_module_setting( string $module_id, string $key ) {
	return Flurfunk\Modules\setting( $module_id, $key );
}

/**
 * Declare a capability (see inc/caps.php): it shows up in the permissions matrix under
 * Settings → Flurfunk, and the recommended roles get it once unless the site already decided.
 *
 * @param string $cap  Capability, e.g. "acme_send".
 * @param array  $args label, description, roles (recommended role names), group.
 */
function flurfunk_register_capability( string $cap, array $args = array() ): bool {
	return Flurfunk\Caps\register( $cap, $args );
}
