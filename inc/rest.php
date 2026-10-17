<?php
/**
 * REST API for the interface: /wp-json/flurfunk/v1/…
 *
 *   GET  bootstrap                 me, people, channels, providers, counts, look
 *   GET  counts                    unread numbers
 *   GET  threads                   list (view, channel, target, ref_prefix, before, limit, order, q)
 *   POST read                      mark all as read ({ channel } optional)
 *   POST threads                   new thread (with ref_key: returns the existing one if present)
 *   GET  threads/<id>              thread with messages; marks it read
 *   POST threads/<id>/messages     reply
 *   POST threads/<id>/status       open | done | ''
 *   POST threads/<id>/edit         title, body (author or manager)
 *   POST threads/<id>/follow       mode: follow | mute | default
 *   POST threads/<id>/remind       when: hour | tomorrow | monday | none | ISO time
 *   POST threads/<id>/task         due (Y-m-d or ''), assignees (user IDs)
 *   POST messages/<id>             new text (author or manager)
 *   DELETE messages/<id>           remove (a note stays in its place)
 *   GET  providers/<id>/search     suggestions for a slash command
 *   GET  targets?q=                things a thread can be attached to
 *   POST cards/<id>/refresh        fetch a fresh snapshot for a card
 *   POST files                     upload a Flurfunk file (raw body, Content-Disposition: filename)
 *   POST files/<id>/adopt          add a Flurfunk file to the media library
 *   POST me/prefs                  theme, avatar, mail, push, quiet, badge, hour, channel {slug, level}
 *   GET  notifications             the person's notifications (before, limit)
 *   POST notifications             mark read ({ ids } or { all: true })
 *   POST me/push / DELETE me/push  subscribe / unsubscribe this browser; POST me/push/test
 *
 * @package Flurfunk
 */

namespace Flurfunk\Rest;

use Flurfunk\Access;
use Flurfunk\Store;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

const NS = 'flurfunk/v1';

add_action( 'rest_api_init', __NAMESPACE__ . '\\routes' );

/**
 * Routes.
 */
