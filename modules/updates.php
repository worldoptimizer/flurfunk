<?php
/**
 * Module "Updates available": once a day it looks at the updates WordPress has found (it checks
 * twice a day by itself) – WordPress, plugins, themes and, if wanted, translations. The first time
 * one thread lists them, as a task with a card that always shows what is still open; later only
 * new updates come as a reply in that thread; once everything is installed, the thread is ticked
 * off. If they wait for days, a reminder (setting "remind", default 7 days, 0 = never).
 *
 * Nothing is fetched from outside: when WordPress has no current list (e.g. right after a cache
 * was emptied), the module waits for the next run instead of thinking everything is installed.
 *
 * Settings (Settings → Flurfunk → Modules): channel (default its own #updates, only for people who
 * may update plugins), what to look at, plugins and themes to leave out, who is responsible, the
 * reminder. Filter flurfunk_updates_hour (0–23, site time, default 8).
 *
 * @package Flurfunk
 */

namespace Flurfunk\Modules\Updates;

use Flurfunk\Access;
use Flurfunk\Watch;

defined( 'ABSPATH' ) || exit;

const MODULE   = 'flurfunk/updates';
const PROVIDER = 'flurfunk/update-list';
const HOOK     = 'flurfunk_updates_daily';
const STATE    = 'flurfunk_updates_watch';

add_action( 'init', __NAMESPACE__ . '\\register', 20 );
add_action( HOOK, __NAMESPACE__ . '\\run' );
add_action(
	'flurfunk_module_off',
	static function ( string $id ): void {
		if ( MODULE === $id ) {
			wp_clear_scheduled_hook( HOOK );
		}
	}
);
// After installing updates, look again a little later (WordPress refreshes its lists by then).
add_action(
	'upgrader_process_complete',
	static function (): void {
		if ( ! wp_next_scheduled( HOOK . '_soon' ) ) {
			wp_schedule_single_event( time() + 10 * MINUTE_IN_SECONDS, HOOK . '_soon' );
		}
	}
);
add_action( HOOK . '_soon', __NAMESPACE__ . '\\run' );

/**
 * Register the (hidden) provider for the card and schedule the daily run.
 */
function register(): void {
	flurfunk_register_provider(
		PROVIDER,
		array(
			'command'  => 'update-list',
			'label'    => __( 'Updates available', 'flurfunk' ),
			'hidden'   => true,
			'can'      => static fn ( int $user_id ) => Access\is_bot( $user_id ) || ( Access\can_use( $user_id ) && user_can( $user_id, 'update_plugins' ) ),
			'snapshot' => __NAMESPACE__ . '\\snapshot',
		)
	);
	if ( ! wp_next_scheduled( HOOK ) ) {
		$hour = max( 0, min( 23, (int) apply_filters( 'flurfunk_updates_hour', 8 ) ) );
		$next = new \DateTime( 'today ' . $hour . ':00', wp_timezone() );
		if ( $next->getTimestamp() <= time() ) {
			$next->modify( '+1 day' );
		}
		wp_schedule_event( $next->getTimestamp(), 'daily', HOOK );
	}
}

/**
 * A setting of the module.
 *
 * @param string $key Key.
 */
function setting( string $key ) {
	return flurfunk_module_setting( MODULE, $key );
}

/**
 * Plugins and themes to leave out (slugs or folder names, from the setting).
 *
 * @return string[]
 */
function ignored(): array {
	return array_values( array_filter( array_map( static fn ( $s ) => sanitize_key( trim( $s ) ), explode( ',', (string) setting( 'ignore' ) ) ) ) );
}

/**
 * What waits: key => [ type, name, now, new ]. Null when WordPress has no current list of a kind
 * the module looks at (unknown – not "nothing").
 *
 * @return array<string, array{type:string, name:string, now:string, new:string}>|null
 */
