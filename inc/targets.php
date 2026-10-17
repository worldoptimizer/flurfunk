<?php
/**
 * Targets: what a thread can be attached to. Built in: posts of the configured post types.
 * Plugins add more (a dossier, a product, a person …):
 *
 *   flurfunk_register_target( 'dossier', array(
 *       'label'  => 'Dossier',
 *       'search' => fn ( string $q, int $user_id ) => array( array( 'id' => 12, 'title' => 'Travel', 'subtitle' => '12 posts' ) ),
 *       'info'   => fn ( int $id ) => array( 'title' => 'Travel', 'url' => '…' ),   // null = gone
 *       'can'    => fn ( int $user_id, int $id ) => true,                              // default: team only
 *   ) );
 *
 * Guests can only attach threads to their own posts ("own-posts" channels).
 *
 * @package Flurfunk
 */

namespace Flurfunk\Targets;

use Flurfunk\Access;

defined( 'ABSPATH' ) || exit;

/**
 * Registry.
 *
 * @return array<string, array>
 */
function &registry(): array {
	static $types = array();
	return $types;
}

/**
 * Register a target type.
 *
 * @param string $type Type key (lowercase, up to 32 characters).
 * @param array  $args label, search, info, can.
 */
function register( string $type, array $args ): void {
	$type = substr( sanitize_key( $type ), 0, 32 );
	if ( '' === $type || ! is_callable( $args['info'] ?? null ) ) {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'A Flurfunk target type needs an info callback.', 'flurfunk' ), '0.2.0' );
		return;
	}
	$registry          = &registry();
	$registry[ $type ] = array(
		'type'   => $type,
		'label'  => (string) ( $args['label'] ?? $type ),
		'search' => is_callable( $args['search'] ?? null ) ? $args['search'] : null,
		'info'   => $args['info'],
		'can'    => is_callable( $args['can'] ?? null ) ? $args['can'] : null,
	);
}

/**
 * May the person attach a thread to this target?
 *
 * @param string $type    Type.
 * @param int    $id      ID.
 * @param int    $user_id User.
 */
function can( string $type, int $id, int $user_id ): bool {
	$t = registry()[ $type ] ?? null;
	if ( ! $t || ! $id ) {
		return false;
	}
	if ( $t['can'] ) {
		return (bool) call_user_func( $t['can'], $user_id, $id );
	}
	return 'team' === Access\kind( $user_id ) && null !== info( $type, $id );
}

/**
 * Title, link and status of a target ('' title when gone).
 *
 * @param string $type Type.
 * @param int    $id   ID.
 */
function info( string $type, int $id ): ?array {
	$t = registry()[ $type ] ?? null;
	$i = $t ? call_user_func( $t['info'], $id ) : null;
	return is_array( $i ) ? array(
		'title'  => wp_strip_all_tags( (string) ( $i['title'] ?? '' ) ),
		'url'    => esc_url_raw( (string) ( $i['url'] ?? '' ) ),
		'status' => wp_strip_all_tags( (string) ( $i['status'] ?? '' ) ),
		'label'  => $t['label'],
	) : null;
}

/**
 * Search all target types the person may use.
 *
 * @param string $q       Search text.
 * @param int    $user_id User.
 * @return array[] { type, id, title, subtitle, label }
 */
function search( string $q, int $user_id ): array {
	$out   = array();
	$guest = 'team' !== Access\kind( $user_id );
	foreach ( registry() as $t ) {
		if ( ! $t['search'] || ( $guest && 'post' !== $t['type'] ) ) {
			continue;
		}
		foreach ( (array) call_user_func( $t['search'], $q, $user_id ) as $item ) {
			$id = (int) ( $item['id'] ?? 0 );
			if ( $id && can( $t['type'], $id, $user_id ) ) {
				$out[] = array(
					'type'     => $t['type'],
					'id'       => $id,
					'title'    => wp_strip_all_tags( (string) ( $item['title'] ?? '' ) ),
					'subtitle' => wp_strip_all_tags( (string) ( $item['subtitle'] ?? '' ) ),
					'label'    => $t['label'],
				);
			}
		}
	}
	return array_slice( $out, 0, 30 );
}

/*
 * Built in: posts. Guests only their own; the team all of the configured post types.
 */
add_action(
	'init',
	static function (): void {
		register(
			'post',
			array(
				'label'  => __( 'Post', 'flurfunk' ),
				'search' => static function ( string $q, int $user_id ): array {
					$args = array(
						'post_type'      => \Flurfunk\Settings\get()['post_types'],
						'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
						'posts_per_page' => 15,
						'orderby'        => '' === $q ? 'modified' : 'relevance',
						's'              => $q,
					);
					if ( 'team' !== Access\kind( $user_id ) ) {
						$args['post__in'] = Access\owned_post_ids( $user_id ) ?: array( 0 );
					}
					return array_map(
						static fn ( \WP_Post $p ) => array(
							'id'       => $p->ID,
							'title'    => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ) ?: __( '(no title)', 'flurfunk' ),
							'subtitle' => implode( ' · ', array_filter( array( count( \Flurfunk\Settings\get()['post_types'] ) > 1 ? ( get_post_type_object( $p->post_type )->labels->singular_name ?? '' ) : '', get_post_status_object( $p->post_status )->label ?? '', get_the_date( '', $p ) ) ) ),
						),
						get_posts( $args )
					);
				},
				'info'   => static function ( int $id ): ?array {
					$p = get_post( $id );
					if ( ! $p ) {
						return null;
					}
					return array(
						'title'  => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ) ?: __( '(no title)', 'flurfunk' ),
						'url'    => current_user_can( 'edit_post', $id ) ? (string) get_edit_post_link( $id, 'raw' ) : (string) get_permalink( $p ),
						'status' => get_post_status_object( $p->post_status )->label ?? $p->post_status,
					);
				},
				'can'    => static fn ( int $user_id, int $id ): bool => (bool) get_post( $id ) && ( 'team' === Access\kind( $user_id ) || in_array( $id, Access\owned_post_ids( $user_id ), true ) ),
			)
		);
	},
	5
);