function routes(): void {
	$auth = static fn (): bool => is_user_logged_in() && Access\can_use();
	$id   = array(
		'id' => array(
			'type'     => 'integer',
			'required' => true,
		),
	);
	register_rest_route( NS, '/bootstrap', array( 'methods' => 'GET', 'callback' => __NAMESPACE__ . '\\bootstrap', 'permission_callback' => $auth ) );
	register_rest_route( NS, '/counts', array( 'methods' => 'GET', 'callback' => static fn () => Store\counts( get_current_user_id() ), 'permission_callback' => $auth ) );
	register_rest_route(
		NS,
		'/threads',
		array(
			array( 'methods' => 'GET', 'callback' => __NAMESPACE__ . '\\list_threads', 'permission_callback' => $auth ),
			array( 'methods' => 'POST', 'callback' => __NAMESPACE__ . '\\create_thread', 'permission_callback' => $auth ),
		)
	);
	register_rest_route( NS, '/threads/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => __NAMESPACE__ . '\\get_thread', 'permission_callback' => $auth, 'args' => $id ) );
	register_rest_route( NS, '/threads/(?P<id>\d+)/messages', array( 'methods' => 'POST', 'callback' => __NAMESPACE__ . '\\add_message', 'permission_callback' => $auth, 'args' => $id ) );
	register_rest_route(
		NS,
		'/threads/(?P<id>\d+)/remind',
		array(
			'methods'             => 'POST',
			'callback'            => static function ( WP_REST_Request $r ) {
				$t = Store\thread_row( (int) $r['id'] );
				if ( ! $t || ! Access\can_see( $t ) ) {
					return new \WP_Error( 'flurfunk_forbidden', __( 'This conversation does not exist.', 'flurfunk' ), array( 'status' => 404 ) );
				}
				$at = Store\remind_time( (string) ( $r->get_json_params()['when'] ?? '' ), get_current_user_id() );
				if ( false === $at ) {
					return new \WP_Error( 'flurfunk_invalid', __( 'Please choose a time within the next year.', 'flurfunk' ), array( 'status' => 400 ) );
				}
				Store\set_reminder( (int) $r['id'], get_current_user_id(), $at );
				return array( 'remind' => Store\iso( $at ) );
			},
			'permission_callback' => $auth,
			'args'                => $id,
		)
	);
	register_rest_route(
		NS,
		'/threads/(?P<id>\d+)/follow',
		array(
			'methods'             => 'POST',
			'callback'            => static function ( WP_REST_Request $r ) {
				$t = Store\thread_row( (int) $r['id'] );
				if ( ! $t || ! Access\can_see( $t ) ) {
					return new \WP_Error( 'flurfunk_forbidden', __( 'This conversation does not exist.', 'flurfunk' ), array( 'status' => 404 ) );
				}
				$mode = array( 'follow' => 1, 'mute' => -1 )[ (string) ( $r->get_json_params()['mode'] ?? '' ) ] ?? 0;
				Store\set_follow( (int) $r['id'], get_current_user_id(), $mode );
				return array( 'follow' => $mode );
			},
			'permission_callback' => $auth,
			'args'                => $id,
		)
	);
	register_rest_route( NS, '/threads/(?P<id>\d+)/task', array( 'methods' => 'POST', 'callback' => __NAMESPACE__ . '\\set_task', 'permission_callback' => $auth, 'args' => $id ) );
	register_rest_route( NS, '/threads/(?P<id>\d+)/edit', array( 'methods' => 'POST', 'callback' => __NAMESPACE__ . '\\edit_thread', 'permission_callback' => $auth, 'args' => $id ) );
	register_rest_route(
		NS,
		'/messages/(?P<id>\d+)',
		array(
			array( 'methods' => 'POST', 'callback' => __NAMESPACE__ . '\\edit_message', 'permission_callback' => $auth, 'args' => $id ),
			array( 'methods' => 'DELETE', 'callback' => __NAMESPACE__ . '\\delete_message', 'permission_callback' => $auth, 'args' => $id ),
		)
	);
	register_rest_route( NS, '/threads/(?P<id>\d+)/status', array( 'methods' => 'POST', 'callback' => __NAMESPACE__ . '\\set_status', 'permission_callback' => $auth, 'args' => $id ) );
	register_rest_route( NS, '/providers/(?P<provider>[a-z0-9_\-]+/[a-z0-9_\-]+)/search', array( 'methods' => 'GET', 'callback' => __NAMESPACE__ . '\\search', 'permission_callback' => $auth ) );
	register_rest_route(
		NS,
		'/notifications',
		array(
			array( 'methods' => 'GET', 'callback' => static fn ( WP_REST_Request $r ) => \Flurfunk\Notify\list_for( get_current_user_id(), (int) $r->get_param( 'before' ), (int) ( $r->get_param( 'limit' ) ?: 30 ) ), 'permission_callback' => $auth ),
			array(
				'methods'             => 'POST',
				'callback'            => static function ( WP_REST_Request $r ) {
					$p = (array) $r->get_json_params();
					\Flurfunk\Notify\mark_read( get_current_user_id(), ! empty( $p['all'] ) ? null : array_map( 'intval', (array) ( $p['ids'] ?? array() ) ) );
					return Store\counts( get_current_user_id() );
				},
				'permission_callback' => $auth,
			),
		)
	);
	register_rest_route(
		NS,
		'/me/push',
		array(
			array(
				'methods'             => 'POST',
				'callback'            => static function ( WP_REST_Request $r ) {
					$p  = (array) $r->get_json_params();
					$ok = \Flurfunk\Push\subscribe( get_current_user_id(), (array) ( $p['subscription'] ?? array() ), (string) ( $p['label'] ?? '' ) );
					return is_wp_error( $ok ) ? $ok : array( 'devices' => count( \Flurfunk\Push\devices( get_current_user_id() ) ) );
				},
				'permission_callback' => $auth,
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => static function ( WP_REST_Request $r ) {
					\Flurfunk\Push\unsubscribe( get_current_user_id(), (string) ( $r->get_json_params()['endpoint'] ?? $r->get_param( 'endpoint' ) ?? '' ) );
					return array( 'devices' => count( \Flurfunk\Push\devices( get_current_user_id() ) ) );
				},
				'permission_callback' => $auth,
			),
		)
	);
	register_rest_route(
		NS,
		'/me/push/test',
		array(
			'methods'             => 'POST',
			'callback'            => static fn () => array(
				'sent' => \Flurfunk\Push\send(
					get_current_user_id(),
					array(
						'title' => __( 'Flurfunk works', 'flurfunk' ),
						'body'  => __( 'Notifications on this device are on.', 'flurfunk' ),
						'url'   => \Flurfunk\Admin\url(),
						'tag'   => 'flurfunk-test',
						'icon'  => \Flurfunk\Settings\logo(),
					)
				),
			),
			'permission_callback' => $auth,
		)
	);
	register_rest_route( NS, '/read', array( 'methods' => 'POST', 'callback' => static fn ( WP_REST_Request $r ) => array( 'marked' => Store\mark_all_read( get_current_user_id(), sanitize_key( (string) ( $r->get_json_params()['channel'] ?? '' ) ) ) ) + Store\counts( get_current_user_id() ), 'permission_callback' => $auth ) );
	register_rest_route( NS, '/cards/(?P<id>\d+)/refresh', array( 'methods' => 'POST', 'callback' => static function ( WP_REST_Request $r ) {
		$tid = Store\refresh_card( (int) $r['id'] );
		return is_wp_error( $tid ) ? $tid : decorate( Store\full_thread( $tid, get_current_user_id() ) );
	}, 'permission_callback' => $auth, 'args' => $id ) );
	register_rest_route( NS, '/files', array( 'methods' => 'POST', 'callback' => __NAMESPACE__ . '\\upload', 'permission_callback' => $auth ) );
	register_rest_route( NS, '/files/(?P<id>\d+)/adopt', array( 'methods' => 'POST', 'callback' => __NAMESPACE__ . '\\adopt', 'permission_callback' => $auth, 'args' => $id ) );
	register_rest_route( NS, '/targets', array( 'methods' => 'GET', 'callback' => static fn ( WP_REST_Request $r ) => \Flurfunk\Targets\search( sanitize_text_field( (string) $r->get_param( 'q' ) ), get_current_user_id() ), 'permission_callback' => $auth ) );
	register_rest_route( NS, '/me/prefs', array( 'methods' => 'POST', 'callback' => static fn ( WP_REST_Request $r ) => \Flurfunk\Notify\save_prefs( get_current_user_id(), (array) $r->get_json_params() ), 'permission_callback' => $auth ) );
}

/**
 * Everything the app needs to start.
 */
function bootstrap(): array {
	$uid      = get_current_user_id();
	$s        = \Flurfunk\Settings\get();
	$channels = array();
	foreach ( Store\channels() as $c ) {
		$level = Access\level( $c, $uid );
		if ( 'none' !== $level ) {
			$channels[] = Store\format_channel( $c ) + array( 'level' => $level );
		}
	}
	$me = Store\person( $uid );
	return array(
		'format'    => 'flurfunk/1',
		'me'        => $me + array(
			'prefs'  => \Flurfunk\Notify\prefs( $uid ),
			'manage' => Access\can_manage( $uid ),
			'upload' => current_user_can( 'upload_files' ),
		),
		'people'    => Store\people(),
		'channels'  => $channels,
		'providers' => \Flurfunk\Providers\available( $uid ),
		'counts'    => Store\counts( $uid ),
		'targets'   => my_targets( $uid ),
		'recent'    => array_map( static fn ( $t ) => target_info( $t['type'], $t['id'] ) + array( 'id' => $t['id'] ), Store\recent_targets( $uid ) ),
		'locale'    => str_replace( '_', '-', get_user_locale( $uid ) ),
		'look'      => array(
			'name'    => \Flurfunk\Settings\name(),
			'logo'    => \Flurfunk\Settings\logo(),
			'accent'  => $s['accent'],
			'sidebar' => $s['sidebar'],
		),
		'settings'  => Access\can_manage( $uid ) ? admin_url( 'options-general.php?page=' . \Flurfunk\Settings\PAGE ) : '',
		'push'      => array(
			'available' => \Flurfunk\Push\available() && is_ssl(),
			'key'       => \Flurfunk\Push\available() ? ( \Flurfunk\Push\vapid()['public'] ?? '' ) : '',
			'devices'   => count( \Flurfunk\Push\devices( $uid ) ),
		),
	);
}

/**
 * Posts the person may attach new threads to (newest first; guests: their own).
 *
 * @param int $uid User.
 */
function my_targets( int $uid ): array {
	return array_map(
		static fn ( $t ) => target_info( $t['type'], $t['id'] ) + array( 'id' => $t['id'] ),
		array_slice( \Flurfunk\Targets\search( '', $uid ), 0, 15 )
	);
}

/**
 * Title and link of a target.
 *
 * @param string $type Target type.
 * @param int    $id   Target ID.
 */
function target_info( string $type, int $id ): array {
	static $cache = array();
	$key = $type . ':' . $id;
	if ( ! isset( $cache[ $key ] ) ) {
		$info          = \Flurfunk\Targets\info( $type, $id ) ?? array(
			'title'  => '',
			'url'    => '',
			'status' => '',
			'label'  => '',
		);
		$cache[ $key ] = (array) apply_filters( 'flurfunk_target_info', array( 'type' => $type ) + $info, $type, $id );
	}
	return $cache[ $key ];
}

/**
 * Add target titles for the interface.
 *
 * @param array $t Thread in the data format.
 */
function decorate( array $t ): array {
	if ( $t['target'] ) {
		$t['target'] += target_info( $t['target']['type'], (int) $t['target']['id'] );
	}
	return $t;
}

/**
 * GET threads.
 *
 * @param WP_REST_Request $r Request.
 */
function list_threads( WP_REST_Request $r ): array {
	$args = array_intersect_key( $r->get_params(), array_flip( array( 'view', 'channel', 'target', 'ref_prefix', 'before', 'limit', 'order', 'q' ) ) );
	return array_map( __NAMESPACE__ . '\\decorate', Store\list_threads( get_current_user_id(), array_map( 'sanitize_text_field', $args ) ) );
}

/**
 * GET threads/<id> (marks it as read).
 *
 * @param WP_REST_Request $r Request.
 */
function get_thread( WP_REST_Request $r ) {
	$uid = get_current_user_id();
	$t   = Store\full_thread( (int) $r['id'], $uid );
	if ( is_wp_error( $t ) ) {
		return $t;
	}
	if ( empty( $r['peek'] ) ) {
		Store\mark_read( (int) $r['id'], $uid );
		\Flurfunk\Notify\mark_read( $uid, null, (int) $r['id'] );
	}
	return decorate( $t );
}

/**
 * POST threads.
 *
 * @param WP_REST_Request $r Request.
 */
function create_thread( WP_REST_Request $r ) {
	$p   = (array) $r->get_json_params();
	$ref = sanitize_text_field( (string) ( $p['ref_key'] ?? '' ) );
	if ( '' !== $ref && ( $existing = Store\thread_by_ref( $ref ) ) && Access\can_see( $existing ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
		return decorate( Store\full_thread( (int) $existing->id, get_current_user_id() ) );
	}
	$id = Store\create_thread(
		array(
			'channel'     => (string) ( $p['channel'] ?? '' ),
			'target'      => is_array( $p['target'] ?? null ) ? $p['target'] : null,
			'title'       => (string) ( $p['title'] ?? '' ),
			'body'        => (string) ( $p['body'] ?? '' ),
			'task'        => ! empty( $p['task'] ) || ! empty( $p['due'] ) || ! empty( $p['assignees'] ),
			'due'         => (string) ( $p['due'] ?? '' ),
			'assignees'   => array_map( 'intval', (array) ( $p['assignees'] ?? array() ) ),
			'attachments' => (array) ( $p['attachments'] ?? array() ),
			'ref_key'     => $ref,
		)
	);
	return is_wp_error( $id ) ? $id : decorate( Store\full_thread( $id, get_current_user_id() ) );
}

/**
 * POST threads/<id>/messages.
 *
 * @param WP_REST_Request $r Request.
 */
function add_message( WP_REST_Request $r ) {
	$p  = (array) $r->get_json_params();
	$id = Store\add_message(
		(int) $r['id'],
		array(
			'body'        => (string) ( $p['body'] ?? '' ),
			'files'       => array_map( 'intval', (array) ( $p['files'] ?? array() ) ),
			'attachments' => (array) ( $p['attachments'] ?? array() ),
		)
	);
	return is_wp_error( $id ) ? $id : decorate( Store\full_thread( (int) $r['id'], get_current_user_id() ) );
}

/**
 * POST threads/<id>/status.
 *
 * @param WP_REST_Request $r Request.
 */
function set_status( WP_REST_Request $r ) {
	$ok = Store\set_status( (int) $r['id'], (string) ( $r->get_json_params()['status'] ?? '' ) );
	return is_wp_error( $ok ) ? $ok : decorate( Store\full_thread( (int) $r['id'], get_current_user_id() ) );
}

/**
 * GET providers/<id>/search.
 *
 * @param WP_REST_Request $r Request.
 */
function search( WP_REST_Request $r ) {
	$items = \Flurfunk\Providers\search( (string) $r['provider'], sanitize_text_field( (string) $r->get_param( 'q' ) ), get_current_user_id() );
	return null === $items ? new \WP_Error( 'flurfunk_provider', __( 'Unknown command.', 'flurfunk' ), array( 'status' => 404 ) ) : $items;
}

/**
 * POST threads/<id>/edit.
 *
 * @param WP_REST_Request $r Request.
 */
function edit_thread( WP_REST_Request $r ) {
	$ok = Store\edit_thread( (int) $r['id'], array_intersect_key( (array) $r->get_json_params(), array_flip( array( 'title', 'body' ) ) ) );
	return is_wp_error( $ok ) ? $ok : decorate( Store\full_thread( (int) $r['id'], get_current_user_id() ) );
}

/**
 * POST messages/<id>.
 *
 * @param WP_REST_Request $r Request.
 */
function edit_message( WP_REST_Request $r ) {
	$tid = Store\edit_message( (int) $r['id'], (string) ( $r->get_json_params()['body'] ?? '' ) );
	return is_wp_error( $tid ) ? $tid : decorate( Store\full_thread( $tid, get_current_user_id() ) );
}

/**
 * DELETE messages/<id>.
 *
 * @param WP_REST_Request $r Request.
 */
function delete_message( WP_REST_Request $r ) {
	$tid = Store\delete_message( (int) $r['id'] );
	return is_wp_error( $tid ) ? $tid : decorate( Store\full_thread( $tid, get_current_user_id() ) );
}

/**
 * POST files: the body is the file, the name comes from Content-Disposition (like wp/v2/media).
 *
 * @param WP_REST_Request $r Request.
 */
function upload( WP_REST_Request $r ) {
	$disposition = (string) $r->get_header( 'content_disposition' );
	$name        = preg_match( '/filename\*?=(?:UTF-8\'\')?"?([^";]+)"?/i', $disposition, $m ) ? rawurldecode( $m[1] ) : 'datei';
	$id          = \Flurfunk\Files\store( $name, (string) $r->get_body() );
	return is_wp_error( $id ) ? $id : Store\file_view( Store\files( array( $id ), get_current_user_id(), true )[0] ?? array( 'id' => $id ) );
}

/**
 * POST files/<id>/adopt.
 *
 * @param WP_REST_Request $r Request.
 */
function adopt( WP_REST_Request $r ) {
	$ok = \Flurfunk\Files\adopt( (int) $r['id'] );
	return is_wp_error( $ok ) ? $ok : Store\file_view( Store\files( array( (int) $r['id'] ), get_current_user_id(), false )[0] ?? array() );
}

/**
 * POST threads/<id>/task.
 *
 * @param WP_REST_Request $r Request.
 */
function set_task( WP_REST_Request $r ) {
	$ok = Store\set_task( (int) $r['id'], array_intersect_key( (array) $r->get_json_params(), array_flip( array( 'due', 'assignees' ) ) ) );
	return is_wp_error( $ok ) ? $ok : decorate( Store\full_thread( (int) $r['id'], get_current_user_id() ) );
}
