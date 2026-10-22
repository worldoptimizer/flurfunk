<?php
/**
 * One open thread for something that waits for people – comments to approve, updates to install:
 * the first time a thread (with a card that always shows the current state), afterwards only what
 * is new, as a reply in that thread; once nothing waits any more it is ticked off; after some days
 * without anything new a reminder. Unchanged things never post again, and when the state cannot be
 * known (a list WordPress has not fetched yet) nothing happens.
 *
 * Used by the modules "Daily moderation thread" and "Updates available"; plugins can use it too.
 *
 * @package Flurfunk
 */

namespace Flurfunk\Watch;

use Flurfunk\Store;
use function Flurfunk\Schema\table;

defined( 'ABSPATH' ) || exit;

/**
 * The thread a watch keeps (null if none, or deleted).
 *
 * @param string $key Option of the watch.
 */
function thread( string $key ): ?object {
	$state = (array) get_option( $key, array() );
	return empty( $state['thread'] ) ? null : Store\thread_row( (int) $state['thread'] );
}

/**
 * Compare what waits now with what the thread already says, and post only the difference.
 *
 * @param string     $key   Option that keeps the state ({ thread, items, since, reminded }).
 * @param array|null $items What waits now: key => line. Null: not known right now – nothing happens.
 * @param array      $args  {
 *     channel:   slug of the channel for a new thread,
 *     title:     title of a new thread,
 *     body:      callable( array $items ): string – text of a new thread,
 *     new:       callable( array $new ): string – reply when something new waits,
 *     remind:    callable( int $days ): string – reply when it waits that long (optional),
 *     days:      days until a reminder (0 = never),
 *     card:      [ provider, ref ] of a card in the thread, refreshed on every run (optional),
 *     assignees: user IDs responsible for a new thread (optional; makes it a task),
 * }
 * @return int Thread ID, 0 if nothing waits (or the state is unknown).
 */
function run( string $key, ?array $items, array $args ): int {
	if ( null === $items ) {
		return 0;
	}
	$state  = (array) get_option( $key, array() );
	$thread = thread( $key );
	if ( ! $items ) {
		if ( $thread && 'done' !== (string) $thread->status ) {
			flurfunk_set_status( (int) $thread->id, 'done' ); // Nothing waits any more.
		}
		delete_option( $key );
		return 0;
	}
	if ( ! $thread ) {
		$card = ! empty( $args['card'] ) ? array( array( 'provider' => (string) $args['card'][0], 'ref' => (array) $args['card'][1] ) ) : array();
		$id   = flurfunk_post(
			array_filter(
				array(
					'channel'     => (string) $args['channel'],
					'title'       => (string) $args['title'],
					'body'        => (string) call_user_func( $args['body'], $items ),
					'attachments' => $card,
					'task'        => true,
					'assignees'   => array_values( array_map( 'intval', (array) ( $args['assignees'] ?? array() ) ) ),
				)
			)
		);
		if ( is_wp_error( $id ) ) {
			return 0; // Next run again.
		}
		update_option(
			$key,
			array(
				'thread'   => (int) $id,
				'items'    => array_keys( $items ),
				'since'    => time(),
				'reminded' => 0,
			),
			false
		);
		return (int) $id;
	}
	refresh( (int) $thread->id, $args );
	$known = array_map( 'strval', (array) ( $state['items'] ?? array() ) );
	$new   = array_diff_key( $items, array_flip( $known ) );
	if ( $new ) {
		flurfunk_reply( (int) $thread->id, (string) call_user_func( $args['new'], $new ) );
		if ( 'done' === (string) $thread->status ) {
			flurfunk_set_status( (int) $thread->id, 'open' ); // Ticked off, but something new came.
		}
		$state['since']    = time();
		$state['reminded'] = 0;
	} elseif ( 'done' !== (string) $thread->status && ! empty( $args['remind'] ) && (int) ( $args['days'] ?? 0 ) > 0 ) {
		$last = max( (int) ( $state['since'] ?? 0 ), (int) ( $state['reminded'] ?? 0 ) );
		$days = (int) $args['days'];
		if ( $last && time() - $last >= $days * DAY_IN_SECONDS ) {
			flurfunk_reply( (int) $thread->id, (string) call_user_func( $args['remind'], (int) floor( ( time() - (int) ( $state['since'] ?? $last ) ) / DAY_IN_SECONDS ) ) );
			$state['reminded'] = time();
		}
	}
	$state['items'] = array_keys( $items );
	update_option( $key, $state, false );
	return (int) $thread->id;
}

/**
 * Bring the watch's card in its thread up to date.
 *
 * @param int   $thread_id Thread.
 * @param array $args      Watch arguments (card).
 */
function refresh( int $thread_id, array $args ): void {
	if ( empty( $args['card'] ) ) {
		return;
	}
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Flurfunk's own table.
	$card = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE thread_id = %d AND provider = %s ORDER BY id LIMIT 1', table( 'attachments' ), $thread_id, (string) $args['card'][0] ) );
	if ( $card ) {
		flurfunk_as( 0, static fn () => Store\refresh_card( $card ) );
	}
}

/**
 * Take over an open thread a module posted before it kept a state (e.g. one thread per day):
 * the newest becomes the watch's thread, older open ones are ticked off.
 *
 * @param string   $key    Option of the watch.
 * @param string   $prefix ref_key prefix of the old threads, e.g. "flurfunk-comments:".
 * @param string[] $items  Keys of what waits now (taken as known, so nothing posts again).
 */
function adopt( string $key, string $prefix, array $items ): void {
	if ( get_option( $key ) ) {
		return;
	}
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Flurfunk's own table, once.
	$open = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE ref_key LIKE %s AND ( status IS NULL OR status <> 'done' ) ORDER BY id DESC", table( 'threads' ), $wpdb->esc_like( $prefix ) . '%' ) );
	if ( ! $open ) {
		return;
	}
	foreach ( array_slice( $open, 1 ) as $old ) {
		flurfunk_set_status( (int) $old, 'done' );
	}
	update_option(
		$key,
		array(
			'thread'   => (int) $open[0],
			'items'    => array_values( array_map( 'strval', $items ) ),
			'since'    => time(),
			'reminded' => 0,
		),
		false
	);
}
