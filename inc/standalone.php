<?php
/**
 * Flurfunk on its own: a full-screen page outside wp-admin (no admin bar, no menu, no theme),
 * installable as a web app on a phone's home screen.
 *
 * Address: home_url( '/flurfunk/' ) (slug via the flurfunk_app_slug filter), or
 * home_url( '/?flurfunk_app=1' ) without pretty permalinks. Takes the same ?view= as the
 * admin page. The manifest points there, so the installed app opens it.
 *
 * @package Flurfunk
 */

namespace Flurfunk\Standalone;

use Flurfunk\Access;
use Flurfunk\Admin;
use Flurfunk\Settings;

defined( 'ABSPATH' ) || exit;

const QUERY_VAR = 'flurfunk_app';
const OPTION    = 'flurfunk_rewrite'; // Version and slug the rewrite rules were last flushed for.

/**
 * Path segment of the address, e.g. "flurfunk" or "team/chat".
 */
function slug(): string {
	$slug = trim( strtolower( (string) apply_filters( 'flurfunk_app_slug', 'flurfunk' ) ), '/' );
	$slug = trim( (string) preg_replace( '#[^a-z0-9_/-]+#', '', $slug ), '/' );
	return '' !== $slug ? $slug : 'flurfunk';
}

/**
 * Address of the full-screen app.
 *
 * @param string $view Optional view ("channel:general", "target:post:12", "thread:5").
 */
function url( string $view = '' ): string {
	$base = get_option( 'permalink_structure' ) ? home_url( '/' . slug() . '/' ) : add_query_arg( QUERY_VAR, '1', home_url( '/' ) );
	return '' !== $view ? add_query_arg( 'view', rawurlencode( $view ), $base ) : $base;
}

/**
 * Web app scope: the app's folder (the whole site without pretty permalinks).
 */
function scope(): string {
	return get_option( 'permalink_structure' ) ? url() : home_url( '/' );
}

/**
 * Is this request the full-screen app?
 */
function is_app(): bool {
	return ! is_admin() && (bool) get_query_var( QUERY_VAR );
}

add_filter(
	'query_vars',
	static function ( array $vars ): array {
		$vars[] = QUERY_VAR;
		return $vars;
	}
);

add_action(
	'init',
	static function (): void {
		add_rewrite_rule( '^' . slug() . '/?$', 'index.php?' . QUERY_VAR . '=1', 'top' );
	}
);

// Flush once after activation, an update or a changed slug – after init, so every plugin's rules are in.
add_action(
	'wp_loaded',
	static function (): void {
		$key = FLURFUNK_VERSION . '|' . slug();
		if ( get_option( OPTION ) !== $key ) {
			flush_rewrite_rules( false );
			update_option( OPTION, $key, true );
		}
	}
);

// Before core sets up the admin bar (template_redirect, priority 0).
add_action(
	'wp',
	static function (): void {
		if ( is_app() ) {
			show_admin_bar( false );
		}
	}
);

/*
 * The page: logged out → login, no access → 403, otherwise our own minimal document.
 */
add_action(
	'template_redirect',
	static function (): void {
		if ( ! is_app() ) {
			return;
		}
		nocache_headers();
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		$view = isset( $_GET['view'] ) && is_string( $_GET['view'] ) ? sanitize_text_field( wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		// auth_redirect() checks the wp-admin cookie, which the front end never receives; the login cookie is enough here.
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( url( $view ) ) );
			exit;
		}
		if ( ! Access\can_use() ) {
			wp_die(
				esc_html__( 'You are logged in, but Flurfunk is not available for your account. Please ask an administrator if you need access.', 'flurfunk' ),
				esc_html__( 'Flurfunk', 'flurfunk' ),
				array(
					'response'  => 403,
					'link_url'  => admin_url(),
					'link_text' => __( 'Go to the dashboard', 'flurfunk' ),
				)
			);
		}
		render();
		exit;
	},
	1
);

/**
 * Output the page. Only Flurfunk's own styles and scripts (and the media library, for the
 * picker): no wp_head()/wp_footer(), so neither the theme nor the admin bar get in.
 */
