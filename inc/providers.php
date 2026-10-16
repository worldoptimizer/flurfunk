<?php
/**
 * Providers: the extension API for slash commands and cards.
 *
 * Flurfunk itself only knows text, mentions and files. Everything else comes from providers
 * that other plugins register:
 *
 *   flurfunk_register_provider( 'myplugin/report', array(
 *       'command'  => 'report',                       // typed as /report
 *       'label'    => 'Report',
 *       'hint'     => 'Insert a report card',
 *       'search'   => fn ( string $q, int $user_id ) => array(   // suggestions while typing
 *           array( 'ref' => array( 'id' => 12 ), 'title' => 'Q3', 'subtitle' => 'Draft' ),
 *       ),
 *       'snapshot' => fn ( array $ref, int $user_id ) => array(  // stored with the message;
 *           'title' => 'Q3', 'subtitle' => 'Draft', 'url' => '…', // null = not allowed
 *       ),
 *       'can'      => fn ( int $user_id ) => true,   // offer the command to this person?
 *   ) );
 *
 * A message stores the reference plus a small snapshot, so it stays readable after the source
 * is gone or the data moves elsewhere. Snapshots follow a generic card shape the interface can
 * draw without extra code:
 *
 *   title, subtitle, text, url, image, audio,
 *   metric  { value, label }      big number
 *   spark   [ numbers ]           small bar chart
 *   meta    [ strings ]           small facts
 *   target  { type, id }          "open conversations about this" link
 *   action  { label, url }        button
 *
 * A plugin that needs a special look registers a renderer in JavaScript:
 *   window.Flurfunk.registerCard( 'myplugin/report', ( snapshot, h ) => h.card( … ) ).
 *
 * @package Flurfunk
 */

namespace Flurfunk\Providers;

use Flurfunk\Access;

defined( 'ABSPATH' ) || exit;

/**
 * Registry.
 *
 * @return array<string, array>
 */
function &registry(): array {
	static $providers = array();
	return $providers;
}

/**
 * Register a provider.
 *
 * @param string $id   Identifier ("vendor/name").
 * @param array  $args See file header.
 */
function register( string $id, array $args ): void {
	$command = sanitize_key( (string) ( $args['command'] ?? '' ) );
	if ( '' === $command || ! is_callable( $args['snapshot'] ?? null ) ) {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'A Flurfunk provider needs a command and a snapshot callback.', 'flurfunk' ), '0.1.0' );
		return;
	}
	$registry        = &registry();
	$registry[ $id ] = array(
		'id'       => $id,
		'command'  => $command,
		'label'    => (string) ( $args['label'] ?? $command ),
		'hint'     => (string) ( $args['hint'] ?? '' ),
		'search'   => is_callable( $args['search'] ?? null ) ? $args['search'] : null,
		'snapshot' => $args['snapshot'],
		'can'      => is_callable( $args['can'] ?? null ) ? $args['can'] : null,
	);
}

/**
 * Providers a person may use (for the interface).
 *
 * @param int $user_id User.
 * @return array[] id, command, label, hint.
 */
function available( int $user_id ): array {
	$out = array();
	foreach ( registry() as $p ) {
		if ( ! $p['can'] || call_user_func( $p['can'], $user_id ) ) {
			$out[] = array(
				'id'      => $p['id'],
				'command' => $p['command'],
				'label'   => $p['label'],
				'hint'    => $p['hint'],
			);
		}
	}
	return $out;
}

/**
 * Suggestions while typing.
 *
 * @param string $id      Provider.
 * @param string $query   Search text.
 * @param int    $user_id User.
 * @return array[]|null Null if the provider is unknown or not allowed.
 */
function search( string $id, string $query, int $user_id ): ?array {
	$p = registry()[ $id ] ?? null;
	if ( ! $p || ( $p['can'] && ! call_user_func( $p['can'], $user_id ) ) ) {
		return null;
	}
	if ( ! $p['search'] ) {
		return array();
	}
	$items = array();
	foreach ( (array) call_user_func( $p['search'], $query, $user_id ) as $item ) {
		if ( is_array( $item['ref'] ?? null ) ) {
			$items[] = array(
				'ref'      => $item['ref'],
				'title'    => wp_strip_all_tags( (string) ( $item['title'] ?? '' ) ),
				'subtitle' => wp_strip_all_tags( (string) ( $item['subtitle'] ?? '' ) ),
			);
		}
	}
	return array_slice( $items, 0, 20 );
}

/**
 * Snapshot for a reference (checks permissions via the provider).
 *
 * @param string $id      Provider.
 * @param array  $ref     Reference.
 * @param int    $user_id User.
 */
function snapshot( string $id, array $ref, int $user_id ): ?array {
	$p = registry()[ $id ] ?? null;
	if ( ! $p || ( $p['can'] && ! call_user_func( $p['can'], $user_id ) ) ) {
		return null;
	}
	$snap = call_user_func( $p['snapshot'], $ref, $user_id );
	return is_array( $snap ) ? clean_snapshot( $snap ) : null;
}

