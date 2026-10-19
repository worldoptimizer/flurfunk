<?php
/**
 * Emoji reactions on threads and replies, as in Slack: a click adds yours, a second click takes it
 * back. Nobody is notified. Stored as code points ("1f44d", "1f469-200d-1f4bb"), so the database
 * never mixes two emoji up and needs no emoji support of its own.
 *
 * @package Flurfunk
 */

namespace Flurfunk\Reactions;

use Flurfunk\Access;
use Flurfunk\Store;
use function Flurfunk\Schema\table;

defined( 'ABSPATH' ) || exit;

const MAX_KINDS = 20; // Different emoji on one message.
const PER_MINUTE = 60;

// One emoji: a flag, a keycap, a tag sequence (e.g. the flag of Scotland) or pictographs with
// variation selectors, skin tones and zero-width joiners.
const BASE    = '[\x{00A9}\x{00AE}\x{203C}\x{2049}\x{2122}\x{2139}\x{2194}-\x{21AA}\x{231A}-\x{23FF}\x{24C2}\x{25AA}-\x{27BF}\x{2934}\x{2935}\x{2B05}-\x{2B55}\x{3030}\x{303D}\x{3297}\x{3299}\x{1F000}-\x{1FAFF}]';
const MOD     = '[\x{FE0F}\x{1F3FB}-\x{1F3FF}\x{20E3}]';
const PATTERN = '(?:[\x{1F1E6}-\x{1F1FF}]{2}|[#*0-9]\x{FE0F}?\x{20E3}|\x{1F3F4}[\x{E0020}-\x{E007E}]+\x{E007F}|' . BASE . MOD . '*(?:\x{200D}' . BASE . MOD . '*)*)';

