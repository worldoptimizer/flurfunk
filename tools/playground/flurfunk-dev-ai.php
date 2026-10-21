<?php
/**
 * Plugin Name: Flurfunk dev: AI through a local proxy
 * Description: Local development only (WordPress Playground). Sends the OpenAI provider's
 * requests to an OpenAI-compatible proxy on this machine (FLURFUNK_DEV_AI_PROXY, e.g.
 * http://localhost:8317/v1), so the AI features can be tried without a paid key. Never use this on
 * a real site.
 *
 * @package Flurfunk
 */

defined( 'ABSPATH' ) || exit;

/*
 * The plugin folder is mounted from the working copy: WordPress must never update it, since an
 * update deletes the folder first. (When Playground's file system loses a plugin's files, WordPress
 * can even offer another plugin's update under Flurfunk's name.)
 */
add_filter(
	'upgrader_pre_install',
	static fn ( $ok, $extra ) => str_contains( (string) ( $extra['plugin'] ?? '' ), 'flurfunk/' ) ? new WP_Error( 'flurfunk_dev_mount', 'Flurfunk is mounted from the working copy and is not updated here.' ) : $ok,
	1,
	2
);
add_filter( 'auto_update_plugin', static fn ( $update, $item ) => str_contains( (string) ( $item->plugin ?? '' ), 'flurfunk/' ) ? false : $update, 99, 2 );

/*
 * The German translation from the working copy, found the way a language pack is – also after a
 * switch of language, e.g. for a mail (the plugin ships none; on WordPress.org it comes as a language
 * pack, elsewhere the file goes to wp-content/languages/plugins/).
 */
add_filter(
	'lang_dir_for_domain',
	static fn ( $path, $domain ) => 'flurfunk' === $domain ? WP_PLUGIN_DIR . '/flurfunk/tools/translations/build/' : $path,
	10,
	2
);

if ( ! defined( 'FLURFUNK_DEV_AI_PROXY' ) || ! FLURFUNK_DEV_AI_PROXY ) {
	return;
}

// The connector counts as set up once a key exists; the proxy does not check it.
if ( ! defined( 'OPENAI_API_KEY' ) ) {
	define( 'OPENAI_API_KEY', 'local-proxy' );
}

add_filter(
	'pre_http_request',
	static function ( $pre, array $args, string $url ) {
		$from = 'https://api.openai.com/v1';
		if ( false !== $pre || ! str_starts_with( $url, $from ) ) {
			return $pre;
		}
		$args['timeout']            = max( 120, (int) ( $args['timeout'] ?? 0 ) );
		$args['reject_unsafe_urls'] = false; // The AI client asks for "safe" URLs only; the proxy is on localhost on purpose.
		return wp_remote_request( rtrim( FLURFUNK_DEV_AI_PROXY, '/' ) . substr( $url, strlen( $from ) ), $args );
	},
	10,
	3
);

/*
 * Local Playground only: no login screen. Opening the dashboard or the app on localhost without being
 * logged in logs in the administrator; ?as=redaktion or ?as=gast switches to another account. After
 * logging out it stays out (cookie). Lives in this dev mu-plugin, which only start-ai.sh mounts –
 * never on a real site.
 */
