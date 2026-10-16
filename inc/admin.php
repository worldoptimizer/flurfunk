<?php
/**
 * Where Flurfunk shows up in wp-admin:
 * - its own page (menu item with unread badge; OpenStation's dock reads the same badge),
 * - a "Conversations" panel in the block editor,
 * - a column in the posts list,
 * - a desktop icon when OpenStation is active (works just as well without it).
 *
 * @package Flurfunk
 */

namespace Flurfunk\Admin;

use Flurfunk\Access;
use Flurfunk\Settings;
use Flurfunk\Store;

defined( 'ABSPATH' ) || exit;

const PAGE = 'flurfunk';

/**
 * URL of the app.
 *
 * @param string $view Optional view ("channel:general", "target:post:12", "thread:5").
 */
function url( string $view = '' ): string {
	return admin_url( 'admin.php?page=' . PAGE . ( '' !== $view ? '&view=' . rawurlencode( $view ) : '' ) );
}

/**
 * Config for the JavaScript.
 */
function config(): array {
	return array(
		'root'          => esc_url_raw( rest_url( 'flurfunk/v1/' ) ),
		'nonce'         => wp_create_nonce( 'wp_rest' ),
		'fileEndpoint'  => esc_url_raw( rest_url( 'flurfunk/v1/files' ) ),
		'appUrl'        => url(),
		'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
		'loginUrl'      => wp_login_url( url() ),
		'i18n'          => \Flurfunk\Strings\all(),
	);
}

/**
 * Load the app's script and styles.
 */
function enqueue(): void {
	wp_enqueue_style( 'flurfunk', FLURFUNK_URL . 'assets/app.css', array(), (string) filemtime( FLURFUNK_DIR . 'assets/app.css' ) );
	wp_enqueue_script( 'flurfunk', FLURFUNK_URL . 'assets/app.js', array(), (string) filemtime( FLURFUNK_DIR . 'assets/app.js' ), true );
	wp_add_inline_script( 'flurfunk', 'window.flurfunkConfig = ' . wp_json_encode( config() ) . ';', 'before' );
}

add_action(
	'admin_menu',
	static function (): void {
		if ( ! Access\can_use() ) {
			return;
		}
		$n     = \Flurfunk\Notify\prefs( get_current_user_id() )['badge'] ? Store\counts( get_current_user_id() )['total'] : 0;
		$badge = $n ? ' <span class="awaiting-mod count-' . $n . '"><span class="pending-count">' . number_format_i18n( $n ) . '</span></span>' : '';
		$hook  = add_menu_page( __( 'Flurfunk', 'flurfunk' ), esc_html__( 'Flurfunk', 'flurfunk' ) . $badge, 'read', PAGE, __NAMESPACE__ . '\\render', 'dashicons-format-chat', 3 );
		add_action( 'load-' . $hook, __NAMESPACE__ . '\\load_page' );
	}
);

/**
 * Our page: assets, no admin notices, full height.
 */
function load_page(): void {
	add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue' );
	add_filter( 'admin_body_class', static fn ( string $c ): string => $c . ' flurfunk-page' );
	remove_all_actions( 'admin_notices' );
	remove_all_actions( 'all_admin_notices' );
}

/**
 * The page itself.
 */
function render(): void {
	echo '<div class="wrap flurfunk-wrap"><h1 class="screen-reader-text">' . esc_html__( 'Flurfunk', 'flurfunk' ) . '</h1><div id="flurfunk-app"></div></div>';
}

/*
 * Block editor: "Conversations" panel.
 */
add_action(
	'enqueue_block_editor_assets',
	static function (): void {
		$screen = get_current_screen();
		if ( ! Access\can_use() || ! Settings\get()['editor_panel'] || ! $screen || ! in_array( $screen->post_type, Settings\get()['post_types'], true ) ) {
			return;
		}
		enqueue();
		wp_enqueue_script( 'flurfunk-editor', FLURFUNK_URL . 'assets/editor.js', array( 'flurfunk', 'wp-plugins', 'wp-editor', 'wp-element', 'wp-data' ), (string) filemtime( FLURFUNK_DIR . 'assets/editor.js' ), true );
	}
);

/*
 * Posts list: column with the number of conversations and open tasks.
 */
add_action(
	'admin_init',
	static function (): void {
		if ( ! Access\can_use() || ! Settings\get()['posts_column'] ) {
			return;
		}
		foreach ( Settings\get()['post_types'] as $pt ) {
			add_filter(
				"manage_{$pt}_posts_columns",
				static function ( array $cols ): array {
					$cols['flurfunk'] = '<span class="dashicons dashicons-format-chat" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'Conversations', 'flurfunk' ) . '</span>';
					return $cols;
				}
			);
			add_action( "manage_{$pt}_posts_custom_column", __NAMESPACE__ . '\\column', 10, 2 );
		}
	}
);

/**
 * Column content.
 *
 * @param string $col     Column.
 * @param int    $post_id Post.
 */
function column( string $col, int $post_id ): void {
	global $wpdb, $wp_query;
	static $stats = null;
	if ( 'flurfunk' !== $col ) {
		return;
	}
	if ( null === $stats ) {
		$stats = array();
		$ids   = array_map( 'intval', wp_list_pluck( (array) ( $wp_query->posts ?? array() ), 'ID' ) );
		if ( $ids ) {
			$rows = $wpdb->get_results( 'SELECT t.target_id, COUNT(*) n, SUM(t.status = \'open\') open FROM ' . \Flurfunk\Schema\table( 'threads' ) . " t WHERE t.target_type = 'post' AND t.target_id IN (" . implode( ',', $ids ) . ') AND ' . Access\where( get_current_user_id() ) . ' GROUP BY t.target_id' ); // phpcs:ignore WordPress.DB
			foreach ( $rows as $r ) {
				$stats[ (int) $r->target_id ] = $r;
			}
		}
	}
	$s = $stats[ $post_id ] ?? null;
	if ( ! $s ) {
		echo '<span aria-hidden="true">–</span>';
		return;
	}
	printf(
		'<a href="%s" class="flurfunk-count">%s</a>',
		esc_url( url( 'target:post:' . $post_id ) ),
		esc_html(
			(int) $s->open
				/* translators: 1: conversations, 2: open tasks */
				? sprintf( __( '%1$d · %2$d open', 'flurfunk' ), $s->n, $s->open )
				: number_format_i18n( (int) $s->n )
		)
	);
}

/*
 * OpenStation: a desktop icon. Sites can move or drop it with the flurfunk_openstation_icon filter.
 */
add_action(
	'init',
	static function (): void {
		if ( ! function_exists( 'openstation_register_icon' ) || ! is_user_logged_in() || ! Access\can_use() ) {
			return;
		}
		$args = apply_filters(
			'flurfunk_openstation_icon',
			array(
				'title'    => __( 'Flurfunk', 'flurfunk' ),
				'icon'     => 'dashicons-format-chat',
				'url'      => url(),
				'position' => 50,
			)
		);
		if ( $args ) {
			openstation_register_icon( 'flurfunk', $args );
		}
	},
	25
);