add_action(
	'flurfunk_message_deleted',
	static function ( $thread, int $message_id ): void {
		global $wpdb;
		$wpdb->delete( table( 'reactions' ), array( 'thread_id' => (int) $thread->id, 'message_id' => $message_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Flurfunk's own table.
	},
	10,
	2
);

/**
 * An emoji as its code points, or '' if it is not one emoji.
 *
 * @param string $emoji Emoji.
 */
function key( string $emoji ): string {
	$emoji = trim( $emoji );
	if ( '' === $emoji || strlen( $emoji ) > 64 || ! preg_match( '/^' . PATTERN . '$/u', $emoji ) ) {
		return '';
	}
	// Without variation selectors: 👍 and 👍️ are the same reaction (char() adds them back where needed).
	$cps = array_map( static fn ( string $c ): int => (int) mb_ord( $c, 'UTF-8' ), (array) preg_split( '//u', $emoji, -1, PREG_SPLIT_NO_EMPTY ) );
	return implode( '-', array_map( 'dechex', array_values( array_filter( $cps, static fn ( int $c ): bool => 0xFE0F !== $c ) ) ) );
}

/**
 * The emoji of a stored key.
 *
 * @param string $key Code points.
 */
function char( string $key ): string {
	$out = '';
	foreach ( explode( '-', $key ) as $h ) {
		$c    = (int) hexdec( $h );
		$out .= (string) mb_chr( $c, 'UTF-8' );
		// Older symbols (❤, ☀, digits of a keycap) show as text without the emoji selector.
		if ( $c < 0x1F000 && ! in_array( $c, array( 0x200D, 0x20E3 ), true ) && ( $c < 0xE0020 || $c > 0xE007F ) ) {
			$out .= "\u{FE0F}";
		}
	}
	return $out;
}

/**
 * Reactions of threads: thread → message (0 = the thread itself) → [ { emoji, people } ], in the
 * order they were first given.
 *
 * @param int[] $thread_ids Threads.
 * @param bool  $roots_only Only those on the threads themselves (for lists).
 */
function for_threads( array $thread_ids, bool $roots_only = false ): array {
	global $wpdb;
	$ids = array_values( array_filter( array_map( 'intval', $thread_ids ) ) );
	if ( ! $ids ) {
		return array();
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Flurfunk's own table; the IN list is placeholders.
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT thread_id, message_id, user_id, emoji FROM %i WHERE thread_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') AND ( %d = 0 OR message_id = 0 ) ORDER BY created, user_id', array_merge( array( table( 'reactions' ) ), $ids, array( (int) $roots_only ) ) ) );
	$group = array();
	foreach ( (array) $rows as $r ) {
		$group[ (int) $r->thread_id ][ (int) $r->message_id ][ (string) $r->emoji ][] = (int) $r->user_id;
	}
	$out = array();
	foreach ( $group as $t => $messages ) {
		foreach ( $messages as $m => $emojis ) {
			foreach ( $emojis as $e => $people ) {
				$out[ $t ][ $m ][] = array(
					'emoji'  => char( (string) $e ),
					'people' => $people,
				);
			}
		}
	}
	return $out;
}

/**
 * Add or take back a reaction.
 *
 * @param int    $thread_id  Thread.
 * @param int    $message_id Reply (0 = the thread itself).
 * @param string $emoji      Emoji.
 * @param int    $user_id    Who.
 * @return array|\WP_Error The reactions on that thread or reply now.
 */
function toggle( int $thread_id, int $message_id, string $emoji, int $user_id ) {
	global $wpdb;
	$t = Store\thread_row( $thread_id );
	if ( ! $t || ! $user_id || ! Access\can_see( $t, $user_id ) ) {
		return new \WP_Error( 'flurfunk_forbidden', __( 'This conversation does not exist.', 'flurfunk' ), array( 'status' => 404 ) );
	}
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Flurfunk's own tables, small and current.
	if ( $message_id && 'message' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT type FROM %i WHERE id = %d AND thread_id = %d', table( 'messages' ), $message_id, $thread_id ) ) ) {
		return new \WP_Error( 'flurfunk_forbidden', __( 'This message does not exist.', 'flurfunk' ), array( 'status' => 404 ) );
	}
	$key = key( $emoji );
	if ( '' === $key ) {
		return new \WP_Error( 'flurfunk_emoji', __( 'That is not an emoji.', 'flurfunk' ), array( 'status' => 400 ) );
	}
	$rate = 'flurfunk_react_' . $user_id . '_' . gmdate( 'YmdHi' );
	if ( (int) get_transient( $rate ) >= (int) apply_filters( 'flurfunk_reactions_per_minute', PER_MINUTE, $user_id ) ) {
		return new \WP_Error( 'flurfunk_rate', __( 'That was a lot at once. Please wait a minute.', 'flurfunk' ), array( 'status' => 429 ) );
	}
	set_transient( $rate, (int) get_transient( $rate ) + 1, 2 * MINUTE_IN_SECONDS );
	$where = array(
		'thread_id'  => $thread_id,
		'message_id' => $message_id,
		'user_id'    => $user_id,
		'emoji'      => $key,
	);
	$had   = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE thread_id = %d AND message_id = %d AND user_id = %d AND emoji = %s', table( 'reactions' ), $thread_id, $message_id, $user_id, $key ) );
	if ( $had ) {
		$wpdb->delete( table( 'reactions' ), $where );
	} else {
		$kinds = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT emoji FROM %i WHERE thread_id = %d AND message_id = %d', table( 'reactions' ), $thread_id, $message_id ) );
		if ( count( $kinds ) >= MAX_KINDS && ! in_array( $key, $kinds, true ) ) {
			return new \WP_Error( 'flurfunk_emoji', __( 'There are already many different reactions here.', 'flurfunk' ), array( 'status' => 400 ) );
		}
		$wpdb->insert( table( 'reactions' ), $where + array( 'created' => Store\now() ) );
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	Store\bump();
	/**
	 * A reaction was added or taken back.
	 *
	 * @param object $t          Thread row.
	 * @param int    $message_id Reply (0 = the thread).
	 * @param string $emoji      Emoji.
	 * @param int    $user_id    Who.
	 * @param bool   $added      Added (true) or taken back.
	 */
	do_action( 'flurfunk_reaction', $t, $message_id, char( $key ), $user_id, ! $had );
	return for_threads( array( $thread_id ) )[ $thread_id ][ $message_id ] ?? array();
}

/**
 * Store a reaction as it is (import): no checks, duplicates are left out.
 *
 * @param int    $thread_id  Thread.
 * @param int    $message_id Reply (0 = the thread).
 * @param int    $user_id    Who.
 * @param string $emoji      Emoji.
 */
function put( int $thread_id, int $message_id, int $user_id, string $emoji ): void {
	global $wpdb;
	$key = key( $emoji );
	if ( '' !== $key && $user_id ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Flurfunk's own table.
		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (thread_id, message_id, user_id, emoji, created) VALUES (%d, %d, %d, %s, %s)', table( 'reactions' ), $thread_id, $message_id, $user_id, $key, Store\now() ) );
	}
}