function render(): void {
	Admin\enqueue();
	$media = current_user_can( 'upload_files' );
	if ( $media ) {
		wp_enqueue_media();
	}
	$title = Settings\name() . ' · ' . __( 'Flurfunk', 'flurfunk' );
	header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
	?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title><?php echo esc_html( $title ); ?></title>
	<?php
	head();
	printf( '<style>html{background:%s}</style>', esc_attr( Settings\get()['sidebar'] ) );
	wp_styles()->do_items();
	wp_scripts()->do_head_items();
	?>
</head>
<body class="flurfunk-standalone">
<h1 class="screen-reader-text"><?php echo esc_html( $title ); ?></h1>
<div id="flurfunk-app"></div>
	<?php
	if ( $media && function_exists( 'wp_print_media_templates' ) ) {
		wp_print_media_templates();
	}
	print_late_styles();
	print_footer_scripts();
	?>
</body>
</html>
	<?php
}

/**
 * Web app tags for the <head>: manifest, colours, home screen icon and title.
 * Used by this page and by the admin page (so either can be added to the home screen).
 */
function head(): void {
	printf( '<link rel="manifest" href="%s">' . "\n", esc_url( admin_url( 'admin-ajax.php?action=flurfunk_manifest' ) ) );
	printf( '<meta name="theme-color" content="%s">' . "\n", esc_attr( Settings\get()['sidebar'] ) );
	echo '<meta name="mobile-web-app-capable" content="yes">' . "\n" . '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
	printf( '<meta name="apple-mobile-web-app-title" content="%s">' . "\n", esc_attr( Settings\name() ) );
	printf( '<link rel="apple-touch-icon" href="%s">' . "\n", esc_url( touch_icon() ) );
}

/**
 * Home screen icon for iOS: site icon, else logo, else Flurfunk's own.
 */
function touch_icon(): string {
	$logo = (int) Settings\get()['logo_id'];
	$src  = $logo ? wp_get_attachment_image_src( $logo, array( 180, 180 ) ) : false;
	return get_site_icon_url( 180 ) ?: ( $src ? (string) $src[0] : FLURFUNK_URL . 'assets/icon-192.png' );
}

/**
 * Manifest icons: the site icon or the logo at 192 and 512 px; without either, Flurfunk's own
 * (full-bleed, so it also works as a maskable icon).
 */
function icons(): array {
	$icons = array();
	$logo  = (int) Settings\get()['logo_id'];
	foreach ( array( 192, 512 ) as $size ) {
		$site = get_site_icon_url( $size );
		$src  = $site ? array( $site, $size, $size ) : ( $logo ? wp_get_attachment_image_src( $logo, array( $size, $size ) ) : false );
		if ( $src ) {
			$icons[] = array(
				'src'     => $src[0],
				'sizes'   => (int) $src[1] . 'x' . (int) $src[2],
				'purpose' => 'any',
			);
		}
	}
	if ( ! $icons ) {
		foreach ( array( 'any', 'maskable' ) as $purpose ) {
			foreach ( array( 192, 512 ) as $size ) {
				$icons[] = array(
					'src'     => FLURFUNK_URL . 'assets/icon-' . $size . '.png',
					'sizes'   => $size . 'x' . $size,
					'type'    => 'image/png',
					'purpose' => $purpose,
				);
			}
		}
	}
	return $icons;
}

/**
 * Web app manifest. Public: browsers fetch manifests without cookies, and it only holds
 * what the login page shows anyway (name, colours, icon).
 */
function manifest(): array {
	$colour = Settings\get()['sidebar'];
	return (array) apply_filters(
		'flurfunk_manifest',
		array(
			'id'               => url(),
			'name'             => Settings\name() . ' · ' . __( 'Flurfunk', 'flurfunk' ),
			'short_name'       => Settings\name(),
			'lang'             => get_bloginfo( 'language' ),
			'start_url'        => url(),
			'scope'            => scope(),
			'display'          => 'standalone',
			'background_color' => $colour,
			'theme_color'      => $colour,
			'icons'            => icons(),
		)
	);
}

$flurfunk_manifest = static function (): void {
	header( 'Content-Type: application/manifest+json; charset=utf-8' );
	echo wp_json_encode( manifest(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	exit;
};
add_action( 'wp_ajax_flurfunk_manifest', $flurfunk_manifest );
add_action( 'wp_ajax_nopriv_flurfunk_manifest', $flurfunk_manifest );
unset( $flurfunk_manifest );
