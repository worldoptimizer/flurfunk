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
