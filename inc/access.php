<?php
/**
 * Who sees what.
 *
 * A person is "team" (may edit other people's posts) or "guest" (writes only their own posts,
 * e.g. guest authors) – or has no access. Each channel sets a level for both sides:
 *   all        every thread in the channel
 *   own-posts  only threads attached to one of their own posts
 *   none       nothing
 *
 * Adjust with the filters flurfunk_user_kind and flurfunk_owned_post_ids (e.g. to count
 * co-authors from another plugin). The required capabilities come from the settings.
 *
 * @package Flurfunk
 */

namespace Flurfunk\Access;

defined( 'ABSPATH' ) || exit;

const LEVELS = array( 'all', 'own-posts', 'none' );

/**
 * Kind of person.
 *
 * @param int $user_id User (0 = current).
 * @return string 'team', 'guest' or ''.
 */
function kind( int $user_id = 0 ): string {
	static $cache = array();
	$user_id = $user_id ?: get_current_user_id();
	if ( isset( $cache[ $user_id ] ) ) {
		return $cache[ $user_id ];
	}
	$user = get_userdata( $user_id );
	$kind = '';
	if ( $user ) {
		$s    = \Flurfunk\Settings\get();
		$kind = user_can( $user, $s['team_cap'] ) ? 'team' : ( '' !== $s['guest_cap'] && user_can( $user, $s['guest_cap'] ) ? 'guest' : '' );
		$kind = (string) apply_filters( 'flurfunk_user_kind', $kind, $user );
	}
	return $cache[ $user_id ] = $kind;
}

/**
 * May the person use Flurfunk at all?
 *
 * @param int $user_id User (0 = current).
 */
function can_use( int $user_id = 0 ): bool {
	return '' !== kind( $user_id );
}

/**
 * May the person manage channels and settings?
 *
 * @param int $user_id User (0 = current).
 */
function can_manage( int $user_id = 0 ): bool {
	return user_can( $user_id ?: get_current_user_id(), 'manage_options' );
}

/**
 * Access level of a person in a channel.
 *
 * @param object $channel Channel row.
 * @param int    $user_id User.
 */
function level( object $channel, int $user_id ): string {
	static $cache = array();
	$key = $channel->id . ':' . $user_id;
	if ( isset( $cache[ $key ] ) ) {
		return $cache[ $key ];
	}
	$kind  = kind( $user_id );
	$level = '' === $kind || ! empty( $channel->archived ) ? 'none' : ( 'team' === $kind ? (string) $channel->access_team : (string) $channel->access_guests );
	// E.g. a private channel for a few people: return 'none' for everyone else.
	$level = (string) apply_filters( 'flurfunk_channel_level', $level, $channel, $user_id, $kind );
	return $cache[ $key ] = in_array( $level, LEVELS, true ) ? $level : 'none';
}

/**
 * Posts that belong to a person (for "own-posts").
 *
 * @param int $user_id User.
 * @return int[]
 */
function owned_post_ids( int $user_id ): array {
	static $cache = array();
	if ( ! isset( $cache[ $user_id ] ) ) {
		$ids = get_posts(
			array(
				'author'           => $user_id,
				'post_type'        => 'any',
				'post_status'      => 'any',
				'fields'           => 'ids',
				'posts_per_page'   => -1,
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);
		$cache[ $user_id ] = array_values( array_unique( array_map( 'intval', (array) apply_filters( 'flurfunk_owned_post_ids', $ids, $user_id ) ) ) );
	}
	return $cache[ $user_id ];
}

/**
 * May the person see this thread?
 *
 * @param object $thread  Thread row.
 * @param int    $user_id User (0 = current).
 */
function can_see( object $thread, int $user_id = 0 ): bool {
	$user_id = $user_id ?: get_current_user_id();
	$channel = \Flurfunk\Store\channel( (int) $thread->channel_id );
	if ( ! $channel ) {
		return false;
	}
	$level = level( $channel, $user_id );
	if ( 'all' === $level ) {
		return true;
	}
	return 'own-posts' === $level && 'post' === $thread->target_type && in_array( (int) $thread->target_id, owned_post_ids( $user_id ), true );
}

/**
 * May the person start a thread in this channel (with this target)?
 *
 * @param object $channel     Channel row.
 * @param string $target_type Target type ('' = none).
 * @param int    $target_id   Target ID.
 * @param int    $user_id     User (0 = current).
 */
function can_start( object $channel, string $target_type, int $target_id, int $user_id = 0 ): bool {
	$user_id = $user_id ?: get_current_user_id();
	$level   = level( $channel, $user_id );
	if ( 'all' === $level ) {
		return '' === $target_type || can_target( $target_type, $target_id, $user_id );
	}
	return 'own-posts' === $level && 'post' === $target_type && in_array( $target_id, owned_post_ids( $user_id ), true );
}

/**
 * May the person attach a thread to this target?
 *
 * @param string $type    Target type (built in: post).
 * @param int    $id      Target ID.
 * @param int    $user_id User.
 */
function can_target( string $type, int $id, int $user_id ): bool {
	$ok = 'post' === $type && get_post( $id ) && ( 'team' === kind( $user_id ) || in_array( $id, owned_post_ids( $user_id ), true ) );
	return (bool) apply_filters( 'flurfunk_can_target', $ok, $type, $id, $user_id );
}

/**
 * SQL condition for the threads a person may see (table alias t).
 *
 * @param int $user_id User.
 */
function where( int $user_id ): string {
	$all = array();
	$own = array();
	foreach ( \Flurfunk\Store\channels() as $c ) {
		$level = level( $c, $user_id );
		if ( 'all' === $level ) {
			$all[] = (int) $c->id;
		} elseif ( 'own-posts' === $level ) {
			$own[] = (int) $c->id;
		}
	}
	$parts = array();
	if ( $all ) {
		$parts[] = 't.channel_id IN (' . implode( ',', $all ) . ')';
	}
	$posts = $own ? owned_post_ids( $user_id ) : array();
	if ( $posts ) {
		$parts[] = '(t.channel_id IN (' . implode( ',', $own ) . ") AND t.target_type = 'post' AND t.target_id IN (" . implode( ',', $posts ) . '))';
	}
	return $parts ? '(' . implode( ' OR ', $parts ) . ')' : '0=1';
}