function pending(): ?array {
	$out  = array();
	$skip = ignored();
	if ( setting( 'core' ) ) {
		$core = get_site_transient( 'update_core' );
		if ( ! is_object( $core ) ) {
			return null;
		}
		foreach ( (array) ( $core->updates ?? array() ) as $u ) {
			if ( 'upgrade' === ( $u->response ?? '' ) && ! empty( $u->current ) ) {
				$out[ 'core:' . $u->current ] = array(
					'type' => __( 'WordPress', 'flurfunk' ),
					'name' => 'WordPress',
					'now'  => (string) get_bloginfo( 'version' ),
					'new'  => (string) $u->current,
				);
				break;
			}
		}
	}
	if ( setting( 'plugins' ) ) {
		$plugins = get_site_transient( 'update_plugins' );
		if ( ! is_object( $plugins ) ) {
			return null;
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$installed = get_plugins();
		foreach ( (array) ( $plugins->response ?? array() ) as $file => $u ) {
			$slug = sanitize_key( (string) ( $u->slug ?? dirname( (string) $file ) ) );
			if ( in_array( $slug, $skip, true ) || in_array( sanitize_key( dirname( (string) $file ) ), $skip, true ) ) {
				continue;
			}
			$out[ 'plugin:' . $file . ':' . ( $u->new_version ?? '' ) ] = array(
				'type' => __( 'Plugin', 'flurfunk' ),
				'name' => (string) ( $installed[ $file ]['Name'] ?? $slug ),
				'now'  => (string) ( $installed[ $file ]['Version'] ?? '' ),
				'new'  => (string) ( $u->new_version ?? '' ),
			);
		}
	}
	if ( setting( 'themes' ) ) {
		$themes = get_site_transient( 'update_themes' );
		if ( ! is_object( $themes ) ) {
			return null;
		}
		foreach ( (array) ( $themes->response ?? array() ) as $stylesheet => $u ) {
			if ( in_array( sanitize_key( (string) $stylesheet ), $skip, true ) ) {
				continue;
			}
			$theme = wp_get_theme( (string) $stylesheet );
			$out[ 'theme:' . $stylesheet . ':' . ( $u['new_version'] ?? '' ) ] = array(
				'type' => __( 'Theme', 'flurfunk' ),
				'name' => $theme->exists() ? (string) $theme->get( 'Name' ) : (string) $stylesheet,
				'now'  => $theme->exists() ? (string) $theme->get( 'Version' ) : '',
				'new'  => (string) ( $u['new_version'] ?? '' ),
			);
		}
	}
	if ( setting( 'translations' ) ) {
		$count = 0;
		foreach ( array( 'update_core', 'update_plugins', 'update_themes' ) as $t ) {
			$list   = get_site_transient( $t );
			$count += is_object( $list ) ? count( (array) ( $list->translations ?? array() ) ) : 0;
		}
		if ( $count ) {
			$out[ 'translations:' . $count ] = array(
				'type' => __( 'Translations', 'flurfunk' ),
				/* translators: %s: number */
				'name' => sprintf( _n( '%s translation', '%s translations', $count, 'flurfunk' ), number_format_i18n( $count ) ),
				'now'  => '',
				'new'  => '',
			);
		}
	}
	return $out;
}

/**
 * One line for a thread or a reply: "Plugin "Akismet": 5.3 → 5.4".
 *
 * @param array $u Entry of pending().
 */
function line( array $u ): string {
	$version = '' !== $u['new'] ? ': ' . ( '' !== $u['now'] ? $u['now'] . ' → ' : '' ) . $u['new'] : '';
	return $u['type'] . ' "' . $u['name'] . '"' . $version;
}

/**
 * The card: what is still open, and the way to the updates screen.
 */
function snapshot(): array {
	$list = (array) pending();
	$url  = admin_url( 'update-core.php' );
	if ( ! $list ) {
		return array(
			'title'  => __( 'Updates available', 'flurfunk' ),
			'url'    => $url,
			'status' => array(
				'label' => __( 'All installed', 'flurfunk' ),
				'tone'  => 'positive',
			),
		);
	}
	return array(
		/* translators: %s: number */
		'title'  => sprintf( _n( '%s update available', '%s updates available', count( $list ), 'flurfunk' ), number_format_i18n( count( $list ) ) ),
		'url'    => $url,
		'status' => array(
			'label' => __( 'Waiting', 'flurfunk' ),
			'tone'  => 'warning',
		),
		'table'  => array(
			'columns' => array( __( 'What', 'flurfunk' ), __( 'Installed', 'flurfunk' ), __( 'New', 'flurfunk' ) ),
			'rows'    => array_map( static fn ( $u ) => array( $u['type'] . ': ' . $u['name'], $u['now'], $u['new'] ), array_values( $list ) ),
		),
		'action' => array(
			'label' => __( 'Go to the updates', 'flurfunk' ),
			'url'   => $url,
		),
	);
}

/**
 * The channel of the thread: the setting; its own #updates is created on first use.
 */
function channel(): string {
	$slug = sanitize_key( (string) setting( 'channel' ) );
	if ( \Flurfunk\Modules\UPDATES_CHANNEL === $slug ) {
		\Flurfunk\Modules\updates_channel();
	}
	return $slug;
}

/**
 * Look at the updates: a thread the first time, a reply for new ones, ticked off when everything
 * is installed. Returns the thread ID (0 = nothing waits, or not known right now).
 */
function run(): int {
	$list = pending();
	return Watch\run(
		STATE,
		null === $list ? null : array_map( __NAMESPACE__ . '\\line', $list ),
		array(
			'channel'   => $list ? channel() : '',
			'title'     => __( 'Updates available', 'flurfunk' ),
			'body'      => static fn ( array $all ) => __( 'Ready to install:', 'flurfunk' ) . "\n- " . implode( "\n- ", $all ),
			'new'       => static fn ( array $new ) => __( 'New since:', 'flurfunk' ) . "\n- " . implode( "\n- ", $new ),
			/* translators: %s: number of days */
			'remind'    => static fn ( int $days ) => sprintf( _n( 'These updates have been waiting for %s day.', 'These updates have been waiting for %s days.', $days, 'flurfunk' ), number_format_i18n( $days ) ),
			'days'      => (int) setting( 'remind' ),
			'card'      => array( PROVIDER, array( 'id' => 'site' ) ),
			'assignees' => (array) setting( 'assign' ),
		)
	);
}