/**
 * Keep only the generic card fields, as plain values.
 *
 * @param array $s Snapshot from a provider.
 */
function clean_snapshot( array $s ): array {
	$out = array();
	foreach ( array( 'title', 'subtitle', 'text' ) as $k ) {
		if ( isset( $s[ $k ] ) ) {
			$out[ $k ] = wp_strip_all_tags( (string) $s[ $k ] );
		}
	}
	foreach ( array( 'url', 'image', 'audio' ) as $k ) {
		if ( ! empty( $s[ $k ] ) ) {
			$out[ $k ] = esc_url_raw( (string) $s[ $k ] );
		}
	}
	if ( isset( $s['metric']['value'] ) ) {
		$out['metric'] = array(
			'value' => wp_strip_all_tags( (string) $s['metric']['value'] ),
			'label' => wp_strip_all_tags( (string) ( $s['metric']['label'] ?? '' ) ),
		);
	}
	if ( ! empty( $s['spark'] ) && is_array( $s['spark'] ) ) {
		$out['spark'] = array_map( 'floatval', array_slice( array_values( $s['spark'] ), 0, 60 ) );
	}
	if ( ! empty( $s['meta'] ) && is_array( $s['meta'] ) ) {
		$out['meta'] = array_map( static fn ( $m ) => wp_strip_all_tags( (string) $m ), array_slice( $s['meta'], 0, 6 ) );
	}
	if ( isset( $s['target']['type'], $s['target']['id'] ) ) {
		$out['target'] = array(
			'type' => sanitize_key( (string) $s['target']['type'] ),
			'id'   => (int) $s['target']['id'],
		);
	}
	if ( isset( $s['action']['url'] ) ) {
		$out['action'] = array(
			'label' => wp_strip_all_tags( (string) ( $s['action']['label'] ?? '' ) ),
			'url'   => esc_url_raw( (string) $s['action']['url'] ),
		);
	}
	// Providers may keep extra data for their own renderer under "data".
	if ( isset( $s['data'] ) && is_array( $s['data'] ) ) {
		$out['data'] = json_decode( (string) wp_json_encode( $s['data'] ), true );
	}
	return $out;
}

/**
 * Short key of a reference for lookups ("where was post 12 referenced?").
 *
 * @param array $ref Reference.
 */
function ref_key( array $ref ): string {
	if ( isset( $ref['id'] ) && is_scalar( $ref['id'] ) ) {
		return substr( (string) $ref['id'], 0, 191 );
	}
	return substr( md5( (string) wp_json_encode( $ref ) ), 0, 32 );
}

/*
 * Built-in provider: posts (any type the person may edit). Plugins can register more, or
 * unregister this one with remove_action( 'init', 'Flurfunk\Providers\register_builtin', 20 ).
 */
add_action( 'init', __NAMESPACE__ . '\\register_builtin', 20 );

/**
 * Register the built-in post provider.
 */
function register_builtin(): void {
	register(
		'flurfunk/post',
		array(
			'command'  => _x( 'post', 'slash command', 'flurfunk' ),
			'label'    => __( 'Post', 'flurfunk' ),
			'hint'     => __( 'Insert a post as a card', 'flurfunk' ),
			'search'   => __NAMESPACE__ . '\\search_posts',
			'snapshot' => __NAMESPACE__ . '\\post_snapshot',
		)
	);
}

/**
 * Posts a person may reference.
 *
 * @param string $q       Search text.
 * @param int    $user_id User.
 */
function search_posts( string $q, int $user_id ): array {
	$args = array(
		'post_type'      => \Flurfunk\Settings\get()['post_types'],
		'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
		'posts_per_page' => 12,
		'orderby'        => 'modified',
		's'              => $q,
	);
	if ( 'team' !== Access\kind( $user_id ) ) {
		$args['post__in'] = Access\owned_post_ids( $user_id ) ?: array( 0 );
	}
	return array_map(
		static fn ( \WP_Post $p ) => array(
			'ref'      => array( 'id' => $p->ID ),
			'title'    => get_the_title( $p ) ?: __( '(no title)', 'flurfunk' ),
			'subtitle' => get_post_status_object( $p->post_status )->label ?? $p->post_status,
		),
		get_posts( $args )
	);
}

/**
 * Card for a post.
 *
 * @param array $ref     { id }.
 * @param int   $user_id User.
 */
function post_snapshot( array $ref, int $user_id ): ?array {
	$p = get_post( (int) ( $ref['id'] ?? 0 ) );
	if ( ! $p || ! Access\can_target( 'post', $p->ID, $user_id ) ) {
		return null;
	}
	$author = get_userdata( (int) $p->post_author );
	return array(
		'title'    => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
		'subtitle' => implode( ' · ', array_filter( array( get_post_status_object( $p->post_status )->label ?? '', $author ? $author->display_name : '' ) ) ),
		'image'    => (string) get_the_post_thumbnail_url( $p, 'thumbnail' ),
		'url'      => (string) get_edit_post_link( $p->ID, 'raw' ),
		'target'   => array(
			'type' => 'post',
			'id'   => $p->ID,
		),
	);
}