add_action(
	'wp_logout',
	static function () {
		setcookie( 'ff_dev_noauto', '1', 0, '/' );
	}
);
add_action(
	'init',
	static function () {
		$host   = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$remote = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! in_array( $host, array( 'localhost', '127.0.0.1' ), true ) || ! in_array( $remote, array( '127.0.0.1', '::1', '' ), true ) || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		$as   = sanitize_user( (string) ( $_GET['as'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		// Without ?as= only in the dashboard and the full-screen app, and not after logging out
		// (tests and the login screen stay as they are).
		$uri  = (string) ( $_SERVER['REQUEST_URI'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$auto = ( is_admin() || str_contains( $uri, '/flurfunk' ) ) && ! str_contains( $uri, 'admin-ajax.php' ) && empty( $_COOKIE['ff_dev_noauto'] );
		$user = '' !== $as ? get_user_by( 'login', $as ) : ( is_user_logged_in() || ! $auto ? null : get_user_by( 'id', 1 ) );
		if ( ! $user || get_current_user_id() === $user->ID ) {
			return;
		}
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true );
		if ( ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) && 'GET' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			wp_safe_redirect( remove_query_arg( 'as', ( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			exit;
		}
	},
	1
);

/*
 * Local Playground only: made-up visitor numbers, so the site's numbers and snapshots can be tried
 * without a statistics plugin (they replace those of Koko Analytics or WP Statistics, if active). They follow the content a little – each post or page with an excerpt
 * brings visits – so a measure (adding excerpts) shows in the numbers. And a route that keeps a
 * snapshot as of some days ago (with fewer visits), to compare "then" and "now" without waiting.
 */
add_filter(
	'flurfunk_metrics',
	static function ( array $m ): array {
		$with = count(
			array_filter(
				get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'publish', 'posts_per_page' => 200 ) ),
				static fn ( $p ) => '' !== trim( (string) $p->post_excerpt )
			)
		);
		$visits = 800 + 90 * $with + ( (int) gmdate( 'z' ) % 7 ) * 12;
		$m['pageviews_7d'] = array( 'label' => 'Page views in the last 7 days', 'value' => $visits, 'unit' => '', 'source' => 'Demo statistics (made up)' );
		$m['visitors_7d'] = array( 'label' => 'Visitors in the last 7 days', 'value' => (int) round( $visits * 0.62 ), 'unit' => '', 'source' => 'Demo statistics (made up)' );
		$m['search_7d']   = array( 'label' => 'Visits from search engines in the last 7 days', 'value' => (int) round( 120 + 45 * $with ), 'unit' => '', 'source' => 'Demo statistics (made up)' );
		return $m;
	}
);
add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'flurfunk-dev/v1',
			'/snapshot',
			array(
				'methods'             => 'POST',
				'permission_callback' => static fn () => current_user_can( 'manage_options' ),
				'callback'            => static function ( WP_REST_Request $r ) {
					$numbers = Flurfunk\Insights\metrics();
					$scale   = (float) ( $r['scale'] ?? 0.7 );
					foreach ( array( 'pageviews_7d', 'visitors_7d', 'search_7d' ) as $k ) {
						if ( isset( $numbers[ $k ] ) ) {
							$numbers[ $k ]['value'] = (int) round( $numbers[ $k ]['value'] * $scale );
						}
					}
					foreach ( array( 'check_no_description' ) as $k ) {
						if ( isset( $numbers[ $k ] ) && isset( $r['missing'] ) ) {
							$numbers[ $k ]['value'] = (int) $r['missing'];
						}
					}
					return Flurfunk\Insights\take( sanitize_key( (string) $r['channel'] ), (string) $r['note'], $numbers, time() - (int) $r['days'] * DAY_IN_SECONDS );
				},
			)
		);
		// A channel's newest routine run now instead of at its time (or all its routines removed), to
		// try a weekly report without waiting a week.
		register_rest_route(
			'flurfunk-dev/v1',
			'/routine',
			array(
				'methods'             => 'POST',
				'permission_callback' => static fn () => current_user_can( 'manage_options' ),
				'callback'            => static function ( WP_REST_Request $r ) {
					$slug = sanitize_key( (string) $r['channel'] );
					$mine = Flurfunk\Modules\Ai\routines( $slug );
					if ( ! empty( $r['remove'] ) ) {
						$all = (array) get_option( Flurfunk\Modules\Ai\ROUTINES, array() );
						foreach ( array_keys( $mine ) as $id ) {
							unset( $all[ $id ] );
							wp_clear_scheduled_hook( Flurfunk\Modules\Ai\ROUTINE_HOOK, array( $id ) );
						}
						update_option( Flurfunk\Modules\Ai\ROUTINES, $all, false );
						return array( 'removed' => count( $mine ) );
					}
					$id = array_key_last( $mine );
					if ( null === $id ) {
						return new WP_Error( 'flurfunk_dev', 'No routine in this channel.', array( 'status' => 404 ) );
					}
					$before = count( Flurfunk\Insights\snapshots( $slug ) );
					Flurfunk\Modules\Ai\run_routine( (string) $id );
					return array(
						'id'               => $id,
						'thread'           => (int) $mine[ $id ]['thread'],
						'post'             => (string) $mine[ $id ]['post'],
						'snapshots_before' => $before,
						'snapshots_after'  => count( Flurfunk\Insights\snapshots( $slug ) ),
					);
				},
			)
		);
	}
);
